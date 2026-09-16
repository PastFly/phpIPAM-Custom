#!/usr/bin/env python3
from pathlib import Path
import hashlib

ROOT = Path(__file__).resolve().parents[1]
PATH = Path('layers/phase5g2/app/integrations/vmware/mirror/native.php')
BASE_BLOB = 'dfa35e5e215230feb2cef2d6078bd39dc5a2fe9f'
current = (ROOT / PATH).read_text()
n = 0
def ok(value, label):
    global n
    n += 1
    if not value: raise AssertionError(label)

def git_blob_sha(text):
    raw = text.encode('utf-8')
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()

def require_span(text):
    start = text.index("    foreach (['IntegrationVmwareSnapshotService'")
    stop = text.index("\n    $context = new IntegrationVmwareInventoryContext", start)
    return text[start:stop]

current_require = require_span(current)
base_require = (
    "    foreach (['IntegrationVmwareSnapshotService', 'IntegrationVmwareInventoryStore', 'IntegrationVmwareInventoryContext',\n"
    "        'IntegrationVmwareInventoryReader', 'IntegrationVmwareInventoryNative', 'IntegrationVmwareVcenterExplorer',\n"
    "        'IntegrationPhpIpamAddressLinkResolver', 'IntegrationVmwareInventoryTelemetryStore'] as $class) require_once($application . '/functions/classes/class.' . $class . '.php');"
)
selection = (
    "    $fastConfig = IntegrationVmwareFastReaderConfigLoader::load();\n"
    "    $reader = (new IntegrationVmwareFastOverlayFactory($context, $fastConfig, (int)$id))->selector($reader);\n"
)
canonical = current.replace(current_require, base_require, 1).replace(selection, '', 1)
ok(git_blob_sha(canonical) == BASE_BLOB, 'route delta exceeds exact H8 blob + reviewed H9 additions')

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
print('PHASE5H9_NATIVE_ROUTE_EXACT_H8_BLOB_PARITY_OK')
print('PHASE5H9_NATIVE_ROUTE_ONLY_REVIEWED_DELTA_OK')
print('PHASE5H9_NATIVE_AUTH_CSRF_ERROR_PARITY_OK')
print('PHASE5H9_NATIVE_NO_REQUEST_ENV_ENABLEMENT_OK')
