<?php
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryStore.php';
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php';
require_once __DIR__ . '/../layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';
require_once __DIR__ . '/../layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
$checks=0; function h3ok($v):void{global $checks;++$checks;if(!$v)throw new RuntimeException('assert_'.$checks);} function h3bad(callable $f):bool{try{$f();}catch(Throwable $e){return true;}return false;}
$root=sys_get_temp_dir().'/phase5h3-'.bin2hex(random_bytes(8));
mkdir($root,0700,true); chmod($root,0700); foreach(['properties','metrics','capabilities','roster'] as $d){mkdir($root.'/'.$d,0700);chmod($root.'/'.$d,0700);} $key=random_bytes(32);$binding=str_repeat('a',64);$now=1800000000;
try{
 $store=new IntegrationVmwareFastStore($root,$key,$binding);
 $props=IntegrationVmwareFastDomain::mergeProperties(null,['schema'=>1,'authoritative_membership'=>false,'started_at'=>$now-2,'completed_at'=>$now-1,'objects'=>[
  'vm-1'=>['kind'=>'vm','id'=>'vm-1','state'=>'returned','values'=>['runtime.powerState'=>'poweredOn'],'property_states'=>['runtime.powerState'=>'available'],'collected_at'=>$now-1]
 ]],$now);
 $r=$store->publish('properties',$props);h3ok($r['state']==='published');h3ok($store->read('properties')['objects']['vm-1']['fields']['runtime.powerState']['value']==='poweredOn');
 $metrics=IntegrationVmwareFastDomain::mergeMetrics(null,[['kind'=>'vm','id'=>'vm-1','state'=>'ok','attempted_at'=>$now,'series'=>['cpu_percent'=>[['observed_at'=>$now-10,'value'=>50]]]]],$now);
 h3ok($store->publish('metrics',$metrics)['state']==='published');h3ok($store->read('metrics')['objects']['vm-1']['series']['cpu_percent']['observed_at']===$now-10);
 $caps=IntegrationVmwareCapabilityCache::put(null,'vm','vm-1',['current_supported'=>true,'refresh_rate'=>20,'counters'=>['cpu.usage.average'=>'available','mem.usage.average'=>'aggregate_unavailable']],$now,900);
 h3ok($store->publish('capabilities',$caps)['state']==='published');$saved=$store->read('capabilities');h3ok(IntegrationVmwareCapabilityCache::get($saved,'vm','vm-1',$now+899)!==null);h3ok(IntegrationVmwareCapabilityCache::get($saved,'vm','vm-1',$now+901)===null);
 h3ok(IntegrationVmwareCapabilityCache::due($saved,[['kind'=>'vm','id'=>'vm-1'],['kind'=>'host','id'=>'host-2']],$now+1)===[['kind'=>'host','id'=>'host-2']]);
 $caps=IntegrationVmwareCapabilityCache::prune($saved,['vm'=>[],'host'=>[]],$now+2);h3ok($caps['entries']===[]);
 $roster=IntegrationVmwareRosterReconciler::observe(null,['vm'=>['vm-1'],'host'=>[],'datastore'=>[]],true,$now);
 $roster=IntegrationVmwareRosterReconciler::observe($roster,['vm'=>['vm-1'],'host'=>[],'datastore'=>[]],true,$now+1);
 h3ok($store->publish('roster',$roster)['state']==='published');h3ok($store->read('roster')['authoritative']['roster']['vm']===['vm-1']);
 // Same ciphertext directory under a different connector binding is unreadable as current authorized data.
 $other=new IntegrationVmwareFastStore($root,$key,str_repeat('b',64));h3ok($other->read('properties')===null);
 unset($other);
 // Cross-domain ciphertext copy cannot be decrypted by another domain key.
 copy($root.'/properties/current.enc',$root.'/metrics/current.enc');h3ok(h3bad(fn()=>$store->read('metrics')));
 h3ok(h3bad(fn()=>new IntegrationVmwareFastStore($root,'short',$binding)));
 h3ok(h3bad(fn()=>IntegrationVmwareCapabilityCache::put(null,'vm','vm-1',['current_supported'=>true,'refresh_rate'=>999],$now)));
 echo 'PHASE5H3_FAST_STORE_ASSERTIONS='.$checks."\nPHASE5H3_ENCRYPTED_DOMAIN_ISOLATION_OK\n";
} finally {
 sodium_memzero($key);
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());} @rmdir($root);
}
