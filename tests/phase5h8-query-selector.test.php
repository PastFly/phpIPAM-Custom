<?php
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php';
require_once __DIR__ . '/../layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php';
$n = 0;
function qs(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
function qsfails(callable $f, string $code): void { global $n; try { $f(); } catch (Throwable $e) { ++$n; if ($e->getMessage() === $code) return; throw $e; } throw new RuntimeException('expected_' . $code); }

$base = new class {
    public array $calls = [];
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        $this->calls[] = [$view,$id,$search,$page,$pageSize];
        return [
            'ok'=>true,'state'=>'ready','has_inventory'=>true,'last_error'=>null,
            'progress'=>['completed'=>1,'total'=>1],'generation'=>'phase5g-base','published_at'=>1800000000,
            'collection_started_at'=>1799999990,'age_seconds'=>10,'retry_at'=>null,
            'vcenter_requests'=>0,'inventory_files_written'=>false,'data'=>null,
            'stale'=>false,'clock_skew'=>false,'heartbeat_at'=>1800000000,'worker_silent'=>false,
            'telemetry'=>['state'=>'ok'],
        ];
    }
};

// Disabled mode is the construction-level rollback: no overlay factory call at all.
$disabledFactoryCalls = 0;
$disabled = new IntegrationVmwareInventoryQuerySelector($base, function ($reader) use (&$disabledFactoryCalls): object {
    ++$disabledFactoryCalls; throw new RuntimeException('disabled_factory_must_not_run');
}, false);
$native = new IntegrationVmwareInventoryNative($disabled);
$result = $native->response('status', ['force_refresh'=>'1']);
qs($result['format']==='json' && $result['status']===200, 'disabled native response');
qs($result['body']['generation']==='phase5g-base', 'disabled base result');
qs($disabledFactoryCalls===0, 'disabled factory invoked');
qs($base->calls===[['status','','',1,50]], 'disabled query call shape');
qs($result['body']['cache']['refresh_required']===false, 'disabled force refresh remains local');

// Enabled mode is lazy and memoized: construct only on first query and reuse the same overlay object.
$overlay = new class {
    public int $calls = 0;
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        ++$this->calls;
        return [
            'ok'=>true,'state'=>'ready','has_inventory'=>true,'last_error'=>null,
            'progress'=>['completed'=>2,'total'=>2],'generation'=>'phase5h-overlay','published_at'=>1800000001,
            'collection_started_at'=>1800000000,'age_seconds'=>1,'retry_at'=>null,
            'vcenter_requests'=>0,'inventory_files_written'=>false,'data'=>null,
            'stale'=>false,'clock_skew'=>false,'heartbeat_at'=>1800000001,'worker_silent'=>false,
            'telemetry'=>['state'=>'ok'],'phase5h_overlay'=>['state'=>'applied'],
        ];
    }
};
$enabledFactoryCalls = 0; $seenBase = null;
$enabled = new IntegrationVmwareInventoryQuerySelector($base, function ($reader) use (&$enabledFactoryCalls, &$seenBase, $overlay): object {
    ++$enabledFactoryCalls; $seenBase = $reader; return $overlay;
}, true);
qs($enabledFactoryCalls===0, 'enabled factory eager');
$nativeOverlay = new IntegrationVmwareInventoryNative($enabled);
$first = $nativeOverlay->response('status', []);
qs($first['body']['generation']==='phase5h-overlay', 'enabled overlay result');
qs(($first['body']['phase5h_overlay']['state'] ?? null)==='applied', 'overlay metadata survives');
qs($enabledFactoryCalls===1 && $seenBase===$base, 'factory base identity');
qs($overlay->calls===1, 'overlay first call');
$second = $nativeOverlay->response('status', []);
qs($second['body']['generation']==='phase5h-overlay' && $enabledFactoryCalls===1 && $overlay->calls===2, 'overlay memoized');

// Bad selection input fails closed; explicit enablement may not silently resolve back to the base object.
qsfails(fn()=>new IntegrationVmwareInventoryQuerySelector('reader', fn($r)=>$overlay), 'invalid_inventory_query_reader');
qsfails(fn()=>new IntegrationVmwareInventoryQuerySelector(new stdClass(), fn($r)=>$overlay), 'invalid_inventory_query_reader');
$privateQuery = new class { private function query(): array { return []; } };
qsfails(fn()=>new IntegrationVmwareInventoryQuerySelector($privateQuery, fn($r)=>$overlay), 'invalid_inventory_query_reader');
$same = new IntegrationVmwareInventoryQuerySelector($base, fn($r)=>$r, true);
qsfails(fn()=>$same->query(), 'inventory_overlay_unavailable');
$bad = new IntegrationVmwareInventoryQuerySelector($base, fn($r)=>new stdClass(), true);
qsfails(fn()=>$bad->query(), 'invalid_inventory_query_reader');
$factoryFailure = new IntegrationVmwareInventoryQuerySelector($base, function ($r): object { throw new RuntimeException('factory_failed'); }, true);
qsfails(fn()=>$factoryFailure->query(), 'factory_failed');

// Reader authorization errors remain visible to the route layer; selector never swallows them.
$deniedBase = new class {
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    { throw new RuntimeException('mirror_access_denied'); }
};
qsfails(fn()=>(new IntegrationVmwareInventoryQuerySelector($deniedBase, fn($r)=>$overlay, false))->query(), 'mirror_access_denied');
qs($disabledFactoryCalls===0 && $enabledFactoryCalls===1, 'factory count invariant');

echo 'PHASE5H8_QUERY_SELECTOR_ASSERTIONS='.$n."\n";
echo "PHASE5H8_SELECTOR_DISABLED_FACTORY_ISOLATION_OK\n";
echo "PHASE5H8_SELECTOR_LAZY_OVERLAY_OK\n";
echo "PHASE5H8_SELECTOR_NO_SILENT_BASE_ON_ENABLE_OK\n";
echo "PHASE5H8_SELECTOR_NATIVE_QUERY_COMPAT_OK\n";
