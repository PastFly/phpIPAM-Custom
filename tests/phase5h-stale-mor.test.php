<?php
require_once __DIR__ . '/../layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php';
require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatchInventory.php';
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
if (!class_exists('DOMDocument')) throw new RuntimeException('DOM_REQUIRED_NO_SKIP');

$n = 0;
function morok(bool $ok, string $label): void { global $n; ++$n; if (!$ok) throw new RuntimeException('assert_' . $label); }
function morenv(string $body): string { return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><s:Body>' . $body . '</s:Body></s:Envelope>'; }
function morreply(string $op, string $body): string { return morenv('<' . $op . 'Response xmlns="urn:vim25">' . $body . '</' . $op . 'Response>'); }
function morfield(string $name, string $value, string $attrs = ''): string { return '<propSet><name>' . $name . '</name><val' . $attrs . '>' . $value . '</val></propSet>'; }
function morrow(string $id): string {
    $f = morfield('name', 'Example') . morfield('runtime.powerState', 'poweredOn') .
        morfield('runtime.host', 'host-1', ' type="HostSystem"') . morfield('config.hardware.numCPU', '4') .
        morfield('config.hardware.memoryMB', '8192') . morfield('config.annotation', 'note') .
        morfield('guest.hostName', 'example.test') . morfield('guest.ipAddress', '192.0.2.8') .
        morfield('guest.toolsRunningStatus', 'guestToolsRunning');
    return '<returnval><obj type="VirtualMachine">' . $id . '</obj>' . $f . '</returnval>';
}

$calls = [];
$transport = static function ($method, $url, $headers, $body, $tls, $connect, $timeout) use (&$calls): array {
    morok($method === 'POST' && $url === 'https://vc.example.test/sdk' && $tls === true && $connect <= $timeout, 'transport_scope');
    $doc = new DOMDocument(); $doc->loadXML($body); $xp = new DOMXPath($doc);
    $op = $xp->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*')->item(0)->localName;
    $calls[] = $op; $headersOut = [];
    if ($op === 'RetrieveServiceContent') {
        $payload = '<returnval><sessionManager type="SessionManager">SessionManager</sessionManager><propertyCollector type="PropertyCollector">propertyCollector</propertyCollector><perfManager type="PerformanceManager">PerfMgr</perfManager></returnval>';
        return ['status'=>200,'headers'=>[],'body'=>morreply($op,$payload)];
    }
    if ($op === 'Login') {
        $headersOut = ['Set-Cookie: vmware_soap_session="fixture-session"; Secure; HttpOnly'];
        return ['status'=>200,'headers'=>$headersOut,'body'=>morreply($op,'<returnval/>')];
    }
    if ($op === 'Logout') return ['status'=>200,'headers'=>[],'body'=>morreply($op,'')];
    if ($op !== 'RetrieveProperties') throw new RuntimeException('unexpected_operation');

    $ids = [];
    foreach ($xp->query('//*[local-name()="objectSet"]/*[local-name()="obj"]') as $obj) $ids[] = $obj->textContent;
    if (in_array('vm-2', $ids, true)) {
        $fault = '<s:Fault><detail><ManagedObjectNotFoundFault xmlns="urn:vim25"/></detail></s:Fault>';
        return ['status'=>500,'headers'=>[],'body'=>morenv($fault)];
    }
    $payload = '';
    foreach ($ids as $id) $payload .= morrow($id);
    return ['status'=>200,'headers'=>[],'body'=>morreply($op,$payload)];
};

$runtime = ['endpoint'=>'https://vc.example.test','username'=>'fixture','password'=>'NOT_A_REAL_PASSWORD','verify_tls'=>true];
$entities = [
    ['kind'=>'vm','id'=>'vm-1'], ['kind'=>'vm','id'=>'vm-2'], ['kind'=>'vm','id'=>'vm-3']
];
$session = new IntegrationVmwareBatchSession($runtime, static fn(): bool => true, $transport, 32, 30);
$out = $session->run(static fn(IntegrationVmwareBatchSession $s): array => (new IntegrationVmwareBatchInventory($s, 3))->read($entities));
$stats = $session->statistics();

morok($out['objects']['vm-1']['state'] === 'returned', 'vm1_returned');
morok($out['objects']['vm-2']['state'] === 'unobserved', 'vm2_unobserved');
morok($out['objects']['vm-3']['state'] === 'returned', 'vm3_returned');
morok($out['object_coverage_complete'] === false && $out['all_requested_fields_available'] === false, 'coverage_not_authoritative');
morok(($stats['operations']['RetrieveProperties'] ?? 0) === 5, 'bounded_binary_isolation');
morok($stats['requests'] === 8 && end($calls) === 'Logout', 'session_survives_object_fault');

$now = time();
$previous = ['schema'=>1,'domain'=>'properties','published_at'=>$now-60,'authoritative_membership'=>false,'objects'=>[
    'vm-2'=>['kind'=>'vm','id'=>'vm-2','object_state'=>'fresh','last_attempt_at'=>$now-60,'last_attempt_result'=>'returned','fields'=>[
        'name'=>['state'=>'fresh','value'=>'old-name','observed_at'=>null,'collected_at'=>$now-60,'last_attempt_at'=>$now-60,'error'=>null]
    ]]
]];
$merged = IntegrationVmwareFastDomain::mergeProperties($previous, $out, $now);
morok($merged['objects']['vm-2']['object_state'] === 'stale', 'stale_not_deleted');
morok($merged['objects']['vm-2']['fields']['name']['value'] === 'old-name', 'last_good_preserved');
morok($merged['objects']['vm-2']['last_attempt_result'] === 'unobserved', 'attempt_recorded');

echo 'PHASE5H_STALE_MOR_ASSERTIONS=' . $n . "\n";
echo "PHASE5H_STALE_MOR_ISOLATION_OK\n";
