<?php
/** Full generated providers with controlled transports; no vCenter or runtime state. */
$root = dirname(__DIR__);
$generated = $argv[1] ?? '';
if (!is_dir($generated)) throw new RuntimeException('Pass generated directory');
require_once $root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once $generated . '/class.IntegrationVmwareInventoryGuestNetwork.php';
require_once $generated . '/class.IntegrationVmwareInventoryPerformance.php';
$checks = 0;
function check($truth, string $label): void {
    global $checks; $checks++;
    if (!$truth) throw new RuntimeException('assertion:' . $label);
}
function failure(callable $fn, string $label): void {
    try { $fn(); } catch (Throwable $e) { check(true, $label); return; }
    throw new RuntimeException('exception_expected:' . $label);
}
function guestRead(array $rows, array &$calls, int $status = 200): array {
    $transport = static function ($method, $url, $headers, $user, $pass, $tls) use ($rows, &$calls, $status): array {
        $calls[] = $method; check($tls === true, 'guest_tls');
        if ($method === 'POST') return ['status'=>201, 'body'=>'"fixture-token"'];
        if ($method === 'DELETE') return ['status'=>204, 'body'=>''];
        check($method === 'GET' && str_ends_with($url, '/vm/vm-1/guest/networking/interfaces'), 'guest_scope');
        return ['status'=>$status, 'body'=>json_encode($rows, JSON_THROW_ON_ERROR)];
    };
    return (new IntegrationVmwareInventoryGuestNetwork($transport))->read_vm_interfaces('https://vc.example.test', 'fixture', 'not-a-secret', ['vm-1'], true);
}
$observed = ['mac_address'=>'00-50-56-AA-BB-CC', 'ip'=>['ip_addresses'=>[
    ['ip_address'=>'192.0.2.12', 'prefix_length'=>24], ['ip_address'=>'2001:db8::1', 'prefix_length'=>64]]]];
foreach ([[], ['nic'=>null], ['nic'=>'4000']] as $nic) {
    $calls=[]; $result=guestRead(array_merge([], [array_merge($observed, $nic)]), $calls);
    $row=$result['vms'][0]['interfaces'][0];
    check($calls === ['POST','GET','DELETE'], 'single_guest_session');
    check($row['nic_external_id'] === ($nic['nic'] ?? null), 'honest_nullable_nic');
    check($row['hardware_mapping'] === (isset($nic['nic']) ? 'reported' : 'unmapped'), 'mapping_provenance');
    check($row['mac_address'] === '00:50:56:aa:bb:cc' && count($row['ip_addresses'])===2, 'mac_ips_preserved');
    check($result['ip_count'] === 2 && $result['ip_observations'][0]['nic_external_id'] === ($nic['nic'] ?? null), 'flat_observation_preserved');
}
$calls=[]; $result=guestRead([$observed, $observed], $calls);
check($result['interface_count']===2 && $result['ip_count']===4, 'unmapped_rows_not_collapsed');
foreach (['', "4000\n", str_repeat('a',1025), ['bad'], true, 4000] as $nic) {
    $calls=[]; failure(static function () use ($observed, $nic, &$calls) { guestRead([array_merge($observed,['nic'=>$nic])],$calls); }, 'invalid_nic_still_rejected');
    check(end($calls)==='DELETE', 'cleanup_after_bad_nic');
}
foreach ([['mac_address'=>'invalid'], ['ip'=>['ip_addresses'=>[['ip_address'=>'not-an-ip']]]]] as $bad) {
    $calls=[]; failure(static function () use ($observed, $bad, &$calls) { guestRead([array_merge($observed,$bad)],$calls); }, 'invalid_guest_data_rejected');
}
$calls=[]; $result=guestRead([], $calls, 503);
check($result['vms'][0]['status']==='tools_unavailable', 'tools_state_retained');
$calls=[]; failure(static function () use (&$calls) { guestRead([], $calls,403); }, 'guest_denial_not_empty');
echo 'PHASE5G5_GUEST_ASSERTIONS=' . $checks . "\n";
if (($argv[2] ?? '') === '--guest-only') exit(0);
if (!class_exists('DOMDocument')) throw new RuntimeException('DOM is mandatory for full compatibility tests');

