<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsCipher.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require $root.'/layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php';
require $root.'/layers/dns6/functions/classes/class.IntegrationDnsCredentialDeliveryService.php';
require $root.'/tests/dns4/machine-memory-repository.php';

$n=0;
function ok6(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }
function reject6(callable $fn,string $code): void {
    try { $fn(); }
    catch (DomainException $e) { ok6($e->getMessage()===$code,'reject '.$code); return; }
    throw new RuntimeException('expected '.$code);
}

$clock=1700000000;
$contexts=[];
$resolver=static function(string $id) use (&$contexts): ?array {
    $c=$contexts[$id]??null;
    if($c===null) return null;
    return array_intersect_key($c,array_flip(['id','server','revision','binding_epoch','credential_mode','credential_version']));
};
$secureResolver=static function(string $id) use (&$contexts): ?array { return $contexts[$id]??null; };

$repo=new Dns4MemoryRepository();
$admin=new IntegrationDnsCollectorControlService($repo,$resolver,static function() use (&$clock): int { return $clock; });
$machine=new IntegrationDnsCollectorMachineService($repo,$resolver,static function() use (&$clock): int { return $clock; });

$key=random_bytes(32);
$cipher=new IntegrationDnsCipher('fixture',['fixture'=>$key]);
$decryptCalls=0;
$decryptor=static function(array $ctx) use ($cipher,&$decryptCalls): string {
    ++$decryptCalls;
    return $cipher->open($ctx['secret_envelope'],$ctx['profile_id'],$ctx['username'],$ctx['secret_version']);
};
$delivery=new IntegrationDnsCredentialDeliveryService(
    $repo,$secureResolver,$decryptor,static function() use (&$clock): int { return $clock; }
);

$registration=$admin->registerCollector(['name'=>'Collector 01'],17);
$collectorId=$registration['collector']['id'];
$token=$registration['control_token'];
$connectionId=str_repeat('c',32);
$profileId=str_repeat('d',32);
$password='Fictional DNS password';
$username='RBank\\svc_phpipam_dns';
$envelope=$cipher->seal($password,$profileId,$username,3);

$contexts[$connectionId]=[
    'id'=>$connectionId,'server'=>'dc01.example.invalid','revision'=>5,'binding_epoch'=>7,
    'credential_mode'=>'profile','credential_version'=>3,
    'profile_id'=>$profileId,'username'=>$username,'secret_version'=>3,'secret_envelope'=>$envelope
];

$assignment=$admin->saveAssignment(['connection_id'=>$connectionId,'collector_id'=>$collectorId,'expected_revision'=>0],17);
$job=$admin->queueJob($connectionId,'test_connection',17);
$lease=$machine->poll($token);
ok6($lease['id']===$job['id'],'job leased');

reject6(fn()=> $delivery->deliver('bad',$job['id'],$lease['lease_id']),'collector_auth_failed');
ok6($decryptCalls===0,'bad token cannot reach decryptor');
reject6(fn()=> $delivery->deliver($token,$job['id'],str_repeat('f',32)),'lease_invalid');
ok6($decryptCalls===0,'bad lease cannot reach decryptor');

$cred=$delivery->deliver($token,$job['id'],$lease['lease_id']);
ok6($cred['credential_mode']==='profile','profile mode returned');
ok6($cred['username']===$username && $cred['password']===$password,'exact credential delivered');
ok6(!isset($cred['profile_id']) && !isset($cred['secret_envelope']),'internal selector/envelope hidden');
ok6($decryptCalls===1,'decrypt exactly once');
ok6($repo->jobs[$job['id']]['credential_delivered_at']===$clock,'one-shot marker persisted');
$json=json_encode($repo->jobs[$job['id']],JSON_THROW_ON_ERROR);
ok6(!str_contains($json,$password) && !str_contains($json,$envelope) && !str_contains($json,$profileId),'job stores no credential material/selectors');
reject6(fn()=> $delivery->deliver($token,$job['id'],$lease['lease_id']),'credential_already_delivered');
ok6($decryptCalls===1,'repeat cannot decrypt again');

$clock+=1;
$job2=$admin->queueJob($connectionId,'list_zones',17);
$lease2=$machine->poll($token);
$contexts[$connectionId]['secret_version']=4;
$contexts[$connectionId]['credential_version']=4;
$contexts[$connectionId]['secret_envelope']=$cipher->seal('Rotated fictional password',$profileId,$username,4);
reject6(fn()=> $delivery->deliver($token,$job2['id'],$lease2['lease_id']),'job_obsolete');
ok6($decryptCalls===1,'rotated secret blocks old job before decrypt');
ok6($repo->jobs[$job2['id']]['state']==='obsolete','stale credential version obsoletes job');

$contexts[$connectionId]['revision']=6;
$job3=$admin->queueJob($connectionId,'test_connection',17);
$lease3=$machine->poll($token);
$repo->assignments[$connectionId]['revision']=2;
reject6(fn()=> $delivery->deliver($token,$job3['id'],$lease3['lease_id']),'assignment_changed');
ok6($decryptCalls===1,'assignment change blocks decrypt');

$contexts[$connectionId]['revision']=7;
$contexts[$connectionId]['binding_epoch']=8;
$contexts[$connectionId]['credential_mode']='collector_identity';
$contexts[$connectionId]['credential_version']=null;
unset($contexts[$connectionId]['profile_id'],$contexts[$connectionId]['username'],$contexts[$connectionId]['secret_version'],$contexts[$connectionId]['secret_envelope']);
$repo->assignments[$connectionId]['revision']=3;
$job4=$admin->queueJob($connectionId,'test_connection',17);
$lease4=$machine->poll($token);
$identity=$delivery->deliver($token,$job4['id'],$lease4['lease_id']);
ok6($identity===['credential_mode'=>'collector_identity'],'collector identity returns no central secret');
ok6($decryptCalls===1,'collector identity never decrypts');
ok6($repo->jobs[$job4['id']]['credential_delivered_at']===$clock,'identity resolution also one-shot');

$clock+=121;
$job5=$admin->queueJob($connectionId,'list_zones',17);
$lease5=$machine->poll($token);
$clock+=121;
reject6(fn()=> $delivery->deliver($token,$job5['id'],$lease5['lease_id']),'job_expired');

foreach($repo->events as $event) {
    $eventJson=json_encode($event,JSON_THROW_ON_ERROR);
    ok6(!str_contains($eventJson,$password) && !str_contains($eventJson,$username),'audit contains no credential');
}

printf("DNS6_CREDENTIAL_DELIVERY_ASSERTIONS=%d\nDNS6_CREDENTIAL_DELIVERY_TESTS_OK=1\n",$n);
