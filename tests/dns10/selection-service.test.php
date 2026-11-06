<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$interface=$root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionRepository.php';
$servicePath=$root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionService.php';
if (!is_file($interface) || !is_file($servicePath)) throw new RuntimeException('zone_selection_service_missing');
require $interface;require $servicePath;require $root.'/tests/dns10/selection-memory-repository.php';
$n=0;
function selectionCheck10(bool $ok,string $label): void {global $n;++$n;if(!$ok)throw new RuntimeException($label);}
function selectionReject10(callable $fn,string $code): void {
    try {$fn();} catch(DomainException $e) {selectionCheck10($e->getMessage()===$code,'expected '.$code);return;}
    throw new RuntimeException('not_rejected: '.$code);
}
function selectionFixture10(): array {
    $repo=new Dns10SelectionMemoryRepository();$source=str_repeat('a',32);$collector=str_repeat('b',32);$job=str_repeat('c',32);$now=2000000000;
    $repo->sources[$source]=['id'=>$source,'enabled'=>true,'revision'=>4,'binding_epoch'=>7,'credential_version'=>null,'interval_seconds'=>300];
    $repo->collectors[$collector]=['id'=>$collector,'revoked'=>false];
    $repo->assignments[$source]=['connection_id'=>$source,'collector_id'=>$collector,'revision'=>6];
    $repo->discoveries[$source]=['id'=>$job,'connection_id'=>$source,'collector_id'=>$collector,'assignment_revision'=>6,
        'configuration_revision'=>4,'binding_epoch'=>7,'credential_version'=>null,'job_type'=>'list_zones','state'=>'succeeded',
        'attempt_count'=>1,'lease_id'=>null,'lease_expires_at'=>null,'credential_delivered_at'=>$now-15,'result_code'=>'ok','finished_at'=>$now-10,'zones'=>[
            ['name'=>'example.test','zone_type'=>'Primary','ds_integrated'=>true,'reverse_lookup'=>false],
            ['name'=>'2.0.192.in-addr.arpa','zone_type'=>'Secondary','ds_integrated'=>false,'reverse_lookup'=>true],
            ['name'=>'stub.test','zone_type'=>'Stub','ds_integrated'=>false,'reverse_lookup'=>false],
            ['name'=>'forward.test','zone_type'=>'Forwarder','ds_integrated'=>false,'reverse_lookup'=>false],
            ['name'=>'','zone_type'=>'Primary','ds_integrated'=>false,'reverse_lookup'=>false],
        ]];
    return [$repo,$source,$collector,$job,$now];
}
function selectionService10(Dns10SelectionMemoryRepository $repo,int &$clock): IntegrationDnsZoneSelectionService {
    return new IntegrationDnsZoneSelectionService($repo,static function() use(&$clock): int{return $clock;});
}
[$r,$source,$collector,$job,$clock]=selectionFixture10();$s=selectionService10($r,$clock);
$row=$s->setSelected($source,'Example.Test',true,null,$job,17);
$id=$row['id'];
selectionCheck10($row['zone_name']==='example.test.' && $row['selected']===true && $row['collection_enabled']===false,'selection is canonical and record collection remains off');
selectionCheck10($row['revision']===1 && count($r->events)===1 && $r->events[0]['action']==='zone_selected','selection audited');
$again=$s->setSelected($source,'example.test.',true,1,$job,17);
selectionCheck10($again['revision']===1 && count($r->events)===1,'same selection is a no-op');
selectionReject10(fn()=> $s->setCollectionEnabled($id,1,true,str_repeat('d',32),17),'discovery_unavailable');
$enabled=$s->setCollectionEnabled($id,1,true,$job,17);
selectionCheck10($enabled['collection_enabled']===true && $enabled['revision']===2,'explicit enable increments revision');
$r->observations[$id]=['fixture'=>'last-good'];unset($r->discoveries[$source]);$r->sources[$source]['enabled']=false;
$disabled=$s->setCollectionEnabled($id,2,false,null,17);
selectionCheck10($disabled['collection_enabled']===false && $disabled['revision']===3,'disable works without discovery or enabled source');
selectionReject10(fn()=> $s->setCollectionEnabled($id,3,true,$job,17),'source_disabled');
$deselected=$s->setSelected($source,'example.test.',false,3,null,17);
selectionCheck10($deselected['selected']===false && $deselected['collection_enabled']===false && $deselected['revision']===4,'deselection disables record collection');
selectionCheck10($r->observations[$id]===['fixture'=>'last-good'],'deselection retains prior observations');
[$fresh]=selectionFixture10();$r->discoveries[$source]=$fresh->discoveries[$source];
$reselected=$s->setSelected($source,'example.test.',true,4,$job,17);
selectionCheck10($reselected['id']===$id && $reselected['revision']===5 && $reselected['collection_enabled']===false,'reselection preserves identity and remains disabled');
selectionReject10(fn()=> $s->setSelected($source,'example.test.',false,4,null,17),'revision_conflict');
selectionReject10(fn()=> $s->setSelected($source,'example.test.',true,null,$job,17),'revision_conflict');

