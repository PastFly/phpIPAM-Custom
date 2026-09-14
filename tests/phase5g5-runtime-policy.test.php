<?php
$root = $argv[1] ?? throw new RuntimeException('generated_release_required');
foreach (['Store', 'Worker'] as $name) require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
require_once __DIR__ . '/../layers/phase5g4/functions/classes/class.IntegrationVmwareInventoryReadiness.php';
require_once __DIR__ . '/../layers/phase5g5/functions/classes/class.IntegrationVmwareInventoryRecovery.php';
require_once $root . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php';
require_once $root . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventorySampler.php';
$count = 0;
function check(bool $ok): void { global $count; ++$count; if (!$ok) throw new RuntimeException('assertion_' . $count); }
$now = time();
$good = static fn(string $kind, string $id): array => ['state' => 'ok', 'collected_at' => $now, 'data' => ['entity_type' => $kind, 'entity_external_id' => $id, 'verify_tls' => true, 'samples' => []]];
check(!IntegrationVmwareInventoryRecovery::usable(null, $now));
check(!IntegrationVmwareInventoryRecovery::usable(['inventory'=>[], 'started_at'=>$now-14401,'published_at'=>$now],$now));
check(!IntegrationVmwareInventoryRecovery::usable(['inventory'=>[], 'started_at'=>$now+61,'published_at'=>$now],$now));
check(!IntegrationVmwareInventoryRecovery::usable(['inventory'=>[], 'blocked'=>true, 'started_at'=>$now,'published_at'=>$now],$now));
check(IntegrationVmwareInventoryRecovery::metricReady($good('vm','vm-1'),'vm','vm-1',$now));
foreach (['state'=>'unavailable','collected_at'=>$now-14401, 'data'=>[]] as $field=>$value) {
    check(!IntegrationVmwareInventoryRecovery::metricReady(array_replace($good('vm','vm-1'),[$field=>$value]),'vm','vm-1',$now));
}
$parts = ['vm-1'=>['performance'=>$good('vm','vm-1')], 'vm-2'=>['performance'=>['state'=>'unavailable', 'attempted_at'=>$now-100]], 'vm-3'=>[]];
$jobs = [['vm','vm-1','performance'], ['vm','vm-2','performance'], ['vm','vm-3','performance']];
check(array_column(IntegrationVmwareInventoryRecovery::order($jobs,static fn($id)=>$parts[$id],$now),1) === ['vm-3','vm-2','vm-1']);
$tmp = sys_get_temp_dir() . '/recovery-policy-' . bin2hex(random_bytes(10));
mkdir($tmp,0700); mkdir($tmp.'/telemetry',0700);
$key=random_bytes(32); $binding=str_repeat('a',64);
$store=new IntegrationVmwareInventoryStore($tmp,$key,$binding);
$metrics=new IntegrationVmwareInventoryTelemetryStore($tmp.'/telemetry',$key,$binding);
$current=['state'=>'error','last_error'=>'timeout','generation'=>str_repeat('b',32),'access_epoch'=>'initial','started_at'=>$now-100,'published_at'=>$now-60,
    'progress'=>['completed'=>1,'total'=>5], 'inventory'=>['objects'=>['vm'=>['vm-1'=>['collected_at'=>$now-99,'data'=>['sections'=>['guest_network'=>['state'=>'unavailable','data'=>null,'observed_at'=>$now-100]]]], 'vm-2'=>['data'=>['sections'=>[]]]], 'host'=>[], 'datastore'=>[]]]];
$store->exclusive(static function()use($store,$current):array{$store->write('current',$current);return [];});
$calls=[];
$guest=static function(string $id)use(&$calls):array{$calls[]=$id;return ['ok'=>true,'verify_tls'=>true,'vm_count'=>1,'vms'=>[['vm_external_id'=>$id,'status'=>'ok','interfaces'=>[['nic_external_id'=>null,'mac_address'=>'00:50:56:11:22:33']]]]];};
$result=IntegrationVmwareInventoryRecovery::repairGuests($store,$guest,static fn()=>true,1,static fn()=>$now);
check($result['work_done']===1 && $calls===['vm-1']);
$after=$store->read('current');
foreach(['generation','started_at','published_at','progress','last_error','access_epoch'] as $name)check($after[$name]===$current[$name]);
check($after['inventory']['objects']['vm']['vm-1']['data']['sections']['guest_network']['data']['vms'][0]['interfaces'][0]['nic_external_id']===null);
check($after['inventory']['objects']['vm']['vm-1']['collected_at']===$current['inventory']['objects']['vm']['vm-1']['collected_at']);
$bad=static function($id){throw new RuntimeException('invalid guest data');};
IntegrationVmwareInventoryRecovery::repairGuests($store,$bad,static fn()=>true,1,static fn()=>$now);
check($store->read('current')['inventory']['objects']['vm']['vm-2']['data']['sections']['guest_network']['state']==='unavailable');
// Real sampler: valid sections are skipped in initial mode, absent ones cannot starve behind refresh work.
$metrics->exclusive(static function()use($metrics,$good):array{$metrics->write('vm-1','initial',['performance'=>$good('vm','vm-1'),'storage_performance'=>$good('vm','vm-1')]);return [];});
$source=new class($now) implements IntegrationVmwareInventoryMetricProvider {
    public array $calls=[]; private int $now;
    public function __construct($now){$this->now=$now;}
    public function sample(string $kind,string $id,string $section):array{$this->calls[]=[$id,$section];return ['entity_type'=>$kind,'entity_external_id'=>$id,'verify_tls'=>true,'samples'=>[],'supported'=>false,'reason'=>'aggregate_metrics_unavailable'];}
};
$sampler=new IntegrationVmwareInventorySampler($store,$metrics,$source,static fn()=>true,static fn()=>$now);
$sampler->tick(2,25,300,true);
check(count($source->calls)===2 && array_unique(array_column($source->calls,0))===['vm-2']);
check($metrics->read('vm-2','initial')['performance']['data']['supported']===false);
$sampler->tick(2,25,300,true);check(count($source->calls)===2);
// Binding denial aborts rather than becoming legitimate unavailability.
$denied=static function($id){throw new RuntimeException('mirror_access_denied');};
IntegrationVmwareInventoryRecovery::repairGuests($store,$denied,static fn()=>true,1,static fn()=>$now+301);
$blocked=$store->read('current');check($blocked['blocked']===true && $blocked['access_epoch']!=='initial');
check(IntegrationVmwareInventoryRecovery::inspect($blocked,static function(){throw new RuntimeException('no read');},$now)['state']==='access_denied');
$rm=static function(string $p)use(&$rm):void{foreach(scandir($p)as$f){if($f==='.'||$f==='..')continue;$q=$p.'/'.$f;if(is_dir($q))$rm($q);else unlink($q);}rmdir($p);};$rm($tmp);
echo "PHASE5G5_RUNTIME_POLICY_ASSERTIONS=$count\nPHASE5G5_REAL_STORE_SAMPLER_REPAIR_OK\n";
