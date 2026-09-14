#!/usr/bin/env python3
"""Build the actual git-pinned runtime package; no host/bootstrap/systemd operations."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('rollout_package', ROOT / 'tools/phase5g4-rollout.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
p = m.source_package()
assert len(p['routes']) == 20
assert len(p['app']) == 34, sorted(p['app'])
assert len(p['release']) == 18, sorted(p['release'])
assert len(p['dependencies']) == 32
assert 'functions/classes/class.IntegrationVmwareVcenterSoapHostResolver.php' not in p['dependencies']
assert 'functions/functions.php' in p['dependencies']
assert 'app/integrations/vmware/cache/search-provider.php' in p['dependencies']
assert p['release']['tools/phase5g4-rollout.py'] == (ROOT / 'tools/phase5g4-rollout.py').read_bytes()
for path in p['routes']:
    body = p['app'][path].decode()
    assert 'PHPIPAM_VMWARE_MIRROR_OPERATION' in body and '/mirror/native.php' in body
for key in ['CURRENT', 'password', 'secret_envelope']:
    assert key not in p['units']['/etc/systemd/system/phpipam-vmware-inventory@.service'].decode()
assert b'phase5g4-worker.py' in p['units']['/etc/systemd/system/phpipam-vmware-inventory@.service']
with tempfile.TemporaryDirectory(prefix='real-rollout-package-') as temp:
    root = Path(temp)
    for path, data in {**p['app'], **{'release/' + k:v for k,v in p['release'].items()}}.items():
        target = root / path; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(data)
        if path.endswith('.php'): subprocess.run(['php', '-l', str(target)], check=True, stdout=subprocess.DEVNULL)
    for name, value in p['units'].items():
        (root / Path(name).name).write_bytes(value)
    subprocess.run(['systemd-analyze','verify','--man=no',str(root/'phpipam-vmware-inventory@1.service'),
        str(root/'phpipam-vmware-inventory@1.timer')], check=True)
print('PHASE5G4_REAL_PACKAGE=34_FILES_PLUS_2_INDEXES; IMMUTABLE_SOURCE_FILES=18')
print('PHASE5G4_SOURCE_PACKAGE_NO_DEPLOYMENT_OK')
