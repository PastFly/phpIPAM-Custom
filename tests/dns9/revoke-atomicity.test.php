<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php';
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require $root.'/layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php';
require $root.'/tests/dns1/memory-repository.php';
require $root.'/tests/dns3/control-memory-repository.php';
// Configuration service is unused by revoke; its repository is exercised directly.
final class IntegrationDnsConfigService {}
$configRepo=new DnsMemoryRepository();$controlRepo=new Dns3MemoryRepository();
$controlRepo->joinedConfig=$configRepo;
$id=str_repeat('a',32);$connectionId=str_repeat('b',32);$jobId=str_repeat('c',32);
$configRepo->connections[$connectionId]=['id'=>$connectionId,'enabled'=>true,'status'=>'active','revision'=>3,'binding_epoch'=>4,'updated_at'=>1,'updated_by'=>1];
$controlRepo->collectors[$id]=['id'=>$id,'revision'=>2,'control_epoch'=>2,'revoked'=>false];
$controlRepo->assignments[$connectionId]=['connection_id'=>$connectionId,'collector_id'=>$id,'revision'=>1];
$controlRepo->jobs[$jobId]=['id'=>$jobId,'connection_id'=>$connectionId,'collector_id'=>$id,'state'=>'pending'];
$otherId=str_repeat('d',32);$otherConnection=str_repeat('e',32);$otherJob=str_repeat('f',32);
$configRepo->connections[$otherConnection]=['id'=>$otherConnection,'enabled'=>true,'status'=>'active','revision'=>1,'binding_epoch'=>1,'updated_at'=>1,'updated_by'=>1];
$controlRepo->collectors[$otherId]=['id'=>$otherId,'revision'=>1,'control_epoch'=>1,'revoked'=>false];
$controlRepo->assignments[$otherConnection]=['connection_id'=>$otherConnection,'collector_id'=>$otherId,'revision'=>1];
$controlRepo->jobs[$otherJob]=['id'=>$otherJob,'connection_id'=>$otherConnection,'collector_id'=>$otherId,'state'=>'pending'];
$control=new IntegrationDnsCollectorControlService($controlRepo,static fn($unused)=>null,static fn()=>100);
$admin=new IntegrationDnsAutonomousAdminService(new IntegrationDnsConfigService(),$configRepo,$control);
$state=static fn()=>[$configRepo->connections,$configRepo->events,$controlRepo->collectors,$controlRepo->assignments,$controlRepo->jobs,$controlRepo->events];
$before=$state();$n=0;
$check=static function(bool $ok,string $label) use(&$n): void {++$n;if(!$ok)throw new RuntimeException($label);};
$controlRepo->failAt=2;
try {$admin->revokeCollector($id,2,17);throw new RuntimeException('failure_missing');}
catch(RuntimeException $e) {if($e->getMessage()!=='injected_write_failure')throw $e;}
$check($state()===$before,'failed_revocation_changed_state');
$controlRepo->failAt=null;$configRepo->failAt=2;
try {$admin->revokeCollector($id,2,17);throw new RuntimeException('failure_missing');}
catch(RuntimeException $e) {$check($e->getMessage()==='injected_write_failure','configuration failure propagated');}
$check($state()===$before,'configuration failure rolls back all changes');
$configRepo->failAt=null;
try {$admin->revokeCollector($id,99,17);throw new RuntimeException('conflict_missing');}
catch(DomainException $e) {$check($e->getMessage()==='revision_conflict','stale revision rejected');}
$check($state()===$before,'stale revision leaves state unchanged');
try {$admin->revokeCollector(str_repeat('0',32),0,17);throw new RuntimeException('missing_collector_accepted');}
catch(DomainException $e) {$check($e->getMessage()==='not_found','missing collector error precedence preserved');}
$controlRepo->joinedConfig=null;
try {$admin->revokeCollector($id,2,17);throw new RuntimeException('mismatch_missing');}
catch(DomainException $e) {$check($e->getMessage()==='configuration_unavailable','unshared transaction rejected');}
$check($state()===$before,'unshared transaction leaves state unchanged');
$controlRepo->joinedConfig=$configRepo;
foreach([
    static function($repo,$revoke): void {},
    static function($repo,$revoke): void {$revoke();$revoke();}
] as $invalidCallback) {
    try {$control->revokeCollector($id,2,17,$invalidCallback);throw new RuntimeException('invalid_callback_accepted');}
    catch(DomainException $e) {$check($e->getMessage()==='control_unavailable','callback must revoke exactly once');}
    $check($state()===$before,'invalid callback rolls back control changes');
}
$admin->revokeCollector($id,2,17);
$connection=$configRepo->connections[$connectionId];$collector=$controlRepo->collectors[$id];
$check(!$connection['enabled'] && $connection['status']==='collector_revoked','success disables connection');
$check($connection['revision']===4 && $connection['binding_epoch']===5,'success increments connection versions once');
$check($collector['revoked'] && $collector['revision']===3 && $collector['control_epoch']===3,'success revokes collector once');
$check(!isset($controlRepo->assignments[$connectionId]) && $controlRepo->jobs[$jobId]['state']==='obsolete','success removes assignment and obsoletes job');
$check(count($configRepo->events)===1 && count($controlRepo->events)===1,'both audits committed');
$check($configRepo->connections[$otherConnection]===$before[0][$otherConnection] &&
    $controlRepo->collectors[$otherId]===$before[2][$otherId] && $controlRepo->assignments[$otherConnection]===$before[3][$otherConnection] &&
    $controlRepo->jobs[$otherJob]===$before[4][$otherJob],'unrelated collector state preserved');

