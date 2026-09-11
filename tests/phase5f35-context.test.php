<?php
require __DIR__.'/../layers/phase5d/functions/classes/class.IntegrationVmwareVcenterExplorer.php';
require __DIR__.'/../layers/phase5f34/functions/classes/class.IntegrationVmwareSnapshotCache.php';
require __DIR__.'/../layers/phase5f34/functions/classes/class.IntegrationVmwareSnapshotService.php';
require __DIR__.'/../layers/phase5f35/functions/classes/class.IntegrationVmwareVmCardContext.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function failure($fn, $expected) { try { $fn(); } catch (Throwable $e) { check($e->getMessage()===$expected,$e->getMessage());return; } throw new RuntimeException('missing '.$expected); }
function inventory($vm='vm-1') { return ['ok'=>true,'topology'=>['api_family'=>'modern','datacenters'=>[['external_id'=>'dc-1','name'=>'DC']], 'clusters'=>[], 'hosts'=>[['external_id'=>'host-1','name'=>'ESXi','datacenter_external_id'=>'dc-1']], 'vms'=>[['external_id'=>$vm,'name'=>'fixture VM','host_external_id'=>'host-1','datacenter_external_id'=>'dc-1']]], 'datastores'=>['datastores'=>[]]]; }
$root=sys_get_temp_dir().'/vmcard-context-'.bin2hex(random_bytes(8));mkdir($root,0700);
$time=1000; $calls=0; $next=inventory();
$engine=new IntegrationVmwareSnapshotCache($root,str_repeat('k',32),str_repeat('b',64),function()use(&$time){return $time;});
$loader=function($domain)use(&$calls,&$next){$calls++;check($domain==='core','core domain only');if($next instanceof Throwable)throw $next;return $next;};
$service=new IntegrationVmwareSnapshotService($engine,[],1,'fixture',$loader);
try {
 $a=IntegrationVmwareVmCardContext::read($service,'vm-1');check($calls===1&&$a['context']['host_name']==='ESXi','cold context');
 $b=IntegrationVmwareVmCardContext::read($service,'vm-1');check($calls===1&&$b['cache']['state']==='fresh','warm card no whole inventory recollection');
 $time+=61;$c=IntegrationVmwareVmCardContext::read($service,'vm-1');check($calls===1&&$c['cache']['age_seconds']===61,'soft stale explicit age no synchronous recollection');
 $next=inventory('vm-2');$d=IntegrationVmwareVmCardContext::read($service,'vm-2');check($calls===2&&$d['context']['external_id']==='vm-2','new VM triggers one forced refresh');
 failure(fn()=>IntegrationVmwareVmCardContext::read($service,'vm-999'),'snapshot_vm_not_found');check($calls===2,'missing VM obeys force floor');
 $time+=600;$e=IntegrationVmwareVmCardContext::read($service,'vm-2');check($calls===3,'hard expiry refetch');
 $time+=600;$next=new RuntimeException('HTTP 403 SECRET');failure(fn()=>IntegrationVmwareVmCardContext::read($service,'vm-2'),'snapshot_access_denied');
 $deniedCalls=$calls;failure(fn()=>IntegrationVmwareVmCardContext::read($service,'vm-2'),'snapshot_access_denied');check($calls===$deniedCalls,'no bypass on denied backoff');
 $next=inventory();$live=new IntegrationVmwareSnapshotService(null,[],1,'disabled',$loader);
 check(IntegrationVmwareVmCardContext::read($live,'vm-1')['cache']['state']==='disabled','explicit live fallback');
 $before=$calls;failure(fn()=>IntegrationVmwareVmCardContext::read($live,'vm-999'),'snapshot_vm_not_found');check($calls===$before+1,'disabled cache no second crawl');
 $next=inventory();$next['partial']=true;failure(fn()=>IntegrationVmwareVmCardContext::read($live,'vm-1'),'snapshot_context_incomplete');
 failure(fn()=>IntegrationVmwareVmCardContext::read($service,'../vm-1'),'invalid_vm_id');
 echo "PHASE5F35_SHARED_CONTEXT_TESTS_OK\n";
} finally {foreach(glob($root.'/*')as$p)unlink($p);rmdir($root);}
