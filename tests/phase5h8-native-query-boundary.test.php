<?php
require_once __DIR__ . '/../layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php';
$n = 0;
function nq(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
function nqfails(callable $f, string $code): void { global $n; try { $f(); } catch (Throwable $e) { ++$n; if ($e->getMessage() === $code) return; throw $e; } throw new RuntimeException('expected_' . $code); }

final class Phase5h8LegacyLikeReader
{
    public array $calls = [];
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        $this->calls[] = [$view,$id,$search,$page,$pageSize];
        return [
            'ok'=>true,'state'=>'ready','has_inventory'=>true,'last_error'=>null,
            'progress'=>['completed'=>1,'total'=>1],'generation'=>'gen-1','published_at'=>1800000000,
            'collection_started_at'=>1799999990,'age_seconds'=>10,'retry_at'=>null,
            'vcenter_requests'=>0,'inventory_files_written'=>false,'data'=>null,
            'stale'=>false,'clock_skew'=>false,'heartbeat_at'=>1800000000,'worker_silent'=>false,
            'telemetry'=>['state'=>'ok'],
        ];
    }
}

// Existing query-object behavior remains accepted without any Phase5H dependency.
$legacy = new Phase5h8LegacyLikeReader();
$native = new IntegrationVmwareInventoryNative($legacy);
$result = $native->response('status', []);
nq($result['format'] === 'json' && $result['status'] === 200, 'status response contract');
nq($legacy->calls === [['status','','',1,50]], 'legacy query call shape');
nq($result['body']['state'] === 'ready' && $result['body']['has_inventory'] === true, 'legacy status body');
nq($result['body']['cache']['state'] === 'fresh' && $result['body']['cache']['refresh_required'] === false, 'legacy cache contract');
nq($result['body']['vcenter_requests'] === 0 && $result['body']['inventory_files_written'] === false, 'local-only metadata preserved');

// An overlay-like object with only the same query() surface is sufficient.
$overlay = new class {
    public int $calls = 0;
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        ++$this->calls;
        return [
            'ok'=>true,'state'=>'ready','has_inventory'=>true,'last_error'=>null,
            'progress'=>['completed'=>2,'total'=>2],'generation'=>'fast-overlay','published_at'=>1800000001,
            'collection_started_at'=>1800000000,'age_seconds'=>1,'retry_at'=>null,
            'vcenter_requests'=>0,'inventory_files_written'=>false,'data'=>null,
            'stale'=>false,'clock_skew'=>false,'heartbeat_at'=>1800000001,'worker_silent'=>false,
            'telemetry'=>['state'=>'ok'],'phase5h_overlay'=>['state'=>'applied'],
        ];
    }
};
$nativeOverlay = new IntegrationVmwareInventoryNative($overlay);
$result = $nativeOverlay->response('status', ['force_refresh'=>'1']);
nq($overlay->calls === 1, 'overlay query called once');
nq($result['body']['generation'] === 'fast-overlay', 'overlay-like result accepted');
nq(($result['body']['phase5h_overlay']['state'] ?? null) === 'applied', 'overlay metadata preserved');
nq($result['body']['cache']['refresh_required'] === false, 'force refresh remains non-collecting');

// Constructor fails closed for a closure, scalar or object without a public callable query().
nqfails(fn()=>new IntegrationVmwareInventoryNative(static fn(): array => []), 'invalid_inventory_query_reader');
nqfails(fn()=>new IntegrationVmwareInventoryNative('reader'), 'invalid_inventory_query_reader');
nqfails(fn()=>new IntegrationVmwareInventoryNative(new stdClass()), 'invalid_inventory_query_reader');
$privateQuery = new class { private function query(): array { return []; } };
nqfails(fn()=>new IntegrationVmwareInventoryNative($privateQuery), 'invalid_inventory_query_reader');

// Reader exceptions are not swallowed or converted by the native adapter; route error mapping stays external.
$failing = new class {
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    { throw new RuntimeException('mirror_access_denied'); }
};
nqfails(fn()=>(new IntegrationVmwareInventoryNative($failing))->response('status', []), 'mirror_access_denied');

echo 'PHASE5H8_NATIVE_QUERY_ASSERTIONS='.$n."\n";
echo "PHASE5H8_LEGACY_QUERY_OBJECT_COMPAT_OK\n";
echo "PHASE5H8_OVERLAY_QUERY_OBJECT_COMPAT_OK\n";
echo "PHASE5H8_NATIVE_QUERY_FAIL_CLOSED_OK\n";
