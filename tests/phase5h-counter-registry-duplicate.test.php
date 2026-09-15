<?php
/** Exact/irrelevant duplicate PerfCounterInfo rows are tolerated; selected mappings remain fail-closed. */
require_once __DIR__ . '/../layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchInventory.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php';

if (!class_exists('DOMDocument')) throw new RuntimeException('DOM_REQUIRED_NO_SKIP');
$n = 0;
function ok(bool $value, string $name): void { global $n; ++$n; if (!$value) throw new RuntimeException('assert_' . $name); }
function soap_reply(string $operation, string $payload = ''): string {
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><' . $operation . 'Response xmlns="urn:vim25">' . $payload . '</' . $operation . 'Response></s:Body></s:Envelope>';
}
function counter_xml(int $id, string $group, string $name, string $unit, string $rollup = 'average'): string {
    return '<PerfCounterInfo><key>' . $id . '</key><groupInfo><key>' . $group . '</key></groupInfo>' .
        '<nameInfo><key>' . $name . '</key></nameInfo><unitInfo><key>' . $unit . '</key></unitInfo>' .
        '<rollupType>' . $rollup . '</rollupType></PerfCounterInfo>';
}
function registry_session(string $counters, array &$calls): IntegrationVmwareBatchSession {
    $transport = static function ($method, $url, $headers, $body, $tls, $connect, $timeout) use ($counters, &$calls): array {
        ok($method === 'POST' && $url === 'https://vc.example.test/sdk' && $tls === true, 'transport_scope');
        $doc = new DOMDocument();
        if (!$doc->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)) throw new RuntimeException('fixture_request_xml');
        $xp = new DOMXPath($doc);
        $node = $xp->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*')->item(0);
        if (!$node instanceof DOMElement) throw new RuntimeException('fixture_operation');
        $op = $node->localName; $calls[] = $op;
        $headersOut = [];
        if ($op === 'RetrieveServiceContent') {
            $payload = '<returnval><sessionManager type="SessionManager">SessionManager</sessionManager>' .
                '<propertyCollector type="PropertyCollector">propertyCollector</propertyCollector>' .
                '<perfManager type="PerformanceManager">PerfMgr</perfManager></returnval>';
        } elseif ($op === 'Login') {
            $payload = '<returnval/>';
            $headersOut = ['Set-Cookie: vmware_soap_session="fixture-session"; Secure; HttpOnly'];
        } elseif ($op === 'RetrieveProperties') {
            if (!str_contains($body, '<pathSet>perfCounter</pathSet>')) throw new RuntimeException('fixture_unexpected_read');
            $payload = '<returnval><obj type="PerformanceManager">PerfMgr</obj><propSet><name>perfCounter</name><val>' .
                $counters . '</val></propSet></returnval>';
        } elseif ($op === 'Logout') {
            $payload = '';
        } else {
            throw new RuntimeException('fixture_unexpected_operation');
        }
        return ['status' => 200, 'headers' => $headersOut, 'body' => soap_reply($op, $payload)];
    };
    return new IntegrationVmwareBatchSession(
        ['endpoint'=>'https://vc.example.test','username'=>'fixture-user','password'=>'NOT_A_REAL_PASSWORD','verify_tls'=>true],
        static fn(): bool => true,
        $transport,
        16,
        30
    );
}
function definitions_for(string $counters, array &$calls): array {
    $session = registry_session($counters, $calls);
    return $session->run(static fn(IntegrationVmwareBatchSession $s): array => (new IntegrationVmwareBatchPerformance($s))->definitions());
}
function rejected(string $counters, string $expected): void {
    $calls = [];
    try { definitions_for($counters, $calls); }
    catch (Throwable $e) {
        ok($e->getMessage() === $expected, 'expected_' . $expected . '_got_' . $e->getMessage());
        ok(end($calls) === 'Logout', 'logout_after_' . $expected);
        return;
    }
    throw new RuntimeException('missing_rejection_' . $expected);
}

$cpu = counter_xml(71, 'cpu', 'usage', 'percent');
$mem = counter_xml(84, 'mem', 'usage', 'percent');
$disk = counter_xml(99, 'disk', 'usage', 'kiloBytesPerSecond');
$other = counter_xml(150, 'sys', 'uptime', 'second', 'latest');

// The same selected or irrelevant row may be repeated by vCenter. Selected mappings stay unique.
$calls = [];
$defs = definitions_for($cpu . $cpu . $mem . $disk . $other . $other, $calls);
ok(count($defs) === 3, 'only_selected_definitions');
ok(($defs['cpu.usage.average']['id'] ?? null) === 71, 'cpu_id');
ok(($defs['mem.usage.average']['id'] ?? null) === 84, 'mem_id');
ok(($defs['disk.usage.average']['id'] ?? null) === 99, 'disk_id');
ok($calls === ['RetrieveServiceContent','Login','RetrieveProperties','Logout'], 'single_registry_read');

// Ambiguity wholly outside the three selected QueryPerf mappings must not block collection.
$calls = [];
$defs = definitions_for(
    $cpu . $mem . $disk .
    counter_xml(150, 'sys', 'uptime', 'second', 'latest') .
    counter_xml(151, 'sys', 'uptime', 'second', 'latest') .
    counter_xml(160, 'net', 'packetsRx', 'number', 'summation') .
    counter_xml(160, 'net', 'packetsTx', 'number', 'summation'),
    $calls
);
ok(count($defs) === 3, 'irrelevant_conflicts_ignored');
ok(($defs['cpu.usage.average']['id'] ?? null) === 71, 'irrelevant_conflicts_cpu_stable');
ok($calls === ['RetrieveServiceContent','Login','RetrieveProperties','Logout'], 'irrelevant_conflicts_single_read');

// Same ID describing another canonical counter remains ambiguous when the ID belongs to a selected mapping.
rejected($cpu . counter_xml(71, 'sys', 'other', 'percent') . $mem . $disk, 'batch_counter_duplicate');
// Same selected canonical name with a different ID remains ambiguous and is rejected.
rejected($cpu . counter_xml(72, 'cpu', 'usage', 'percent') . $mem . $disk, 'batch_counter_duplicate');
// A selected counter with the wrong unit is still rejected before publication/query planning.
rejected(counter_xml(71, 'cpu', 'usage', 'megaHertz') . $mem . $disk, 'batch_counter_unit_invalid');

print 'PHASE5H_COUNTER_DUPLICATE_ASSERTIONS=' . $n . "\n";
print "PHASE5H_SELECTED_COUNTER_MAPPING_OK\n";
