<?php
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php';
require_once __DIR__ . '/../layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php';
$checks = 0;
function ok($v): void { global $checks; ++$checks; if (!$v) throw new RuntimeException('assert_' . $checks); }
function fails(callable $f): bool { try { $f(); } catch (Throwable $e) { return true; } return false; }

$now = 1800000000;
$roster = ['schema'=>1,'domain'=>'roster','published_at'=>$now-5,
    'authoritative'=>['hash'=>str_repeat('a',64),'roster'=>['vm'=>['vm-1'],'host'=>['host-1'],'datastore'=>['datastore-1']], 'confirmed_at'=>$now-10],
    'pending'=>null,'last_attempt_at'=>$now-5,'last_attempt_complete'=>true];
$properties = ['schema'=>1,'domain'=>'properties','published_at'=>$now-2,'authoritative_membership'=>false,'objects'=>[
    'vm-1'=>['kind'=>'vm','id'=>'vm-1','object_state'=>'fresh','fields'=>[
        'name'=>['state'=>'fresh','value'=>'vm-a','collected_at'=>$now-2,'last_attempt_at'=>$now-2,'error'=>null],
        'runtime.powerState'=>['state'=>'fresh','value'=>'poweredOn','collected_at'=>$now-400,'last_attempt_at'=>$now-2,'error'=>null],
        'guest.ipAddress'=>['state'=>'unavailable','value'=>null,'collected_at'=>$now-2,'last_attempt_at'=>$now-2,'error'=>null],
    ]],
    'host-1'=>['kind'=>'host','id'=>'host-1','object_state'=>'stale','fields'=>[
        'name'=>['state'=>'stale','value'=>'esx-a','collected_at'=>$now-20,'last_attempt_at'=>$now-1,'error'=>'not_reported']
    ]],
    'vm-9'=>['kind'=>'vm','id'=>'vm-9','object_state'=>'fresh','fields'=>[
        'name'=>['state'=>'fresh','value'=>'retained-deleted','collected_at'=>$now-1,'last_attempt_at'=>$now-1,'error'=>null]
    ]]
]];
$metrics = ['schema'=>1,'domain'=>'metrics','published_at'=>$now-1,'authoritative_membership'=>false,'objects'=>[
    'vm-1'=>['kind'=>'vm','id'=>'vm-1','object_state'=>'fresh','series'=>[
        'cpu_percent'=>['state'=>'fresh','points'=>[['observed_at'=>$now-20,'value'=>31.5]],'collected_at'=>$now-2,'observed_at'=>$now-20],
        'memory_percent'=>['state'=>'fresh','points'=>[['observed_at'=>$now-500,'value'=>72.0]],'collected_at'=>$now-2,'observed_at'=>$now-500],
        'disk_kib_per_second'=>['state'=>'unsupported','points'=>[],'collected_at'=>$now-2,'observed_at'=>null],
    ]],
    'host-1'=>['kind'=>'host','id'=>'host-1','object_state'=>'unsupported','series'=>[
        'cpu_percent'=>['state'=>'unsupported','points'=>[['observed_at'=>$now-100,'value'=>12.0]],'collected_at'=>$now-2,'observed_at'=>$now-100]
    ]],
    'vm-9'=>['kind'=>'vm','id'=>'vm-9','object_state'=>'fresh','series'=>[
        'cpu_percent'=>['state'=>'fresh','points'=>[['observed_at'=>$now-1,'value'=>99]],'collected_at'=>$now-1,'observed_at'=>$now-1]
    ]]
]];

$r = IntegrationVmwareFastReader::project($roster,$properties,$metrics,'vm','vm-1',$now,300,300);
ok($r['local_only'] === true && $r['source'] === 'phase5h_fast');
ok($r['membership_state'] === 'present' && $r['membership_confirmed_at'] === $now-10);
ok($r['roster_age_seconds'] === 5 && $r['property_domain_age_seconds'] === 2 && $r['metric_domain_age_seconds'] === 1);
ok($r['properties']['name']['state'] === 'fresh' && $r['properties']['name']['value'] === 'vm-a');
ok($r['properties']['runtime.powerState']['state'] === 'stale' && $r['properties']['runtime.powerState']['value'] === 'poweredOn');
ok($r['properties']['guest.ipAddress']['state'] === 'unavailable' && $r['properties']['guest.ipAddress']['value'] === null);
ok($r['property_object_state'] === 'partial');
ok($r['metrics']['cpu_percent']['state'] === 'fresh' && $r['metrics']['cpu_percent']['points'][0]['value'] === 31.5);
ok($r['metrics']['memory_percent']['state'] === 'stale' && $r['metrics']['memory_percent']['points'][0]['value'] === 72.0);
ok($r['metrics']['disk_kib_per_second']['state'] === 'unsupported' && $r['metrics']['disk_kib_per_second']['points'] === []);
ok($r['metric_object_state'] === 'partial');

