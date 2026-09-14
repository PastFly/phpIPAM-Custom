#!/usr/bin/env php
<?php
/** Bounded examples from saved failures; no snapshot writes, scheduler or cutover. */
final class Phase5G4ProviderProbe
{
    public static function label($v, array $allowed): string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : 'unknown';
    }
    public static function reason(Throwable $e): string
    {
        // Exact local collector messages only. Never emit the provider's exception text.
        $messages = [
            'Selected vCenter entity does not expose bounded real-time performance statistics' => 'provider_not_realtime',
            'Aggregate performance metric is unavailable for selected entity: cpu.usage.average' => 'aggregate_cpu_unavailable',
            'Aggregate performance metric is unavailable for selected entity: mem.usage.average' => 'aggregate_memory_unavailable',
            'Required vCenter performance counter not available: cpu.usage.average' => 'cpu_counter_missing',
            'Required vCenter performance counter not available: mem.usage.average' => 'memory_counter_missing',
            'Unexpected unit for performance counter: cpu.usage.average' => 'cpu_unit_invalid',
            'Unexpected unit for performance counter: mem.usage.average' => 'memory_unit_invalid',
            'vCenter performance provider summary missing' => 'provider_summary_missing',
            'vCenter guest network response is not valid JSON' => 'guest_json_invalid',
            'Unexpected vCenter guest network response' => 'guest_response_shape',
            'vCenter guest NIC has an invalid external id' => 'guest_nic_id_invalid',
            'vCenter guest MAC address has an invalid value' => 'guest_mac_invalid',
            'vCenter guest IP address has an invalid value' => 'guest_ip_invalid',
            'vCenter guest IP prefix length has an invalid value' => 'guest_prefix_invalid',
            'vCenter guest network interface row has an invalid shape' => 'guest_interface_shape',
            'vCenter guest IP observation has an invalid shape' => 'guest_ip_shape',
            'vCenter guest IP address collection has an invalid shape' => 'guest_ip_collection_shape',
            'vCenter guest IP origin has an invalid value' => 'guest_ip_origin_invalid',
            'vCenter guest IP state has an invalid value' => 'guest_ip_state_invalid',
            'mirror_access_denied' => 'access_denied', 'probe_access_denied' => 'access_denied',
            'probe_binding_changed' => 'binding_changed', 'probe_budget' => 'budget_exhausted',
        ];
        if (isset($messages[$e->getMessage()])) return $messages[$e->getMessage()];
        if (preg_match('/^vCenter guest network inventory request failed \(HTTP ([1-5][0-9]{2})\)$/D', $e->getMessage(), $m)) return 'guest_http_' . $m[1];
        return 'unclassified_error';
    }
    public static function plan(array $current, callable $read): array
    {
        if (!empty($current['blocked']) || !is_array($current['inventory'] ?? null)) throw new RuntimeException('probe_no_authorized_inventory');
        $rows = $current['inventory']['objects']['vm'] ?? null;
        if (!is_array($rows) || count($rows) > 1000) throw new RuntimeException('probe_shape');
        ksort($rows, SORT_STRING); $groups = ['cpu' => [], 'guest_network' => []]; $buckets = $groups; $missing = 0;
        foreach ($rows as $id => $row) {
            if (!is_string($id) || !preg_match('/^vm-[0-9]{1,20}$/D', $id)) throw new RuntimeException('probe_shape');
            $parts = $read($id, $current['access_epoch'] ?? 'initial');
            $power = self::label($row['data']['detail']['vm']['power_state'] ?? null, ['POWERED_ON', 'POWERED_OFF', 'SUSPENDED']);
            $disk = $parts['storage_performance'] ?? [];
            $diskReason = ($disk['state'] ?? '') === 'ok' ? self::label($disk['data']['reason'] ?? null,
                ['provider_not_realtime', 'disk_usage_counter_unavailable', 'aggregate_disk_usage_unavailable', 'insufficient_samples']) : 'unknown';
            $cpu = $parts['performance'] ?? [];
            foreach (['performance', 'storage_performance'] as $name) if (!isset($parts[$name])) $missing++;
            if (in_array($cpu['state'] ?? '', ['unavailable', 'stale'], true)) {
                $key = $power . '/' . $diskReason;
                $groups['cpu'][$key] = ($groups['cpu'][$key] ?? 0) + 1;
                $buckets['cpu'][$key][] = ['id' => $id, 'saved_power' => $power, 'saved_disk_reason' => $diskReason];
            }
            $guest = $row['data']['sections']['guest_network'] ?? [];
            if (in_array($guest['state'] ?? '', ['unavailable', 'stale'], true)) {
                $groups['guest_network'][$power] = ($groups['guest_network'][$power] ?? 0) + 1;
                $buckets['guest_network'][$power][] = ['id' => $id, 'saved_power' => $power];
            }
        }
        $examples = [];
        foreach (['cpu' => 3, 'guest_network' => 2] as $kind => $limit) {
            $examples[$kind] = [];
            // Take diverse saved-state groups first, then fill the bounded remainder.
            while (count($examples[$kind]) < $limit) {
                $added = false;
                foreach ($buckets[$kind] as &$bucket) {
                    if ($bucket === []) continue;
                    $examples[$kind][] = array_shift($bucket); $added = true;
                    if (count($examples[$kind]) === $limit) break;
                }
                unset($bucket);
                if (!$added) break;
            }
        }
        return ['aggregate' => ['cpu_failure_groups' => $groups['cpu'], 'guest_failure_groups' => $groups['guest_network'],
            'missing_vm_metric_sections' => $missing, 'saved_state_is_live_power_state' => false], 'examples' => $examples];
    }
    public static function emit(string $prefix, array $value): void
    {
        echo $prefix . '=' . json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

final class Phase5G4ProbeTransport
{
    private $authorized;
    private $soap;
    private $rest;
    private string $endpoint;
    private float $deadline;
    private int $requests = 0;
    private bool $denied = false;
    public array $report = [];
    public function __construct(string $endpoint, callable $authorized, callable $soap, callable $rest)
    {
        $this->endpoint = $endpoint; $this->authorized = $authorized; $this->soap = $soap; $this->rest = $rest;
        $this->deadline = microtime(true) + 160;
    }
    public function begin(): void { $this->report = ['requests' => 0, 'http_statuses' => [], 'main_stage' => 'none']; }
    public function count(): int { return $this->requests; }
    public function check(): int
    {
        if ($this->denied) throw new RuntimeException('probe_access_denied');
        if (($this->authorized)() !== true) throw new RuntimeException('probe_binding_changed');
        $left = (int)floor($this->deadline - microtime(true));
        if ($left < 1 || $this->requests >= 64) throw new RuntimeException('probe_budget');
        return min(20, $left);
    }
    public function denied(): bool { return $this->denied; }
    private function record(array $out, string $stage): array
    {
        $status = (int)($out['status'] ?? 0);
        $key = (string)($status >= 100 && $status <= 599 ? $status : 0);
        $this->report['http_statuses'][$key] = ($this->report['http_statuses'][$key] ?? 0) + 1;
        $body = (string)($out['body'] ?? '');
        if (in_array($status, [401, 403], true) || preg_match('/<(?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?\b|xsi:type=["\x27](?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?["\x27]/i', $body)) {
            $this->denied = true; throw new RuntimeException('probe_access_denied');
        }
        if ($stage === 'QueryPerfProviderSummary' && $status >= 200 && $status < 300 && strlen($body) <= 65536 &&
            stripos($body, '<!DOCTYPE') === false && stripos($body, '<!ENTITY') === false) {
            $doc = new DOMDocument(); $before = libxml_use_internal_errors(true);
            try { $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
            if ($ok) {
                $xp = new DOMXPath($doc);
                $nodes = $xp->query('//*[local-name()="QueryPerfProviderSummaryResponse"]/*[local-name()="returnval"]/*[local-name()="currentSupported"]');
                if ($nodes->length === 1 && in_array(trim($nodes->item(0)->textContent), ['true', 'false', '1', '0'], true)) {
                    $this->report['provider_current_supported'] = in_array(trim($nodes->item(0)->textContent), ['true', '1'], true);
                }
            }
        }
        return $out;
    }
    public function soap($method, $url, $headers, $body, $tls, $connect, $timeout): array
    {
        // The original MetricSource validates the complete request XML before this wrapper.
        if ($method !== 'POST' || $url !== $this->endpoint . '/sdk' || $tls !== true || !is_string($body) ||
            !preg_match('/<(RetrieveServiceContent|Login|Logout|RetrieveProperties|QueryPerfProviderSummary|QueryAvailablePerfMetric|QueryPerf)\s+xmlns="urn:vim25"[>\s]/', $body, $m)) throw new RuntimeException('probe_scope');
        $limit = $this->check(); $stage = $m[1];
        if ($stage !== 'Logout') $this->report['main_stage'] = $stage;
        $this->requests++; $this->report['requests']++;
        return $this->record(($this->soap)($method, $url, $headers, $body, true, min(8, $limit, $connect), min($limit, $timeout)), $stage);
    }
    public function guest(string $id): callable
    {
        if (!preg_match('/^vm-[0-9]{1,20}$/D', $id)) throw new RuntimeException('probe_scope');
        return function ($method, $url, $headers, $username, $password, $tls, $connect, $timeout) use ($id): array {
            $session = in_array($url, [$this->endpoint . '/api/session', $this->endpoint . '/rest/com/vmware/cis/session'], true);
            if ($tls !== true || !(($session && in_array($method, ['POST', 'DELETE'], true)) ||
                ($method === 'GET' && $url === $this->endpoint . '/api/vcenter/vm/' . $id . '/guest/networking/interfaces'))) throw new RuntimeException('probe_scope');
            $limit = $this->check(); $stage = $method === 'GET' ? 'guest_interfaces' : ($method === 'POST' ? 'Login' : 'Logout');
            if ($stage !== 'Logout') $this->report['main_stage'] = $stage;
            $this->requests++; $this->report['requests']++;
            return $this->record(($this->rest)($method, $url, $headers, $username, $password, true, min(8, $limit, $connect), min($limit, $timeout)), $stage);
        };
    }
}

function phase5g4_provider_probe_main(): int
{
    if (PHP_SAPI !== 'cli' || ($_SERVER['argc'] ?? 0) !== 1 || posix_geteuid() !== 33 || posix_getegid() !== 33) return 64;
    $lock = null; $runtime = null;
    try {
        $app = '/var/www/html'; $release = '/opt/phpipam-custom/inventory';
        require_once $app . '/functions/functions.php';
        foreach (['IntegrationVmwareSnapshotService', 'IntegrationVmwareVcenter', 'IntegrationSecretCipher',
            'IntegrationVmwareVcenterDirectTransport', 'IntegrationVmwareVcenterDirectRestTransport',
            'IntegrationVmwareVcenterFaultAwareTransport', 'IntegrationVmwareVcenterPerformance',
            'IntegrationVmwareVcenterStoragePerformance', 'IntegrationVmwareVcenterGuestNetwork'] as $class) {
            require_once $app . '/functions/classes/class.' . $class . '.php';
        }
        foreach (['5g' => ['Store', 'Context'], '5g3' => ['TelemetryStore', 'Sampler', 'MetricSource']] as $layer => $names) {
            foreach ($names as $name) require_once $release . '/layers/phase' . $layer . '/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
        }
        $ctx = new IntegrationVmwareInventoryContext(new Database_PDO(), $app, 1);
        $path = '/var/lib/phpipam-custom/vmware-inventory/connector-1/worker.lock';
        IntegrationVmwareInventoryStore::noLinks($path); $s = lstat($path);
        if (!$s || ($s['mode'] & 0170000) !== 0100000 || ($s['mode'] & 0777) !== 0600 || $s['uid'] !== 33 || $s['nlink'] !== 1 || $s['size'] !== 0) throw new RuntimeException('probe_lock');
        $lock = fopen($path, 'rb'); $t = fstat($lock);
        if (!$t || $s['ino'] !== $t['ino'] || $s['dev'] !== $t['dev'] || !flock($lock, LOCK_SH | LOCK_NB)) throw new RuntimeException('probe_busy');
        $store = $ctx->store(); $metrics = $ctx->telemetry();
        if ($metrics === null || !$ctx->current()) throw new RuntimeException('probe_binding_changed');
        $current = $store->read('current');
        $plan = Phase5G4ProviderProbe::plan($current ?? [], [$metrics, 'read']);
        $plan['aggregate']['inventory_last_error'] = Phase5G4ProviderProbe::label($current['last_error'] ?? null,
            ['timeout', 'binding_changed', 'access_denied', 'membership_changed', 'source_unavailable', 'tls', 'interrupted', 'generation_expired']);
        Phase5G4ProviderProbe::emit('SAVED_FAILURE_CORRELATION', $plan['aggregate']);
        $runtime = $ctx->workerRuntime(IntegrationSecretCipher::from_keyring_file());
        if ($runtime['endpoint'] !== 'https://srv-vcenter01.rbank.local:443' && $runtime['endpoint'] !== 'https://srv-vcenter01.rbank.local') throw new RuntimeException('probe_endpoint');
        $wire = new Phase5G4ProbeTransport((new IntegrationVmwareVcenter())->normalize_endpoint($runtime['endpoint']),
            [$ctx, 'current'], new IntegrationVmwareVcenterDirectTransport(8388608), new IntegrationVmwareVcenterDirectRestTransport(8388608));
        $source = new IntegrationVmwareInventoryMetricSource($runtime, [$wire, 'soap']);
        $sample = 0;
        foreach ($plan['examples'] as $kind => $examples) {
            foreach ($examples as $example) {
                $wire->check(); $wire->begin(); $sample++;
                $out = ['example' => $sample, 'section' => $kind, 'saved_power' => $example['saved_power']];
                try {
                    if ($kind === 'cpu') {
                        $value = $source->sample('vm', $example['id'], 'performance');
                        $out['outcome'] = 'success'; $out['sample_count'] = count($value['samples'] ?? []);
                    } else {
                        $value = (new IntegrationVmwareVcenterGuestNetwork($wire->guest($example['id'])))->read_vm_interfaces(
                            $runtime['endpoint'], $runtime['username'], $runtime['password'], [$example['id']], true);
                        $out['outcome'] = Phase5G4ProviderProbe::label($value['vms'][0]['status'] ?? null, ['ok', 'tools_unavailable', 'vm_not_found']);
                    }
                } catch (Throwable $e) { $out['outcome'] = Phase5G4ProviderProbe::reason($e); }
                if ($wire->denied()) $out['outcome'] = 'access_denied';
                Phase5G4ProviderProbe::emit('TARGETED_PROVIDER_RESULT', $out + $wire->report);
                unset($value);
                if (in_array($out['outcome'], ['access_denied', 'binding_changed', 'budget_exhausted'], true)) throw new RuntimeException('probe_stopped');
            }
        }
        if (!$ctx->current()) throw new RuntimeException('probe_binding_changed');
        Phase5G4ProviderProbe::emit('TARGETED_PROBE_COMPLETE', ['examples' => $sample, 'requests' => $wire->count(),
            'snapshot_written' => false, 'scheduler_started' => false, 'website_changed' => false]);
        return 0;
    } catch (Throwable $e) {
        // No raw exception text or source data may leave this process.
        Phase5G4ProviderProbe::emit('TARGETED_PROBE_STOP', ['reason' => Phase5G4ProviderProbe::reason($e)]);
        return 1;
    } finally {
        if (is_resource($lock)) fclose($lock);
        if (isset($runtime['password'])) sodium_memzero($runtime['password']);
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(phase5g4_provider_probe_main());
