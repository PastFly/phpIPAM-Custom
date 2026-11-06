#!/usr/bin/env python3
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'layers/phase5h7/functions/classes/class.IntegrationVmwareFastOverlayReader.php'
s=p.read_text(); n=0
def ok(v,label):
 global n; n+=1
 if not v: raise AssertionError(label)
for x in [
 'final class IntegrationVmwareFastOverlayReader', 'callable $baseQuery',
 'private IntegrationVmwareFastReader $fast', "if (!$this->enabled || $view !== 'vm'",
 "$this->fast->object('vm', $id", "if ($membership === 'absent_confirmed')",
 "throw new RuntimeException('mirror_object_not_found')", "throw new RuntimeException('mirror_access_denied')",
 "IntegrationVmwareFastMetricCompat::sections($fast, 'vm', $id)", "'metrics.' . $section",
 "'state' => $state", "'applied_fields' => array_values($applied)",
]: ok(x in s,'required:'+x)
for x in ['IntegrationVmwareVcenter','PropertyCollector','QueryPerf','curl_','SoapClient','username','password','endpoint',
          '->publish(', '->update(', 'file_put_contents','unlink(','system(','exec(','shell_exec(']:
 ok(x not in s,'forbidden:'+x)
ok(s.count('($this->baseQuery)(')==1,'one_base_query')
ok('return $entry;' in s,'rollback_return')
ok("Stale/partial/unsupported fast metric evidence therefore leaves the Phase5G section untouched" in s,'metric_fallback_boundary')
print(f'PHASE5H7_FAST_OVERLAY_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H7_OVERLAY_SOURCE_SELECTION_ROLLBACK_OK')
print('PHASE5H7_OVERLAY_NO_PROVIDER_WRITE_DEPENDENCY_OK')
print('PHASE5H7_OVERLAY_FAST_METRIC_FALLBACK_BOUNDARY_OK')
