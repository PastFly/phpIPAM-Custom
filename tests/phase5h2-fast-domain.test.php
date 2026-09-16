<?php
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
$checks = 0;
function ok($v): void { global $checks; ++$checks; if (!$v) throw new RuntimeException('assert_' . $checks); }
function fails(callable $f): bool { try { $f(); } catch (Throwable $e) { return true; } return false; }
$now = 1800000000;
$batch = ['schema'=>1,'authoritative_membership'=>false,'started_at'=>$now-3,'completed_at'=>$now-1,'objects'=>[
    'vm-1'=>['kind'=>'vm','id'=>'vm-1','state'=>'returned','values'=>['runtime.powerState'=>'poweredOn','config.hardware.memoryMB'=>8192],
        'property_states'=>['runtime.powerState'=>'available','config.hardware.memoryMB'=>'available','guest.ipAddress'=>'not_reported'],'collected_at'=>$now-1],
    'vm-2'=>['kind'=>'vm','id'=>'vm-2','state'=>'unobserved','values'=>[],'property_states'=>[],'collected_at'=>null]
]];
$previous = ['schema'=>1,'domain'=>'properties','published_at'=>$now-100,'authoritative_membership'=>false,'objects'=>[
    'vm-1'=>['kind'=>'vm','id'=>'vm-1','object_state'=>'fresh','fields'=>['guest.ipAddress'=>['state'=>'fresh','value'=>'192.0.2.10','observed_at'=>null,'collected_at'=>$now-50,'last_attempt_at'=>$now-50,'error'=>null]]],
    'vm-2'=>['kind'=>'vm','id'=>'vm-2','object_state'=>'fresh','fields'=>['runtime.powerState'=>['state'=>'fresh','value'=>'poweredOff','observed_at'=>null,'collected_at'=>$now-50,'last_attempt_at'=>$now-50,'error'=>null]]],
    'vm-3'=>['kind'=>'vm','id'=>'vm-3','object_state'=>'fresh','fields'=>['runtime.powerState'=>['state'=>'fresh','value'=>'poweredOn','observed_at'=>null,'collected_at'=>$now-50,'last_attempt_at'=>$now-50,'error'=>null]]]
]];
$out = IntegrationVmwareFastDomain::mergeProperties($previous, $batch, $now);
ok($out['published_at'] === $now && $out['authoritative_membership'] === false);
ok($out['objects']['vm-1']['fields']['runtime.powerState']['value'] === 'poweredOn');
ok($out['objects']['vm-1']['fields']['config.hardware.memoryMB']['value'] === 8192);
ok($out['objects']['vm-1']['fields']['guest.ipAddress']['state'] === 'stale');
ok($out['objects']['vm-1']['fields']['guest.ipAddress']['value'] === '192.0.2.10');
ok($out['objects']['vm-2']['object_state'] === 'stale' && $out['objects']['vm-2']['fields']['runtime.powerState']['value'] === 'poweredOff');
ok(isset($out['objects']['vm-3'])); // omission from a partial batch is never deletion.
$batch['objects']['vm-1']['property_states']['guest.ipAddress'] = 'unavailable';
$batch['objects']['vm-1']['values']['guest.ipAddress'] = null;
$out2 = IntegrationVmwareFastDomain::mergeProperties($out, $batch, $now+10);
ok($out2['objects']['vm-1']['fields']['guest.ipAddress']['state'] === 'unavailable');
ok($out2['objects']['vm-1']['fields']['guest.ipAddress']['value'] === null);
$metrics = IntegrationVmwareFastDomain::mergeMetrics(null, [
    ['kind'=>'vm','id'=>'vm-1','state'=>'ok','attempted_at'=>$now,'series'=>[
        'cpu_percent'=>[['observed_at'=>$now-20,'value'=>32.5]],
        'memory_percent'=>[['observed_at'=>$now-20,'value'=>71.0]]]],
    ['kind'=>'host','id'=>'host-1','state'=>'unsupported','attempted_at'=>$now,'series'=>['cpu_percent'=>[]]],
], $now);
ok($metrics['objects']['vm-1']['series']['cpu_percent']['observed_at'] === $now-20);
ok(IntegrationVmwareFastDomain::age($now-20, $now, $now) === 20);
ok($metrics['objects']['host-1']['object_state'] === 'unsupported');

