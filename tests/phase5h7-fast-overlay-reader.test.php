<?php
final class IntegrationVmwareFastStore {
    public function __construct(public array $domains) {}
    public function read(string $domain): ?array { return $this->domains[$domain] ?? null; }
}
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php';
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
require_once __DIR__ . '/../layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php';
require_once __DIR__ . '/../layers/phase5h7/functions/classes/class.IntegrationVmwareFastMetricCompat.php';
require_once __DIR__ . '/../layers/phase5h7/functions/classes/class.IntegrationVmwareFastOverlayReader.php';
$n = 0;
function ov(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
function ovfails(callable $f, string $code): void { global $n; try { $f(); } catch (Throwable $e) { ++$n; if ($e->getMessage() === $code) return; throw $e; } throw new RuntimeException('expected_' . $code); }
$now = 1800000000;
$base = ['ok'=>true,'state'=>'ready','has_inventory'=>true,'stale'=>true,'age_seconds'=>1000,'vcenter_requests'=>0,'inventory_files_written'=>false,
    'data'=>['ok'=>true,'name'=>'old-name','detail'=>['vm'=>['name'=>'old-name','power_state'=>'powered_off','host_external_id'=>'host-9','cpu_count'=>2,'memory_size_mib'=>4096],
        'guest_identity'=>['primary_ip'=>'192.0.2.9','guest_os'=>'Debian']], 'context'=>['datacenter_external_id'=>'datacenter-1'],
        'sections'=>[
            'annotation'=>['state'=>'ok','observed_at'=>$now-1000,'data'=>['annotation'=>'old notes']],
            'performance'=>['state'=>'stale','observed_at'=>$now-1000,'data'=>['samples'=>[['timestamp'=>'old','cpu_percent'=>1.0,'memory_percent'=>2.0]]]],
            'storage_performance'=>['state'=>'stale','observed_at'=>$now-1000,'data'=>['supported'=>true,'samples'=>[['timestamp'=>'old','disk_mib_per_second'=>9.0]]]],
        ]]];
$baseCalls = 0;
$baseQuery = function (string $view='status', string $id='', string $search='', int $page=1, int $pageSize=50) use (&$baseCalls,$base): array {
    $baseCalls++; if ($view !== 'vm' || $id !== 'vm-1') return ['ok'=>true,'has_inventory'=>false,'data'=>null]; return $base;
};
$roster = ['schema'=>1,'domain'=>'roster','published_at'=>$now-1,'authoritative'=>['hash'=>str_repeat('a',64),'roster'=>['vm'=>['vm-1'],'host'=>[],'datastore'=>[]],'confirmed_at'=>$now-2], 'pending'=>null];
$properties = ['schema'=>1,'domain'=>'properties','published_at'=>$now,'authoritative_membership'=>false,'objects'=>[
    'vm-1'=>['kind'=>'vm','id'=>'vm-1','object_state'=>'fresh','fields'=>[
        'name'=>['state'=>'fresh','value'=>'new-name','collected_at'=>$now,'last_attempt_at'=>$now,'error'=>null],
        'runtime.powerState'=>['state'=>'fresh','value'=>'poweredOn','collected_at'=>$now,'last_attempt_at'=>$now,'error'=>null],
        'runtime.host'=>['state'=>'fresh','value'=>'host-1','collected_at'=>$now,'last_attempt_at'=>$now,'error'=>null],
        'config.hardware.numCPU'=>['state'=>'fresh','value'=>8,'collected_at'=>$now,'last_attempt_at'=>$now,'error'=>null],
        'config.hardware.memoryMB'=>['state'=>'fresh','value'=>16384,'collected_at'=>$now,'last_attempt_at'=>$now,'error'=>null],
        'guest.ipAddress'=>['state'=>'fresh','value'=>'2001:db8::1','collected_at'=>$now,'last_attempt_at'=>$now,'error'=>null],
        'config.annotation'=>['state'=>'fresh','value'=>'new notes','collected_at'=>$now,'last_attempt_at'=>$now,'error'=>null],
        'guest.hostName'=>['state'=>'stale','value'=>'old-hostname','collected_at'=>$now-900,'last_attempt_at'=>$now,'error'=>null],
    ]]
]];
$metricPoints = static fn(float $a, float $b): array => [
    ['observed_at'=>$now-20,'value'=>$a], ['observed_at'=>$now,'value'=>$b]
];
$metrics = ['schema'=>1,'domain'=>'metrics','published_at'=>$now,'authoritative_membership'=>false,'objects'=>[
    'vm-1'=>['kind'=>'vm','id'=>'vm-1','object_state'=>'fresh','series'=>[
        'cpu_percent'=>['state'=>'fresh','points'=>$metricPoints(10.0,20.0),'collected_at'=>$now,'observed_at'=>$now],
        'memory_percent'=>['state'=>'fresh','points'=>$metricPoints(30.0,40.0),'collected_at'=>$now,'observed_at'=>$now],
        'disk_kib_per_second'=>['state'=>'fresh','points'=>$metricPoints(1024.0,2048.0),'collected_at'=>$now,'observed_at'=>$now],
    ]]
]];
$store = new IntegrationVmwareFastStore(['roster'=>$roster,'properties'=>$properties,'metrics'=>$metrics]);
$fast = new IntegrationVmwareFastReader($store,300,300,300);
$allowed = true; $guard = static function () use (&$allowed): bool { return $allowed; }; $clock = static fn(): int => $now;

$rollback = new IntegrationVmwareFastOverlayReader($baseQuery,$fast,$guard,false,$clock);
$r = $rollback->query('vm','vm-1');
ov($r === $base,'disabled overlay is not byte-shape rollback');
ov(!isset($r['phase5h_overlay']),'disabled overlay leaked metadata');

$overlay = new IntegrationVmwareFastOverlayReader($baseQuery,$fast,$guard,true,$clock);
$r = $overlay->query('vm','vm-1');
ov($r['data']['name']==='new-name' && $r['data']['detail']['vm']['name']==='new-name','name overlay');
ov($r['data']['detail']['vm']['power_state']==='powered_on','power overlay');
ov($r['data']['detail']['vm']['host_external_id']==='host-1','host overlay');
ov($r['data']['detail']['vm']['cpu_count']===8 && $r['data']['detail']['vm']['memory_size_mib']===16384,'hardware overlay');
ov($r['data']['detail']['guest_identity']['primary_ip']==='2001:db8::1','IP overlay');
ov($r['data']['sections']['annotation']['data']['annotation']==='new notes','annotation overlay');
ov($r['data']['sections']['performance']['state']==='ok' && $r['data']['sections']['performance']['data']['samples'][1]['cpu_percent']===20.0,'performance overlay');
ov($r['data']['sections']['performance']['data']['samples'][1]['memory_percent']===40.0,'memory metric overlay');
ov($r['data']['sections']['storage_performance']['state']==='ok' && $r['data']['sections']['storage_performance']['data']['samples'][1]['disk_mib_per_second']===2.0,'storage metric overlay');
ov($r['data']['context']['datacenter_external_id']==='datacenter-1','rich base context lost');
ov($r['phase5h_overlay']['state']==='applied' && count($r['phase5h_overlay']['applied_fields'])===9,'overlay metadata');
ov(in_array('metrics.performance',$r['phase5h_overlay']['applied_fields'],true) && in_array('metrics.storage_performance',$r['phase5h_overlay']['applied_fields'],true),'metric overlay metadata');
ov($r['vcenter_requests']===0 && $r['inventory_files_written']===false,'local base contract changed');

// Stale fast property evidence never overwrites a possibly newer rich base value.
$store->domains['properties']['objects']['vm-1']['fields']['name']['state']='stale';
$r=$overlay->query('vm','vm-1');
ov($r['data']['name']==='old-name','stale fast name overlaid');
$store->domains['properties']=$properties;

// CPU/RAM is all-or-nothing on one sample timeline; disk can still refresh independently.
$store->domains['metrics']['objects']['vm-1']['series']['cpu_percent']['state']='stale';
$r=$overlay->query('vm','vm-1');
ov($r['data']['sections']['performance']['state']==='stale' && $r['data']['sections']['performance']['data']['samples'][0]['cpu_percent']===1.0,'stale fast performance must fall back');
ov($r['data']['sections']['storage_performance']['state']==='ok' && $r['data']['sections']['storage_performance']['data']['samples'][1]['disk_mib_per_second']===2.0,'fresh disk independent from stale cpu');
$store->domains['metrics']=$metrics;
$store->domains['metrics']['objects']['vm-1']['series']['memory_percent']['points'][0]['observed_at']++;
$r=$overlay->query('vm','vm-1');
ov($r['data']['sections']['performance']['state']==='stale','misaligned fast performance must fall back');
$store->domains['metrics']=$metrics;

// Unknown membership keeps the Phase5G local fallback; only fresh confirmed absence suppresses stale membership.
$unknown=$roster; $unknown['authoritative']=null; $store->domains['roster']=$unknown;
$r=$overlay->query('vm','vm-1');
ov($r['data']['name']==='old-name' && $r['phase5h_overlay']['state']==='membership_unknown','unknown membership fallback');
ov($r['data']['sections']['performance']['state']==='stale','unknown membership metric fallback');
$absent=$roster; $absent['authoritative']['roster']['vm']=[]; $store->domains['roster']=$absent;
ovfails(fn()=>$overlay->query('vm','vm-1'),'mirror_object_not_found');
$store->domains['roster']=$roster;
$staleAbsent=$absent; $staleAbsent['published_at']=$now-301; $store->domains['roster']=$staleAbsent;
$r=$overlay->query('vm','vm-1');
ov($r['data']['name']==='old-name' && $r['phase5h_overlay']['state']==='membership_unknown','stale roster absence must fall back');
$store->domains['roster']=$roster;

// Malformed fast data degrades to the existing local Phase5G card, without exposing the error text.
$bad=$properties; unset($bad['objects']['vm-1']['id']); $store->domains['properties']=$bad;
$r=$overlay->query('vm','vm-1');
ov($r['data']['name']==='old-name' && $r['phase5h_overlay']['state']==='unavailable','malformed fast fallback');
ov($r['data']['sections']['performance']['state']==='stale','malformed fast metric fallback');
$store->domains['properties']=$properties;

$allowed=false;
ovfails(fn()=>$overlay->query('vm','vm-1'),'mirror_access_denied');
$allowed=true;

// Unsupported views are exact pass-through until they receive dedicated compatibility coverage.
$statusBase=['ok'=>true,'has_inventory'=>true,'data'=>null,'state'=>'ready'];
$statusCalls=0; $statusQuery=static function(...$args) use (&$statusCalls,$statusBase):array{$statusCalls++;return $statusBase;};
$statusOverlay=new IntegrationVmwareFastOverlayReader($statusQuery,$fast,$guard,true,$clock);
ov($statusOverlay->query('status')===$statusBase && $statusCalls===1,'unsupported view not exact pass-through');
ov($baseCalls>=10,'base query not primary source');

// Producer-to-reader/overlay regression: publication cannot refresh old membership.
$regressionStart = $n;
$regressionFailures = [];
$checkRegression = static function (bool $condition, string $label) use (&$regressionFailures, &$n): void {
    ++$n;
    if (!$condition) $regressionFailures[] = $label;
};
$emptyRoster = ['vm'=>[], 'host'=>[], 'datastore'=>[]];
$presentRoster = ['vm'=>['vm-1'], 'host'=>[], 'datastore'=>[]];
$oldAbsent = IntegrationVmwareRosterReconciler::observe(null, $emptyRoster, true, $now-610);
$oldAbsent = IntegrationVmwareRosterReconciler::observe($oldAbsent, $emptyRoster, true, $now-600);
$incomplete = IntegrationVmwareRosterReconciler::observe($oldAbsent, $presentRoster, false, $now);
$pending = IntegrationVmwareRosterReconciler::observe($oldAbsent, $presentRoster, true, $now);
foreach (['incomplete'=>$incomplete, 'pending'=>$pending] as $label=>$observed) {
    $store->domains['roster']=$observed;
    $projection=$fast->object('vm','vm-1',$now);
    $checkRegression($projection['membership_state']==='unknown', $label.'_old_confirmation');
    try { $observedResult=$overlay->query('vm','vm-1'); }
    catch (Throwable $e) { $observedResult=null; }
    $checkRegression(is_array($observedResult) && $observedResult['data']['name']==='old-name' &&
        $observedResult['phase5h_overlay']['state']==='membership_unknown', $label.'_base_fallback');
}
// A fresh confirmation at the limit remains authoritative; one second older does not.
$boundary = IntegrationVmwareRosterReconciler::observe(null, $emptyRoster, true, $now-301);
$boundary = IntegrationVmwareRosterReconciler::observe($boundary, $emptyRoster, true, $now-300);
$boundary = IntegrationVmwareRosterReconciler::observe($boundary, $presentRoster, false, $now);
$checkRegression($fast::project($boundary, null, null, 'vm', 'vm-1', $now)['membership_state']==='absent_confirmed', 'confirmation_limit_inclusive');
$checkRegression($fast::project($boundary, null, null, 'vm', 'vm-1', $now+1)['membership_state']==='unknown', 'confirmation_limit_expired');
$freshAbsent = IntegrationVmwareRosterReconciler::observe($oldAbsent, $emptyRoster, true, $now-1);
$freshAbsent = IntegrationVmwareRosterReconciler::observe($freshAbsent, $emptyRoster, true, $now);
$store->domains['roster']=$freshAbsent;
try { $overlay->query('vm','vm-1'); $absentRejected=false; }
catch (RuntimeException $e) { $absentRejected=$e->getMessage()==='mirror_object_not_found'; }
$checkRegression($absentRejected, 'fresh_confirmed_absence');

// An unobserved producer record retains history but cannot replace the base card.
$batch = ['schema'=>1, 'authoritative_membership'=>false, 'objects'=>[
    'vm-1'=>['kind'=>'vm', 'id'=>'vm-1', 'state'=>'returned', 'collected_at'=>$now-10,
        'values'=>['name'=>'retained-fast-name'], 'property_states'=>['name'=>'available']]
]];
$produced = IntegrationVmwareFastDomain::mergeProperties(null, $batch, $now-10);
$batch['objects']['vm-1']=['kind'=>'vm', 'id'=>'vm-1', 'state'=>'unobserved'];
$produced = IntegrationVmwareFastDomain::mergeProperties($produced, $batch, $now);
$store->domains['roster']=$roster; $store->domains['properties']=$produced;
$projection=$fast->object('vm','vm-1',$now);
$checkRegression($projection['property_object_state']==='stale' && $projection['properties']['name']['state']==='stale', 'unobserved_field_stale');
$observedResult=$overlay->query('vm','vm-1');
$checkRegression($observedResult['data']['name']==='old-name' && $observedResult['data']['detail']['vm']['name']==='old-name', 'unobserved_base_fallback');
$batch['objects']['vm-1']=['kind'=>'vm', 'id'=>'vm-1', 'state'=>'returned', 'collected_at'=>$now,
    'values'=>['name'=>'fresh-fast-name'], 'property_states'=>['name'=>'available']];
$store->domains['properties']=IntegrationVmwareFastDomain::mergeProperties($produced, $batch, $now);
$checkRegression($overlay->query('vm','vm-1')['data']['name']==='fresh-fast-name', 'observed_recovery');
echo 'PHASE5H7_FRESHNESS_ASSERTIONS='.($n-$regressionStart)."\n";
if ($regressionFailures !== []) {
    echo 'PHASE5H7_FRESHNESS_FAILED='.implode(',', $regressionFailures)."\n";
    exit(1);
}

echo 'PHASE5H7_FAST_OVERLAY_ASSERTIONS='.$n."\n";
echo "PHASE5H7_PHASE5G_ROLLBACK_PARITY_OK\n";
echo "PHASE5H7_VM_FAST_OVERLAY_CONTRACT_OK\n";
echo "PHASE5H7_FAST_METRIC_OVERLAY_FALLBACK_OK\n";
echo "PHASE5H7_UNKNOWN_FALLBACK_ABSENT_SUPPRESSION_OK\n";
echo "PHASE5H7_OVERLAY_NO_FOREGROUND_PROVIDER_OK\n";
