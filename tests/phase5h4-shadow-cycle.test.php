<?php
require_once __DIR__ . '/../layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php';
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryStore.php';
foreach (['Session','Inventory','Performance'] as $n) require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatch'.$n.'.php';
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
require_once __DIR__ . '/../layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';
require_once __DIR__ . '/../layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
require_once __DIR__ . '/../layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';
require_once __DIR__ . '/../layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php';
if(!class_exists('DOMDocument'))throw new RuntimeException('DOM_REQUIRED_NO_SKIP');
$n=0;function shok($v):void{global$n;++$n;if(!$v)throw new RuntimeException('assert_'.$n);}function shbad(callable$f):bool{try{$f();}catch(Throwable$e){return true;}return false;}
function she(string$b):string{return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><s:Body>'.$b.'</s:Body></s:Envelope>';}
function shr(string$o,string$b):string{return she('<'.$o.'Response xmlns="urn:vim25">'.$b.'</'.$o.'Response>');}
function shf(string$n,string$v,string$a=''):string{return '<propSet><name>'.$n.'</name><val'.$a.'>'.$v.'</val></propSet>';}
function sho(string$id,string$type,string$f):string{return '<returnval><obj type="'.$type.'">'.$id.'</obj>'.$f.'</returnval>';}
function shtransport(array &$calls):callable{return static function($method,$url,$headers,$body,$tls)use(&$calls):array{
 shok($method==='POST'&&$url==='https://vc.example.test/sdk'&&$tls===true);$d=new DOMDocument();$d->loadXML($body);$x=new DOMXPath($d);$op=$x->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*')->item(0)->localName;$calls[]=$op;$p='';$h=[];
 if($op==='RetrieveServiceContent')$p='<returnval><sessionManager type="SessionManager">SessionManager</sessionManager><propertyCollector type="PropertyCollector">propertyCollector</propertyCollector><perfManager type="PerformanceManager">PerfMgr</perfManager></returnval>';
 elseif($op==='Login'){$p='<returnval/>';$h=['Set-Cookie: vmware_soap_session="fixture-session"; Secure'];}
 elseif($op==='RetrieveProperties'&&str_contains($body,'<pathSet>perfCounter</pathSet>')){$c='';foreach(['cpu'=>71,'mem'=>84,'disk'=>99]as$g=>$id)$c.='<PerfCounterInfo><key>'.$id.'</key><groupInfo><key>'.$g.'</key></groupInfo><nameInfo><key>usage</key></nameInfo><unitInfo><key>'.($g==='disk'?'kiloBytesPerSecond':'percent').'</key></unitInfo><rollupType>average</rollupType></PerfCounterInfo>';$p=sho('PerfMgr','PerformanceManager',shf('perfCounter',$c));}
 elseif($op==='RetrieveProperties'){foreach($x->query('//*[local-name()="objectSet"]/*[local-name()="obj"]')as$o){$id=$o->textContent;$t=$o->getAttribute('type');$f=shf('name','Example');if($t==='VirtualMachine')$f.=shf('runtime.powerState','poweredOn').shf('runtime.host','host-1',' type="HostSystem"').shf('config.hardware.numCPU','4').shf('config.hardware.memoryMB','8192').shf('config.annotation','note').shf('guest.hostName','example.test').shf('guest.ipAddress','192.0.2.8').shf('guest.toolsRunningStatus','guestToolsRunning');if($t==='HostSystem')$f.=shf('runtime.connectionState','connected').shf('runtime.inMaintenanceMode','false').shf('summary.quickStats.overallCpuUsage','10').shf('summary.quickStats.overallMemoryUsage','42');if($t==='Datastore')$f.=shf('summary.capacity','1000000').shf('summary.freeSpace','900000').shf('summary.accessible','true');$p.=sho($id,$t,$f);}}
 elseif($op==='QueryPerf'){foreach($x->query('//*[local-name()="querySpec"]')as$s){$e=IntegrationVmwareBatchSession::one($s,'entity');$p.='<returnval xsi:type="PerfEntityMetric"><entity type="'.$e->getAttribute('type').'">'.$e->textContent.'</entity>';$ts=time()-20;$p.='<sampleInfo><timestamp>'.gmdate('Y-m-d\TH:i:s\Z',$ts).'</timestamp><interval>20</interval></sampleInfo>';foreach(IntegrationVmwareBatchSession::children($s,'metricId')as$m){$id=IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($m,'counterId'));$p.='<value xsi:type="PerfMetricIntSeries"><id><counterId>'.$id.'</counterId><instance></instance></id><value>2500</value></value>';}$p.='</returnval>';}}
 return ['status'=>200,'headers'=>$h,'body'=>shr($op,$p)];};}
