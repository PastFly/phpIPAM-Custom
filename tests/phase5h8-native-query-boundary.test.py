#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[1]
p = root / 'layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php'
s = p.read_text(); n = 0
def ok(value, label):
    global n
    n += 1
    if not value:
        raise AssertionError(label)

for token in [
    'final class IntegrationVmwareInventoryNative',
    'private $reader;',
    'public function __construct($reader, $addressLinks = null)',
    "!is_object($reader) || !is_callable([$reader, 'query'])",
    "throw new InvalidArgumentException('invalid_inventory_query_reader')",
    '$entry = $this->reader->query($view, $id);',
    "'vcenter_requests' => 0",
    "'inventory_files_written' => false",
]:
    ok(token in s, 'required:' + token)

for token in [
    'private IntegrationVmwareInventoryReader $reader',
    'IntegrationVmwareFastReader', 'IntegrationVmwareFastOverlayReader', 'IntegrationVmwareFastMetricCompat',
    'IntegrationVmwareFastStore', 'IntegrationVmwareBatchSession', 'PropertyCollector', 'QueryPerf',
    'curl_', 'SoapClient', 'username', 'password', 'endpoint', 'credential',
    'file_put_contents', 'fopen(', 'unlink(', 'mkdir(', '->publish(', '->update(',
    'system(', 'exec(', 'shell_exec(', 'proc_open(',
]:
    ok(token not in s, 'forbidden:' + token)

ok(s.count('$this->reader->query(') == 1, 'single_query_boundary')
ok('force_refresh' not in s or 'never schedules or performs collection' in s, 'force_refresh_non_collecting')
print(f'PHASE5H8_NATIVE_QUERY_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H8_NATIVE_NO_PHASE5H_COUPLING_OK')
print('PHASE5H8_NATIVE_NO_PROVIDER_WRITE_DEPENDENCY_OK')
