<?php
final class IntegrationVmwareFastStore {
    public function __construct(public array $domains) {}
    public function read(string $domain): ?array { return $this->domains[$domain] ?? null; }
}
require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php';
require_once __DIR__ . '/../layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php';
require_once __DIR__ . '/../layers/phase5h7/functions/classes/class.IntegrationVmwareFastOverlayReader.php';
$n = 0;
function ov(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
function ovfails(callable $f, string $code): void { global $n; try { $f(); } catch (Throwable $e) { ++$n; if ($e->getMessage() === $code) return; throw $e; } throw new RuntimeException('expected_' . $code); }
$now = 1800000000;
$base = ['ok'=>true,'state'=>'ready','has_inventory'=>true,'stale'=>true,'age_seconds'=>1000,'vcenter_requests'=>0,'inventory_files_written'=>false,
    'data'=>['ok'=>true,'name'=>'old-name','detail'=>['vm'=>['name'=>'old-name','power_state'=>'powered_off','host_external_id'=>'host-9','cpu_count'=>2,'memory_size_mib'=>4096],
        'guest_identity'=>['primary_ip'=>'192.0.2.9','guest_os'=>'Debian']], 'context'=>['datacenter_external_id'=>'datacenter-1'],
        'sections'=>['annotation'=>['state'=>'ok','observed_at'=>$now-1000,'data'=>['annotation'=>'old notes']]]]];
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
$metrics = ['schema'=>1,'domain'=>'metrics','published_at'=>$now,'authoritative_membership'=>false,'objects'=>[]];
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
ov($r['data']['context']['datacenter_external_id']==='datacenter-1','rich base context lost');
ov($r['phase5h_overlay']['state']==='applied' && count($r['phase5h_overlay']['applied_fields'])===7,'overlay metadata');
ov($r['vcenter_requests']===0 && $r['inventory_files_written']===false,'local base contract changed');

// Stale fast evidence never overwrites a possibly newer rich base value.
$store->domains['properties']['objects']['vm-1']['fields']['name']['state']='stale';
$r=$overlay->query('vm','vm-1');
ov($r['data']['name']==='old-name','stale fast name overlaid');
$store->domains['properties']=$properties;

// Unknown membership keeps the Phase5G local fallback; only fresh confirmed absence suppresses stale membership.
$unknown=$roster; $unknown['authoritative']=null; $store->domains['roster']=$unknown;
$r=$overlay->query('vm','vm-1');
ov($r['data']['name']==='old-name' && $r['phase5h_overlay']['state']==='membership_unknown','unknown membership fallback');
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
$store->domains['properties']=$properties;

$allowed=false;
ovfails(fn()=>$overlay->query('vm','vm-1'),'mirror_access_denied');
$allowed=true;

// Unsupported views are exact pass-through until they receive dedicated compatibility coverage.
$statusBase=['ok'=>true,'has_inventory'=>true,'data'=>null,'state'=>'ready'];
$statusCalls=0; $statusQuery=static function(...$args) use (&$statusCalls,$statusBase):array{$statusCalls++;return $statusBase;};
$statusOverlay=new IntegrationVmwareFastOverlayReader($statusQuery,$fast,$guard,true,$clock);
ov($statusOverlay->query('status')===$statusBase && $statusCalls===1,'unsupported view not exact pass-through');
ov($baseCalls>=7,'base query not primary source');

echo 'PHASE5H7_FAST_OVERLAY_ASSERTIONS='.$n."\n";
echo "PHASE5H7_PHASE5G_ROLLBACK_PARITY_OK\n";
echo "PHASE5H7_VM_FAST_OVERLAY_CONTRACT_OK\n";
echo "PHASE5H7_UNKNOWN_FALLBACK_ABSENT_SUPPRESSION_OK\n";
echo "PHASE5H7_OVERLAY_NO_FOREGROUND_PROVIDER_OK\n";
