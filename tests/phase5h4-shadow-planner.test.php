<?php
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
require_once __DIR__ . '/../layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
require_once __DIR__ . '/../layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';
$c=0; function s4($v):void{global $c;++$c;if(!$v)throw new RuntimeException('assert_'.$c);} function s4bad(callable $f):bool{try{$f();}catch(Throwable $e){return true;}return false;}
$now=1800000000;
$current=['generation'=>str_repeat('a',32),'published_at'=>$now-60,'inventory'=>['objects'=>[
 'vm'=>['vm-1'=>['data'=>['ok'=>true,'id'=>'vm-1']]],
 'host'=>['host-1'=>['data'=>['ok'=>true,'id'=>'host-1']]],
 'datastore'=>['datastore-1'=>['data'=>['ok'=>true,'id'=>'datastore-1']]]
]]];
$r=IntegrationVmwareShadowPlanner::roster($current);
s4(count($r['entities'])===3 && $r['membership']['vm']===['vm-1']);s4($r['authoritative']===false);
$telemetry=['vm-1'=>['performance'=>['data'=>['provider'=>['current_supported'=>true,'refresh_rate'=>20],
 'counters'=>['cpu.usage.average'=>['id'=>1],'mem.usage.average'=>['id'=>2]]]]]];
$read=fn($id)=>$telemetry[$id]??[];
$caps=IntegrationVmwareCapabilityCache::put(null,'host','host-1',['current_supported'=>false,'refresh_rate'=>null,'counters'=>[]],$now,900);
$p=IntegrationVmwareShadowPlanner::metricPlans($r['entities'],$read,$caps,$now);
s4($p['plans']===[['kind'=>'vm','id'=>'vm-1','interval_id'=>20,'counters'=>['cpu.usage.average','mem.usage.average']]]);
s4($p['unsupported']===[['kind'=>'host','id'=>'host-1','reason'=>'provider_not_realtime']]);s4($p['capability_due']===[]);

// A complete, unexpired negative aggregate-capability result is resolved until TTL expiry.
$negative=IntegrationVmwareCapabilityCache::put(null,'vm','vm-2',['current_supported'=>true,'refresh_rate'=>20,'counters'=>[
 'cpu.usage.average'=>'aggregate_unavailable','mem.usage.average'=>'aggregate_unavailable','disk.usage.average'=>'aggregate_unavailable']],$now,900);
$pn=IntegrationVmwareShadowPlanner::metricPlans([['kind'=>'vm','id'=>'vm-2']],fn($id)=>[],$negative,$now);
s4($pn['plans']===[]);s4($pn['capability_due']===[]);
s4($pn['unsupported']===[['kind'=>'vm','id'=>'vm-2','reason'=>'aggregate_metrics_unavailable']]);

$batch=['schema'=>1,'objects'=>['vm-1'=>['kind'=>'vm','id'=>'vm-1','state'=>'partial','missing_counters'=>['cpu.usage.average'],
 'samples'=>[['observed_at'=>$now-20,'values'=>['cpu.usage.average'=>null,'mem.usage.average'=>66.5]]],'collected_at'=>$now]]];
$n=IntegrationVmwareShadowPlanner::normalizeMetrics($batch,$p['plans'],$now);
s4($n[0]['state']==='partial');s4(!isset($n[0]['series']['cpu_percent']));s4($n[0]['series']['memory_percent'][0]['value']===66.5);
$previous=IntegrationVmwareFastDomain::mergeMetrics(null,[['kind'=>'vm','id'=>'vm-1','state'=>'ok','attempted_at'=>$now-100,'series'=>[
 'cpu_percent'=>[['observed_at'=>$now-120,'value'=>40]],'memory_percent'=>[['observed_at'=>$now-120,'value'=>50]]]]],$now-100);
$merged=IntegrationVmwareFastDomain::mergeMetrics($previous,$n,$now);
s4($merged['objects']['vm-1']['object_state']==='partial');s4($merged['objects']['vm-1']['series']['cpu_percent']['state']==='stale');
s4($merged['objects']['vm-1']['series']['cpu_percent']['points'][0]['value']===40);s4($merged['objects']['vm-1']['series']['memory_percent']['state']==='fresh');s4($merged['objects']['vm-1']['series']['memory_percent']['points'][0]['value']===66.5);

// With a 20-second provider interval the freshness window is 60 seconds. Exactly 60 is valid;
// anything older is stale-source evidence and must not replace last-good metric values.
$onePlan=[['kind'=>'vm','id'=>'vm-9','interval_id'=>20,'counters'=>['cpu.usage.average','mem.usage.average']]];
$freshBatch=['schema'=>1,'objects'=>['vm-9'=>['kind'=>'vm','id'=>'vm-9','state'=>'returned','missing_counters'=>[],
 'samples'=>[['observed_at'=>$now-60,'values'=>['cpu.usage.average'=>25.0,'mem.usage.average'=>50.0]]],'collected_at'=>$now]]];
$fresh=IntegrationVmwareShadowPlanner::normalizeMetrics($freshBatch,$onePlan,$now);
s4($fresh[0]['state']==='ok');s4(count($fresh[0]['series'])===2);
$staleBatch=$freshBatch;$staleBatch['objects']['vm-9']['samples'][0]['observed_at']=$now-61;
$stale=IntegrationVmwareShadowPlanner::normalizeMetrics($staleBatch,$onePlan,$now);
s4($stale[0]['state']==='stale_source');s4($stale[0]['series']===[]);
$emptyBatch=['schema'=>1,'objects'=>['vm-9'=>['kind'=>'vm','id'=>'vm-9','state'=>'empty','missing_counters'=>[], 'samples'=>[],'collected_at'=>$now]]];
$empty=IntegrationVmwareShadowPlanner::normalizeMetrics($emptyBatch,$onePlan,$now);
s4($empty[0]['state']==='stale_source');

$u=IntegrationVmwareShadowPlanner::unsupportedRecords($p['unsupported'],$now);s4($u[0]['state']==='unsupported');
s4(s4bad(fn()=>IntegrationVmwareShadowPlanner::roster(['blocked'=>true])));
echo 'PHASE5H4_SHADOW_ASSERTIONS='.$c."\nPHASE5H4_PARTIAL_METRIC_PRESERVATION_OK\nPHASE5H4_STALE_SOURCE_AND_NEGATIVE_CAPABILITY_OK\n";
