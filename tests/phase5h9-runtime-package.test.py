#!/usr/bin/env python3
from pathlib import Path
import importlib.util

ROOT = Path(__file__).resolve().parents[1]
BUILDER = ROOT / 'tools/build-phase5h9-runtime.py'
spec = importlib.util.spec_from_file_location('phase5h9_runtime', BUILDER)
mod = importlib.util.module_from_spec(spec); spec.loader.exec_module(mod)
n = 0
def ok(value, label):
    global n
    n += 1
    if not value: raise AssertionError(label)
def fails(fn, code):
    global n
    try: fn()
    except RuntimeError as e:
        n += 1
        if str(e).startswith(code): return
        raise
    raise AssertionError('expected:' + code)

expected = (
    'layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php',
    'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastReader.php',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastMetricCompat.php',
    'layers/phase5h7/functions/classes/class.IntegrationVmwareFastOverlayReader.php',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php',
    'layers/phase5h8/functions/classes/class.IntegrationVmwareFastOverlayFactory.php',
    'layers/phase5h9/functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php',
)
ok(mod.RUNTIME_CLASSES == expected, 'exact runtime class allowlist')
controller = 'app/integrations/vmware/mirror/native.php'
native = (ROOT / 'layers/phase5g2/app/integrations/vmware/mirror/native.php').read_bytes()
package = {'app': {controller: native, 'existing.txt': b'keep'}, 'release': {'old': b'keep'}, 'receipt': {'keep': True}}
out = mod.generate(package)
ok(out['receipt'] == {'keep': True}, 'package metadata preserved')
ok(out['app']['existing.txt'] == b'keep' and out['release']['old'] == b'keep', 'existing payload preserved')
ok(out['app'][controller] == native, 'native controller preserved')

for rel in expected:
    value = (ROOT / rel).read_bytes()
    target = 'functions/classes/' + Path(rel).name
    ok(out['release'][rel] == value, 'release source:' + rel)
    ok(out['app'][target] == value, 'app class:' + target)

ok(len([k for k in out['app'] if k.startswith('functions/classes/class.IntegrationVmware')]) >= len(expected), 'runtime classes present')
for forbidden in ['IntegrationVmwareBatchSession','IntegrationVmwareVcenterDirectTransport','IntegrationSecretCipher']:
    ok(all(forbidden not in rel for rel in mod.RUNTIME_CLASSES), 'forbidden allowlist:' + forbidden)

first = expected[0]
first_target = 'functions/classes/' + Path(first).name
collision = {'app': {controller: native, first_target: b'wrong'}, 'release': {}}
fails(lambda: mod.generate(collision), 'phase5h9_runtime_target_collision:')
fails(lambda: mod.generate({'app': {}, 'release': {}}), 'phase5h9_native_controller_missing')

unwired = native.replace(b'IntegrationVmwareFastReaderConfigLoader::load()', b'IntegrationVmwareFastReaderConfigLoader_DISABLED')
fails(lambda: mod.generate({'app': {controller: unwired}, 'release': {}}), 'phase5h9_native_controller_not_wired')
request_wired = native.replace(b'IntegrationVmwareFastReaderConfigLoader::load()', b"getenv('FAST')")
fails(lambda: mod.generate({'app': {controller: request_wired}, 'release': {}}), 'phase5h9_native_controller_not_wired')

print(f'PHASE5H9_RUNTIME_PACKAGE_ASSERTIONS={n}')
print('PHASE5H9_RUNTIME_EXACT_CLASS_ALLOWLIST_OK')
print('PHASE5H9_RUNTIME_OLD_PACKAGE_PRESERVED_OK')
print('PHASE5H9_RUNTIME_NO_PROVIDER_CREDENTIAL_CLASSES_OK')
