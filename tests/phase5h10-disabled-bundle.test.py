#!/usr/bin/env python3
from pathlib import Path
import importlib.util
import json
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / 'tools/build-phase5h10-disabled-reader-candidate.py'
spec = importlib.util.spec_from_file_location('phase5h10_bundle', PATH)
mod = importlib.util.module_from_spec(spec); spec.loader.exec_module(mod)
n = 0

def ok(value, label):
    global n
    n += 1
    if not value: raise AssertionError(label)

def fails(fn, code):
    global n
    n += 1
    try: fn()
    except RuntimeError as error:
        if str(error) != code: raise
        return
    raise AssertionError('expected:' + code)

bundle = mod.generate()
files = bundle['files']; manifest = bundle['manifest']
overlay = {k[len('overlay/'):]: v for k, v in files.items() if k.startswith('overlay/')}
ok(len(overlay) == 12, 'exact 12-file overlay')
ok(set(overlay) == set(mod.TARGETS), 'exact target allowlist')
ok(manifest['source_head'] == mod.ACCEPTED_H9_HEAD, 'head pin')
ok(manifest['create_only'] is True and manifest['serving_cutover'] is False, 'create only')
for key in ['website_mutation','systemd_mutation','database_mutation','connector_tls_mutation','provider_calls']:
    ok(manifest[key] is False, key)
ok(manifest['config_enabled'] is False, 'config disabled manifest')
config = json.loads(files['config/vmware-fast-reader.json'])
ok(config == {
    'schema':1, 'enabled':False, 'connector_id':1,
    'root':'/var/lib/phpipam-custom/vmware-fast-shadow',
    'max_property_age':300, 'max_metric_age':300, 'max_roster_age':300,
}, 'exact disabled config')
ok(manifest['intended_config_target'] == '/etc/phpipam-custom/vmware-fast-reader.json', 'fixed target')

context = overlay['functions/classes/class.IntegrationVmwareInventoryContext.php']
ok(b'/etc/phpipam-custom/vmware-inventory-recovery5g5.json' in context, 'recovery config path')
ok(b'/var/lib/phpipam-custom/vmware-inventory-recovery5g5' in context, 'recovery data path')
ok(b'/etc/phpipam-custom/vmware-inventory.json' not in context, 'old config removed')
ok(b"public function fastStore(string $root): IntegrationVmwareFastStore" in context, 'fastStore boundary')

native = overlay['functions/classes/class.IntegrationVmwareInventoryNative.php']
route = overlay['app/integrations/vmware/mirror/native.php']
ok(b"is_callable([$reader, 'query'])" in native, 'generic native reader')
ok(b'IntegrationVmwareFastReaderConfigLoader::load()' in route, 'fixed loader route')
ok(b'->selector($reader)' in route, 'selector route')
for token in [b'getenv(', b'$_ENV', b'IntegrationSecretCipher', b'curl_exec(', b'SoapClient']:
    ok(token not in route, 'route forbidden:' + token.decode())

for target, rec in manifest['overlay'].items():
    ok(target in overlay, 'manifest target:' + target)
    ok(mod.sha256(overlay[target]) == rec['sha256'], 'manifest hash:' + target)
    ok(rec['source_blob'] == mod.SOURCE_BLOBS[rec['source']], 'source pin:' + target)

# Drift in any accepted source must stop packaging before output.
source_path = next(iter(mod.SOURCE_BLOBS))
old = mod.SOURCE_BLOBS[source_path]
mod.SOURCE_BLOBS[source_path] = '0' * 40
fails(mod.generate, 'phase5h10_source_blob_drift:' + source_path)
mod.SOURCE_BLOBS[source_path] = old

# CLI output is a standalone staging bundle, not a webroot tree.
with tempfile.TemporaryDirectory() as td:
    out = Path(td) / 'candidate'
    for rel, raw in bundle['files'].items():
        path = out / rel; path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(raw)
    ok((out/'manifest.json').is_file(), 'manifest staged')
    ok((out/'config/vmware-fast-reader.json').is_file(), 'config staged')
    ok(not (out/'var/www/html').exists(), 'no webroot tree')

print(f'PHASE5H10_DISABLED_BUNDLE_ASSERTIONS={n}')
print('PHASE5H10_EXACT_12_FILE_OVERLAY_OK')
print('PHASE5H10_CONFIG_DEFAULT_DISABLED_OK')
print('PHASE5H10_RECOVERY_CONTEXT_TRANSFORM_OK')
print('PHASE5H10_NO_PROVIDER_WEBROOT_MUTATION_OK')
