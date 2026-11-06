<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require $root.'/layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineRepository.php';
require $root.'/layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php';
require __DIR__.'/machine-memory-repository.php';

$n=0;
function ok4(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }
function reject4(callable $fn,string $code): void {
    try { $fn(); }
    catch (DomainException $e) { ok4($e->getMessage()===$code,'reject '.$code); return; }
    throw new RuntimeException('expected '.$code);
}

$clock=1700000000;
$contexts=[];
$resolver=static function(string $id) use (&$contexts): ?array { return $contexts[$id]??null; };
$repo=new Dns4MemoryRepository();
$admin=new IntegrationDnsCollectorControlService($repo,$resolver,static function() use (&$clock): int { return $clock; });
$machine=new IntegrationDnsCollectorMachineService($repo,$resolver,static function() use (&$clock): int { return $clock; });

$registration=$admin->registerCollector(['name'=>'Collector 01'],17);
$collectorId=$registration['collector']['id'];
$token=$registration['control_token'];
$connectionId=str_repeat('c',32);
$contexts[$connectionId]=[
    'id'=>$connectionId,
    'server'=>'dc01.example.invalid',
    'revision'=>5,
    'binding_epoch'=>7,
    'credential_mode'=>'profile',
    'credential_version'=>3
];

$assignment=$admin->saveAssignment([
    'connection_id'=>$connectionId,
    'collector_id'=>$collectorId,
    'expected_revision'=>0
],17);
ok4($assignment['revision']===1,'assignment revision one');

$queued=$admin->queueJob($connectionId,'test_connection',17);
ok4($queued['assignment_revision']===1,'job binds assignment revision');
reject4(fn()=> $machine->poll('bad'),'collector_auth_failed');

$work=$machine->poll($token);
ok4(is_array($work) && $work['id']===$queued['id'],'poll leases queued job');
ok4($work['job_type']==='test_connection' && $work['server']==='dc01.example.invalid','poll returns typed source metadata');
ok4($work['configuration_revision']===5 && $work['binding_epoch']===7 && $work['credential_version']===3,'poll preserves config versions');
ok4($work['assignment_revision']===1,'poll preserves assignment revision');
ok4(preg_match('/^[0-9a-f]{32}$/D',$work['lease_id'])===1 && $work['lease_expires_at']===$clock+120,'bounded lease');
ok4($repo->jobs[$queued['id']]['state']==='running' && $repo->jobs[$queued['id']]['attempt_count']===1,'stored running lease');
ok4($machine->poll($token)===null,'second poll does not double lease');
$serialized=json_encode($work,JSON_THROW_ON_ERROR);
ok4(!str_contains($serialized,'profile_id') && !str_contains($serialized,'password') && !str_contains($serialized,'token_digest'),'poll contains no secret selector/value');

reject4(fn()=> $machine->submitResult($token,$queued['id'],str_repeat('f',32),['ok'=>true,'error_code'=>null]),'lease_invalid');

$contexts[$connectionId]['revision']=6;
reject4(fn()=> $machine->submitResult($token,$queued['id'],$work['lease_id'],['ok'=>true,'error_code'=>null]),'job_obsolete');
ok4($repo->jobs[$queued['id']]['state']==='obsolete','stale config result marks obsolete');

$new=$admin->queueJob($connectionId,'list_zones',17);
ok4($new['configuration_revision']===6,'new job binds updated config revision');
$work=$machine->poll($token);
ok4($work['id']===$new['id'],'updated job leased');

$bad=['ok'=>true,'error_code'=>null,'zones'=>[['name'=>'example.invalid','zone_type'=>'Primary','ds_integrated'=>true,'reverse_lookup'=>false,'unexpected'=>'x']]];
reject4(fn()=> $machine->submitResult($token,$new['id'],$work['lease_id'],$bad),'result_invalid');

$result=$machine->submitResult($token,$new['id'],$work['lease_id'],[
    'ok'=>true,
    'error_code'=>null,
    'zones'=>[
        ['name'=>'example.invalid','zone_type'=>'Primary','ds_integrated'=>true,'reverse_lookup'=>false],
        ['name'=>'30.20.10.in-addr.arpa','zone_type'=>'Primary','ds_integrated'=>true,'reverse_lookup'=>true]
    ]
]);
ok4($result['state']==='succeeded' && $result['result_code']==='ok','zone result accepted');
ok4($repo->jobs[$new['id']]['finished_at']===$clock,'finished timestamp stored');
$payload=json_decode($repo->jobs[$new['id']]['result_payload'],true,16,JSON_THROW_ON_ERROR);
ok4(count($payload['zones'])===2 && $payload['zones'][1]['reverse_lookup']===true,'sanitized zone payload stored');
reject4(fn()=> $machine->submitResult($token,$new['id'],$work['lease_id'],[
    'ok'=>true,'error_code'=>null,'zones'=>[]
]),'job_not_running');