function envelope(string $xml): string {
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'.$xml.'</s:Body></s:Envelope>';
}
function cpuRead(array $options, array &$calls): array {
    $transport=static function ($method,$url,$headers,$body,$tls) use ($options,&$calls): array {
        preg_match('/<(RetrieveServiceContent|Login|Logout|QueryPerfProviderSummary|RetrieveProperties|QueryAvailablePerfMetric|QueryPerf) xmlns=/', $body, $match);
        $op=$match[1] ?? 'unknown'; $calls[]=$op;
        check($method==='POST' && $url==='https://vc.example.test/sdk' && $tls===true, 'cpu_strict_transport');
        if (($options['fail_stage'] ?? '')===$op) return ['status'=>$options['status'] ?? 403, 'headers'=>[], 'body'=>envelope('<s:Fault><faultstring>NoPermission</faultstring></s:Fault>')];
        $outHeaders=[];
        switch ($op) {
            case 'RetrieveServiceContent': $xml='<returnval><sessionManager>sm</sessionManager><propertyCollector>pc</propertyCollector><perfManager>pm</perfManager></returnval>'; break;
            case 'Login': $xml='<returnval><key>fixture</key></returnval>'; $outHeaders=['Set-Cookie: vmware_soap_session="fixture"; Path=/; Secure']; break;
            case 'Logout': $xml=''; break;
            case 'QueryPerfProviderSummary': $xml='<returnval><currentSupported>'.($options['current'] ?? 'true').'</currentSupported><summarySupported>true</summarySupported><refreshRate>'.($options['rate'] ?? '20').'</refreshRate></returnval>'; break;
            case 'RetrieveProperties':
                $xml='<returnval><propSet><name>perfCounter</name><val>';
                foreach (['cpu'=>6, 'mem'=>24] as $group=>$id) {
                    if (($options['no_counter'] ?? '')===$group) continue;
                    $xml.='<PerfCounterInfo><key>'.$id.'</key><groupInfo><key>'.$group.'</key></groupInfo><nameInfo><key>usage</key></nameInfo><unitInfo><key>'.($options['unit'] ?? 'percent').'</key></unitInfo><rollupType>average</rollupType></PerfCounterInfo>';
                }
                $xml.='</val></propSet></returnval>'; break;
            case 'QueryAvailablePerfMetric':
                $xml='';
                foreach (($options['available'] ?? [6=>'',24=>'']) as $id=>$instance) $xml.='<returnval><counterId>'.$id.'</counterId><instance>'.$instance.'</instance></returnval>';
                if (!empty($options['wrong_response'])) return ['status'=>200,'headers'=>[],'body'=>envelope('<OtherResponse xmlns="urn:vim25"/>')];
                if (!empty($options['no_instance'])) $xml='<returnval><counterId>6</counterId></returnval>';
                if (!empty($options['invalid_xml'])) return ['status'=>200,'headers'=>[],'body'=>'<invalid'];
                if (!empty($options['dtd'])) return ['status'=>200,'headers'=>[],'body'=>'<!DOCTYPE x>'.envelope('<QueryAvailablePerfMetricResponse xmlns="urn:vim25"/>')];
                break;
            case 'QueryPerf':
                if (!empty($options['empty_samples'])) { $xml=''; break; }
                $xml='<returnval><sampleInfo><timestamp>2026-09-14T10:00:00Z</timestamp><interval>20</interval></sampleInfo><sampleInfo><timestamp>2026-09-14T10:00:20Z</timestamp><interval>20</interval></sampleInfo>';
                foreach ([6=>[1000,2000],24=>[3000,4000]] as $id=>$values) {
                    if (!str_contains($body,'<counterId>'.$id.'</counterId>') || ($options['omit_series'] ?? null)===$id) continue;
                    $xml.='<value><id><counterId>'.$id.'</counterId><instance/></id><value>'.$values[0].'</value><value>'.$values[1].'</value></value>';
                }
                $xml.='</returnval>'; break;
            default: throw new RuntimeException('unexpected_operation');
        }
        return ['status'=>200,'headers'=>$outHeaders,'body'=>envelope('<'.$op.'Response xmlns="urn:vim25">'.$xml.'</'.$op.'Response>')];
    };
    return (new IntegrationVmwareInventoryPerformance($transport))->read_realtime_percent_series('https://vc.example.test','fixture','not-a-secret','vm','vm-1',60,true);
}
$calls=[]; $r=cpuRead([], $calls);
check(count($calls)===7 && $r['supported']===true && $r['reason']===null, 'normal_stats_retained');
check($r['samples'][0]['cpu_percent']===10.0 && $r['samples'][1]['memory_percent']===40.0, 'normal_percent_values');
foreach ([[], [6=>'0']] as $available) {
    $calls=[]; $r=cpuRead(['available'=>$available], $calls);
    check($r['supported']===false && $r['reason']==='aggregate_cpu_memory_unavailable', 'confirmed_aggregate_absence');
    check($r['samples']===[] && count($r['unavailable_counters'])===2 && !in_array('QueryPerf',$calls,true), 'no_fabricated_zero_or_device_sum');
    check(count($calls)===6 && end($calls)==='Logout', 'absence_cleanup');
}
foreach ([[24=>''], [6=>'0',24=>'']] as $available) {
    $calls=[]; $r=cpuRead(['available'=>$available], $calls);
    check($r['supported']===true && $r['samples'][0]['cpu_percent']===null && $r['samples'][0]['memory_percent']===30.0, 'keep_available_memory');
    check(array_keys($r['unavailable_counters'])===['cpu.usage.average'], 'per_counter_availability');
}
$calls=[]; $r=cpuRead(['available'=>[6=>'']],$calls);
check($r['samples'][0]['cpu_percent']===10.0 && $r['samples'][0]['memory_percent']===null, 'keep_available_cpu');
$calls=[]; $r=cpuRead(['current'=>'false'],$calls);
check($r['reason']==='provider_not_realtime' && count($calls)===4 && $r['samples']===[], 'provider_absence_is_explicit');
$calls=[]; $r=cpuRead(['empty_samples'=>true],$calls);
check($r['reason']==='insufficient_samples' && $r['unavailable_counters']===[], 'empty_series_not_capability_absence');
foreach ([['current'=>'invalid'], ['rate'=>'-1'], ['no_counter'=>'cpu'], ['unit'=>'bytes'], ['wrong_response'=>true], ['invalid_xml'=>true], ['dtd'=>true], ['no_instance'=>true], ['available'=>[-1=>'']], ['omit_series'=>6], ['fail_stage'=>'QueryAvailablePerfMetric'], ['fail_stage'=>'QueryAvailablePerfMetric','status'=>200], ['fail_stage'=>'QueryPerf','status'=>500]] as $options) {
    $calls=[]; failure(static function () use ($options, &$calls) { cpuRead($options,$calls); }, 'real_failure_is_not_unsupported');
    check(end($calls)==='Logout', 'failure_cleanup_retained');
}
echo 'PHASE5G5_COMPAT_ASSERTIONS=' . $checks . "\nPHASE5G5_GENERATED_PROVIDER_TEST_OK\n";
