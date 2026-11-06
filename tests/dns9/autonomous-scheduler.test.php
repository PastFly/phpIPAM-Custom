<?php
declare(strict_types=1);

require_once(__DIR__.'/../../layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php');
require_once(__DIR__.'/../../layers/dns9/functions/classes/class.IntegrationDnsAutonomousScheduler.php');

final class Dns9MemoryRepo implements IntegrationDnsCollectorControlRepository
{
    public array $collectors=[];
    public array $assignments=[];
    public array $jobs=[];
    public array $events=[];

    public function transaction(callable $work): mixed { return $work(); }
    public function collectors(): array { return array_values($this->collectors); }
    public function collector(string $id): ?array { return $this->collectors[$id]??null; }
    public function putCollector(array $row): void { $this->collectors[$row['id']]=$row; }
    public function assignments(): array { return array_values($this->assignments); }
    public function assignment(string $connectionId): ?array { return $this->assignments[$connectionId]??null; }
    public function putAssignment(array $row): void { $this->assignments[$row['connection_id']]=$row; }
    public function removeAssignment(string $connectionId): void { unset($this->assignments[$connectionId]); }
    public function jobs(): array { return array_values($this->jobs); }
    public function putJob(array $row): void { $this->jobs[$row['id']]=$row; }
    public function removeJob(string $id): void { unset($this->jobs[$id]); }
    public function audit(array $event): void { $this->events[]=$event; }
    public function autonomousJobIds(): array {
        $ids=[];
        foreach($this->events as $event) {
            if(($event['action']??null)==='autonomous_job_queued' && ($event['actor_id']??null)===0) $ids[$event['object_id']]=true;
        }
        return $ids;
    }
}

$n=0;
function ok(bool $v,string $label): void {
    global $n;
    $n++;
    if(!$v) throw new RuntimeException('FAIL '.$label);
}

$connection=str_repeat('a',32);
$collector=str_repeat('b',32);
$now=2000000000;
$repo=new Dns9MemoryRepo();
$repo->collectors[$collector]=[
    'id'=>$collector,'revoked'=>false
];
$repo->assignments[$connection]=[
    'connection_id'=>$connection,'collector_id'=>$collector,'revision'=>3,'assigned_at'=>$now-100,'assigned_by'=>1
];

$base=[
    'id'=>$connection,
    'enabled'=>true,
    'interval_seconds'=>300,
    'revision'=>7,
    'binding_epoch'=>4,
    'credential_mode'=>'profile',
    'credential_version'=>9
];

$scheduler=new IntegrationDnsAutonomousScheduler($repo,static function() use (&$now): int { return $now; });
$r=$scheduler->run([$base]);
ok($r['queued_count']===1,'first due job queued');
ok(count($repo->jobs)===1,'one job persisted');
$job=array_values($repo->jobs)[0];
ok($job['job_type']==='list_zones','list zones only');
ok($job['state']==='pending','pending state');
ok($job['collector_id']===$collector,'assigned collector');
ok($job['assignment_revision']===3,'assignment revision');
ok($job['configuration_revision']===7,'config revision');
ok($job['binding_epoch']===4,'binding epoch');
ok($job['credential_version']===9,'credential version');
ok($job['attempt_count']===0,'attempt zero');
ok($job['expires_at']===$now+300,'bounded expiry');
ok($job['updated_by']===0,'system actor');
ok(count($repo->events)===1 && $repo->events[0]['action']==='autonomous_job_queued','audit queued');

$r=$scheduler->run([$base]);
ok($r['queued_count']===0 && $r['skipped_active']===1,'active job deduplicated');

$job['state']='succeeded';
$job['finished_at']=$now+10;
$job['updated_at']=$now+10;
$job['result_code']='ok';
$repo->jobs[$job['id']]=$job;
$now+=100;
$r=$scheduler->run([$base]);
ok($r['queued_count']===0 && $r['skipped_interval']===1,'interval respected');

$now+=300;
$r=$scheduler->run([$base]);
ok($r['queued_count']===1,'next interval queues');

$disabled=$base;
$disabled['enabled']=false;
$now+=1000;
$r=$scheduler->run([$disabled]);
ok($r['queued_count']===0 && $r['skipped_disabled']===1,'disabled skipped');

$repo2=new Dns9MemoryRepo();
$scheduler2=new IntegrationDnsAutonomousScheduler($repo2,static fn(): int=>2000000000);
$r=$scheduler2->run([$base]);
ok($r['queued_count']===0 && $r['skipped_no_assignment']===1,'no assignment skipped');

$repo3=new Dns9MemoryRepo();
$repo3->collectors[$collector]=['id'=>$collector,'revoked'=>true];
$repo3->assignments[$connection]=['connection_id'=>$connection,'collector_id'=>$collector,'revision'=>1,'assigned_at'=>1,'assigned_by'=>1];
$scheduler3=new IntegrationDnsAutonomousScheduler($repo3,static fn(): int=>2000000000);
$r=$scheduler3->run([$base]);
ok($r['queued_count']===0 && $r['skipped_collector']===1,'revoked collector skipped');

