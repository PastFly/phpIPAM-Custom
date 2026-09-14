<?php
require __DIR__ . '/../tools/phase5g4-provider-probe.php';
$checks = 0;
function check($v): void { global $checks; $checks++; if (!$v) throw new RuntimeException('assertion_' . $checks); }
function fails(callable $f, string $expected): void {
    try { $f(); } catch (RuntimeException $e) { check($e->getMessage() === $expected); return; }
    throw new RuntimeException('not_rejected');
}
check(Phase5G4ProviderProbe::reason(new RuntimeException('Selected vCenter entity does not expose bounded real-time performance statistics')) === 'provider_not_realtime');
check(Phase5G4ProviderProbe::reason(new RuntimeException('vCenter guest MAC address has an invalid value')) === 'guest_mac_invalid');
check(Phase5G4ProviderProbe::reason(new RuntimeException('vCenter guest network inventory request failed (HTTP 500)')) === 'guest_http_500');
foreach (['SECRET vm-7 password', 'prefix Selected vCenter entity does not expose bounded real-time performance statistics',
    'vCenter guest network inventory request failed (HTTP 500) SECRET'] as $value) {
    check(Phase5G4ProviderProbe::reason(new RuntimeException($value)) === 'unclassified_error');
}
$objects = []; $metrics = [];
for ($i = 1; $i <= 8; $i++) {
    $objects['vm-' . $i] = ['data' => ['name' => 'SECRET-NAME', 'detail' => ['vm' => ['power_state' => $i < 6 ? 'POWERED_OFF' : 'POWERED_ON']],
        'sections' => ['guest_network' => ['state' => 'unavailable']]]];
    $metrics['vm-' . $i] = ['performance' => ['state' => 'unavailable'],
        'storage_performance' => ['state' => 'ok', 'data' => ['reason' => $i < 6 ? 'provider_not_realtime' : 'PRIVATE_REASON']]];
}
$objects['vm-9'] = ['data' => ['detail' => ['vm' => ['power_state' => 'PRIVATE_POWER']]]];
$current = ['inventory' => ['objects' => ['vm' => $objects]]];
$plan = Phase5G4ProviderProbe::plan($current, static fn($id, $epoch) => $metrics[$id] ?? []);
check($plan['aggregate']['cpu_failure_groups'] === ['POWERED_OFF/provider_not_realtime' => 5, 'POWERED_ON/unknown' => 3]);
check($plan['aggregate']['guest_failure_groups'] === ['POWERED_OFF' => 5, 'POWERED_ON' => 3]);
check($plan['aggregate']['missing_vm_metric_sections'] === 2);
check(count($plan['examples']['cpu']) === 3 && count($plan['examples']['guest_network']) === 2);
check($plan['examples']['cpu'][0]['saved_power'] === 'POWERED_OFF' && $plan['examples']['cpu'][1]['saved_power'] === 'POWERED_ON');
$aggregate = json_encode($plan['aggregate'], JSON_THROW_ON_ERROR);
foreach (['vm-1', 'SECRET-NAME', 'PRIVATE_REASON', 'PRIVATE_POWER'] as $s) check(!str_contains($aggregate, $s));
fails(fn() => Phase5G4ProviderProbe::plan(['blocked' => true], fn() => []), 'probe_no_authorized_inventory');
fails(fn() => Phase5G4ProviderProbe::plan(['inventory' => ['objects' => ['vm' => ['bad-id' => []]]]], fn() => []), 'probe_shape');
$unvisited = Phase5G4ProviderProbe::plan(['inventory' => ['objects' => ['vm' => ['vm-10' => []]]]], fn() => []);
check($unvisited['examples']['cpu'] === [] && $unvisited['examples']['guest_network'] === []);
check($unvisited['aggregate']['missing_vm_metric_sections'] === 2);
$endpoint = 'https://example.invalid:443';
$good = static fn(...$args): array => ['status' => 200, 'headers' => [], 'body' => '<response/>'];
$t = new Phase5G4ProbeTransport($endpoint, fn() => true, $good, $good); $t->begin();
$body = '<RetrieveServiceContent xmlns="urn:vim25"><_this>test</_this></RetrieveServiceContent>';
fails(fn() => $t->soap('POST', $endpoint . '/sdk', [], $body, false, 8, 20), 'probe_scope');
fails(fn() => $t->soap('GET', $endpoint . '/sdk', [], $body, true, 8, 20), 'probe_scope');
fails(fn() => $t->soap('POST', 'https://other.invalid/sdk', [], $body, true, 8, 20), 'probe_scope');
check($t->count() === 0);
$rest = $t->guest('vm-1');
fails(fn() => $rest('GET', $endpoint . '/api/vcenter/vm/vm-2/guest/networking/interfaces', [], null, null, true, 8, 20), 'probe_scope');
fails(fn() => $rest('POST', $endpoint . '/api/vcenter/vm/vm-1/guest/networking/interfaces', [], null, null, true, 8, 20), 'probe_scope');
fails(fn() => $rest('GET', $endpoint . '/api/vcenter/vm/vm-1/guest/networking/interfaces', [], null, null, false, 8, 20), 'probe_scope');
for ($i = 0; $i < 64; $i++) $t->soap('POST', $endpoint . '/sdk', [], $body, true, 8, 20);
check($t->count() === 64);
fails(fn() => $t->soap('POST', $endpoint . '/sdk', [], $body, true, 8, 20), 'probe_budget');
$t = new Phase5G4ProbeTransport($endpoint, fn() => false, $good, $good); $t->begin();
fails(fn() => $t->soap('POST', $endpoint . '/sdk', [], $body, true, 8, 20), 'probe_binding_changed');
foreach ([401, 403, 500] as $status) {
    $bad = static fn(...$args): array => ['status' => $status, 'headers' => [], 'body' => '<NoPermissionFault>SECRET</NoPermissionFault>'];
    $t = new Phase5G4ProbeTransport($endpoint, fn() => true, $bad, $bad); $t->begin();
    fails(fn() => $t->soap('POST', $endpoint . '/sdk', [], $body, true, 8, 20), 'probe_access_denied');
    check($t->denied());
    check(!str_contains(json_encode($t->report), 'SECRET'));
}
$wire = file_get_contents(__DIR__ . '/../tools/phase5g4-provider-probe.php');
foreach (['->exclusive(', '->write(', '->saveState(', 'file_put_contents(', 'systemctl', 'var_dump(', 'print_r('] as $f) check(!str_contains($wire, $f));
check(str_contains($wire, "fopen(\$path, 'rb')") && str_contains($wire, 'LOCK_SH | LOCK_NB'));

