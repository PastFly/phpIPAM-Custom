<?php
require_once __DIR__ . '/../layers/phase5g4/functions/classes/class.IntegrationVmwareInventoryReadiness.php';
$n = 0;
function check($ok) { global $n; $n++; if (!$ok) throw new RuntimeException('readiness_assertion_' . $n); }
function rejected($fn) { try { $fn(); } catch (Throwable $e) { return true; } return false; }
$t = time();
$objects = [];
foreach (['vm', 'host', 'datastore'] as $kind) {
    $id = $kind . '-1';
    $objects[$kind][$id] = ['collected_at' => $t, 'data' => ['ok' => true, 'id' => $id,
        'detail' => ['vm' => [], 'datastore' => []], 'context' => [], 'summary' => [], 'sections' => []]];
}
$c = ['state' => 'ready', 'generation' => str_repeat('a', 32), 'started_at' => $t - 60,
    'published_at' => $t, 'inventory' => ['objects' => $objects, 'core' => ['ok' => true,
        'topology' => ['datacenters' => [['external_id' => 'datacenter-1']], 'vms' => [['external_id' => 'vm-1']], 'hosts' => [['external_id' => 'host-1']]],
        'datastores' => ['datastores' => [['external_id' => 'datastore-1']]]],
        'host_folders' => ['data' => ['ok' => true]], 'object_folders' => ['data' => ['ok' => true]],
        'search' => ['data' => ['ok' => true, 'rows' => [['vm_external_id' => 'vm-1']]]]]];
$metric = static function ($id, $epoch) use ($t) {
    $p = ['state' => 'ok', 'collected_at' => $t, 'data' => ['entity_type' => explode('-', $id)[0],
        'entity_external_id' => $id, 'verify_tls' => true, 'supported' => false, 'samples' => []]];
    return ['performance' => $p, 'storage_performance' => $p];
};
$read = static fn($x, $m = null) => IntegrationVmwareInventoryReadiness::inspect($x, $m ?? $metric, $t);
check(!$read(null)['ready']);
check($read(['blocked' => true])['state'] === 'access_denied');
$r = $read($c); check($r['ready']); check($r['metric_sections'] === 4); check($r['metric_expected'] === 4);
check(!$read($c, static fn() => [])['ready']);
foreach (['host_folders', 'object_folders', 'search'] as $part) {
    $bad = $c; $bad['inventory'][$part]['data']['partial'] = true; check(rejected(fn() => $read($bad)));
}
foreach (['vm', 'host', 'datastore'] as $kind) {
    $bad = $c; $bad['inventory']['objects'][$kind][$kind . '-1']['data']['id'] = 'wrong'; check(rejected(fn() => $read($bad)));
    $bad = $c; $bad['inventory']['objects'][$kind][$kind . '-1']['collected_at'] = $t + 100; check(rejected(fn() => $read($bad)));
}
foreach (['started_at', 'published_at'] as $field) {
    $bad = $c; $bad[$field] = $t + 100; check(rejected(fn() => $read($bad)));
}
$bad = $c; $bad['started_at'] = $t - 15000; check(rejected(fn() => $read($bad)));
$bad = $c; $bad['inventory']['search']['data']['rows'] = []; check(rejected(fn() => $read($bad)));
$bad = $c; $bad['inventory']['core']['topology']['vms'][] = ['external_id' => 'vm-99']; check(rejected(fn() => $read($bad)));
$bad = $c; $bad['inventory']['objects']['vm']['vm-1']['data']['sections']['annotation'] = ['state' => 'unavailable'];
check($read($bad)['ready']); check($read($bad)['optional_unavailable'] === 1);
$badMetric = static function ($id, $epoch) use ($metric) { $parts = $metric($id, $epoch); $parts['performance']['state'] = 'unavailable'; return $parts; };
check($read($c, $badMetric)['state'] === 'sampling');
check($read($c, $badMetric)['metric_unavailable'] === 2);
$badMetric = static function ($id, $epoch) use ($metric) { $parts = $metric($id, $epoch); $parts['performance']['data']['verify_tls'] = false; return $parts; };
check(!$read($c, $badMetric)['ready']);
$called = 0; check($read(['blocked' => true], function () use (&$called) { $called++; return []; })['state'] === 'access_denied'); check($called === 0);
$bad = $c; $bad['inventory']['objects']['vm'] = []; $bad['inventory']['core']['topology']['vms'] = []; $bad['inventory']['search']['data']['rows'] = [];
check($read($bad)['state'] === 'unexpected_empty_inventory');
check(!str_contains(json_encode($r), 'secret'));
echo "PHASE5G4_READINESS_ASSERTIONS=$n\n";