$identity=$base;
$identity['credential_mode']='collector_identity';
$identity['credential_version']=null;
$repo4=new Dns9MemoryRepo();
$repo4->collectors[$collector]=['id'=>$collector,'revoked'=>false];
$repo4->assignments[$connection]=['connection_id'=>$connection,'collector_id'=>$collector,'revision'=>1,'assigned_at'=>1,'assigned_by'=>1];
$scheduler4=new IntegrationDnsAutonomousScheduler($repo4,static fn(): int=>2000000000);
$r=$scheduler4->run([$identity]);
ok($r['queued_count']===1,'collector identity supported');
$identityJob=array_values($repo4->jobs)[0];
ok($identityJob['credential_version']===null,'collector identity has no credential version');

$repo5=new Dns9MemoryRepo();
$repo5->collectors[$collector]=['id'=>$collector,'revoked'=>false];
$repo5->assignments[$connection]=['connection_id'=>$connection,'collector_id'=>$collector,'revision'=>1,'assigned_at'=>1,'assigned_by'=>1];
$staleId=str_repeat('c',32);
$repo5->jobs[$staleId]=[
    'id'=>$staleId,'collector_id'=>$collector,'connection_id'=>$connection,'assignment_revision'=>1,
    'job_type'=>'list_zones','state'=>'pending','configuration_revision'=>7,'binding_epoch'=>4,
    'credential_version'=>9,'lease_id'=>null,'lease_expires_at'=>null,'attempt_count'=>0,
    'credential_delivered_at'=>null,'result_code'=>null,'result_payload'=>null,'finished_at'=>null,
    'created_at'=>1999999000,'expires_at'=>1999999300,'updated_at'=>1999999000,'updated_by'=>0
];
$oldId=str_repeat('d',32);
$repo5->jobs[$oldId]=[
    'id'=>$oldId,'collector_id'=>$collector,'connection_id'=>$connection,'assignment_revision'=>1,
    'job_type'=>'test_connection','state'=>'succeeded','configuration_revision'=>7,'binding_epoch'=>4,
    'credential_version'=>9,'lease_id'=>null,'lease_expires_at'=>null,'attempt_count'=>1,
    'credential_delivered_at'=>1999800000,'result_code'=>'ok','result_payload'=>null,'finished_at'=>1999800000,
    'created_at'=>1999800000,'expires_at'=>1999800300,'updated_at'=>1999800000,'updated_by'=>0
];
$scheduler5=new IntegrationDnsAutonomousScheduler($repo5,static fn(): int=>2000000000);
$r=$scheduler5->run([$base]);
ok(!isset($repo5->jobs[$oldId]),'old terminal job pruned');
ok($repo5->jobs[$staleId]['state']==='expired' && $repo5->jobs[$staleId]['result_code']==='job_expired','stale pending job expired');
ok($r['queued_count']===1,'scheduler recovers after stale pending job');

$repo6=new Dns9MemoryRepo();
$repo6->collectors[$collector]=['id'=>$collector,'revoked'=>false];
$second=str_repeat('e',32);
foreach([$connection,$second] as $id) $repo6->assignments[$id]=['connection_id'=>$id,'collector_id'=>$collector,'revision'=>1,'assigned_at'=>1,'assigned_by'=>1];
$contexts=[$identity,$identity]; $contexts[1]['id']=$second;
$tick=2000000000;
$scheduler6=new IntegrationDnsAutonomousScheduler($repo6,static function() use (&$tick): int {return $tick;});
// 26 hours with two five-minute sources previously exceeded the 512-job ceiling.
for ($i=0;$i<26*60;$i++,$tick+=60) {
    $result=$scheduler6->run($contexts);
    ok(($result['skipped_capacity']??-1)===0,'normal load keeps making progress');
    foreach ($result['queued'] as $jobId) {
        $repo6->jobs[$jobId]['state']='succeeded';
        $repo6->jobs[$jobId]['result_code']='ok';
        $repo6->jobs[$jobId]['finished_at']=$tick;
        $repo6->jobs[$jobId]['updated_at']=$tick;
    }
}
ok(count($repo6->jobs)<512,'autonomous history remains bounded');
foreach([$connection,$second] as $id) {
    $success=array_filter($repo6->jobs,static fn($j)=>$j['connection_id']===$id && $j['state']==='succeeded');
    ok(count($success)>0,'last successful result for each source is preserved');
}

$repo7=new Dns9MemoryRepo();
$repo7->collectors[$collector]=['id'=>$collector,'revoked'=>false];
$repo7->assignments[$connection]=['connection_id'=>$connection,'collector_id'=>$collector,'revision'=>1,'assigned_at'=>1,'assigned_by'=>1];
// Unknown-origin terminal jobs are retained; hitting capacity is visible and never deletes them.
for ($i=0;$i<512;$i++) {
    $id=str_pad(dechex($i+1),32,'0',STR_PAD_LEFT);
    $repo7->jobs[$id]=['id'=>$id,'collector_id'=>$collector,'connection_id'=>$connection,'job_type'=>'test_connection',
        'state'=>'failed','finished_at'=>2000000000-4000,'updated_at'=>2000000000-4000,'created_at'=>2000000000-4000];
}
$scheduler7=new IntegrationDnsAutonomousScheduler($repo7,static fn(): int=>2000000000);
$full=$scheduler7->run([$identity]);
ok($full['skipped_capacity']===1 && $full['queued_count']===0,'unrecoverable capacity is reported');
ok(count($repo7->jobs)===512,'unknown-origin jobs preserved');