foreach (['stale_job','stale_source','stale_epoch','stale_assignment','stale_credential','foreign_source','foreign_collector',
          'revoked_collector','bad_state','wrong_type','wrong_attempt','lease_present','lease_expiry_present','no_credential_marker',
          'credential_after_finish','old_discovery','future_discovery','bad_zone_shape'] as $case) {
    [$repo,$src,$col,$discovery,$time]=selectionFixture10();$service=selectionService10($repo,$time);
    switch($case) {
        case 'stale_job':$discovery=str_repeat('d',32);break;
        case 'stale_source':$repo->discoveries[$src]['configuration_revision']=3;break;
        case 'stale_epoch':$repo->discoveries[$src]['binding_epoch']=6;break;
        case 'stale_assignment':$repo->discoveries[$src]['assignment_revision']=5;break;
        case 'stale_credential':$repo->sources[$src]['credential_version']=2;break;
        case 'foreign_source':$repo->discoveries[$src]['connection_id']=str_repeat('d',32);break;
        case 'foreign_collector':$repo->discoveries[$src]['collector_id']=str_repeat('d',32);break;
        case 'revoked_collector':$repo->collectors[$col]['revoked']=true;break;
        case 'bad_state':$repo->discoveries[$src]['state']='failed';break;
        case 'wrong_type':$repo->discoveries[$src]['job_type']='test_connection';break;
        case 'wrong_attempt':$repo->discoveries[$src]['attempt_count']=2;break;
        case 'lease_present':$repo->discoveries[$src]['lease_id']=str_repeat('f',32);break;
        case 'lease_expiry_present':$repo->discoveries[$src]['lease_expires_at']=$time+1;break;
        case 'no_credential_marker':$repo->discoveries[$src]['credential_delivered_at']=null;break;
        case 'credential_after_finish':$repo->discoveries[$src]['credential_delivered_at']=$time-5;break;
        case 'old_discovery':$repo->discoveries[$src]['finished_at']=$time-901;break;
        case 'future_discovery':$repo->discoveries[$src]['finished_at']=$time+301;break;
        case 'bad_zone_shape':$repo->discoveries[$src]['zones'][0]['reverse_lookup']='false';break;
    }
    selectionReject10(fn()=> $service->setSelected($src,'example.test.',true,null,$discovery,17),'discovery_unavailable');
    selectionCheck10($repo->datasets===[] && $repo->events===[],$case.' does not write');
}
foreach(['stub.test.','forward.test.','missing.test.'] as $zone) {
    [$repo,$src,$col,$discovery,$time]=selectionFixture10();$service=selectionService10($repo,$time);
    selectionReject10(fn()=> $service->setSelected($src,$zone,true,null,$discovery,17),'zone_not_eligible');
}
[$repo,$src,$col,$discovery,$time]=selectionFixture10();$service=selectionService10($repo,$time);
$reverse=$service->setSelected($src,'2.0.192.in-addr.arpa.',true,null,$discovery,17);
selectionCheck10($reverse['collection_enabled']===false,'secondary reverse zone selectable but off');
$rootRow=$service->setSelected($src,'.',true,null,$discovery,17);
selectionCheck10($rootRow['zone_name']==='.' && $rootRow['collection_enabled']===false,'root dataset identity canonical');
$lostSourceRepo=clone $repo;unset($lostSourceRepo->sources[$src]);$lostSourceService=selectionService10($lostSourceRepo,$time);
$lostSourceRow=$lostSourceService->setSelected($src,'.',false,$rootRow['revision'],null,17);
selectionCheck10($lostSourceRow['selected']===false,'deselection works when source context is unavailable');
selectionReject10(fn()=> $service->setSelected($src,'пример.test',true,null,$discovery,17),'zone_invalid');
selectionReject10(fn()=> $service->setSelected($src,'*.example.test',true,null,$discovery,17),'zone_invalid');
selectionReject10(fn()=> $service->setSelected($src,'example.test',true,null,$discovery,0),'actor_invalid');

[$repo,$src,$col,$discovery,$time]=selectionFixture10();$service=selectionService10($repo,$time);
for($i=0;$i<64;++$i) {$id=str_pad(dechex($i+100),32,'0',STR_PAD_LEFT);$repo->datasets[$id]=['id'=>$id,'connection_id'=>$src,'zone_name'=>'z'.$i.'.test.','selected'=>true];}
selectionReject10(fn()=> $service->setSelected($src,'example.test.',true,null,$discovery,17),'selection_limit');
selectionCheck10(count($repo->datasets)===64 && $repo->events===[],'selection limit leaves data intact');
[$repo,$src,$col,$discovery,$time]=selectionFixture10();$service=selectionService10($repo,$time);$repo->countOverride=-1;
selectionReject10(fn()=> $service->setSelected($src,'example.test.',true,null,$discovery,17),'selection_unavailable');
selectionCheck10($repo->datasets===[],'invalid selected count fails closed');
[$repo,$src,$col,$discovery,$time]=selectionFixture10();$service=selectionService10($repo,$time);$repo->failAt=2;
selectionReject10(fn()=> $service->setSelected($src,'example.test.',true,null,$discovery,17),'injected_failure');
selectionCheck10($repo->datasets===[] && $repo->events===[],'audit failure rolls back selection');
echo 'DNS10_SELECTION_SERVICE_ASSERTIONS='.$n."\n";
echo "DNS10_SELECTION_SERVICE_TEST_OK=1\n";
