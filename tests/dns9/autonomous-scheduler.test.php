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

$scheduler=new IntegrationDnsAutonomousScheduler($repo,static fn(): int=>$now);
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

echo "DNS9_AUTONOMOUS_SCHEDULER_ASSERTIONS={$n}\n";
echo "DNS9_AUTONOMOUS_SCHEDULER_TESTS_OK=1\n";
