<?php
/** One bounded CLI-only, read-only SOAP session. No persistence or HTTP entrypoint. */
final class IntegrationVmwareBatchSession
{
    public const SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const VIM = 'urn:vim25';
    private array $runtime;
    private $transport;
    private $authorized;
    private ?string $cookie = null;
    private array $managers = [];
    private bool $used = false;
    private bool $active = false;
    private bool $failed = false;
    private float $deadline = 0;
    private int $requests = 0;
    private int $bytes = 0;
    private array $operations = [];

    public function __construct(#[SensitiveParameter] array $runtime, callable $authorized,
        ?callable $transport = null, private int $requestLimit = 128, private int $seconds = 120)
    {
        if (PHP_SAPI !== 'cli' || ($runtime['verify_tls'] ?? null) !== true ||
            !is_string($runtime['username'] ?? null) || $runtime['username'] === '' ||
            !is_string($runtime['password'] ?? null) || $runtime['password'] === '' ||
            strlen($runtime['username']) > 1024 || strlen($runtime['password']) > 8192 ||
            $requestLimit < 4 || $requestLimit > 256 || $seconds < 5 || $seconds > 300) {
            throw new RuntimeException('batch_configuration_invalid');
        }
        if (!class_exists('DOMDocument')) throw new RuntimeException('batch_dom_required');
        $runtime['endpoint'] = (new IntegrationVmwareVcenter())->normalize_endpoint($runtime['endpoint']);
        $this->runtime = $runtime; $this->authorized = $authorized;
        $this->transport = $transport ?? new IntegrationVmwareVcenterDirectTransport(8388608);
    }
    public function __debugInfo(): array { return ['used' => $this->used, 'requests' => $this->requests]; }
    public function __destruct()
    {
        if (isset($this->runtime['password'])) sodium_memzero($this->runtime['password']);
        $this->cookie = null;
    }
    private function guard(): void
    {
        try { $valid = ($this->authorized)() === true; } catch (Throwable $e) { $valid = false; }
        if (!$valid) throw new RuntimeException('batch_binding_changed');
    }
    /** All requested operations and Logout must finish before a result can be returned. */
    public function run(callable $callback): mixed
    {
        if ($this->used) throw new RuntimeException('batch_session_single_use');
        $this->used = true; $this->active = true; $this->deadline = hrtime(true) / 1000000000 + $this->seconds;
        $error = null; $result = null;
        try {
            $this->guard();
            $response = $this->exchange('RetrieveServiceContent', '<_this type="ServiceInstance">ServiceInstance</_this>');
            $content = self::one($response, 'returnval');
            foreach (['sessionManager' => 'SessionManager', 'propertyCollector' => 'PropertyCollector', 'perfManager' => 'PerformanceManager'] as $name => $type) {
                $node = self::one($content, $name); $ref = self::text($node);
                if ($node->getAttribute('type') !== $type || !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D', $ref)) {
                    throw new RuntimeException('batch_service_reference_invalid');
                }
                $this->managers[$name] = $ref;
            }
            $this->exchange('Login', '<_this type="SessionManager">' . self::xml($this->manager('sessionManager')) . '</_this>' .
                '<userName>' . self::xml($this->runtime['username']) . '</userName><password>' . self::xml($this->runtime['password']) . '</password>');
            if ($this->cookie === null) throw new RuntimeException('batch_session_cookie_missing');
            $result = $callback($this);
            if ($this->failed) throw new RuntimeException('batch_session_failed');
            $this->guard();
        } catch (Throwable $e) { $error = $e; }
        finally {
            if ($this->cookie !== null && isset($this->managers['sessionManager'])) {
                try { $this->exchange('Logout', '<_this type="SessionManager">' . self::xml($this->managers['sessionManager']) . '</_this>', true); }
                catch (Throwable $e) {
                    // Authorization loss during cleanup must never authorize publishing the earlier result.
                    if ($error === null || in_array($e->getMessage(), ['batch_access_denied', 'batch_binding_changed'], true)) $error = $e;
                }
            }
            try { $this->guard(); } catch (Throwable $e) { $error = $e; }
            $this->active = false; $this->cookie = null; $this->managers = [];
            if (isset($this->runtime['password'])) sodium_memzero($this->runtime['password']);
        }
        if ($error !== null) throw $error;
        return $result;
    }
    public function manager(string $name): string
    {
        if (!$this->active || !isset($this->managers[$name])) throw new RuntimeException('batch_session_inactive');
        return $this->managers[$name];
    }
    /** Internal collector boundary: no URL parameter, no arbitrary/mutating SOAP operation. */
    public function read(string $operation, #[SensitiveParameter] string $body): DOMElement
    {
        if (!$this->active || $this->cookie === null || $this->failed ||
            !in_array($operation, ['RetrieveProperties', 'QueryPerf', 'QueryPerfProviderSummary', 'QueryAvailablePerfMetric'], true)) {
            throw new RuntimeException('batch_read_scope');
        }
        try { return $this->exchange($operation, $body); }
        catch (Throwable $e) { $this->failed = true; throw $e; }
    }
    public function statistics(): array
    {
        return ['requests' => $this->requests, 'response_bytes' => $this->bytes, 'operations' => $this->operations];
    }
    private function exchange(string $operation, #[SensitiveParameter] string $inner, bool $cleanup = false): DOMElement
    {
        $this->guard(); $remaining = $this->deadline - hrtime(true) / 1000000000;
        if ($remaining < ($cleanup ? 1 : 3) || $this->requests >= $this->requestLimit - ($cleanup ? 0 : 1)) {
            throw new RuntimeException('batch_budget_exhausted');
        }
        $wire = '<s:Envelope xmlns:s="' . self::SOAP . '"><s:Body><' . $operation . ' xmlns="urn:vim25">' . $inner . '</' . $operation . '></s:Body></s:Envelope>';
        if (strlen($wire) > 1048576) throw new RuntimeException('batch_request_limit');
        // Reparse generated requests as well: callers cannot smuggle a second operation or a DTD.
        self::response($wire, $operation, false);
        $headers = ['Content-Type: text/xml; charset=utf-8', 'Accept: text/xml'];
        if ($this->cookie !== null) $headers[] = 'Cookie: ' . $this->cookie;
        ++$this->requests; $this->operations[$operation] = ($this->operations[$operation] ?? 0) + 1;
        $timeout = min($cleanup ? 3 : 20, max(1, (int)$remaining));
        try { $reply = ($this->transport)('POST', $this->runtime['endpoint'] . '/sdk', $headers, $wire, true, min(8, $timeout), $timeout); }
        catch (Throwable $e) {
            // Never propagate arbitrary cURL/provider text or raw request bodies.
            throw new RuntimeException('batch_transport_failed');
        }
        $this->guard();
        if (!is_array($reply) || !is_int($reply['status'] ?? null) || !is_string($reply['body'] ?? null)) {
            throw new RuntimeException('batch_transport_shape');
        }
        if (in_array($reply['status'], [401, 403], true)) throw new RuntimeException('batch_access_denied');
        if (strlen($reply['body']) > 8388608 || ($this->bytes += strlen($reply['body'])) > 67108864) {
            throw new RuntimeException('batch_response_limit');
        }
        if ($reply['status'] === 200 && $operation === 'Login') {
            $cookies = [];
            foreach ($reply['headers'] ?? [] as $line) {
                if (!is_string($line)) throw new RuntimeException('batch_header_invalid');
                if (stripos($line, 'Set-Cookie:') !== 0) continue;
                $pair = trim(explode(';', substr($line, 11), 2)[0]);
                if (stripos($pair, 'vmware_soap_session=') !== 0) continue;
                if (!preg_match('/^vmware_soap_session=(?:"[A-Za-z0-9._~+\/=\-]{1,512}"|[A-Za-z0-9._~+\/=\-]{1,512})$/D', $pair)) {
                    throw new RuntimeException('batch_cookie_invalid');
                }
                $cookies[] = $pair;
            }
            if (count($cookies) !== 1) throw new RuntimeException('batch_session_cookie_missing');
            $this->cookie = $cookies[0];
        }
        // SOAP faults may arrive with HTTP 500. Classify structural access faults before status.
        $element = self::response($reply['body'], $operation . 'Response');
        if ($reply['status'] !== 200) throw new RuntimeException('batch_http_failed');
        return $element;
    }
    public static function response(#[SensitiveParameter] string $wire, string $name, bool $checkFault = true): DOMElement
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $wire)) throw new RuntimeException('batch_xml_unsafe');
        $doc = new DOMDocument(); $old = libxml_use_internal_errors(true);
        try { $ok = $doc->loadXML($wire, LIBXML_NONET | LIBXML_NOBLANKS); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        if (!$ok || $doc->doctype !== null) throw new RuntimeException('batch_xml_invalid');
        $root = $doc->documentElement;
        if ($root->localName !== 'Envelope' || $root->namespaceURI !== self::SOAP) throw new RuntimeException('batch_envelope_invalid');
        $bodies = [];
        foreach ($root->childNodes as $n) if ($n instanceof DOMElement && $n->localName === 'Body' && $n->namespaceURI === self::SOAP) $bodies[] = $n;
        if (count($bodies) !== 1) throw new RuntimeException('batch_envelope_invalid');
        $entries = [];
        foreach ($bodies[0]->childNodes as $n) if ($n instanceof DOMElement) $entries[] = $n;
        if (count($entries) !== 1) throw new RuntimeException('batch_operation_invalid');
        if ($checkFault) {
            $xp = new DOMXPath($doc);
            foreach ($xp->query('//*') as $n) {
                $type = $n->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
                $type = preg_replace('/^.*:/', '', $type);
                if (in_array($n->localName, ['NoPermission', 'NoPermissionFault', 'NotAuthenticated', 'NotAuthenticatedFault'], true) ||
                    in_array($type, ['NoPermission', 'NoPermissionFault', 'NotAuthenticated', 'NotAuthenticatedFault'], true)) {
                    throw new RuntimeException('batch_access_denied');
                }
            }
            if ($entries[0]->localName === 'Fault') throw new RuntimeException('batch_soap_fault');
        }
        if ($entries[0]->namespaceURI !== self::VIM || $entries[0]->localName !== $name) throw new RuntimeException('batch_operation_invalid');
        return $entries[0];
    }
    public static function children(DOMElement $node, string $name): array
    {
        $out = [];
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement && $child->localName === $name) {
            if ($child->namespaceURI !== self::VIM) throw new RuntimeException('batch_namespace_invalid');
            $out[] = $child;
        }
        return $out;
    }
    public static function one(DOMElement $node, string $name): DOMElement
    {
        $nodes = self::children($node, $name);
        if (count($nodes) !== 1) throw new RuntimeException('batch_field_missing_or_duplicate');
        return $nodes[0];
    }
    public static function text(DOMElement $node): string
    {
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement) throw new RuntimeException('batch_scalar_invalid');
        return $node->textContent;
    }
    public static function xml(string $text): string
    {
        if (!preg_match('//u', $text) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) throw new RuntimeException('batch_text_invalid');
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
