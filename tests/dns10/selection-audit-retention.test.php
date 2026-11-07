<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationRepository.php';
require $root.'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationPhpIpamRepository.php';
require $root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionRepository.php';
require $root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionPhpIpamRepository.php';

$n=0;
function auditCheck10(bool $ok,string $label): void {global $n;++$n;if(!$ok)throw new RuntimeException($label);}
final class SelectionAuditDbFixture {
    public array $events=[];
    public int $datasetRevision=1,$nextId=1,$commits=0,$rollbacks=0;
    public ?string $failure=null;
    private array $snapshot=[];
    public function beginTransaction(): bool {$this->snapshot=[$this->events,$this->datasetRevision,$this->nextId];return true;}
    public function commit(): bool {++$this->commits;return true;}
    public function rollBack(): bool {[$this->events,$this->datasetRevision,$this->nextId]=$this->snapshot;++$this->rollbacks;return true;}
    public function getObjectsQuery($table,$sql=null,$params=[],$class='stdClass'): mixed {
        if($sql==='SELECT id FROM custom_dns_config_guard WHERE id = 1 FOR UPDATE')return [(object)['id'=>1]];
        if($sql==='SELECT event_id FROM custom_dns_config_events ORDER BY event_id DESC LIMIT 1 OFFSET 999') {
            if($this->failure==='floor')return false;
            if($this->failure==='malformed')return [(object)['event_id'=>'invalid']];
            $count=count($this->events);
            return $count<1000?[]:[(object)['event_id'=>(string)$this->events[$count-1000]['event_id']]];
        }
        throw new RuntimeException('unexpected query');
    }
    public function runQuery($sql,$params=[]): bool {
        if($sql==='INSERT INTO custom_dns_config_events (action,object_id,actor_id,created_at) VALUES (?,?,?,?)') {
            $this->events[]=['event_id'=>$this->nextId++,'action'=>$params[0]];
            return $this->failure!=='insert';
        }
        if($sql==='DELETE FROM custom_dns_config_events WHERE event_id < ?') {
            $this->events=array_values(array_filter($this->events,fn($event)=>$event['event_id'] >= $params[0]));
            return $this->failure!=='delete';
        }
        throw new RuntimeException('unexpected write');
    }
    public function seed(int $count): void {
        for($i=0;$i<$count;$i++)$this->events[]=['event_id'=>$this->nextId++,'action'=>'unrelated_example'];
    }
}
$db=new SelectionAuditDbFixture();
$repo=new IntegrationDnsZoneSelectionPhpIpamRepository($db);
$event=['action'=>'zone_selected','object_id'=>str_repeat('a',32),'actor_id'=>17,'created_at'=>100];
try {$repo->audit($event);throw new RuntimeException('outside transaction accepted');}
catch(DomainException $e) {auditCheck10($e->getMessage()==='selection_unavailable','transaction required');}
$db->seed(998);
$repo->transaction(function() use($repo,$event): void {$repo->audit($event);});
auditCheck10(count($db->events)===999,'below limit retains all');
$repo->transaction(function() use($repo,$event): void {$repo->audit($event);});
auditCheck10(count($db->events)===1000 && $db->events[0]['event_id']===1,'exact limit retains oldest');
$db->events[]=['event_id'=>$db->nextId++,'action'=>'unrelated_example'];
$db->events[]=['event_id'=>$db->nextId++,'action'=>'unrelated_example'];
$repo->transaction(function() use($repo,$event,$db): void {++$db->datasetRevision;$repo->audit($event);});
auditCheck10(count($db->events)===1000,'over limit pruned to cap');
auditCheck10($db->events[0]['event_id']===4 && end($db->events)['event_id']===1003,'latest event IDs retained');
auditCheck10($db->events[998]['action']==='unrelated_example' && end($db->events)['action']==='zone_selected','recent unrelated and selection events retained');
auditCheck10($db->datasetRevision===2,'dataset change committed with event');
foreach(['zone_deselected','zone_collection_enabled','zone_collection_disabled'] as $action) {
    $next=$event;$next['action']=$action;
    $repo->transaction(function() use($repo,$next): void {$repo->audit($next);});
    auditCheck10(count($db->events)===1000 && end($db->events)['action']===$action,'every selection action retains the cap');
}
foreach(['floor','malformed','insert','delete'] as $failure) {
    $before=[$db->events,$db->datasetRevision,$db->nextId];$db->failure=$failure;$rollbacks=$db->rollbacks;
    try {$repo->transaction(function() use($repo,$event,$db): void {++$db->datasetRevision;$repo->audit($event);});
        throw new RuntimeException('failure accepted');
    } catch(DomainException $e) {auditCheck10($e->getMessage()==='selection_unavailable','failure closed');}
    auditCheck10([$db->events,$db->datasetRevision,$db->nextId]===$before,'failure restores dataset and audit state');
    auditCheck10($db->rollbacks===$rollbacks+1,'failure rolled back');
}
printf("DNS10_SELECTION_AUDIT_ASSERTIONS=%d\nDNS10_SELECTION_AUDIT_TEST_OK=1\n",$n);