// Exercise real adapter transaction guards against a shared SQL spy, not a database.
final class RevokeJoinDbSpy {
    public array $events=[],$old=[];
    public bool $failControl=false,$failCommit=false,$failRollback=false;
    public int $begins=0,$commits=0,$rollbacks=0;
    public function beginTransaction(): bool {$this->old=$this->events;++$this->begins;return true;}
    public function commit(): bool {++$this->commits;return !$this->failCommit;}
    public function rollBack(): bool {++$this->rollbacks;if($this->failRollback)return false;$this->events=$this->old;return true;}
    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        if($sql==='SELECT id FROM custom_dns_config_guard WHERE id = 1 FOR UPDATE')return [(object)['id'=>1]];
        if(str_contains($sql,'SELECT event_id FROM custom_dns_') && str_contains($sql,'OFFSET'))return [];
        throw new RuntimeException('unexpected_query');
    }
    public function runQuery($sql,$values=[]): bool {
        if(str_contains($sql,'INSERT INTO custom_dns_config_events')) {$this->events[]='config';return true;}
        if(str_contains($sql,'INSERT INTO custom_dns_control_events')) {$this->events[]='control';return !$this->failControl;}
        throw new RuntimeException('unexpected_write');
    }
}
$db=new RevokeJoinDbSpy();$sqlConfig=new IntegrationDnsPhpIpamConfigRepository($db);
$sqlControl=new IntegrationDnsCollectorControlPhpIpamRepository($db);
$audit=['action'=>'fixture','object_id'=>$id,'actor_id'=>17,'created_at'=>100];
$joined=static function() use($sqlControl,$sqlConfig,$audit): void {
    $sqlControl->transaction(function() use($sqlControl,$sqlConfig,$audit): void {
        $sqlConfig->joinControlTransaction($sqlControl,function() use($sqlConfig,$sqlControl,$audit): void {
            $sqlConfig->audit($audit);$sqlControl->audit($audit);
        });
    });
};
$joined();$check($db->events===['config','control'] && $db->begins===1 && $db->commits===1,'shared DB commits once');
$db->failControl=true;
try {$joined();throw new RuntimeException('sql_failure_missing');}catch(DomainException $e) {$check($e->getMessage()==='control_unavailable','control write failure propagated');}
$check($db->events===['config','control'] && $db->rollbacks===1,'shared DB rollback restores both audit writes');
$db->failControl=false;$db->failCommit=true;
try {$joined();throw new RuntimeException('commit_failure_missing');}catch(DomainException $e) {$check($e->getMessage()==='control_unavailable','commit failure propagated');}
$check($db->events===['config','control'] && $db->rollbacks===2,'failed commit attempts rollback');
$db->failRollback=true;
try {$joined();throw new RuntimeException('rollback_failure_missing');}catch(DomainException $e) {$check($e->getMessage()==='control_unavailable','rollback failure leaves original error');}
$check(count($db->events)===4 && $db->rollbacks===3,'failed rollback leaves outcome uncertain');
try {$sqlConfig->joinControlTransaction($sqlControl,static fn()=>null);throw new RuntimeException('unguarded_join');}
catch(DomainException $e) {$check($e->getMessage()==='configuration_unavailable','join outside transaction rejected');}
$otherConfig=new IntegrationDnsPhpIpamConfigRepository(new RevokeJoinDbSpy());
try {$sqlControl->transaction(fn()=> $otherConfig->joinControlTransaction($sqlControl,static fn()=>null));throw new RuntimeException('different_db_join');}
catch(DomainException $e) {$check($e->getMessage()==='configuration_unavailable','different DB rejected');}
printf("DNS9_REVOKE_ATOMIC_ASSERTIONS=%d\nDNS9_REVOKE_ATOMIC_TEST_OK=1\n",$n);