if (class_exists('DOMDocument')) {
    // Reproduce the actual retained CPU reader's refusal of a well-formed unsupported provider.
    $root = dirname(__DIR__);
    foreach (['layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php',
        'layers/phase5f/functions/classes/class.IntegrationVmwareVcenterPerformance.php',
        'layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php'] as $rel) require_once $root . '/' . $rel;
    $envelope = static fn($xml) => '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>' . $xml . '</soap:Body></soap:Envelope>';
    $soap = static function ($method, $url, $headers, $body, $tls, $connect, $timeout) use ($envelope): array {
        check($tls === true);
        if (str_contains($body, '<RetrieveServiceContent')) $xml = '<RetrieveServiceContentResponse><returnval><sessionManager>SessionManager</sessionManager><propertyCollector>propertyCollector</propertyCollector><perfManager>perfManager</perfManager></returnval></RetrieveServiceContentResponse>';
        elseif (str_contains($body, '<Login')) return ['status' => 200, 'headers' => ['set-cookie' => ['vmware_soap_session="fixture"; Path=/']], 'body' => $envelope('<LoginResponse/>')];
        elseif (str_contains($body, '<QueryPerfProviderSummary')) $xml = '<QueryPerfProviderSummaryResponse><returnval><currentSupported>false</currentSupported><summarySupported>true</summarySupported><refreshRate>-1</refreshRate></returnval></QueryPerfProviderSummaryResponse>';
        elseif (str_contains($body, '<Logout')) $xml = '<LogoutResponse/>';
        else throw new RuntimeException('unexpected_request');
        return ['status' => 200, 'headers' => [], 'body' => $envelope($xml)];
    };
    $t = new Phase5G4ProbeTransport($endpoint, fn() => true, $soap, $good); $t->begin();
    try { (new IntegrationVmwareVcenterPerformance([$t, 'soap']))->read_realtime_percent_series($endpoint, 'fixture', 'fixture', 'vm', 'vm-1', 60, true); }
    catch (RuntimeException $e) { check(Phase5G4ProviderProbe::reason($e) === 'provider_not_realtime'); }
    check(($t->report['provider_current_supported'] ?? null) === false);
    check($t->count() === 4 && $t->report['main_stage'] === 'QueryPerfProviderSummary');
    $rest = static function ($method, ...$args): array {
        if ($method === 'POST') return ['status' => 201, 'headers' => [], 'body' => '"fixture-token"'];
        if ($method === 'DELETE') return ['status' => 204, 'headers' => [], 'body' => ''];
        return ['status' => 200, 'headers' => [], 'body' => '[{"nic":"10","mac_address":"invalid-mac","ip":{}}]'];
    };
    $t = new Phase5G4ProbeTransport($endpoint, fn() => true, $good, $rest); $t->begin();
    try { (new IntegrationVmwareVcenterGuestNetwork($t->guest('vm-1')))->read_vm_interfaces($endpoint, 'fixture', 'fixture', ['vm-1'], true); }
    catch (RuntimeException $e) { check(Phase5G4ProviderProbe::reason($e) === 'guest_mac_invalid'); }
    check($t->count() === 3 && $t->report['main_stage'] === 'guest_interfaces');
    echo "PHASE5G4_PROBE_REAL_COLLECTOR_FIXTURES_OK\n";
} else { echo "PHASE5G4_PROBE_DOM_FIXTURES_NOT_RUN=1\n"; }
echo 'PHASE5G4_PROVIDER_PROBE_ASSERTIONS=' . $checks . "\n";
