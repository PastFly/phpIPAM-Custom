<?php
declare(strict_types=1);

/** Destructive fixture ONLY in an empty localhost database named phpipam_dns10_test. */
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$root=dirname(__DIR__,2);
$migration=$root.'/database/dns10/001-zone-snapshots.sql';
if (!is_file($migration)) throw new RuntimeException('snapshot_schema_missing');
$workMigration=$root.'/database/dns10/002-upload-work.sql';
$adapterPath=$root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationPhpIpamRepository.php';
if (!is_file($workMigration) || !is_file($adapterPath)) throw new RuntimeException('snapshot_repository_missing');
$selectionPath=$root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionPhpIpamRepository.php';
if (!is_file($selectionPath)) throw new RuntimeException('selection_repository_missing');
$dsn=getenv('DNS10_TEST_DSN');
if (!$dsn || !in_array('mysql',PDO::getAvailableDrivers(),true)) {echo "DNS10_MYSQL_NOT_RUN=1\n";exit(77);}
if (!preg_match('/^mysql:host=127\\.0\\.0\\.1;port=[0-9]{1,5};dbname=phpipam_dns10_test;charset=utf8mb4$/D',$dsn)) {
    throw new RuntimeException('local_test_database_required');
}
$pdo=new PDO($dsn,getenv('DNS10_TEST_USER'),getenv('DNS10_TEST_PASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='phpipam_dns10_test' || $pdo->query('SHOW TABLES')->fetchAll()) {
    throw new RuntimeException('empty_test_database_required');
}
$n=0;
function mysqlCheck10(bool $value,string $label): void {global $n;++$n;if(!$value) throw new RuntimeException($label);}
function mysqlReject10(callable $fn,string $label): void {
    try {$fn();} catch(PDOException $e) {mysqlCheck10(($e->errorInfo[0]??'')==='23000',$label);return;}
    throw new RuntimeException('not_rejected: '.$label);
}
$pdo->exec(file_get_contents($root.'/database/dns1/001-configuration.sql'));
$pdo->exec(file_get_contents($root.'/database/dns3/001-collector-control.sql'));
$pdo->exec(file_get_contents($root.'/database/dns4/001-machine-job-lifecycle.sql'));
$pdo->exec(file_get_contents($root.'/database/dns6/001-credential-delivery-marker.sql'));
$pdo->exec(file_get_contents($root.'/database/dns9/001-autonomous-enable.sql'));
$pdo->exec(file_get_contents($migration));
$pdo->exec(file_get_contents($workMigration));

$sourceA=str_repeat('a',32);$sourceB=str_repeat('b',32);$collector=str_repeat('c',32);
$datasetA=str_repeat('d',32);$datasetB=str_repeat('e',32);
$conn=$pdo->prepare('INSERT INTO custom_dns_connections (id,provider,name,server,interval_seconds,credential_mode,profile_id,collector_id,enabled,status,revision,binding_epoch,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([$sourceA,$sourceB] as $i=>$id) $conn->execute([$id,'microsoft_dns','fixture-'.$i,'dc.example.invalid',300,'collector_identity',null,null,0,'disabled',1,1,100,1]);
$q=$pdo->prepare('INSERT INTO custom_dns_collectors (id,name,token_digest,revision,control_epoch,revoked,created_at,created_by,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
$q->execute([$collector,'fixture',str_repeat('0',64),1,1,0,100,1,100,1]);
$dataset=$pdo->prepare('INSERT INTO custom_dns_zone_datasets (id,connection_id,zone_name,virtualization_instance,zone_scope,selected,revision,created_at,created_by,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$addDataset=static fn($id,$source,$selected=0)=>$dataset->execute([$id,$source,'example.test.','default','default',$selected,1,100,1,100,1]);
$addDataset($datasetA,$sourceA);$addDataset($datasetB,$sourceB);
mysqlCheck10((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_zone_datasets')->fetchColumn()===2,'same zone across sources is independent');
mysqlReject10(fn()=> $addDataset(str_repeat('f',32),$sourceA),'duplicate zone within source');
mysqlReject10(fn()=> $addDataset(str_repeat('f',32),$sourceA,2),'invalid selection flag');
mysqlReject10(fn()=> $pdo->prepare('INSERT INTO custom_dns_zone_datasets (id,connection_id,zone_name,virtualization_instance,zone_scope,selected,collection_enabled,revision,created_at,created_by,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([str_repeat('f',32),$sourceA,'other.example.test.','default','default',0,1,1,100,1,100,1]),'collection cannot be enabled without selection');

$gen=$pdo->prepare('INSERT INTO custom_dns_zone_generations (id,dataset_id,collector_id,job_id,run_id,sequence_no,dataset_revision,configuration_revision,binding_epoch,assignment_revision,credential_version,payload_sha256,payload_bytes,record_count,started_at,finished_at,received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$addGeneration=static function($id,$datasetId,$seq,$bytes=100,$count=1) use($gen,$collector): void {
    $run='12345678-1234-4abc-8def-'.substr(hash('sha256',(string)$seq),0,12);
    $gen->execute([$id,$datasetId,$collector,str_repeat('1',32),$run,$seq,1,1,1,1,null,str_repeat('a',64),$bytes,$count,100,101,102]);
};
$g1=str_repeat('1',32);$g2=str_repeat('2',32);$g3=str_repeat('3',32);$other=str_repeat('4',32);
$addGeneration($g1,$datasetA,1);$addGeneration($other,$datasetB,1);
mysqlReject10(fn()=> $addGeneration(str_repeat('5',32),$datasetA,0),'sequence zero rejected');
mysqlReject10(fn()=> $addGeneration(str_repeat('5',32),$datasetA,9007199254740992),'sequence beyond safe integer rejected');
mysqlReject10(fn()=> $addGeneration(str_repeat('5',32),$datasetA,2,4194305),'payload limit');
mysqlReject10(fn()=> $addGeneration(str_repeat('5',32),$datasetA,2,100,10001),'record count limit');
mysqlReject10(fn()=> $addGeneration(str_repeat('5',32),$datasetA,1),'duplicate sequence per dataset');
$head=$pdo->prepare('INSERT INTO custom_dns_zone_heads (dataset_id,current_generation_id,previous_generation_id) VALUES (?,?,?)');
mysqlReject10(fn()=> $head->execute([$datasetA,$other,null]),'cross-dataset head rejected');
mysqlReject10(fn()=> $head->execute([$datasetA,null,$g1]),'previous without current rejected');
mysqlReject10(fn()=> $head->execute([$datasetA,$g1,$g1]),'duplicate head pointers rejected');
$head->execute([$datasetA,$g1,null]);$head->execute([$datasetB,$other,null]);
$record=$pdo->prepare('INSERT INTO custom_dns_zone_records (generation_id,owner,display_owner,record_type,record_class,rdata,display_rdata,ttl_seconds,source_aging_timestamp) VALUES (?,?,?,?,?,?,?,?,?)');
$addRecord=static fn($generation)=>$record->execute([$generation,'host.example.test.','host','A','IN','192.0.2.1','192.0.2.1',300,null]);
$addRecord($g1);
mysqlReject10(fn()=> $addRecord($g1),'duplicate normalized record rejected');
mysqlReject10(fn()=> $pdo->prepare('DELETE FROM custom_dns_zone_generations WHERE id=?')->execute([$g1]),'pointed generation retained');
mysqlReject10(fn()=> $pdo->prepare('DELETE FROM custom_dns_connections WHERE id=?')->execute([$sourceA]),'source with dataset retained');

$pdo->beginTransaction();
$addGeneration($g2,$datasetA,2);$addRecord($g2);
$pdo->prepare('UPDATE custom_dns_zone_heads SET current_generation_id=?,previous_generation_id=? WHERE dataset_id=?')->execute([$g2,$g1,$datasetA]);
$pdo->rollBack();
$q=$pdo->prepare('SELECT current_generation_id,previous_generation_id FROM custom_dns_zone_heads WHERE dataset_id=?');$q->execute([$datasetA]);
$after=$q->fetch(PDO::FETCH_ASSOC);
mysqlCheck10($after['current_generation_id']===$g1 && $after['previous_generation_id']===null,'rollback keeps last-good head');
mysqlCheck10((int)$pdo->query("SELECT COUNT(*) FROM custom_dns_zone_generations WHERE id='".$g2."'")->fetchColumn()===0,'rollback removes partial generation');

$pdo->beginTransaction();
$addGeneration($g2,$datasetA,2);$addRecord($g2);
$pdo->prepare('UPDATE custom_dns_zone_heads SET current_generation_id=?,previous_generation_id=? WHERE dataset_id=?')->execute([$g2,$g1,$datasetA]);
$pdo->commit();
mysqlReject10(fn()=> $pdo->prepare('DELETE FROM custom_dns_zone_generations WHERE id=?')->execute([$g1]),'previous generation retained');
$pdo->beginTransaction();
$addGeneration($g3,$datasetA,3);$addRecord($g3);
$pdo->prepare('UPDATE custom_dns_zone_heads SET current_generation_id=?,previous_generation_id=? WHERE dataset_id=?')->execute([$g3,$g2,$datasetA]);
$pdo->prepare('DELETE FROM custom_dns_zone_generations WHERE id=?')->execute([$g1]);
$pdo->commit();
mysqlCheck10((int)$pdo->query("SELECT COUNT(*) FROM custom_dns_zone_records WHERE generation_id='".$g1."'")->fetchColumn()===0,'unreferenced pruned generation records cascade');
$q->execute([$datasetA]);$after=$q->fetch(PDO::FETCH_ASSOC);
mysqlCheck10($after['current_generation_id']===$g3 && $after['previous_generation_id']===$g2,'two newest generations preserved');

foreach ([
    $root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationRepository.php',
    $root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneUploadToken.php',
    $root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSnapshotValidator.php',
    $root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationPolicy.php',
    $adapterPath,$root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationService.php'
] as $class) require $class;
final class Dns10LazyPdoFixture {
    public ?PDO $pdo=null;public int $connections=0;public ?int $failAt=null;public int $writes=0;
    public function __construct(private string $dsn) {}
    public function isConnected(): bool {return $this->pdo!==null;}
    public function connect(): void {$this->pdo=new PDO($this->dsn,getenv('DNS10_TEST_USER'),getenv('DNS10_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);++$this->connections;}
    public function beginTransaction(): bool {$this->writes=0;return $this->pdo->beginTransaction();}
    public function commit(): bool {return $this->pdo->commit();}
    public function rollBack(): bool {return $this->pdo->inTransaction()?$this->pdo->rollBack():false;}
    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        $q=$this->pdo->prepare($sql);$q->execute((array)$values);return $q->fetchAll(PDO::FETCH_OBJ);
    }
    public function runQuery($sql,$values=[]): bool {
        ++$this->writes;if($this->failAt===$this->writes)throw new DomainException('injected_failure');
        $q=$this->pdo->prepare($sql);return $q->execute((array)$values);
    }
}
function mysqlDomainReject10(callable $fn,string $code): void {
    try {$fn();} catch(DomainException $e) {mysqlCheck10($e->getMessage()===$code,'domain '.$code);return;}
    throw new RuntimeException('not_rejected: '.$code);
}
$pdo->prepare('UPDATE custom_dns_connections SET enabled=1,status=? WHERE id=?')->execute(['active',$sourceA]);
$pdo->prepare('UPDATE custom_dns_zone_datasets SET selected=1,collection_enabled=1 WHERE id=?')->execute([$datasetA]);
$pdo->prepare('INSERT INTO custom_dns_collector_assignments (connection_id,collector_id,revision,assigned_at,assigned_by) VALUES (?,?,?,?,?)')->execute([$sourceA,$collector,1,100,1]);
$issued=IntegrationDnsZoneUploadToken::issue();
$pdo->prepare('INSERT INTO custom_dns_zone_upload_tokens (id,connection_id,collector_id,assignment_revision,token_digest,revoked,created_at,created_by,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
    ->execute([$issued['token_id'],$sourceA,$collector,1,$issued['token_digest'],0,100,1,100,1]);
$clock=2000000000;$space=1024*1024*1024;
$jobInsert=$pdo->prepare('INSERT INTO custom_dns_control_jobs (id,collector_id,connection_id,assignment_revision,job_type,state,configuration_revision,binding_epoch,credential_version,lease_id,lease_expires_at,attempt_count,credential_delivered_at,result_code,result_payload,finished_at,created_at,expires_at,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$bindingInsert=$pdo->prepare('INSERT INTO custom_dns_zone_job_datasets (job_id,connection_id,dataset_id,dataset_revision) VALUES (?,?,?,?)');
$makeJob=static function(string $id,string $lease) use($jobInsert,$bindingInsert,$collector,$sourceA,$datasetA,$clock): void {
    $jobInsert->execute([$id,$collector,$sourceA,1,'collect_selected_zones','running',1,1,null,$lease,$clock+120,1,$clock,null,null,null,$clock,$clock+300,$clock,0]);
    $bindingInsert->execute([$id,$sourceA,$datasetA,1]);
};
$jid=str_repeat('6',32);$lease=str_repeat('7',32);
$jobInsert->execute([$jid,$collector,$sourceA,1,'collect_selected_zones','running',1,1,null,$lease,$clock+120,1,$clock,null,null,null,$clock,$clock+300,$clock,0]);
mysqlReject10(fn()=> $bindingInsert->execute([$jid,$sourceA,$datasetB,1]),'job cannot target another source dataset');
$bindingInsert->execute([$jid,$sourceA,$datasetA,1]);
$doc=['schema_version'=>1,'source_id'=>$sourceA,'dataset_id'=>$datasetA,'collector_id'=>$collector,'job_id'=>$jid,'lease_id'=>$lease,
    'zone'=>'example.test.','virtualization_instance'=>'default','zone_scope'=>'default','configuration_revision'=>1,'binding_epoch'=>1,
    'dataset_revision'=>1,'assignment_revision'=>1,'credential_version'=>null,'run_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'sequence'=>4,'started_at'=>$clock-10,'finished_at'=>$clock,'supported_types'=>['A','AAAA','CNAME','PTR'],'complete'=>true,'record_count'=>1,
    'records'=>[['owner'=>'host.example.test.','display_owner'=>'host','type'=>'A','record_class'=>'IN','rdata'=>'192.0.2.1',
                'display_rdata'=>'192.0.2.1','ttl_seconds'=>300,'source_aging_timestamp'=>null]]];
$raw=json_encode($doc,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$db=new Dns10LazyPdoFixture($dsn);$repo=new IntegrationDnsZonePublicationPhpIpamRepository($db);
$service=new IntegrationDnsZonePublicationService($repo,static function() use(&$clock): int{return $clock;},static function() use(&$space): int{return $space;});
$service->authenticateUpload($issued['token']);
mysqlCheck10($db->connections===1 && $db->isConnected() && !$db->pdo->inTransaction(),'lazy database initialized and authentication transaction closed');
mysqlCheck10($service->publishUpload($issued['token'],$doc,hash('sha256',$raw),strlen($raw))===['decision'=>'publish'],'real authenticated transaction publishes');
$q->execute([$datasetA]);$headBefore=$q->fetch(PDO::FETCH_ASSOC);
mysqlCheck10($headBefore['previous_generation_id']===$g3 && $headBefore['current_generation_id']!==$g3,'new head retains previous current');
mysqlCheck10((int)$pdo->query("SELECT COUNT(*) FROM custom_dns_zone_generations WHERE dataset_id='".$datasetA."'")->fetchColumn()===2,'only two generations remain');
$jobRead=$pdo->prepare('SELECT state,lease_id,finished_at FROM custom_dns_control_jobs WHERE id=?');$jobRead->execute([$jid]);$finished=$jobRead->fetch(PDO::FETCH_ASSOC);
mysqlCheck10($finished['state']==='succeeded' && $finished['lease_id']===null && (int)$finished['finished_at']===$clock,'job completed and lease cleared');
mysqlCheck10((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_zone_sync_events')->fetchColumn()===1,'bounded sync audit inserted');
$clock+=3600;
mysqlCheck10($service->publishUpload($issued['token'],$doc,hash('sha256',$raw),strlen($raw))===['decision'=>'replay'],'identical retry after job completion');
$q->execute([$datasetA]);mysqlCheck10($q->fetch(PDO::FETCH_ASSOC)===$headBefore,'replay leaves head unchanged');
mysqlCheck10((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_zone_sync_events')->fetchColumn()===1,'replay does not add audit event');
$clock-=3600;
$jid2=str_repeat('8',32);$lease2=str_repeat('9',32);$makeJob($jid2,$lease2);
$doc2=$doc;$doc2['job_id']=$jid2;$doc2['lease_id']=$lease2;$doc2['sequence']=5;$doc2['run_id']='aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeef';
$raw2=json_encode($doc2,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
for($failure=1;$failure<=6;++$failure) {
    $db->failAt=$failure;
    mysqlDomainReject10(fn()=> $service->publishUpload($issued['token'],$doc2,hash('sha256',$raw2),strlen($raw2)),'injected_failure');
    $q->execute([$datasetA]);$afterFailure=$q->fetch(PDO::FETCH_ASSOC);
    $jobRead->execute([$jid2]);$stillRunning=$jobRead->fetch(PDO::FETCH_ASSOC);
    mysqlCheck10($afterFailure===$headBefore && $stillRunning['state']==='running' && $stillRunning['lease_id']===$lease2,'each injected write failure rolls back');
    mysqlCheck10((int)$pdo->query("SELECT COUNT(*) FROM custom_dns_zone_generations WHERE dataset_id='".$datasetA."'")->fetchColumn()===2 &&
        (int)$pdo->query('SELECT COUNT(*) FROM custom_dns_zone_sync_events')->fetchColumn()===1,'rollback preserves generations and audit');
}
$db->failAt=null;
$pdo->prepare('UPDATE custom_dns_zone_upload_tokens SET revoked=1 WHERE id=?')->execute([$issued['token_id']]);
mysqlDomainReject10(fn()=> $service->publishUpload($issued['token'],$doc2,hash('sha256',$raw2),strlen($raw2)),'upload_auth_failed');
$pdo->prepare('UPDATE custom_dns_zone_upload_tokens SET revoked=0 WHERE id=?')->execute([$issued['token_id']]);
$pdo->prepare('UPDATE custom_dns_collector_assignments SET revision=2 WHERE connection_id=?')->execute([$sourceA]);
mysqlDomainReject10(fn()=> $service->publishUpload($issued['token'],$doc2,hash('sha256',$raw2),strlen($raw2)),'upload_auth_failed');
$pdo->prepare('UPDATE custom_dns_collector_assignments SET revision=1 WHERE connection_id=?')->execute([$sourceA]);
$pdo->prepare('UPDATE custom_dns_zone_datasets SET selected=0,collection_enabled=0 WHERE id=?')->execute([$datasetA]);
mysqlDomainReject10(fn()=> $service->publishUpload($issued['token'],$doc2,hash('sha256',$raw2),strlen($raw2)),'snapshot_unavailable');
$pdo->prepare('UPDATE custom_dns_zone_datasets SET selected=1,collection_enabled=1 WHERE id=?')->execute([$datasetA]);
mysqlCheck10($service->publishUpload($issued['token'],$doc2,hash('sha256',$raw2),strlen($raw2))===['decision'=>'publish'],'new authorized generation publishes after rollback tests');
$jid3=str_repeat('a',32);$lease3=str_repeat('b',32);$makeJob($jid3,$lease3);
$doc3=$doc2;$doc3['job_id']=$jid3;$doc3['lease_id']=$lease3;$doc3['sequence']=6;
$doc3['run_id']='aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeee01';$doc3['record_count']=205;$doc3['records']=[];
for($i=0;$i<205;++$i) {$recordRow=$doc['records'][0];$recordRow['owner']='host'.$i.'.example.test.';$doc3['records'][]=$recordRow;}
$raw3=json_encode($doc3,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
mysqlCheck10($service->publishUpload($issued['token'],$doc3,hash('sha256',$raw3),strlen($raw3))===['decision'=>'publish'],'multi-batch records publish');
$q->execute([$datasetA]);$multiHead=$q->fetch(PDO::FETCH_ASSOC);
$countRecords=$pdo->prepare('SELECT COUNT(*) FROM custom_dns_zone_records WHERE generation_id=?');$countRecords->execute([$multiHead['current_generation_id']]);
mysqlCheck10((int)$countRecords->fetchColumn()===205,'all multi-batch records retained');
$jid4=str_repeat('c',32);$lease4=str_repeat('d',32);$makeJob($jid4,$lease4);
$emptyDoc=$doc3;$emptyDoc['job_id']=$jid4;$emptyDoc['lease_id']=$lease4;$emptyDoc['sequence']=7;
$emptyDoc['run_id']='aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeee02';$emptyDoc['record_count']=0;$emptyDoc['records']=[];
$emptyRaw=json_encode($emptyDoc,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
mysqlCheck10($service->publishUpload($issued['token'],$emptyDoc,hash('sha256',$emptyRaw),strlen($emptyRaw))===['decision'=>'publish'],'complete empty snapshot publishes');
$q->execute([$datasetA]);$emptyHead=$q->fetch(PDO::FETCH_ASSOC);$countRecords->execute([$emptyHead['current_generation_id']]);
mysqlCheck10((int)$countRecords->fetchColumn()===0 && $emptyHead['previous_generation_id']===$multiHead['current_generation_id'],'empty current preserves prior full snapshot');
$oldest=(int)$pdo->query('SELECT MIN(event_id) FROM custom_dns_zone_sync_events')->fetchColumn();
$eventCount=(int)$pdo->query('SELECT COUNT(*) FROM custom_dns_zone_sync_events')->fetchColumn();
$eventInsert=$pdo->prepare("INSERT INTO custom_dns_zone_sync_events (dataset_id,job_id,action,actor_id,created_at) VALUES (?,?,'snapshot_published',0,?)");
for($i=$eventCount;$i<1000;++$i) $eventInsert->execute([$datasetA,$jid,100+$i]);
$jid5=str_repeat('e',32);$lease5=str_repeat('f',32);$makeJob($jid5,$lease5);
$boundedDoc=$emptyDoc;$boundedDoc['job_id']=$jid5;$boundedDoc['lease_id']=$lease5;$boundedDoc['sequence']=8;
$boundedDoc['run_id']='aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeee03';
$boundedRaw=json_encode($boundedDoc,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
mysqlCheck10($service->publishUpload($issued['token'],$boundedDoc,hash('sha256',$boundedRaw),strlen($boundedRaw))===['decision'=>'publish'],'publication at sync event limit');
mysqlCheck10((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_zone_sync_events')->fetchColumn()===1000 &&
    (int)$pdo->query('SELECT COUNT(*) FROM custom_dns_zone_sync_events WHERE event_id='.(int)$oldest)->fetchColumn()===0,
    'only oldest integration sync event pruned at bound');

$otherPdo=new PDO($dsn,getenv('DNS10_TEST_USER'),getenv('DNS10_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$otherPdo->exec('SET innodb_lock_wait_timeout=1');
$repo->transaction(function() use($otherPdo): void {
    $otherPdo->beginTransaction();$blocked=false;
    try {$otherPdo->query('SELECT id FROM custom_dns_config_guard WHERE id=1 FOR UPDATE');}
    catch(PDOException $e) {$blocked=(int)($e->errorInfo[1]??0)===1205;}
    finally {if($otherPdo->inTransaction())$otherPdo->rollBack();}
    mysqlCheck10($blocked,'shared guard excludes concurrent configuration transaction');
});

require $root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionRepository.php';
require $root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionService.php';
require $selectionPath;
$discoveryId=str_repeat('0',32);
$zonePayload=json_encode(['zones'=>[
    ['name'=>'new.example.test','zone_type'=>'Primary','ds_integrated'=>true,'reverse_lookup'=>false],
    ['name'=>'failure.example.test','zone_type'=>'Secondary','ds_integrated'=>false,'reverse_lookup'=>false],
    ['name'=>'stub.example.test','zone_type'=>'Stub','ds_integrated'=>false,'reverse_lookup'=>false],
]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$jobInsert->execute([$discoveryId,$collector,$sourceA,1,'list_zones','succeeded',1,1,null,null,null,1,$clock-15,
    'ok',$zonePayload,$clock-10,$clock-20,$clock+280,$clock-10,0]);
$selectionRepo=new IntegrationDnsZoneSelectionPhpIpamRepository($db);
$selectionService=new IntegrationDnsZoneSelectionService($selectionRepo,static function() use(&$clock): int{return $clock;});
$selected=$selectionService->setSelected($sourceA,'NEW.example.test',true,null,$discoveryId,17);
mysqlCheck10($selected['selected']===true && $selected['collection_enabled']===false && $selected['revision']===1,'real database selection does not start collection');
mysqlDomainReject10(fn()=> $selectionService->setSelected($sourceA,'stub.example.test',true,null,$discoveryId,17),'zone_not_eligible');
mysqlDomainReject10(fn()=> $selectionService->setCollectionEnabled($selected['id'],1,true,str_repeat('9',32),17),'discovery_unavailable');
$enabled=$selectionService->setCollectionEnabled($selected['id'],1,true,$discoveryId,17);
mysqlCheck10($enabled['collection_enabled']===true && $enabled['revision']===2,'real database explicit enable');
mysqlDomainReject10(fn()=> $selectionService->setCollectionEnabled($selected['id'],1,false,null,17),'revision_conflict');
$pdo->prepare('UPDATE custom_dns_connections SET enabled=0,status=? WHERE id=?')->execute(['disabled',$sourceA]);
$disabled=$selectionService->setCollectionEnabled($selected['id'],2,false,null,17);
$deselected=$selectionService->setSelected($sourceA,'new.example.test',false,3,null,17);
mysqlCheck10($disabled['revision']===3 && $deselected['revision']===4 && $deselected['selected']===false,'disable and deselect work with source off');
$pdo->prepare('UPDATE custom_dns_connections SET enabled=1,status=? WHERE id=?')->execute(['active',$sourceA]);
mysqlDomainReject10(function() use($selectionRepo,$deselected): void {
    $selectionRepo->transaction(function() use($selectionRepo,$deselected): void {
        $changed=$deselected;$changed['zone_name']='other.example.test.';++$changed['revision'];$selectionRepo->putDataset($changed);
    });
},'selection_unavailable');
$db->failAt=2;
mysqlDomainReject10(fn()=> $selectionService->setSelected($sourceA,'failure.example.test',true,null,$discoveryId,17),'injected_failure');
$db->failAt=null;
$countDataset=$pdo->prepare('SELECT COUNT(*) FROM custom_dns_zone_datasets WHERE connection_id=? AND zone_name=?');
$countDataset->execute([$sourceA,'failure.example.test.']);
mysqlCheck10((int)$countDataset->fetchColumn()===0,'audit failure rolls back selection in MariaDB');
$pdo->prepare('UPDATE custom_dns_control_jobs SET result_payload=? WHERE id=?')->execute(['{"zones":[],"zones":[]}',$discoveryId]);
mysqlDomainReject10(fn()=> $selectionService->setSelected($sourceA,'failure.example.test',true,null,$discoveryId,17),'discovery_unavailable');
$pdo->prepare('UPDATE custom_dns_control_jobs SET result_payload=? WHERE id=?')->execute([$zonePayload,$discoveryId]);
mysqlCheck10((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_config_events WHERE action IN (\'zone_selected\',\'zone_collection_enabled\',\'zone_collection_disabled\',\'zone_deselected\')')->fetchColumn()===4,'selection changes audited transactionally');
echo 'DNS10_MYSQL_ASSERTIONS='.$n."\n";
echo "DNS10_MYSQL_TEST_OK=1\n";