$test=$admin->queueJob($connectionId,'test_connection',17);
$lease=$machine->poll($token);
$clock+=121;
ok4($machine->poll($token)===null,'expired running lease is not reissued automatically');
ok4($repo->jobs[$test['id']]['state']==='expired','expired lease becomes terminal');

$retry=$admin->queueJob($connectionId,'test_connection',17);
ok4($retry['id']!==$test['id'],'explicit admin retry allowed after expiry');
$lease=$machine->poll($token);
$fail=$machine->submitResult($token,$retry['id'],$lease['lease_id'],['ok'=>false,'error_code'=>'authentication']);
ok4($fail['state']==='failed' && $fail['result_code']==='authentication','sanitized failure stored');
reject4(fn()=> $machine->submitResult($token,$retry['id'],$lease['lease_id'],['ok'=>false,'error_code'=>'raw exception password=secret']),'job_not_running');

$clock+=90000;
$oldCount=count($repo->jobs);
$machine->poll($token);
ok4(count($repo->jobs)<$oldCount,'old terminal jobs pruned during poll');

$future=$admin->queueJob($connectionId,'test_connection',17);
$repo->jobs[$future['id']]['job_type']='collect_selected_zones';
$repo->jobs[$future['id']]['created_at']=$clock-1;
$known=$admin->queueJob($connectionId,'list_zones',17);
$knownWork=$machine->poll($token);
ok4(is_array($knownWork) && $knownWork['id']===$known['id'],'old poll skips unsupported type and leases supported work');
ok4($repo->jobs[$future['id']]['state']==='pending' && $repo->jobs[$future['id']]['attempt_count']===0,'unsupported job is not leased');
$machine->submitResult($token,$known['id'],$knownWork['lease_id'],['ok'=>true,'error_code'=>null,'zones'=>[]]);
$futureLease=str_repeat('e',32);
$repo->jobs[$future['id']]['state']='running';$repo->jobs[$future['id']]['attempt_count']=1;
$repo->jobs[$future['id']]['lease_id']=$futureLease;$repo->jobs[$future['id']]['lease_expires_at']=$clock+120;
reject4(fn()=> $machine->submitResult($token,$future['id'],$futureLease,['ok'=>true,'error_code'=>null,'zones'=>[]]),'result_invalid');
ok4($repo->jobs[$future['id']]['state']==='running','old result handler cannot complete unsupported job');
$repo->assignments[$connectionId]['revision']=2;
reject4(fn()=> $machine->submitResult($token,$future['id'],$futureLease,['ok'=>true,'error_code'=>null,'zones'=>[]]),'result_invalid');
ok4($repo->jobs[$future['id']]['state']==='running','old result handler does not obsolete unsupported job');
$repo->assignments[$connectionId]['revision']=1;
$repo->jobs[$future['id']]['state']='pending';$repo->jobs[$future['id']]['lease_id']=null;
$repo->jobs[$future['id']]['lease_expires_at']=null;$repo->jobs[$future['id']]['expires_at']=$clock-1;
ok4($machine->poll($token)===null && $repo->jobs[$future['id']]['state']==='pending','old poll does not expire unsupported jobs');
unset($repo->jobs[$future['id']]);

$contexts[$connectionId]['revision']=7;
$job=$admin->queueJob($connectionId,'list_zones',17);
$repo->assignments[$connectionId]['revision']=2;
reject4(fn()=> $machine->poll($token),'assignment_changed');
ok4($repo->jobs[$job['id']]['state']==='obsolete','assignment revision mismatch obsoletes job');

$admin->revokeCollector($collectorId,1,17);
reject4(fn()=> $machine->poll($token),'collector_auth_failed');

foreach($repo->jobs as $row) {
    $json=json_encode($row,JSON_THROW_ON_ERROR);
    ok4(!str_contains($json,'profile_id') && !str_contains($json,'password'),'stored machine jobs contain no domain secret selector/value');
}

printf("DNS4_MACHINE_ASSERTIONS=%d\nDNS4_MACHINE_TESTS_OK=1\n",$n);
