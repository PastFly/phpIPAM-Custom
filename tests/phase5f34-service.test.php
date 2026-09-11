<?php
require __DIR__.'/../layers/phase5f34/functions/classes/class.IntegrationVmwareSnapshotCache.php';
require __DIR__.'/../layers/phase5f34/functions/classes/class.IntegrationVmwareSnapshotService.php';
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function failure($fn, $want) { try { $fn(); } catch (Throwable $e) { check($e->getMessage()===$want,$e->getMessage()); return; } throw new RuntimeException('Expected '.$want); }
$root=sys_get_temp_dir().'/snapshot-service-'.bin2hex(random_bytes(6)); mkdir($root,0700);
$key=str_repeat('k',32); $now=1000; $calls=0; $result=['ok'=>true,'rows'=>[['name'=>'VM','annotation'=>'fixture note']]];
$load=function($domain) use (&$calls,&$result) { $calls++; if ($result instanceof Throwable) throw $result; return $result; };
$engine=new IntegrationVmwareSnapshotCache($root,$key,str_repeat('a',64),function() use (&$now) { return $now; });
$service=new IntegrationVmwareSnapshotService($engine,[],1,'fixture',$load);
try {
    check($service->enabled(),'enabled');
    check($service->status()['domains']['core']['state']==='miss','status'); check($calls===0,'no load for status');
    $first=$service->read('search'); check($first['cache']['cache_snapshot_written'],'snapshot write');
    check($service->read('search')['payload']===$first['payload'] && $calls===1,'shared index');
    $now+=300; $service->read('search'); check($calls===1,'stale read no synchronous crawl');
    $result=['ok'=>true,'partial'=>true,'rows'=>[['name'=>'VM']]];
    $stale=$service->read('search',true); check($stale['cache']['state']==='refresh_failed','keep complete snapshot after partial enrichment');
    $now+=1801; $partial=$service->read('search');
    check($partial['cache']['state']==='incomplete_uncached' && $partial['payload']['partial'],'current partial objects retained');
    $now+=30; $result=new RuntimeException('HTTP 403 PRIVATE RESPONSE');
    failure(fn()=>$service->read('search'),'snapshot_access_denied');
    check($service->status()['domains']['core']['state']==='denied','cross-domain revocation');
    $now+=30; $result=['ok'=>true,'rows'=>[]]; check($service->read('search')['payload']['rows']===[],'valid empty index');
    $clean=IntegrationVmwareSnapshotService::publicPayload(['ok'=>true,'endpoint'=>'private','rows'=>[['password'=>'fixture','headers'=>['x'=>'fixture'],'annotation'=>'fixture']]]);
    check($clean===['ok'=>true,'rows'=>[['annotation'=>'fixture']]],'no transport fields');
    $live=new IntegrationVmwareSnapshotService(null,[],1,'fixture_disabled',$load);
    check($live->read('core')['cache']['state']==='disabled','live fallback explicit');
    failure(fn()=>$live->read('core',true),'snapshot_disabled');
    $path=$root.'/key-test'; file_put_contents($path,$key); chmod($path,0600);
    check(IntegrationVmwareSnapshotService::readPrivate($path,32,posix_geteuid(),0600)===$key,'finite key read');
    chmod($path,0644); failure(fn()=>IntegrationVmwareSnapshotService::readPrivate($path,32,posix_geteuid(),0600),'cache_private_file_unavailable');
    unlink($path);
    echo "PHASE5F34_SERVICE_TESTS_OK\n";
} finally { foreach(glob($root.'/*') as $p) unlink($p); rmdir($root); }
