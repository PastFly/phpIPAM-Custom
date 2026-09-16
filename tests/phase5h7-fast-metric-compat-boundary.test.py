#!/usr/bin/env python3
from pathlib import Path
p = Path(__file__).resolve().parents[1] / 'layers/phase5h7/functions/classes/class.IntegrationVmwareFastMetricCompat.php'
s = p.read_text()
n = 0
def ok(value, label):
    global n
    n += 1
    if not value:
        raise AssertionError(label)

for token in [
    'final class IntegrationVmwareFastMetricCompat',
    'public static function sections',
    "'cpu_percent'", "'memory_percent'", "'disk_kib_per_second'",
    "'performance'", "'storage_performance'",
    "'sample_count' => count($samples)",
    "'verify_tls' => true",
    "'insufficient_samples'",
    "gmdate('Y-m-d\\TH:i:s\\Z'",
    'private static function stableInterval',
    "($fast['membership_state'] ?? null) !== 'present'",
]:
    ok(token in s, 'required:' + token)

for token in [
    'IntegrationVmwareVcenter', 'IntegrationVmwareFastStore', 'PropertyCollector', 'QueryPerf',
    'curl_', 'SoapClient', 'username', 'password', 'endpoint', 'credential',
    'file_put_contents', 'fopen(', 'unlink(', 'mkdir(', '->write(', '->publish(', '->update(',
    'system(', 'exec(', 'shell_exec(', 'proc_open(', 'time()', 'microtime(',
]:
    ok(token not in s, 'forbidden:' + token)

ok("if ($point['value'] === null) continue;" in s, 'null_disk_not_zero')
ok("$value > 100" in s, 'percent_bounds')
ok('!is_finite($value)' in s, 'finite_numeric_guard')
ok("return []" in s, 'fail_closed_empty_projection')
print(f'PHASE5H7_FAST_METRIC_COMPAT_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H7_FAST_METRIC_PURE_PROJECTION_OK')
print('PHASE5H7_FAST_METRIC_NO_PROVIDER_WRITE_DEPENDENCY_OK')
