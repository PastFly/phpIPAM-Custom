<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require $root.'/layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php';
require $root.'/tests/dns1/memory-repository.php';
require $root.'/tests/dns3/control-memory-repository.php';
// Configuration service is unused here; production admin and control services run unchanged.
final class IntegrationDnsConfigService {}
final class EnableHookControl implements IntegrationDnsCollectorControlRepository {
    public ?Closure $afterRelease=null,$beforeAcquire=null;public bool $held=false;public int $hookCalls=0,$transactions=0;
    public function __construct(public Dns3MemoryRepository $inner) {}
    public function transaction(callable $work): mixed {
        if($this->beforeAcquire!==null) {$hook=$this->beforeAcquire;$this->beforeAcquire=null;$hook();}
        ++$this->transactions;
        $result=$this->inner->transaction(function() use($work): mixed {
            $this->held=true;try{return $work();}finally{$this->held=false;}
        });
        if($this->afterRelease!==null) {$hook=$this->afterRelease;$this->afterRelease=null;++$this->hookCalls;$hook();}
        return $result;
    }
    public function ownsTransactionFor(object $db): bool {return $this->inner->ownsTransactionFor($db);}
    public function collectors(): array {return $this->inner->collectors();}
    public function collector(string $id): ?array {return $this->inner->collector($id);}
    public function putCollector(array $row): void {$this->inner->putCollector($row);}
    public function assignments(): array {return $this->inner->assignments();}
    public function assignment(string $id): ?array {return $this->inner->assignment($id);}
    public function putAssignment(array $row): void {$this->inner->putAssignment($row);}
    public function removeAssignment(string $id): void {$this->inner->removeAssignment($id);}
    public function jobs(): array {return $this->inner->jobs();}
    public function putJob(array $row): void {$this->inner->putJob($row);}
    public function removeJob(string $id): void {$this->inner->removeJob($id);}
    public function audit(array $event): void {$this->inner->audit($event);}
}
$n=0;function enableCheck(bool $ok,string $label): void {global $n;++$n;if(!$ok)throw new RuntimeException($label);}
$id=str_repeat('a',32);$collectorId=str_repeat('b',32);
$configRepo=new DnsMemoryRepository();$inner=new Dns3MemoryRepository();$inner->joinedConfig=$configRepo;
$hookRepo=new EnableHookControl($inner);
$configRepo->connections[$id]=['id'=>$id,'enabled'=>false,'status'=>'disabled','revision'=>7,'binding_epoch'=>3,
    'credential_mode'=>'collector_identity','profile_id'=>null,'updated_at'=>1,'updated_by'=>1];
