<?php
require_once __DIR__ . '/../tools/phase5g4-diagnose.php';
$checks = 0;
function check($truth): void { global $checks; $checks++; if (!$truth) throw new RuntimeException('assertion_' . $checks); }
$now = 1800000000;
$good = ['state' => 'ok', 'collected_at' => $now, 'data' => ['entity_external_id' => 'vm-1', 'entity_type' => 'vm', 'verify_tls' => true, 'samples' => []]];
check(Phase5G4Diagnostic::metricReason(null, 'vm', 'vm-1', $now) === 'missing');
check(Phase5G4Diagnostic::metricReason($good, 'vm', 'vm-1', $now) === 'ready');
foreach (['stale', 'unavailable'] as $s) check(Phase5G4Diagnostic::metricReason(array_replace($good, ['state' => $s]), 'vm', 'vm-1', $now) === 'state_' . $s);
foreach ([[null, 'receipt_time_missing'], [$now + 61, 'receipt_time_future'], [$now - 14401, 'receipt_time_expired'], [$now - 14400, 'ready']] as [$time, $reason]) {
    check(Phase5G4Diagnostic::metricReason(array_replace($good, ['collected_at' => $time]), 'vm', 'vm-1', $now) === $reason);
}
foreach (['entity_type' => 'host', 'entity_external_id' => 'vm-2', 'verify_tls' => false, 'samples' => null] as $k => $v) {
    $bad = $good; $bad['data'][$k] = $v;
    check(Phase5G4Diagnostic::metricReason($bad, 'vm', 'vm-1', $now) === 'payload_invalid');
}
$current = ['state' => 'collecting', 'access_epoch' => 'initial', 'inventory' => ['objects' => ['vm' => [
    'vm-1' => ['data' => ['name' => 'SECRET_HOSTNAME', 'sections' => ['guest_network' => ['state' => 'unavailable', 'error' => 'optional_read_failed']]]],
    'vm-2' => ['data' => ['sections' => ['SECRET_SECTION' => ['state' => 'SECRET_STATE', 'error' => 'SECRET_ERROR']]]]
], 'host' => [], 'datastore' => []]]];
$parts = ['vm-1' => ['performance' => $good, 'storage_performance' => ['state' => 'unavailable', 'error' => 'sample_unavailable', 'failures' => 3, 'attempted_at' => $now - 100, 'retry_at' => $now + 20]],
    'vm-2' => ['performance' => ['state' => 'stale', 'error' => 'tls', 'failures' => 2, 'attempted_at' => $now - 200]]];
$state = ['state' => 'sampling', 'cursor' => 5, 'completed' => 5, 'total' => 6, 'last_error' => 'SECRET_EXCEPTION', 'due' => ['vm-1:storage_performance' => $now + 40], 'SECRET' => 'RAW_SECRET'];
$read = static function ($id, $epoch) use ($parts): array { check($epoch === 'initial'); return $parts[$id]; };
$out = Phase5G4Diagnostic::summarize($current, $read, $state, $now);
check($out['metric_expected'] === 4 && $out['metric_ready'] === 1 && $out['metric_missing'] === 1 && $out['metric_not_ready'] === 3);
check($out['optional_not_ok'] === 2 && $out['optional_groups']['vm/other/other/other'] === 1);
$g = $out['metric_groups']['vm/storage_performance/state_unavailable/sample_unavailable'];
check($g['count'] === 1 && $g['attempted'] === 1 && $g['failures_min'] === 3 && $g['failures_max'] === 3);
check($g['attempt_age_min_seconds'] === 100 && $g['attempt_age_max_seconds'] === 100 && $g['retry_in_future'] === 1 && $g['due_in_future'] === 1);
check($out['sampler']['last_error'] === 'other' && $out['sampler']['cursor'] === 5);
$encoded = json_encode($out, JSON_THROW_ON_ERROR);
foreach (['SECRET', 'RAW_SECRET', 'vm-1', 'vm-2', 'initial'] as $secret) check(!str_contains($encoded, $secret));
$blocked = Phase5G4Diagnostic::summarize(['blocked' => true], static function () { throw new RuntimeException('must_not_read'); }, [], $now);
check($blocked['state'] === 'access_denied');
$empty = Phase5G4Diagnostic::summarize(null, static function () { throw new RuntimeException('must_not_read'); }, [], $now);
check($empty['has_inventory'] === false && $empty['metric_expected'] === 0);
$wire = file_get_contents(__DIR__ . '/../tools/phase5g4-diagnose.php');
foreach (['->exclusive(', '->write(', '->saveState(', 'workerRuntime(', 'curl_exec(', 'file_put_contents(', 'IntegrationSecretCipher'] as $forbidden) check(!str_contains($wire, $forbidden));
check(str_contains($wire, "fopen(\$path, 'rb')") && str_contains($wire, 'LOCK_SH | LOCK_NB'));
echo 'PHASE5G4_DIAGNOSTIC_ASSERTIONS=' . $checks . "\nPHASE5G4_AGGREGATE_ONLY_DIAGNOSTIC_TEST_OK\n";