$metricsWithDisk = $metrics;
$metricsWithDisk['objects']['vm-1']['series']['disk_kib_per_second'] = [
    'state'=>'fresh','points'=>[['observed_at'=>$now-500,'value'=>12.0]],'collected_at'=>$now-500,'observed_at'=>$now-500
];
$metricsPlanShrink = IntegrationVmwareFastDomain::mergeMetrics($metricsWithDisk, [[
    'kind'=>'vm','id'=>'vm-1','state'=>'ok','attempted_at'=>$now+10,'series'=>[
        'cpu_percent'=>[['observed_at'=>$now,'value'=>33.0]],
        'memory_percent'=>[['observed_at'=>$now,'value'=>72.0]],
    ]
]], $now+10);
ok($metricsPlanShrink['objects']['vm-1']['object_state'] === 'fresh');
ok($metricsPlanShrink['objects']['vm-1']['series']['cpu_percent']['state'] === 'fresh');
ok($metricsPlanShrink['objects']['vm-1']['series']['memory_percent']['state'] === 'fresh');
ok($metricsPlanShrink['objects']['vm-1']['series']['disk_kib_per_second']['state'] === 'stale');
ok($metricsPlanShrink['objects']['vm-1']['series']['disk_kib_per_second']['points'][0]['value'] === 12.0);

$metrics2 = IntegrationVmwareFastDomain::mergeMetrics($metrics, [
    ['kind'=>'vm','id'=>'vm-1','state'=>'not_returned','attempted_at'=>$now+30,'series'=>[]]
], $now+30);
ok($metrics2['objects']['vm-1']['object_state'] === 'stale');
ok($metrics2['objects']['vm-1']['series']['cpu_percent']['points'][0]['value'] === 32.5);
$metrics3 = IntegrationVmwareFastDomain::mergeMetrics($metrics, [
    ['kind'=>'vm','id'=>'vm-1','state'=>'stale_source','attempted_at'=>$now+40,'series'=>[]]
], $now+40);
ok($metrics3['objects']['vm-1']['object_state'] === 'stale');
ok($metrics3['objects']['vm-1']['last_attempt_result'] === 'stale_source');
ok($metrics3['objects']['vm-1']['series']['cpu_percent']['state'] === 'stale');
ok($metrics3['objects']['vm-1']['series']['cpu_percent']['points'][0]['value'] === 32.5);
$metrics4 = IntegrationVmwareFastDomain::mergeMetrics($metrics, [
    ['kind'=>'vm','id'=>'vm-1','state'=>'capability_pending','attempted_at'=>$now+50,'series'=>[]]
], $now+50);
ok($metrics4['objects']['vm-1']['object_state'] === 'stale');
ok($metrics4['objects']['vm-1']['last_attempt_result'] === 'capability_pending');
ok($metrics4['objects']['vm-1']['series']['memory_percent']['state'] === 'stale');
ok($metrics4['objects']['vm-1']['series']['memory_percent']['points'][0]['value'] === 71.0);
$metrics5 = IntegrationVmwareFastDomain::mergeMetrics($metrics, [
    ['kind'=>'vm','id'=>'vm-1','state'=>'unsupported','attempted_at'=>$now+60,'series'=>[]]
], $now+60);
ok($metrics5['objects']['vm-1']['object_state'] === 'unsupported');
ok($metrics5['objects']['vm-1']['last_attempt_result'] === 'unsupported');
ok($metrics5['objects']['vm-1']['series']['cpu_percent']['state'] === 'unsupported');
ok($metrics5['objects']['vm-1']['series']['memory_percent']['state'] === 'unsupported');
ok($metrics5['objects']['vm-1']['series']['cpu_percent']['points'][0]['value'] === 32.5);
ok($metrics5['objects']['vm-1']['series']['memory_percent']['points'][0]['value'] === 71.0);
ok(fails(fn()=>IntegrationVmwareFastDomain::mergeProperties(null, ['schema'=>1,'authoritative_membership'=>true,'objects'=>[]], $now)));
ok(fails(fn()=>IntegrationVmwareFastDomain::mergeMetrics(null, [['kind'=>'vm','id'=>'vm-1','state'=>'ok','series'=>['cpu_percent'=>[['observed_at'=>$now+1000,'value'=>1]]]]], $now)));
echo 'PHASE5H2_FAST_DOMAIN_ASSERTIONS=' . $checks . "\nPHASE5H2_PARTIAL_IS_NOT_DELETION_OK\nPHASE5H2_STALE_SOURCE_PRESERVES_LAST_GOOD_OK\nPHASE5H2_CAPABILITY_PENDING_PRESERVES_LAST_GOOD_OK\nPHASE5H2_UNSUPPORTED_DEMOTES_PRIOR_SERIES_OK\nPHASE5H2_CURRENT_PLAN_STALES_OMITTED_SERIES_OK\n";