$repo8=new Dns9MemoryRepo();
$repo8->collectors[$collector]=['id'=>$collector,'revoked'=>false];
$repo8->assignments[$connection]=['connection_id'=>$connection,'collector_id'=>$collector,'revision'=>1,'assigned_at'=>1,'assigned_by'=>1];
$retentionNow=2000000000;
for ($i=0;$i<384;$i++) {
    $id=str_pad(dechex($i+1),32,'0',STR_PAD_LEFT);
    $finished=$retentionNow-8000+$i;
    $repo8->jobs[$id]=['id'=>$id,'collector_id'=>$collector,'connection_id'=>$connection,'job_type'=>'list_zones',
        'state'=>'succeeded','result_code'=>'ok','finished_at'=>$finished,'updated_at'=>$finished,'created_at'=>$finished];
    $repo8->events[]=['action'=>'autonomous_job_queued','object_id'=>$id,'actor_id'=>0,'created_at'=>$finished];
}
$lastAuto=str_pad(dechex(384),32,'0',STR_PAD_LEFT);
$manual=str_repeat('f',32);
$repo8->jobs[$manual]=['id'=>$manual,'collector_id'=>$collector,'connection_id'=>$connection,'job_type'=>'list_zones',
    'state'=>'succeeded','result_code'=>'ok','finished_at'=>$retentionNow-7999,'updated_at'=>$retentionNow-7999,'created_at'=>$retentionNow-7999];
$repo8->events[]=['action'=>'autonomous_job_queued','object_id'=>$manual,'actor_id'=>17,'created_at'=>$retentionNow-7999];
$scheduler8=new IntegrationDnsAutonomousScheduler($repo8,static fn(): int=>2000000000);
$disabledIdentity=$identity; $disabledIdentity['enabled']=false;
$trim=$scheduler8->run([$disabledIdentity]);
ok($trim['pressure_pruned']>0,'verified automatic history is trimmed under pressure');
ok(isset($repo8->jobs[$manual]),'manual result retained under pressure');
ok(isset($repo8->jobs[$lastAuto]),'newest successful zone result retained under pressure');
ok(count(array_filter($repo8->events,static fn($e)=>$e['action']==='autonomous_job_pruned'))===$trim['pressure_pruned'],'pressure cleanup is audited');

$fast=$identity; $fast['interval_seconds']=60;
$repo9=new Dns9MemoryRepo();
$repo9->collectors[$collector]=['id'=>$collector,'revoked'=>false];
$repo9->assignments[$connection]=['connection_id'=>$connection,'collector_id'=>$collector,'revision'=>1,'assigned_at'=>1,'assigned_by'=>1];
$fastNow=2000000000;
$scheduler9=new IntegrationDnsAutonomousScheduler($repo9,static function() use (&$fastNow): int {return $fastNow;});
for ($i=0;$i<12*60;$i++,$fastNow+=60) {
    $result=$scheduler9->run([$fast]);
    ok($result['skipped_capacity']===0,'one-minute source keeps making progress');
    foreach ($result['queued'] as $jobId) {
        $repo9->jobs[$jobId]['state']='failed';
        $repo9->jobs[$jobId]['result_code']='network';
        $repo9->jobs[$jobId]['finished_at']=$fastNow;
        $repo9->jobs[$jobId]['updated_at']=$fastNow;
    }
}
ok(count($repo9->jobs)<512,'failed automatic jobs also remain bounded');

$futureRepo=new Dns9MemoryRepo();
$futurePending=str_repeat('1',32);$futureTerminal=str_repeat('2',32);
$futureRepo->jobs[$futurePending]=['id'=>$futurePending,'connection_id'=>$connection,'collector_id'=>$collector,
    'job_type'=>'collect_selected_zones','state'=>'pending','expires_at'=>1999999999,'updated_at'=>1999999999];
$futureRepo->jobs[$futureTerminal]=['id'=>$futureTerminal,'connection_id'=>$connection,'collector_id'=>$collector,
    'job_type'=>'collect_selected_zones','state'=>'succeeded','finished_at'=>1999900000,'updated_at'=>1999900000];
$futureScheduler=new IntegrationDnsAutonomousScheduler($futureRepo,static fn(): int=>2000000000);
$futureScheduler->run([$disabledIdentity]);
ok(($futureRepo->jobs[$futurePending]['state']??null)==='pending','legacy scheduler does not expire unknown job types');
ok(isset($futureRepo->jobs[$futureTerminal]),'legacy scheduler does not prune unknown job types');

echo "DNS9_AUTONOMOUS_SCHEDULER_ASSERTIONS={$n}\n";
echo "DNS9_AUTONOMOUS_SCHEDULER_TESTS_OK=1\n";
