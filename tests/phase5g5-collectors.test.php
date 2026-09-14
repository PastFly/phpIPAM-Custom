<?php
/** Synthetic provider responses; no credentials, files or upstream network used by these fixtures. */
$generated = $argv[1] ?? throw new RuntimeException('generated_path_required');
require_once __DIR__ . '/../layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once $generated . '/collectors/class.IntegrationVmwareInventoryGuestNetwork.php';
require_once $generated . '/collectors/class.IntegrationVmwareInventoryPerformance.php';
$count = 0;
function ok(bool $v): void { global $count; ++$count; if (!$v) throw new RuntimeException('assert_' . $count); }
function fails(callable $f): bool { try { $f(); } catch (Throwable $e) { return true; } return false; }
function guest(array $rows, array &$calls): array {
    $transport = static function ($method, $url, $headers, $user, $password, $tls) use ($rows, &$calls): array {
        $calls[] = $method; ok($tls === true);
        return match ($method) {
            'POST' => ['status' => 201, 'headers' => [], 'body' => '"fixture-session"'],
            'DELETE' => ['status' => 204, 'headers' => [], 'body' => ''],
            'GET' => ['status' => 200, 'headers' => [], 'body' => json_encode($rows, JSON_THROW_ON_ERROR)],
            default => throw new RuntimeException('mutating_method')
        };
    };
    return (new IntegrationVmwareInventoryGuestNetwork($transport))->read_vm_interfaces('https://vc.example.test', 'fixture', 'not-real', ['vm-1'], true);
}
$network = ['mac_address' => '00:50:56:aa:bb:cc', 'ip' => ['ip_addresses' => [['ip_address' => '192.0.2.3', 'prefix_length' => 24]]]];
foreach ([$network, $network + ['nic' => null]] as $row) {
    $calls = []; $out = guest([$row], $calls);
    ok($calls === ['POST', 'GET', 'DELETE']); ok($out['ok'] && $out['ip_count'] === 1);
    ok($out['vms'][0]['interfaces'][0]['nic_external_id'] === null);
    ok($out['vms'][0]['interfaces'][0]['hardware_association'] === 'unassociated');
    ok($out['ip_observations'][0]['ip_address'] === '192.0.2.3');
}
$calls = []; $out = guest([$network, $network + ['nic' => '4000']], $calls);
ok($out['interface_count'] === 2 && $out['vms'][0]['interfaces'][1]['nic_external_id'] === '4000');
foreach (['', ' ', false, 4000, [], ['bad'], "bad\0id", str_repeat('x', 1025)] as $nic) {
    $calls = []; ok(fails(static fn() => guest([$network + ['nic' => $nic]], $calls)));
}
foreach ([['mac_address' => 'bad'], ['ip' => ['ip_addresses' => [['ip_address' => 'not-an-ip']]]]] as $invalid) {
    $calls = []; ok(fails(static fn() => guest([array_replace($network, $invalid)], $calls)));
}
ok(fails(static fn() => (new IntegrationVmwareInventoryGuestNetwork())->read_vm_interfaces('vc.example.test', 'u', 'p', ['vm-1'], false)));
if (!class_exists('DOMDocument')) { echo "PHASE5G5_GUEST_ASSERTIONS=$count; CPU_DOM_NOT_AVAILABLE\n"; exit(0); }
function xml(string $body): string { return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $body . '</s:Body></s:Envelope>'; }
function cpu(array $options, array &$calls): array {
    $transport = static function ($method, $url, $headers, $body, $tls) use ($options, &$calls): array {
        ok($method === 'POST' && $url === 'https://vc.example.test/sdk' && $tls === true);
        preg_match('/<(RetrieveServiceContent|Login|QueryPerfProviderSummary|RetrieveProperties|QueryAvailablePerfMetric|QueryPerf|Logout)\b/', $body, $m);
        $op = $m[1] ?? throw new RuntimeException('unexpected_method'); $calls[] = $op;
        if (($options['fail_stage'] ?? '') === $op) return ['status' => 503, 'headers' => [], 'body' => 'unavailable'];
        if (($options['fault_stage'] ?? '') === $op) return ['status' => 200, 'headers' => [], 'body' => xml('<s:Fault><faultstring>NoPermission</faultstring></s:Fault>')];
        $payload = '';
        if ($op === 'RetrieveServiceContent') $payload = '<returnval><sessionManager>SessionManager</sessionManager><propertyCollector>propertyCollector</propertyCollector><perfManager>PerfMgr</perfManager></returnval>';
        if ($op === 'QueryPerfProviderSummary') $payload = '<returnval><currentSupported>' . ($options['capability'] ?? 'true') . '</currentSupported><summarySupported>true</summarySupported><refreshRate>' . ($options['rate'] ?? '20') . '</refreshRate></returnval>';
        if ($op === 'RetrieveProperties') {
            foreach (['cpu' => 6, 'mem' => 24] as $kind => $id) {
                if (($options['absent_counter'] ?? '') === $kind) continue;
                $unit = ($options['bad_unit'] ?? '') === $kind ? 'byte' : 'percent';
                $payload .= '<PerfCounterInfo><key>' . $id . '</key><nameInfo><key>usage</key></nameInfo><groupInfo><key>' . $kind . '</key></groupInfo><unitInfo><key>' . $unit . '</key></unitInfo><rollupType>average</rollupType></PerfCounterInfo>';
            }
            $payload = '<returnval><propSet><name>perfCounter</name><val>' . $payload . '</val></propSet></returnval>';
        }
        if ($op === 'QueryAvailablePerfMetric') {
            foreach ($options['available'] ?? [6, 24] as $id) $payload .= '<returnval><counterId>' . $id . '</counterId><instance>' . ($options['instance'] ?? '') . '</instance></returnval>';
        }
        if ($op === 'QueryPerf') {
            $payload = '<returnval><sampleInfo><timestamp>2026-09-14T00:00:00Z</timestamp><interval>20</interval></sampleInfo><sampleInfo><timestamp>2026-09-14T00:00:20Z</timestamp><interval>20</interval></sampleInfo>';
            foreach ([6, 24] as $id) {
                if (!str_contains($body, '<counterId>' . $id . '</counterId>')) continue;
                $payload .= '<value><id><counterId>' . $id . '</counterId><instance></instance></id><value>2500</value><value>' . (($options['negative'] ?? false) ? '-1' : '3000') . '</value></value>';
            }
            $payload .= '</returnval>';
        }
        $result = xml('<' . $op . 'Response xmlns="urn:vim25">' . $payload . '</' . $op . 'Response>');
        if (($options['wrong_response'] ?? '') === $op) $result = xml('<OtherResponse xmlns="urn:vim25"/>');
        return ['status' => 200, 'headers' => $op === 'Login' ? ['Set-Cookie: vmware_soap_session="fixture"; Secure; HttpOnly'] : [], 'body' => $result];
    };
    return (new IntegrationVmwareInventoryPerformance($transport))->read_realtime_percent_series('vc.example.test', 'fixture', 'not-real', 'vm', 'vm-1', 60, true);
}
$calls=[]; $out=cpu([], $calls); ok($out['supported'] === true && count($out['samples']) === 2);
ok($out['samples'][0]['cpu_percent'] === 25.0 && $out['samples'][0]['memory_percent'] === 25.0);
foreach ([[], [6], [24]] as $available) {
    $calls=[]; $out=cpu(['available'=>$available], $calls);
    ok(end($calls) === 'Logout'); ok(!str_contains(json_encode($out), 'not-real'));
    if ($available === []) { ok($out['supported'] === false && $out['reason'] === 'aggregate_metrics_unavailable' && $out['samples'] === []); ok(!in_array('QueryPerf',$calls,true)); }
    else { $missing = $available === [6] ? 'memory_percent' : 'cpu_percent'; ok($out['samples'][0][$missing] === null); ok($out['supported'] === true); }
}
$calls=[]; $out=cpu(['capability'=>'false'], $calls); ok(!$out['supported'] && $out['reason'] === 'provider_not_realtime'); ok(count($calls)===4);
$calls=[]; $out=cpu(['instance'=>'0'], $calls); ok(!$out['supported']); // Never turn a CPU-core instance into aggregate usage.
foreach ([['capability'=>'invalid'], ['rate'=>'0'], ['bad_unit'=>'mem'], ['wrong_response'=>'QueryAvailablePerfMetric'], ['wrong_response'=>'RetrieveProperties'], ['fail_stage'=>'QueryAvailablePerfMetric'], ['fault_stage'=>'QueryAvailablePerfMetric']] as $option) {
    $calls=[]; ok(fails(static fn() => cpu($option,$calls)));
}
$calls=[]; $out=cpu(['negative'=>true], $calls); ok($out['samples'][1]['cpu_percent'] === null);
echo "PHASE5G5_COLLECTOR_ASSERTIONS=$count\nPHASE5G5_CPU_CAPABILITY_AND_OPTIONAL_NIC_OK\n";