// Confirmed absence hides retained last-good data instead of resurrecting deleted membership.
$absent = IntegrationVmwareFastReader::project($roster,$properties,$metrics,'vm','vm-9',$now);
ok($absent['membership_state'] === 'absent_confirmed');
ok($absent['properties'] === [] && $absent['metrics'] === []);
ok($absent['property_object_state'] === 'unavailable' && $absent['metric_object_state'] === 'unavailable');

// An unconfirmed roster fails closed even when retained fast values exist.
$unknownRoster = $roster; $unknownRoster['authoritative'] = null;
$unknown = IntegrationVmwareFastReader::project($unknownRoster,$properties,$metrics,'vm','vm-1',$now);
ok($unknown['membership_state'] === 'unknown');
ok($unknown['properties'] === [] && $unknown['metrics'] === []);
$noRoster = IntegrationVmwareFastReader::project(null,$properties,$metrics,'vm','vm-1',$now);
ok($noRoster['membership_state'] === 'unknown' && $noRoster['properties'] === []);

$host = IntegrationVmwareFastReader::project($roster,$properties,$metrics,'host','host-1',$now);
ok($host['property_object_state'] === 'stale' && $host['properties']['name']['value'] === 'esx-a');
ok($host['metric_object_state'] === 'unsupported' && $host['metrics']['cpu_percent']['state'] === 'unsupported');

$datastore = IntegrationVmwareFastReader::project($roster,$properties,$metrics,'datastore','datastore-1',$now);
ok($datastore['membership_state'] === 'present');
ok($datastore['metric_object_state'] === 'not_applicable' && $datastore['metrics'] === []);
ok($datastore['property_object_state'] === 'missing');

ok(fails(fn()=>IntegrationVmwareFastReader::project($roster,$properties,$metrics,'vm','host-1',$now)));
$badProperties = $properties; $badProperties['authoritative_membership'] = true;
ok(fails(fn()=>IntegrationVmwareFastReader::project($roster,$badProperties,$metrics,'vm','vm-1',$now)));
$badMetric = $metrics; $badMetric['objects']['vm-1']['series']['cpu_percent']['points'][0]['observed_at'] = $now+1000;
ok(fails(fn()=>IntegrationVmwareFastReader::project($roster,$properties,$badMetric,'vm','vm-1',$now)));
$badUnavailable = $properties; $badUnavailable['objects']['vm-1']['fields']['guest.ipAddress']['value'] = '192.0.2.1';
ok(fails(fn()=>IntegrationVmwareFastReader::project($roster,$badUnavailable,$metrics,'vm','vm-1',$now)));
$missingRecordId = $properties; unset($missingRecordId['objects']['vm-1']['id']);
ok(fails(fn()=>IntegrationVmwareFastReader::project($roster,$missingRecordId,$metrics,'vm','vm-1',$now)));
$numericStringMetric = $metrics; $numericStringMetric['objects']['vm-1']['series']['cpu_percent']['points'][0]['value'] = '31.5';
ok(fails(fn()=>IntegrationVmwareFastReader::project($roster,$properties,$numericStringMetric,'vm','vm-1',$now)));

echo 'PHASE5H7_FAST_READER_ASSERTIONS=' . $checks . "\n";
echo "PHASE5H7_LOCAL_ONLY_READER_OK\n";
echo "PHASE5H7_ROSTER_FAIL_CLOSED_OK\n";
echo "PHASE5H7_CONFIRMED_ABSENCE_HIDES_RETAINED_DATA_OK\n";
echo "PHASE5H7_FRESHNESS_DEMOTION_OK\n";
echo "PHASE5H7_UNSUPPORTED_NULL_SEMANTICS_OK\n";
