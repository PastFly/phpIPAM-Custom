<?php
/** Equivalent duplicate PerfCounterInfo rows are tolerated; ambiguous mappings remain rejected. */
require_once __DIR__ . '/../layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchInventory.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php';
if (!class_exists('DOMDocument')) throw new RuntimeException('DOM_REQUIRED_NO_SKIP');

$n = 0;
function ok_dup(bool $value, string $label): void { global $n; ++$n; if (!$value) throw new RuntimeException('assert_' . $label); }
function envelope_dup(string $body): string { return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $body . '</s:Body></s:Envelope>'; }
function reply_dup(string $op, string $body): string { return envelope_dup('<' . $op . 'Response xmlns="urn:vim25">' . $body . '</' . $op . 'Response>'); }
function counter_dup(int $id, string $group, string $unit): string {
    return '<PerfCounterInfo><key>' . $id . '</key><groupInfo><key>' . $group . '</key></groupInfo>' .
        '<nameInfo><key>usage</key></nameInfo><unitInfo><key>' . $unit . '</key></unitInfo>' .
        '<rollupType>average</rollupType></PerfCounterInfo>';
}
function session_dup(array &$calls, string $mode): IntegrationVmwareBatchSession {
    $transport = static function ($method, $url, $headers, $body, $tls) use (&$calls, $mode): array {
        $doc = new DOMDocument(); if (!$doc->loadXML($body)) throw new RuntimeException('fixture_xml');
        $xp = new DOMXPath($doc); $op = $xp->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*')->item(0)->localName;
        $calls[] = $op; $payload = ''; $responseHeaders = [];
        if ($op === 'RetrieveServiceContent') {
            $payload = '<returnval><sessionManager type="SessionManager">SessionManager</sessionManager>' .
                '<propertyCollector type="PropertyCollector">propertyCollector</propertyCollector>' .
                '<perfManager type="PerformanceManager">PerfMgr</perfManager></returnval>';
        } elseif ($op === 'Login') {
            $payload = '<returnval/>'; $responseHeaders = ['Set-Cookie: vmware_soap_session="fixture-session"; Secure; HttpOnly'];
        } elseif ($op === 'RetrieveProperties') {
            $cpu = counter_dup(71, 'cpu', 'percent');
            $mem = counter_dup($mode === 'conflicting_id' ? 71 : 84, 'mem', 'percent');
            $disk = counter_dup(99, 'disk', 'kiloBytesPerSecond');
            $extra = $mode === 'equivalent' ? $cpu : ($mode === 'conflicting_name' ? counter_dup(72, 'cpu', 'percent') : '');
            $payload = '<returnval><obj type="PerformanceManager">PerfMgr</obj><propSet><name>perfCounter</name><val>' .
                $cpu . $mem . $disk . $extra . '</val></propSet></returnval>';
        }
        return ['status'=>200, 'headers'=>$responseHeaders, 'body'=>reply_dup($op, $payload)];
    };
    return new IntegrationVmwareBatchSession(
        ['endpoint'=>'https://vc.example.test','username'=>'fixture','password'=>'NOT_REAL','verify_tls'=>true],
        static fn(): bool => true, $transport, 16, 30
    );
}

$calls = []; $session = session_dup($calls, 'equivalent');
$defs = $session->run(static fn($s) => (new IntegrationVmwareBatchPerformance($s))->definitions());
ok_dup(count($defs) === 3, 'equivalent_duplicate_deduped');
ok_dup(($defs['cpu.usage.average']['id'] ?? null) === 71, 'cpu_mapping_preserved');
ok_dup($calls === ['RetrieveServiceContent','Login','RetrieveProperties','Logout'], 'equivalent_cleanup');

foreach (['conflicting_id', 'conflicting_name'] as $mode) {
    $calls = []; $session = session_dup($calls, $mode); $caught = null;
    try { $session->run(static fn($s) => (new IntegrationVmwareBatchPerformance($s))->definitions()); }
    catch (Throwable $e) { $caught = $e->getMessage(); }
    ok_dup($caught === 'batch_counter_duplicate', $mode . '_rejected');
    ok_dup(end($calls) === 'Logout', $mode . '_cleanup');
}

echo 'PHASE5H_COUNTER_DUPLICATE_ASSERTIONS=' . $n . "\n";
echo "PHASE5H_EQUIVALENT_COUNTER_DUPLICATE_OK\n";
