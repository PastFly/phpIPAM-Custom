<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require_once $root.'/layers/dns1/functions/classes/class.IntegrationDnsCipher.php';
require_once $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php';
require_once $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigService.php';
require_once $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require_once $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require_once $root.'/layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php';
require_once $root.'/layers/dns1/functions/classes/class.IntegrationDnsAdminController.php';
require_once $root.'/tests/dns1/memory-repository.php';
require_once $root.'/tests/dns3/control-memory-repository.php';

$n=0;
function ok9a(bool $v,string $label): void {
    global $n;
    ++$n;
    if(!$v) throw new RuntimeException('ASSERT '.$label);
}
function reject9a(callable $fn,string $code): void {
    try{$fn();}catch(DomainException $e){ok9a($e->getMessage()===$code,'reject '.$code);return;}
    throw new RuntimeException('expected '.$code);
}

$key=random_bytes(32);
$cipher=new IntegrationDnsCipher('test-key',['test-key'=>$key]);
$configRepo=new DnsMemoryRepository();
$config=new IntegrationDnsConfigService($configRepo,$cipher);
$controlRepo=new Dns3MemoryRepository();

$resolver=static function(string $id) use($configRepo): ?array {
    return $configRepo->transaction(static function() use($configRepo,$id): ?array {
        $c=$configRepo->connection($id);
        if($c===null) return null;
        $credentialVersion=null;
        if($c['credential_mode']==='profile') {
            $p=$configRepo->profile($c['profile_id']);
            if($p===null||$p['secret_envelope']===null) return null;
            $credentialVersion=$p['secret_version'];
        }
        return [
            'id'=>$c['id'],
            'revision'=>$c['revision'],
            'binding_epoch'=>$c['binding_epoch'],
            'credential_mode'=>$c['credential_mode'],
            'profile_id'=>$c['profile_id'],
            'credential_version'=>$credentialVersion,
            'enabled'=>$c['enabled']
        ];
    });
};

$clock=1700000000;
$control=new IntegrationDnsCollectorControlService(
    $controlRepo,
    $resolver,
    static function() use(&$clock): int { return $clock; }
);
$service=new IntegrationDnsAutonomousAdminService($config,$configRepo,$control);

$profile=$service->saveProfile([
    'id'=>null,'expected_revision'=>0,'name'=>'DNS service','username'=>'svc@example.invalid',
    'password_action'=>'replace','password'=>'fictional password','acknowledge_connections'=>[]
],1);
$connection=$service->saveConnection([
    'id'=>null,'expected_revision'=>0,'name'=>'DNS source','server'=>'dc01.example.invalid',
    'interval_seconds'=>300,'credential_mode'=>'profile','profile_id'=>$profile['id'],'share_profile'=>false
],1);

reject9a(
    fn()=> $service->setCollectionEnabled($connection['id'],$connection['revision'],true,1),
    'assignment_unavailable'
);

$registered=$service->registerCollector('Collector 01',1);
$collectorId=$registered['collector']['id'];
ok9a(preg_match('/^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$/D',$registered['control_token'])===1,'one-time collector token returned');
ok9a(!str_contains(json_encode($service->snapshot(),JSON_THROW_ON_ERROR),$registered['control_token']),'collector token absent from snapshot');
$assignment=$service->saveCollectorAssignment($connection['id'],$collectorId,0,1);
ok9a($assignment['revision']===1,'assignment ready');

$state=$service->snapshot();
ok9a($state['collector_control_available']===true,'control available');
$runtime=$state['connection_runtime'][$connection['id']];
ok9a($runtime['assigned']===true && $runtime['collector_id']===$collectorId,'runtime maps collector');
ok9a($runtime['test_connection']===null && $runtime['list_zones']===null,'runtime initially empty');

$testJob=$service->queueControlJob($connection['id'],'test_connection',1);
ok9a($testJob['state']==='pending' && $testJob['job_type']==='test_connection','test job queued');

$state=$service->snapshot();
ok9a($state['connection_runtime'][$connection['id']]['test_connection']['id']===$testJob['id'],'latest test visible');
reject9a(fn()=> $service->queueControlJob($connection['id'],'test_connection',1),'job_already_pending');

