<?php
declare(strict_types=1);

/** Destructive fixture ONLY in an empty localhost database named phpipam_dns10_test. */
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$root=dirname(__DIR__,2);
$migration=$root.'/database/dns10/001-zone-snapshots.sql';
if (!is_file($migration)) throw new RuntimeException('snapshot_schema_missing');
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
$pdo->exec(file_get_contents($migration));

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
echo 'DNS10_MYSQL_ASSERTIONS='.$n."\n";
echo "DNS10_MYSQL_TEST_OK=1\n";
