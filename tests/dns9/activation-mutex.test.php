<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php';
require $root.'/tests/dns1/memory-repository.php';
require $root.'/tools/live/dns9-activation-mutex.php';

final class Dns9MutexFixtureDb {
    public bool $unsafe=false;
    public function getObjectsQuery($mode,$sql,$params=[]): array {
        if(str_contains($sql,'AS queue_state')) return [(object)['queue_state'=>$this->unsafe?'3|0|0|0|0|2':'2|0|0|0|0|2']];
        if(str_contains($sql,'FROM users')) return [(object)['id'=>4]];
        if(str_contains($sql,'FROM custom_dns_control_jobs')) return array_map(static fn($id)=>(object)['id'=>$id],DNS9_ACTIVATION_PENDING_IDS);
        if(str_contains($sql,'FROM custom_dns_control_events')) return [(object)['event_id'=>1,'action'=>'control_job_queued','object_id'=>DNS9_ACTIVATION_PENDING_IDS[0],'actor_id'=>4,'created_at'=>1]];
        throw new RuntimeException('unexpected_fixture_query');
    }
}
function dns9MutexAssert(bool $condition,string $label): void {if(!$condition) throw new RuntimeException($label);}
function dns9MutexFixture(string $command,bool $unsafe=false,?int $failAt=null): array {
    $db=new Dns9MutexFixtureDb(); $db->unsafe=$unsafe;
    $repo=new DnsMemoryRepository();
    $repo->failAt=$failAt;
    $id=DNS9_ACTIVATION_CONNECTION;
    $repo->connections[$id]=['id'=>$id,'provider'=>'microsoft_dns','name'=>'fixture','server'=>'srv-dc01.rbank.local',
        'interval_seconds'=>300,'credential_mode'=>'collector_identity','profile_id'=>null,'collector_id'=>null,'enabled'=>false,
        'status'=>'disabled','revision'=>7,'binding_epoch'=>4,'updated_at'=>1,'updated_by'=>4];
    $dir=sys_get_temp_dir().'/dns9-mutex-test-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
    $in=fopen('php://temp','w+'); fwrite($in,$command); rewind($in); $out=fopen('php://temp','w+');
    try {
        try {$result=dns9ActivationMutex($db,$repo,$dir,$in,$out); $error=null;}
        catch(Throwable $e) {$result=null;$error=$e->getMessage();}
        rewind($out); $output=stream_get_contents($out);
        $backup=is_file($dir.'/prestate.json')?json_decode(file_get_contents($dir.'/prestate.json'),true,32,JSON_THROW_ON_ERROR):null;
        return [$repo,$result,$error,$output,$backup];
    } finally {fclose($in);fclose($out);if(is_file($dir.'/prestate.json')) unlink($dir.'/prestate.json');rmdir($dir);}
}
[$repo,$result,$error,$output,$backup]=dns9MutexFixture("COMMIT_ENABLE\n");
dns9MutexAssert($error===null && $result===['revision'=>8,'binding_epoch'=>5],'approved commit succeeds');
dns9MutexAssert($repo->connections[DNS9_ACTIVATION_CONNECTION]['enabled']===true,'connection enabled');
dns9MutexAssert(count($repo->events)===1 && $repo->events[0]['action']==='collection_enabled','change is audited');
dns9MutexAssert(is_array($backup) && count($backup['jobs'])===2 && $backup['connection']['enabled']===false,'prestate backed up before commit');
dns9MutexAssert(str_contains($output,'DNS9_ACTIVATION_MUTEX_READY='),'readiness reported');
[$repo,$result,$error,$output,$backup]=dns9MutexFixture("ABORT\n");
dns9MutexAssert($error==='activation_cancelled' && $repo->connections[DNS9_ACTIVATION_CONNECTION]['enabled']===false,'abort rolls back');
dns9MutexAssert(is_array($backup) && count($repo->events)===0,'abort retains backup but does not write audit');
[$repo,$result,$error,$output,$backup]=dns9MutexFixture("COMMIT_ENABLE\n",true);
dns9MutexAssert($error==='queue_or_retention_requires_review' && $backup===null,'unknown queue blocks before backup and change');
[$repo,$result,$error,$output,$backup]=dns9MutexFixture("COMMIT_ENABLE\n",false,2);
dns9MutexAssert($error==='injected_write_failure' && $repo->connections[DNS9_ACTIVATION_CONNECTION]['enabled']===false,'audit failure rolls back connection change');
dns9MutexAssert(count($repo->events)===0 && is_array($backup),'audit failure retains backup without audit');
echo "DNS9_ACTIVATION_MUTEX_TEST_OK=1\n";