$enabled=$service->setCollectionEnabled($connection['id'],$connection['revision'],true,1);
ok9a($enabled['enabled']===true && $enabled['status']==='active','collection enabled');
ok9a($enabled['revision']===$connection['revision']+1 && $enabled['binding_epoch']===$connection['binding_epoch']+1,'enable invalidates stale jobs');
ok9a($configRepo->connections[$connection['id']]['enabled']===true,'enabled persisted');

$disabled=$service->setCollectionEnabled($connection['id'],$enabled['revision'],false,1);
ok9a($disabled['enabled']===false && $disabled['status']==='disabled','collection disabled');
ok9a($disabled['revision']===$enabled['revision']+1 && $disabled['binding_epoch']===$enabled['binding_epoch']+1,'disable invalidates stale jobs');

$enabled=$service->setCollectionEnabled($connection['id'],$disabled['revision'],true,1);
$rotate=[
    'id'=>$profile['id'],'expected_revision'=>$profile['revision'],'name'=>$profile['name'],
    'username'=>$profile['username'],'password_action'=>'replace','password'=>'new fictional password',
    'acknowledge_connections'=>[$connection['id']]
];
$rotated=$service->saveProfile($rotate,1);
ok9a($rotated['secret_version']===2,'profile rotated');
$after=$configRepo->connections[$connection['id']];
ok9a($after['enabled']===false && $after['status']==='needs_validation','profile rotation disables automation');
ok9a($after['revision']===$connection['revision']+4 && $after['binding_epoch']===$connection['binding_epoch']+4,'profile rotation invalidates current operational epoch');

$reEnabled=$service->setCollectionEnabled($connection['id'],$after['revision'],true,1);
ok9a($configRepo->connections[$connection['id']]['enabled']===true,'re-enabled before collector revoke');
reject9a(fn()=> $service->revokeCollector($collectorId,99,1),'revision_conflict');
ok9a($configRepo->connections[$connection['id']]['enabled']===true,'stale collector revoke preserves connection state');
$service->revokeCollector($collectorId,1,1);
ok9a($configRepo->connections[$connection['id']]['enabled']===false,'collector revoke disables connection');
ok9a($configRepo->connections[$connection['id']]['status']==='collector_revoked','collector revoke status recorded');
$revokedConnection=$configRepo->connections[$connection['id']];
ok9a($revokedConnection['revision']===$reEnabled['revision']+1 && $revokedConnection['binding_epoch']===$reEnabled['binding_epoch']+1,'collector revoke invalidates active epoch');
reject9a(
    fn()=> $service->setCollectionEnabled($connection['id'],$after['revision'],true,1),
    'assignment_unavailable'
);

foreach($configRepo->events as $event) {
    ok9a(!isset($event['password'])&&!isset($event['token']),'config audit redacted');
}
foreach($controlRepo->events as $event) {
    ok9a(!isset($event['token'])&&!isset($event['token_digest']),'control audit redacted');
}

$controller=new IntegrationDnsAdminController(static fn()=> $service);
$req=static fn(array $input): array => [
    'method'=>'POST','authenticated'=>true,'admin'=>true,'https'=>true,'csrf_valid'=>true,
    'actor_id'=>1,'input'=>$input
];

$out=$controller->handle($req(['action'=>'register_collector','data'=>['name'=>'Collector 02']]));
ok9a($out['status']===200 && preg_match('/^dnsctl1\./',$out['body']['data']['control_token'])===1,'controller register collector');

$out=$controller->handle($req(['action'=>'save_assignment','data'=>[
    'connection_id'=>$connection['id'],
    'collector_id'=>$out['body']['data']['collector']['id'],
    'expected_revision'=>0
]]));
ok9a($out['status']===200,'controller assignment action');

$out=$controller->handle($req(['action'=>'queue_test_connection','data'=>['id'=>$connection['id']]]));
ok9a(in_array($out['status'],[200,409],true),'controller queue test bounded');

$out=$controller->handle($req(['action'=>'set_collection_enabled','data'=>[
    'id'=>$connection['id'],'expected_revision'=>$revokedConnection['revision'],'enabled'=>false
]]));
ok9a($out['status']===200,'controller collection toggle');

printf("DNS9_AUTONOMOUS_ADMIN_ASSERTIONS=%d\nDNS9_AUTONOMOUS_ADMIN_TESTS_OK=1\n",$n);
