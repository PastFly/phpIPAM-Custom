<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require __DIR__.'/control-memory-repository.php';

$n=0;
function ok3(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }
function rejects3(callable $fn,string $code): void {
    try { $fn(); } catch (DomainException $e) { ok3($e->getMessage()===$code,'reject '.$code); return; }
    throw new RuntimeException('expected '.$code);
}

$contexts=[];
$resolver=static function(string $id) use (&$contexts): ?array { return $contexts[$id]??null; };
$clock=1700000000;
$repo=new Dns3MemoryRepository();
$svc=new IntegrationDnsCollectorControlService($repo,$resolver,static function() use (&$clock): int { return $clock; });

$registered=$svc->registerCollector(['name'=>'Windows collector 01'],17);
ok3(preg_match('/^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$/D',$registered['control_token'])===1,'token shape');
ok3($registered['collector']['revision']===1 && $registered['collector']['control_epoch']===1,'collector versions');
ok3($registered['collector']['revoked']===false,'collector active');
$id=$registered['collector']['id']; $token=$registered['control_token'];
ok3(!str_contains(json_encode($repo->collectors),$token),'raw token not stored');
ok3(isset($repo->collectors[$id]['token_digest']) && preg_match('/^[0-9a-f]{64}$/D',$repo->collectors[$id]['token_digest'])===1,'digest stored');
ok3(!str_contains(json_encode($svc->snapshot()),$repo->collectors[$id]['token_digest']),'digest redacted from snapshot');
ok3($svc->authenticateCollectorToken($token)['id']===$id,'valid token authenticates');
$wrongToken=substr($token,0,-1).(substr($token,-1)==='x'?'y':'x');
rejects3(fn()=> $svc->authenticateCollectorToken($wrongToken),'collector_auth_failed');
rejects3(fn()=> $svc->registerCollector(['name'=>''],17),'name_invalid');
rejects3(fn()=> $svc->registerCollector(['name'=>'x','token'=>'bad'],17),'fields_invalid');

$connectionId=str_repeat('c',32);
$profileId=str_repeat('d',32);
$contexts[$connectionId]=[
    'id'=>$connectionId,'revision'=>5,'binding_epoch'=>7,'credential_mode'=>'profile',
    'profile_id'=>$profileId,'credential_version'=>3,'enabled'=>false
];
$assignment=$svc->saveAssignment([
    'connection_id'=>$connectionId,'collector_id'=>$id,'expected_revision'=>0
],17);
ok3($assignment['revision']===1 && $assignment['collector_id']===$id,'assignment created');
rejects3(fn()=> $svc->saveAssignment([
    'connection_id'=>$connectionId,'collector_id'=>$id,'expected_revision'=>0
],17),'revision_conflict');
rejects3(fn()=> $svc->saveAssignment([
    'connection_id'=>str_repeat('e',32),'collector_id'=>$id,'expected_revision'=>0
],17),'connection_unavailable');

$testJob=$svc->queueJob($connectionId,'test_connection',17);
ok3($testJob['job_type']==='test_connection' && $testJob['state']==='pending','test job queued');
ok3($testJob['configuration_revision']===5 && $testJob['binding_epoch']===7 && $testJob['credential_version']===3,'job binds configuration');
ok3($testJob['expires_at']===1700000300,'job expiry bounded');
rejects3(fn()=> $svc->queueJob($connectionId,'test_connection',17),'job_already_pending');
$repo->jobs[$testJob['id']]['state']='running';
rejects3(fn()=> $svc->queueJob($connectionId,'test_connection',17),'job_already_pending');
$repo->jobs[$testJob['id']]['state']='pending';
$listJob=$svc->queueJob($connectionId,'list_zones',17);
ok3($listJob['job_type']==='list_zones','list-zones job queued');
rejects3(fn()=> $svc->queueJob($connectionId,'collect_selected_zones',17),'job_type_invalid');
rejects3(fn()=> $svc->queueJob($connectionId,'powershell -enc xxx',17),'job_type_invalid');

$snapshot=json_encode($svc->snapshot(),JSON_THROW_ON_ERROR);
ok3(!str_contains($snapshot,$token) && !str_contains($snapshot,$repo->collectors[$id]['token_digest']),'snapshot contains no machine secret');
ok3(!str_contains($snapshot,$profileId),'jobs do not expose profile id');

$before=serialize([$repo->collectors,$repo->assignments,$repo->jobs,$repo->events]);
$repo->failAt=2;
try { $svc->revokeCollector($id,1,17); throw new RuntimeException('injected failure absent'); }
catch (RuntimeException $e) { ok3($e->getMessage()==='injected_write_failure','revoke failure injected'); }
ok3($before===serialize([$repo->collectors,$repo->assignments,$repo->jobs,$repo->events]),'revoke rollback atomic');

$repo->failAt=null;
$svc->revokeCollector($id,1,17);
ok3($repo->collectors[$id]['revoked']===true && $repo->collectors[$id]['revision']===2 && $repo->collectors[$id]['control_epoch']===2,'collector revoked');
ok3(!isset($repo->assignments[$connectionId]),'assignment removed on revoke');
ok3($repo->jobs[$testJob['id']]['state']==='obsolete' && $repo->jobs[$listJob['id']]['state']==='obsolete','jobs obsolete on revoke');
rejects3(fn()=> $svc->authenticateCollectorToken($token),'collector_auth_failed');
rejects3(fn()=> $svc->queueJob($connectionId,'list_zones',17),'assignment_unavailable');

foreach($repo->events as $event) {
    ok3(!isset($event['token']) && !isset($event['token_digest']) && !str_contains(json_encode($event),$token),'audit redacted');
}

printf("DNS3_CONTROL_ASSERTIONS=%d\nDNS3_CONTROL_TESTS_OK=1\n",$n);