function shroot(string $prefix):string{$root=sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));mkdir($root,0700,true);chmod($root,0700);foreach(['properties','metrics','capabilities','roster']as$d){mkdir($root.'/'.$d,0700);chmod($root.'/'.$d,0700);}return $root;}
function shclean(string $root):void{$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}@rmdir($root);}
$root=shroot('phase5h4-');$key=random_bytes(32);$binding=str_repeat('a',64);
try{
 $fast=new IntegrationVmwareFastStore($root,$key,$binding);$now=time();
 $current=['generation'=>str_repeat('a',32),'published_at'=>$now-60,'inventory'=>['objects'=>['vm'=>['vm-1'=>['data'=>['ok'=>true,'id'=>'vm-1']]],'host'=>['host-1'=>['data'=>['ok'=>true,'id'=>'host-1']]],'datastore'=>['datastore-1'=>['data'=>['ok'=>true,'id'=>'datastore-1']]]]]];
 $tele=['vm-1'=>['performance'=>['data'=>['provider'=>['current_supported'=>true,'refresh_rate'=>20],'counters'=>['cpu.usage.average'=>['id'=>71],'mem.usage.average'=>['id'=>84]]]]],'host-1'=>['performance'=>['data'=>['provider'=>['current_supported'=>true,'refresh_rate'=>20],'counters'=>['cpu.usage.average'=>['id'=>71],'mem.usage.average'=>['id'=>84]]]]]];
 $calls=[];$runtime=['endpoint'=>'https://vc.example.test','username'=>'fixture','password'=>'NOT_REAL','verify_tls'=>true];
 $result=IntegrationVmwareShadowCycle::run($runtime,$current,static fn()=>true,fn($id)=>$tele[$id]??[],$fast,shtransport($calls),0,static fn()=>$now);
 shok($result['state']==='shadow_complete'&&$result['website_served_from_shadow']===false);shok($result['roster_counts']===['vm'=>1,'host'=>1,'datastore'=>1]);shok($result['metric_plans']===2&&$result['capability_still_due']===0);shok($result['requests']===6);shok($calls===['RetrieveServiceContent','Login','RetrieveProperties','RetrieveProperties','QueryPerf','Logout']);
 $p=$fast->read('properties');$m=$fast->read('metrics');shok($p['objects']['vm-1']['fields']['runtime.powerState']['value']==='poweredOn');shok((float)$m['objects']['vm-1']['series']['cpu_percent']['points'][0]['value']===25.0);shok((float)$m['objects']['host-1']['series']['memory_percent']['points'][0]['value']===25.0);

 // If capability discovery is deferred by the bounded per-cycle budget, an old metric must not remain labelled fresh.
 $root3=shroot('phase5h4c-');$fast3=new IntegrationVmwareFastStore($root3,$key,$binding);$calls3=[];
 $seed=IntegrationVmwareFastDomain::mergeMetrics(null,[
   ['kind'=>'vm','id'=>'vm-1','state'=>'ok','attempted_at'=>$now-1000,'series'=>['cpu_percent'=>[['observed_at'=>$now-1020,'value'=>10.0]]]],
   ['kind'=>'host','id'=>'host-1','state'=>'ok','attempted_at'=>$now-1000,'series'=>['memory_percent'=>[['observed_at'=>$now-1020,'value'=>20.0]]]],
 ],$now-1000);
 $fast3->update('metrics',static fn(?array $old):array=>$seed);
 $pending=IntegrationVmwareShadowCycle::run($runtime,$current,static fn()=>true,static fn($id)=>[],$fast3,shtransport($calls3),0,static fn()=>$now);
 shok($pending['metric_plans']===0&&$pending['capability_revalidated']===0&&$pending['capability_still_due']===2);
 $m3=$fast3->read('metrics');
 shok($m3['objects']['vm-1']['object_state']==='stale'&&$m3['objects']['vm-1']['last_attempt_result']==='capability_pending');
 shok($m3['objects']['vm-1']['series']['cpu_percent']['state']==='stale'&&(float)$m3['objects']['vm-1']['series']['cpu_percent']['points'][0]['value']===10.0);
 shok($m3['objects']['host-1']['object_state']==='stale'&&$m3['objects']['host-1']['last_attempt_result']==='capability_pending');
 shok($calls3===['RetrieveServiceContent','Login','RetrieveProperties','Logout']);
 unset($fast3);shclean($root3);

 // Binding loss before session start must not publish anything into a fresh store.
 $root2=shroot('phase5h4b-');$fast2=new IntegrationVmwareFastStore($root2,$key,$binding);$calls2=[];
 shok(shbad(fn()=>IntegrationVmwareShadowCycle::run($runtime,$current,static fn()=>false,fn($id)=>$tele[$id]??[],$fast2,shtransport($calls2),0,static fn()=>$now)));shok($fast2->read('properties')===null&&$fast2->read('metrics')===null);unset($fast2);shclean($root2);
 echo 'PHASE5H4_SHADOW_CYCLE_ASSERTIONS='.$n."\nPHASE5H4_NON_SERVING_SHADOW_CYCLE_OK\nPHASE5H4_CAPABILITY_BUDGET_STALES_DEFERRED_METRICS_OK\n";
}finally{sodium_memzero($key);shclean($root);}
