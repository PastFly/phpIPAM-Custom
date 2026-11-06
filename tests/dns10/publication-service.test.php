<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$interface=$root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationRepository.php';
$servicePath=$root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationService.php';
if (!is_file($interface) || !is_file($servicePath)) throw new RuntimeException('publication_service_missing');
foreach ([
    $interface,$root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneUploadToken.php',
    $root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSnapshotValidator.php',
    $root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationPolicy.php',
    $servicePath,$root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneUploadHttpController.php',
    $root.'/tests/dns10/publication-memory-repository.php'
] as $path) require $path;
$n=0;
function serviceCheck10(bool $ok,string $label): void {global $n;++$n;if(!$ok)throw new RuntimeException($label);}
function serviceReject10(callable $fn,string $code): void {
    try {$fn();} catch(DomainException $e) {serviceCheck10($e->getMessage()===$code,'expected '.$code);return;}
    throw new RuntimeException('not_rejected: '.$code);
}
function serviceFixture10(): array {
    $repo=new Dns10PublicationMemoryRepository();$issued=IntegrationDnsZoneUploadToken::issue();
    $source=str_repeat('a',32);$collector=str_repeat('b',32);$dataset=str_repeat('c',32);
    $job=str_repeat('d',32);$lease=str_repeat('e',32);$now=2000000000;
    $repo->tokens[$issued['token_id']]=['id'=>$issued['token_id'],'token_digest'=>$issued['token_digest'],
        'connection_id'=>$source,'collector_id'=>$collector,'assignment_revision'=>6,'revoked'=>false];
    $repo->sources[$source]=['id'=>$source,'enabled'=>true,'revision'=>4,'binding_epoch'=>7,'credential_version'=>null];
    $repo->collectors[$collector]=['id'=>$collector,'revoked'=>false];
    $repo->assignments[$source]=['connection_id'=>$source,'collector_id'=>$collector,'revision'=>6];
    $repo->datasets[$dataset]=['id'=>$dataset,'connection_id'=>$source,'zone'=>'example.test.','selected'=>true,'revision'=>3];
    $repo->jobs[$job]=['id'=>$job,'connection_id'=>$source,'collector_id'=>$collector,'dataset_id'=>$dataset,
        'job_type'=>'collect_selected_zones','state'=>'running','attempt_count'=>1,'lease_id'=>$lease,
        'lease_expires_at'=>$now+120,'expires_at'=>$now+300,'credential_delivered_at'=>$now,
        'dataset_revision'=>3,'configuration_revision'=>4,'binding_epoch'=>7,'assignment_revision'=>6,'credential_version'=>null];
    $doc=['schema_version'=>1,'source_id'=>$source,'dataset_id'=>$dataset,'collector_id'=>$collector,'job_id'=>$job,'lease_id'=>$lease,
        'zone'=>'example.test.','virtualization_instance'=>'default','zone_scope'=>'default','configuration_revision'=>4,'binding_epoch'=>7,
        'dataset_revision'=>3,'assignment_revision'=>6,'credential_version'=>null,'run_id'=>'12345678-1234-4abc-8def-123456789abc',
        'sequence'=>1,'started_at'=>$now-10,'finished_at'=>$now,'supported_types'=>['A','AAAA','CNAME','PTR'],'complete'=>true,'record_count'=>1,
        'records'=>[['owner'=>'HOST.example.test.','display_owner'=>'HOST','type'=>'A','record_class'=>'IN','rdata'=>'192.0.2.1',
                    'display_rdata'=>'192.0.2.1','ttl_seconds'=>300,'source_aging_timestamp'=>null]]];
    return [$repo,$issued,$doc,$now];
}
function upload10(IntegrationDnsZonePublicationService $service,string $token,array $doc): array {
    $raw=json_encode($doc,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    return $service->publishUpload($token,$doc,hash('sha256',$raw),strlen($raw));
}
function service10(Dns10PublicationMemoryRepository $repo,int &$now,int &$free): IntegrationDnsZonePublicationService {
    return new IntegrationDnsZonePublicationService($repo,static function() use(&$now): int {return $now;},static function() use(&$free): int {return $free;});
}
[$repo,$issued,$doc,$now]=serviceFixture10();$free=1024*1024*1024;$service=service10($repo,$now,$free);
$service->authenticateUpload($issued['token']);
serviceCheck10(upload10($service,$issued['token'],$doc)===['decision'=>'publish'],'first publication');
$dataset=$doc['dataset_id'];$job=$doc['job_id'];$current=$repo->heads[$dataset]['current'];
serviceCheck10(count($repo->generations)===1 && count($repo->records[$current])===1,'one complete generation');
serviceCheck10($repo->records[$current][0]['owner']==='host.example.test.','records normalized before storage');
serviceCheck10($repo->jobs[$job]['state']==='succeeded' && $repo->jobs[$job]['lease_id']===null,'job completed atomically');
serviceCheck10(!array_key_exists('lease_id',$repo->generations[$current]) && !str_contains(json_encode($repo->generations),$issued['token']),'lease and token not persisted');
$before=serialize([$repo->jobs,$repo->generations,$repo->records,$repo->heads]);$now+=3600;
serviceCheck10(upload10($service,$issued['token'],$doc)===['decision'=>'replay'],'identical replay after cleared lease');
serviceCheck10(serialize([$repo->jobs,$repo->generations,$repo->records,$repo->heads])===$before,'replay does not refresh or write');
$changed=$doc;$changed['records'][0]['ttl_seconds']=301;
serviceReject10(fn()=>upload10($service,$issued['token'],$changed),'snapshot_sequence_conflict');
$repo->tokens[$issued['token_id']]['revoked']=true;
serviceReject10(fn()=>upload10($service,$issued['token'],$doc),'upload_auth_failed');
$repo->tokens[$issued['token_id']]['revoked']=false;
$repo->datasets[$dataset]['revision']=4;
serviceReject10(fn()=>upload10($service,$issued['token'],$doc),'snapshot_unavailable');
$repo->datasets[$dataset]['revision']=3;
$repo->sources[$doc['source_id']]['revision']=5;
serviceReject10(fn()=>upload10($service,$issued['token'],$doc),'snapshot_unavailable');
$repo->sources[$doc['source_id']]['revision']=4;
$repo->sources[$doc['source_id']]['credential_version']=3;
serviceReject10(fn()=>upload10($service,$issued['token'],$doc),'snapshot_unavailable');
$repo->sources[$doc['source_id']]['credential_version']=null;

foreach (['revoked_token','disabled_source','revoked_collector','reassigned','unselected','wrong_dataset','expired_job','expired_lease',
          'wrong_lease','stale_assignment','stale_config','stale_dataset','stale_credential','bad_payload'] as $case) {
    [$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
    $src=$d['source_id'];$col=$d['collector_id'];$ds=$d['dataset_id'];$j=$d['job_id'];$code='snapshot_job_invalid';
    switch($case) {
        case 'revoked_token':$r->tokens[$t['token_id']]['revoked']=true;$code='upload_auth_failed';break;
        case 'disabled_source':$r->sources[$src]['enabled']=false;$code='upload_auth_failed';break;
        case 'revoked_collector':$r->collectors[$col]['revoked']=true;$code='upload_auth_failed';break;
        case 'reassigned':$r->assignments[$src]['revision']=7;$code='upload_auth_failed';break;
        case 'unselected':$r->datasets[$ds]['selected']=false;$code='snapshot_unavailable';break;
        case 'wrong_dataset':$r->jobs[$j]['dataset_id']=str_repeat('f',32);break;
        case 'expired_job':$r->jobs[$j]['expires_at']=$clock-1;break;
        case 'expired_lease':$r->jobs[$j]['lease_expires_at']=$clock-1;break;
        case 'wrong_lease':$d['lease_id']=str_repeat('f',32);$code='snapshot_invalid';break;
        case 'stale_assignment':$r->jobs[$j]['assignment_revision']=5;break;
        case 'stale_config':$r->jobs[$j]['configuration_revision']=3;break;
        case 'stale_dataset':$r->jobs[$j]['dataset_revision']=2;break;
        case 'stale_credential':$r->sources[$src]['credential_version']=2;break;
        case 'bad_payload':$d['complete']=false;$code='snapshot_invalid';break;
    }
    $before=serialize([$r->jobs,$r->generations,$r->records,$r->heads]);
    serviceReject10(fn()=>upload10($s,$t['token'],$d),$code);
    serviceCheck10(serialize([$r->jobs,$r->generations,$r->records,$r->heads])===$before,$case.' preserves state');
}

[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
$r->accountedOverride=256*1024*1024;
serviceReject10(fn()=>upload10($s,$t['token'],$d),'snapshot_capacity_exceeded');
serviceCheck10($r->generations===[] && $r->jobs[$d['job_id']]['state']==='running','capacity failure preserves job');
$r->accountedOverride=null;$space=512*1024*1024;
serviceReject10(fn()=>upload10($s,$t['token'],$d),'snapshot_disk_floor');
serviceCheck10($r->generations===[],'disk failure preserves data');

[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
$r->afterInsert=static function() use (&$clock): void {$clock+=121;};
serviceReject10(fn()=>upload10($s,$t['token'],$d),'snapshot_job_invalid');
serviceCheck10($r->generations===[] && $r->jobs[$d['job_id']]['state']==='running','lease expiry during storage rolls back publication');
[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
$r->afterInsert=static function() use (&$space): void {$space=512*1024*1024-1;};
serviceReject10(fn()=>upload10($s,$t['token'],$d),'snapshot_disk_floor');
serviceCheck10($r->generations===[] && $r->jobs[$d['job_id']]['state']==='running','disk floor crossed during storage rolls back');

[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
$r->heads[$d['dataset_id']]=['current'=>str_repeat('f',32),'previous'=>null];
serviceReject10(fn()=>upload10($s,$t['token'],$d),'snapshot_unavailable');
serviceCheck10($r->generations===[] && $r->heads[$d['dataset_id']]['current']===str_repeat('f',32),'missing head generation does not get overwritten');

[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
$r->generations[str_repeat('f',32)]=['id'=>str_repeat('f',32),'dataset_id'=>$d['dataset_id'],'sequence_no'=>10,'payload_bytes'=>100];
serviceReject10(fn()=>upload10($s,$t['token'],$d),'snapshot_unavailable');
serviceCheck10(count($r->generations)===1 && $r->heads===[],'orphaned generation prevents sequence reset');

// Build two retained generations, then fail each write in a third publication.
[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);upload10($s,$t['token'],$d);
$job2=str_repeat('1',32);$lease2=str_repeat('2',32);$r->jobs[$job2]=$r->jobs[$d['job_id']];
$r->jobs[$job2]['id']=$job2;$r->jobs[$job2]['state']='running';$r->jobs[$job2]['lease_id']=$lease2;
$r->jobs[$job2]['lease_expires_at']=$clock+120;$r->jobs[$job2]['expires_at']=$clock+300;$r->jobs[$job2]['finished_at']=null;$r->jobs[$job2]['result_code']=null;
$d['job_id']=$job2;$d['lease_id']=$lease2;$d['sequence']=2;$d['run_id']='12345678-1234-4abc-8def-123456789abd';upload10($s,$t['token'],$d);
serviceCheck10(count($r->generations)===2 && $r->heads[$d['dataset_id']]['previous']!==null,'two generations retained');
$inconsistent=clone $r;$oldId=$inconsistent->heads[$d['dataset_id']]['previous'];
$inconsistent->generations[$oldId]['sequence_no']=2;
$badService=service10($inconsistent,$clock,$space);
serviceReject10(fn()=>upload10($badService,$t['token'],$d),'snapshot_unavailable');
$inconsistent=clone $r;$oldId=$inconsistent->heads[$d['dataset_id']]['previous'];
$inconsistent->generations[$oldId]['payload_sha256']='broken';
$badService=service10($inconsistent,$clock,$space);
serviceReject10(fn()=>upload10($badService,$t['token'],$d),'snapshot_unavailable');
$job3=str_repeat('3',32);$lease3=str_repeat('4',32);$r->jobs[$job3]=$r->jobs[$job2];
$r->jobs[$job3]['id']=$job3;$r->jobs[$job3]['state']='running';$r->jobs[$job3]['lease_id']=$lease3;
$r->jobs[$job3]['lease_expires_at']=$clock+120;$r->jobs[$job3]['expires_at']=$clock+300;$r->jobs[$job3]['finished_at']=null;$r->jobs[$job3]['result_code']=null;
$d['job_id']=$job3;$d['lease_id']=$lease3;$d['sequence']=3;$d['run_id']='12345678-1234-4abc-8def-123456789abe';
for($fail=1;$fail<=4;++$fail) {
    $copy=clone $r;$copy->failAt=$fail;$failedService=service10($copy,$clock,$space);
    $before=serialize([$copy->jobs,$copy->generations,$copy->records,$copy->heads]);
    serviceReject10(fn()=>upload10($failedService,$t['token'],$d),'injected_failure');
    serviceCheck10(serialize([$copy->jobs,$copy->generations,$copy->records,$copy->heads])===$before,'write '.$fail.' rolls back');
}
serviceCheck10(upload10($s,$t['token'],$d)===['decision'=>'publish'] && count($r->generations)===2,'third success prunes only oldest generation');

[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
$racing=new class($s,$r,$t['token_id']) {
    public function __construct(private IntegrationDnsZonePublicationService $service,private Dns10PublicationMemoryRepository $repo,private string $id) {}
    public function authenticateUpload(#[SensitiveParameter] string $token): void {$this->service->authenticateUpload($token);$this->repo->tokens[$this->id]['revoked']=true;}
    public function publishUpload(#[SensitiveParameter] string $token,array $doc,string $digest,int $bytes): array {return $this->service->publishUpload($token,$doc,$digest,$bytes);}
};
$controller=new IntegrationDnsZoneUploadHttpController(static fn()=> $racing);
$response=$controller->handle(['method'=>'POST','https'=>true,'content_type'=>'application/json','authorization'=>'Bearer '.$t['token'],
    'raw_body'=>json_encode($d,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
serviceCheck10($response['status']===401 && $r->generations===[],'revocation between authentication and publication blocks write');
[$r,$t,$d,$clock]=serviceFixture10();$space=1024*1024*1024;$s=service10($r,$clock,$space);
$controller=new IntegrationDnsZoneUploadHttpController(static fn()=> $s);
$request=['method'=>'POST','https'=>true,'content_type'=>'application/json','authorization'=>'Bearer '.$t['token'],
    'raw_body'=>json_encode($d,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
$response=$controller->handle($request);
serviceCheck10($response['status']===200 && $response['body']===['ok'=>true,'decision'=>'publish'],'authenticated HTTP publishes complete snapshot');
$before=serialize([$r->jobs,$r->generations,$r->records,$r->heads]);$clock+=3600;
$response=$controller->handle($request);
serviceCheck10($response['status']===200 && $response['body']===['ok'=>true,'decision'=>'replay'],'lost HTTP acknowledgment can be replayed');
serviceCheck10(serialize([$r->jobs,$r->generations,$r->records,$r->heads])===$before,'HTTP replay leaves state unchanged');
echo 'DNS10_PUBLICATION_SERVICE_ASSERTIONS='.$n."\n";
echo "DNS10_PUBLICATION_SERVICE_TEST_OK=1\n";
