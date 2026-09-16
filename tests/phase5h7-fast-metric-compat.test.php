<?php
require_once __DIR__ . '/../layers/phase5h7/functions/classes/class.IntegrationVmwareFastMetricCompat.php';
$n = 0;
function mc(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
$now = 1800000000;
$points = static function (array $values, ?int $start = null, int $step = 20) use ($now): array {
    $start ??= $now - 40; $out = [];
    foreach ($values as $i => $value) $out[] = ['observed_at'=>$start + $i * $step, 'value'=>$value];
    return $out;
};
$fast = ['kind'=>'vm','id'=>'vm-1','membership_state'=>'present','metrics'=>[
    'cpu_percent'=>['state'=>'fresh','collected_at'=>$now,'points'=>$points([10.0,20.5,30.0])],
    'memory_percent'=>['state'=>'fresh','collected_at'=>$now-1,'points'=>$points([40.0,50.0,60.0])],
    'disk_kib_per_second'=>['state'=>'fresh','collected_at'=>$now,'points'=>$points([1024.0,2048.0,3072.0])],
]];

$sections = IntegrationVmwareFastMetricCompat::sections($fast, 'vm', 'vm-1');
mc(array_keys($sections) === ['performance','storage_performance'], 'fresh sections');
mc($sections['performance']['state'] === 'ok' && $sections['performance']['observed_at'] === $now, 'performance state');
mc($sections['performance']['data']['sample_count'] === 3, 'performance count');
mc($sections['performance']['data']['provider']['refresh_rate'] === 20, 'performance interval');
mc($sections['performance']['data']['samples'][1]['cpu_percent'] === 20.5, 'cpu sample');
mc($sections['performance']['data']['samples'][1]['memory_percent'] === 50.0, 'memory sample');
mc($sections['performance']['data']['samples'][0]['timestamp'] === gmdate('Y-m-d\TH:i:s\Z', $now-40), 'timestamp contract');
mc($sections['performance']['data']['verify_tls'] === true, 'performance tls provenance');
mc($sections['storage_performance']['data']['supported'] === true, 'storage supported');
mc($sections['storage_performance']['data']['sample_count'] === 3, 'storage count');
mc($sections['storage_performance']['data']['samples'][1]['disk_kib_per_second'] === 2048.0, 'disk kib sample');
mc($sections['storage_performance']['data']['samples'][1]['disk_mib_per_second'] === 2.0, 'disk mib sample');
mc($sections['storage_performance']['data']['refresh_rate'] === 20, 'storage interval');
mc($sections['storage_performance']['data']['verify_tls'] === true, 'storage tls provenance');

// Stale or partial evidence does not evict the Phase5G fallback section.
$stale = $fast; $stale['metrics']['cpu_percent']['state'] = 'stale';
$sections = IntegrationVmwareFastMetricCompat::sections($stale, 'vm', 'vm-1');
mc(!isset($sections['performance']) && isset($sections['storage_performance']), 'stale cpu fallback independent disk');
$partial = $fast; unset($partial['metrics']['memory_percent']);
mc(!isset(IntegrationVmwareFastMetricCompat::sections($partial, 'vm', 'vm-1')['performance']), 'partial pair not invented');
$unsupported = $fast; $unsupported['metrics']['disk_kib_per_second']['state'] = 'unsupported';
mc(!isset(IntegrationVmwareFastMetricCompat::sections($unsupported, 'vm', 'vm-1')['storage_performance']), 'unsupported disk fallback');

// CPU and RAM must describe the same sample times. A mismatch is not merged by index.
$misaligned = $fast; $misaligned['metrics']['memory_percent']['points'][1]['observed_at']++;
mc(!isset(IntegrationVmwareFastMetricCompat::sections($misaligned, 'vm', 'vm-1')['performance']), 'misaligned pair rejected');

// Provider interval is only inferred when all adjacent timestamps agree exactly and remain in real-time bounds.
$irregular = $fast;
$irregular['metrics']['cpu_percent']['points'][2]['observed_at'] -= 5;
$irregular['metrics']['memory_percent']['points'][2]['observed_at'] -= 5;
$sections = IntegrationVmwareFastMetricCompat::sections($irregular, 'vm', 'vm-1');
mc($sections['performance']['data']['provider']['refresh_rate'] === null, 'irregular interval unknown');
mc($sections['performance']['data']['samples'][0]['interval_seconds'] === null, 'irregular sample interval unknown');
$slow = $fast;
$slow['metrics']['cpu_percent']['points'] = $points([10.0,20.0], $now-400, 400);
$slow['metrics']['memory_percent']['points'] = $points([30.0,40.0], $now-400, 400);
$sections = IntegrationVmwareFastMetricCompat::sections($slow, 'vm', 'vm-1');
mc($sections['performance']['data']['provider']['refresh_rate'] === null, 'non realtime interval not inferred');
mc($sections['performance']['data']['samples'][0]['interval_seconds'] === null, 'non realtime sample interval unknown');

// Storage nulls are omitted just like the retained collector; never turn missing throughput into zero.
$nullDisk = $fast; $nullDisk['metrics']['disk_kib_per_second']['points'] = $points([null,1024.0,null]);
$sections = IntegrationVmwareFastMetricCompat::sections($nullDisk, 'vm', 'vm-1');
mc($sections['storage_performance']['data']['supported'] === false && $sections['storage_performance']['data']['sample_count'] === 1, 'null disk omitted');
mc($sections['storage_performance']['data']['reason'] === 'insufficient_samples', 'insufficient disk reason');

// Empty fresh CPU/RAM series remains a valid saved section and lets the retained renderer show insufficient data.
$empty = $fast; $empty['metrics']['cpu_percent']['points'] = []; $empty['metrics']['memory_percent']['points'] = [];
$sections = IntegrationVmwareFastMetricCompat::sections($empty, 'vm', 'vm-1');
mc(isset($sections['performance']) && $sections['performance']['data']['sample_count'] === 0 && $sections['performance']['observed_at'] === null, 'empty performance contract');

// Malformed/unsafe numeric shapes fail closed per section.
$bad = $fast; $bad['metrics']['cpu_percent']['points'][1]['value'] = INF;
mc(!isset(IntegrationVmwareFastMetricCompat::sections($bad, 'vm', 'vm-1')['performance']), 'nonfinite rejected');
$bad = $fast; $bad['metrics']['cpu_percent']['points'][1]['value'] = 101;
mc(!isset(IntegrationVmwareFastMetricCompat::sections($bad, 'vm', 'vm-1')['performance']), 'percent range rejected');
$bad = $fast; $bad['metrics']['disk_kib_per_second']['points'][1]['value'] = -1;
mc(!isset(IntegrationVmwareFastMetricCompat::sections($bad, 'vm', 'vm-1')['storage_performance']), 'negative disk rejected');

$unknown = $fast; $unknown['membership_state'] = 'unknown';
mc(IntegrationVmwareFastMetricCompat::sections($unknown, 'vm', 'vm-1') === [], 'unknown membership fail closed');
$host = $fast; $host['kind'] = 'host'; $host['id'] = 'host-7';
$sections = IntegrationVmwareFastMetricCompat::sections($host, 'host', 'host-7');
mc(isset($sections['performance']) && $sections['performance']['data']['entity_type'] === 'host', 'host contract ready');
try {
    IntegrationVmwareFastMetricCompat::sections($fast, 'datastore', 'datastore-1');
    throw new RuntimeException('identity_not_rejected');
} catch (InvalidArgumentException $e) {
    mc($e->getMessage() === 'fast_metric_compat_identity_invalid', 'identity rejected');
}

echo 'PHASE5H7_FAST_METRIC_COMPAT_ASSERTIONS='.$n."\n";
echo "PHASE5H7_FAST_METRIC_RENDERER_CONTRACT_OK\n";
echo "PHASE5H7_FAST_METRIC_NO_ZERO_FILL_OK\n";
echo "PHASE5H7_FAST_METRIC_STALE_FALLBACK_OK\n";
