#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
source = (ROOT / 'layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php').read_text()
checks = 0

def ok(value, label):
    global checks
    checks += 1
    if not value:
        raise AssertionError(label)

for required in [
    'final class IntegrationVmwareFastReader',
    'private IntegrationVmwareFastStore $store',
    "$this->store->read('roster')",
    "$this->store->read('properties')",
    "$this->store->read('metrics')",
    "'source' => 'phase5h_fast'",
    "'local_only' => true",
    'IntegrationVmwareRosterReconciler::membership',
    "if ($membership !== 'present') return $result;",
]:
    ok(required in source, 'required:' + required)

for forbidden in [
    'IntegrationVmwareBatchSession', 'IntegrationVmwareVcenter', 'PropertyCollector', 'QueryPerf',
    'curl_', 'SoapClient', 'username', 'password', 'endpoint',
    "->publish(", "->update(", 'file_put_contents', 'unlink(', 'system(', 'exec(', 'shell_exec(',
]:
    ok(forbidden not in source, 'forbidden:' + forbidden)

ok(source.count("$this->store->read(") == 3, 'read_only_three_domains')
ok('authoritative_membership' in source, 'independent_domain_contract')
ok("'absent_confirmed'" not in source or "membership" in source, 'absence_via_roster')

print(f'PHASE5H7_LOCAL_READER_BOUNDARY_ASSERTIONS={checks}')
print('PHASE5H7_NO_FOREGROUND_VCENTER_DEPENDENCY_OK')
print('PHASE5H7_READ_ONLY_FAST_STORE_BOUNDARY_OK')
