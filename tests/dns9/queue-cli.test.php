<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require $root.'/tests/dns3/control-memory-repository.php';
require $root.'/tools/live/dns9-queue-cli.php';
function dns9Test(bool $value,string $message): void {if (!$value) throw new RuntimeException($message);}
final class ActorDb {
    public array $rows=[];
    public function getObjectsQuery($mode,$sql,$args) {
        dns9Test(str_contains($sql,"u.disabled='No'") && str_contains($sql,"u.role='Administrator'"),'active admin required');
        dns9Test($args===[DNS9_OLD_JOB,DNS9_CONNECTION,DNS9_COLLECTOR],'exact previous job required');
        return $this->rows;
    }
}
$db=new ActorDb();
try {dns9PreviousActor($db); throw new RuntimeException('empty_actor_accepted');} catch (DomainException $e) {}
$db->rows=[(object)['actor_id'=>'17']]; dns9Test(dns9PreviousActor($db)===17,'actor verified');
$db->rows=[(object)['actor_id'=>'17'],(object)['actor_id'=>'17']];
try {dns9PreviousActor($db); throw new RuntimeException('ambiguous_actor_accepted');} catch (DomainException $e) {}
final class B4ActorDb {
    public array $rows=[];
    public function getObjectsQuery($mode,$sql,$args) {
        dns9Test(str_contains($sql,"u.disabled='No'") && str_contains($sql,"u.role='Administrator'"),'B4 active admin required');
        dns9Test(str_contains($sql,"control_job_cli_queued") && str_contains($sql,"control_job_queued"),'both queue audits required');
        dns9Test(str_contains($sql,"j.state='succeeded'") && str_contains($sql,"j.attempt_count=1"),'successful B3 required');
        dns9Test($args===[DNS9_SUCCESS_JOB,DNS9_CONNECTION,DNS9_COLLECTOR],'exact successful job required');
        return $this->rows;
    }
}
$b4db=new B4ActorDb();
try {dns9B4Actor($b4db); throw new RuntimeException('missing_b4_actor_accepted');} catch (DomainException $e) {}
$b4db->rows=[(object)['actor_id'=>'17']]; dns9Test(dns9B4Actor($b4db)===17,'B4 actor verified by audits');
$b4db->rows=[(object)['actor_id'=>'17'],(object)['actor_id'=>'17']];
try {dns9B4Actor($b4db); throw new RuntimeException('ambiguous_b4_actor_accepted');} catch (DomainException $e) {}
$inner=new Dns3MemoryRepository();
$inner->collectors[DNS9_COLLECTOR]=['id'=>DNS9_COLLECTOR,'revoked'=>false];
$inner->assignments[DNS9_CONNECTION]=['connection_id'=>DNS9_CONNECTION,'collector_id'=>DNS9_COLLECTOR,'revision'=>1];
$context=['id'=>DNS9_CONNECTION,'revision'=>2,'binding_epoch'=>3,'credential_mode'=>'collector_identity','credential_version'=>null];
$svc=new IntegrationDnsCollectorControlService(new Dns9CliAuditRepository($inner),static fn($id)=>$context,static fn()=>1700000000);
$inner->failAt=3;
try {$svc->queueJob(DNS9_CONNECTION,'test_connection',17); throw new RuntimeException('failure_not_injected');} catch (RuntimeException $e) {dns9Test($e->getMessage()==='injected_write_failure','audit failure injected');}
dns9Test(count($inner->jobs)===0 && count($inner->events)===0,'both audits and job rollback');
$inner->failAt=null; $job=$svc->queueJob(DNS9_CONNECTION,'test_connection',17);
dns9Test($job['collector_id']===DNS9_COLLECTOR && count($inner->jobs)===1,'one correct job');
dns9Test(array_column($inner->events,'action')===['control_job_queued','control_job_cli_queued'],'cli audit atomic');
try {$svc->queueJob(DNS9_CONNECTION,'test_connection',17); throw new RuntimeException('duplicate_accepted');} catch (DomainException $e) {dns9Test($e->getMessage()==='job_already_pending','duplicate denied');}
echo "DNS9_QUEUE_CLI_TEST_OK=1\n";
