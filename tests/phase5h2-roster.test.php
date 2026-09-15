<?php
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php';
$checks=0; function ok2($v):void{global $checks;++$checks;if(!$v)throw new RuntimeException('assert_'.$checks);} function bad(callable $f):bool{try{$f();}catch(Throwable $e){return true;}return false;}
$now=1800000000;
$full=['vm'=>['vm-2','vm-1'],'host'=>['host-1'],'datastore'=>['datastore-1']];
$s=IntegrationVmwareRosterReconciler::observe(null,$full,true,$now);
ok2($s['authoritative']===null && $s['pending']['confirmations']===1);
ok2(IntegrationVmwareRosterReconciler::membership($s,'vm','vm-1')==='unknown');
$s=IntegrationVmwareRosterReconciler::observe($s,$full,false,$now+10);
ok2($s['authoritative']===null && $s['pending']===null && $s['last_attempt_complete']===false);
$s=IntegrationVmwareRosterReconciler::observe($s,$full,true,$now+20);
$s=IntegrationVmwareRosterReconciler::observe($s,['vm'=>['vm-1','vm-2'],'host'=>['host-1'],'datastore'=>['datastore-1']],true,$now+30);
ok2($s['authoritative']['roster']['vm']===['vm-1','vm-2']);
ok2(IntegrationVmwareRosterReconciler::membership($s,'vm','vm-1')==='present');
ok2(IntegrationVmwareRosterReconciler::membership($s,'vm','vm-9')==='absent_confirmed');
$changed=['vm'=>['vm-1'],'host'=>['host-1'],'datastore'=>['datastore-1']];
$s1=IntegrationVmwareRosterReconciler::observe($s,$changed,true,$now+40);
ok2(IntegrationVmwareRosterReconciler::membership($s1,'vm','vm-2')==='present'); // one pass cannot remove
$s2=IntegrationVmwareRosterReconciler::observe($s1,$changed,true,$now+50);
ok2(IntegrationVmwareRosterReconciler::membership($s2,'vm','vm-2')==='absent_confirmed');
$fast=['schema'=>1,'domain'=>'properties','published_at'=>$now,'authoritative_membership'=>false,'objects'=>[
 'vm-1'=>['kind'=>'vm','id'=>'vm-1','fields'=>[]], 'vm-2'=>['kind'=>'vm','id'=>'vm-2','fields'=>[]]]];
$marked=IntegrationVmwareRosterReconciler::mark($fast,$s2,$now+51);
ok2($marked['objects']['vm-1']['membership_state']==='present');
ok2($marked['objects']['vm-2']['membership_state']==='absent_confirmed');
ok2(isset($marked['objects']['vm-2'])); // tombstone retained, no physical deletion in merge layer
ok2(bad(fn()=>IntegrationVmwareRosterReconciler::observe(null,['vm'=>['vm-1','vm-1'],'host'=>[],'datastore'=>[]],true,$now)));
ok2(bad(fn()=>IntegrationVmwareRosterReconciler::membership($s2,'vm','bad')));
echo 'PHASE5H2_ROSTER_ASSERTIONS='.$checks."\nPHASE5H2_TWO_PASS_DELETION_GUARD_OK\n";
