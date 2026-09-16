#!/usr/bin/env python3
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PATH = Path('layers/phase5g2/app/integrations/vmware/mirror/native.php')
BASE = 'b4b3aad50048ccd8ffadd24101b18d32d5d2ac59'
current = (ROOT / PATH).read_text()
base = subprocess.check_output(['git', '-C', str(ROOT), 'show', f'{BASE}:{PATH.as_posix()}']).decode()
n = 0
def ok(value, label):
    global n
    n += 1
    if not value: raise AssertionError(label)

def require_span(text):
    start = text.index("    foreach (['IntegrationVmwareSnapshotService'")
    stop = text.index("\n    $context = new IntegrationVmwareInventoryContext", start)
    return text[start:stop]

current_require = require_span(current)
base_require = require_span(base)
selection = (
    "    $fastConfig = IntegrationVmwareFastReaderConfigLoader::load();\n"
    "    $reader = (new IntegrationVmwareFastOverlayFactory($context, $fastConfig, (int)$id))->selector($reader);\n"
)
canonical = current.replace(current_require, base_require, 1).replace(selection, '', 1)
ok(canonical == base, 'route delta exceeds class allowlist + two selection lines')

for token in [
    "header('X-Phpipam-Inventory-Vcenter-Requests: 0')",
    "$User->is_admin(false)",
    "($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'",
    "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'",
    '$reader = new IntegrationVmwareInventoryReader(',
    'IntegrationVmwareFastReaderConfigLoader::load()',
    'IntegrationVmwareFastOverlayFactory($context, $fastConfig, (int)$id)',
    '->selector($reader)',
    'new IntegrationVmwareInventoryNative($reader, $links)',
    "if (!$context->current()) throw new RuntimeException('mirror_access_denied')",
    "if ($code === 'mirror_object_not_found') $status = 404",
]: ok(token in current, 'required:' + token)

for name in [
    'IntegrationVmwareRosterReconciler', 'IntegrationVmwareFastStore', 'IntegrationVmwareFastReader',
    'IntegrationVmwareFastMetricCompat', 'IntegrationVmwareFastOverlayReader',
    'IntegrationVmwareInventoryQuerySelector', 'IntegrationVmwareFastReaderConfig',
    'IntegrationVmwareFastOverlayFactory', 'IntegrationVmwareFastReaderConfigLoader',
]: ok(name in current_require, 'runtime class missing:' + name)

for token in [
    '/etc/phpipam-custom/vmware-fast-reader.json', 'getenv(', '$_ENV', '$_GET', '$_REQUEST',
    'IntegrationSecretCipher', 'PropertyCollector', 'QueryPerf', 'curl_exec(', 'SoapClient',
]: ok(token not in current, 'forbidden:' + token)

positions = [
    current.index("$User->is_admin(false)"),
    current.index("csrf_cookie('validate'"),
    current.index('$reader = new IntegrationVmwareInventoryReader('),
    current.index('IntegrationVmwareFastReaderConfigLoader::load()'),
    current.index('->selector($reader)'),
    current.index('new IntegrationVmwareInventoryNative($reader, $links)'),
    current.index("if (!$context->current())"),
]
ok(positions == sorted(positions) and len(set(positions)) == len(positions), 'route authorization/selection order')
ok(current.count('IntegrationVmwareFastReaderConfigLoader::load()') == 1, 'one config load')
ok(current.count('->selector($reader)') == 1, 'one reader selection')

print(f'PHASE5H9_NATIVE_ROUTE_PARITY_ASSERTIONS={n}')
print('PHASE5H9_NATIVE_ROUTE_ONLY_REVIEWED_DELTA_OK')
print('PHASE5H9_NATIVE_AUTH_CSRF_ERROR_PARITY_OK')
print('PHASE5H9_NATIVE_NO_REQUEST_ENV_ENABLEMENT_OK')