$inner->collectors[$collectorId]=['id'=>$collectorId,'revision'=>1,'control_epoch'=>1,'revoked'=>false];
$inner->assignments[$id]=['connection_id'=>$id,'collector_id'=>$collectorId,'revision'=>1];
$control=new IntegrationDnsCollectorControlService($hookRepo,static fn($unused)=>null,static fn()=>100);
$admin=new IntegrationDnsAutonomousAdminService(new IntegrationDnsConfigService(),$configRepo,$control);
$hookRepo->afterRelease=function() use($hookRepo,$inner,$configRepo,$admin,$collectorId): void {
    enableCheck(!$hookRepo->held && !$inner->ownsTransactionFor($configRepo),'interleave must follow actual guard release');
    $admin->revokeCollector($collectorId,1,17);
};
$result=null;$error=null;
try {$result=$admin->setCollectionEnabled($id,7,true,17);} catch(DomainException $e) {$error=$e->getMessage();}
$row=$configRepo->connections[$id];
enableCheck($hookRepo->hookCalls===1,'interleave happened once');
enableCheck(!$row['enabled'] && $row['status']==='collector_revoked','revoked collector must not leave enabled connection');
enableCheck(!isset($inner->assignments[$id]) && $inner->collectors[$collectorId]['revoked'],'revoke took effect');
if($error===null) {
    enableCheck($result['enabled']===true && $result['revision']===8 && $row['revision']===9,'enable then revoke is a valid ordering');
} else {
    enableCheck($error==='assignment_unavailable' && $row['revision']===7,'revoke then rejected enable is a valid ordering');
}
$reset=function() use($configRepo,$inner,$id,$collectorId): void {
    $configRepo->connections[$id]=['id'=>$id,'enabled'=>false,'status'=>'disabled','revision'=>7,'binding_epoch'=>3,
        'credential_mode'=>'collector_identity','profile_id'=>null,'updated_at'=>1,'updated_by'=>1];
    $configRepo->events=[];$configRepo->failAt=null;$inner->events=[];$inner->failAt=null;$inner->joinedConfig=$configRepo;
    $inner->collectors[$collectorId]=['id'=>$collectorId,'revision'=>1,'control_epoch'=>1,'revoked'=>false];
    $inner->assignments[$id]=['connection_id'=>$id,'collector_id'=>$collectorId,'revision'=>1];
};
$reset();
$hookRepo->beforeAcquire=function() use($hookRepo,$admin,$collectorId): void {
    enableCheck(!$hookRepo->held,'revoke before acquisition starts outside guard');
    $admin->revokeCollector($collectorId,1,17);
};
try {$admin->setCollectionEnabled($id,7,true,17);throw new RuntimeException('enable_after_revoke_accepted');}
catch(DomainException $e) {enableCheck($e->getMessage()==='assignment_unavailable','revoke before enable rejects');}
enableCheck(!$configRepo->connections[$id]['enabled'] && !isset($inner->assignments[$id]),'revoke first preserves disabled state');
$reset();$before=[$configRepo->connections,$configRepo->events,$inner->collectors,$inner->assignments,$inner->events];
try {$admin->setCollectionEnabled($id,99,true,17);throw new RuntimeException('stale_revision_accepted');}
catch(DomainException $e) {enableCheck($e->getMessage()==='revision_conflict','stale revision rejected');}
enableCheck([$configRepo->connections,$configRepo->events,$inner->collectors,$inner->assignments,$inner->events]===$before,'stale revision changes nothing');
$configRepo->failAt=2;
try {$admin->setCollectionEnabled($id,7,true,17);throw new RuntimeException('audit_failure_missing');}
catch(RuntimeException $e) {enableCheck($e->getMessage()==='injected_write_failure','audit failure propagated');}
enableCheck([$configRepo->connections,$configRepo->events,$inner->collectors,$inner->assignments,$inner->events]===$before,'audit failure rolls back config and control state');
$configRepo->failAt=null;$inner->joinedConfig=null;
try {$admin->setCollectionEnabled($id,7,true,17);throw new RuntimeException('unshared_db_accepted');}
catch(DomainException $e) {enableCheck($e->getMessage()==='configuration_unavailable','unshared transaction rejected');}
enableCheck([$configRepo->connections,$configRepo->events,$inner->collectors,$inner->assignments,$inner->events]===$before,'unshared transaction changes nothing');
$reset();unset($inner->assignments[$id]);
try {$admin->setCollectionEnabled($id,7,true,17);throw new RuntimeException('missing_assignment_accepted');}
catch(DomainException $e) {enableCheck($e->getMessage()==='assignment_unavailable','missing assignment rejected');}
enableCheck(!$configRepo->connections[$id]['enabled'],'missing assignment leaves connection disabled');
$reset();$inner->collectors[$collectorId]['revoked']=true;
try {$admin->setCollectionEnabled($id,7,true,17);throw new RuntimeException('revoked_collector_accepted');}
catch(DomainException $e) {enableCheck($e->getMessage()==='collector_unavailable','revoked collector rejected');}
enableCheck(!$configRepo->connections[$id]['enabled'],'revoked collector leaves connection disabled');
$reset();$inner->assignments[$id]['collector_id']=null;
try {$admin->setCollectionEnabled($id,7,true,17);throw new RuntimeException('invalid_collector_accepted');}
catch(DomainException $e) {enableCheck($e->getMessage()==='collector_unavailable','invalid collector rejected safely');}
enableCheck(!$configRepo->connections[$id]['enabled'],'invalid collector leaves connection disabled');
$reset();unset($inner->assignments[$id]);
$configRepo->connections[$id]['enabled']=true;$configRepo->connections[$id]['status']='active';
$transactions=$hookRepo->transactions;
$disabled=$admin->setCollectionEnabled($id,7,false,17);
enableCheck(!$disabled['enabled'] && $disabled['revision']===8,'disable succeeds without assignment');
enableCheck($hookRepo->transactions===$transactions,'disable retains config-only transaction');
printf("DNS9_ENABLE_GUARD_ASSERTIONS=%d\nDNS9_ENABLE_GUARD_TEST_OK=1\n",$n);
