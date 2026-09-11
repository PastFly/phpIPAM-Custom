<?php
/** CLI-only, explicit read-only PerformanceManager operations using retained parsers. */
final class IntegrationVmwareInventoryMetricSource implements IntegrationVmwareInventoryMetricProvider
{
    private array $runtime;
    private $transport;
    private bool $denied = false;
    private int $requests = 0;
    private float $deadline = 0;
    public function __construct(array $runtime, ?callable $transport = null)
    {
        if (PHP_SAPI !== 'cli' || ($runtime['verify_tls'] ?? null) !== true ||
            empty($runtime['username']) || empty($runtime['password'])) throw new RuntimeException('mirror_strict_tls_worker_required');
        $runtime['endpoint'] = (new IntegrationVmwareVcenter())->normalize_endpoint($runtime['endpoint']);
        $this->runtime = $runtime;
        $direct = $transport ?? new IntegrationVmwareVcenterDirectTransport(8388608);
        $checked = function ($method, $url, $headers, $body, $tls, $connect, $timeout) use ($direct): array {
            if ($method !== 'POST' || $url !== $this->runtime['endpoint'] . '/sdk' || $tls !== true ||
                !is_string($body) || strlen($body) > 262144 || stripos($body, '<!DOCTYPE') !== false ||
                stripos($body, '<!ENTITY') !== false) throw new RuntimeException('mirror_metric_transport_scope');
            $doc = new DOMDocument();
            $before = libxml_use_internal_errors(true);
            try { $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
            if (!$ok) throw new RuntimeException('mirror_metric_soap_invalid');
            $xp = new DOMXPath($doc);
            $nodes = $xp->query('/*[local-name()="Envelope" and namespace-uri()="http://schemas.xmlsoap.org/soap/envelope/"]/*[local-name()="Body"]/*');
            if ($nodes->length !== 1 || $nodes->item(0)->namespaceURI !== 'urn:vim25' ||
                !in_array($nodes->item(0)->localName, ['RetrieveServiceContent', 'Login', 'Logout', 'RetrieveProperties',
                    'QueryPerfProviderSummary', 'QueryAvailablePerfMetric', 'QueryPerf'], true)) throw new RuntimeException('mirror_metric_soap_operation');
            $remaining = $this->deadline - microtime(true);
            if (++$this->requests > 16 || $remaining < 1) throw new RuntimeException('mirror_metric_deadline');
            $result = $direct($method, $url, $headers, $body, true, min(8, max(1, (int)$remaining), (int)$connect),
                min(20, max(1, (int)$remaining), (int)$timeout));
            if (in_array((int)($result['status'] ?? 0), [401, 403], true) ||
                preg_match('/<(?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?\b|xsi:type=["\x27](?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?["\x27]/i', (string)($result['body'] ?? ''))) {
                $this->denied = true; throw new RuntimeException('mirror_access_denied');
            }
            return $result;
        };
        $this->transport = new IntegrationVmwareVcenterFaultAwareTransport($checked);
    }
    public function sample(string $kind, string $id, string $section): array
    {
        if (!in_array($kind, ['vm', 'host'], true) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id) ||
            !in_array($section, ['performance', 'storage_performance'], true)) throw new InvalidArgumentException('mirror_metric_query');
        $this->denied = false; $this->requests = 0; $this->deadline = microtime(true) + 45;
        $args = [$this->runtime['endpoint'], $this->runtime['username'], $this->runtime['password'], $kind, $id, 60, true];
        try {
            return $section === 'performance'
                ? (new IntegrationVmwareVcenterPerformance($this->transport, 8, 20))->read_realtime_percent_series(...$args)
                : (new IntegrationVmwareVcenterStoragePerformance($this->transport, 8, 20))->read_realtime_series(...$args);
        } finally {
            // Retained collectors intentionally suppress cleanup failures; access denial must survive that suppression.
            if ($this->denied) throw new RuntimeException('mirror_access_denied');
        }
    }
    public function __destruct() { if (isset($this->runtime['password'])) sodium_memzero($this->runtime['password']); }
}
