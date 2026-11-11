#!/usr/bin/env python3
"""Actual generated worker/UI package; no systemd, credentials or vCenter."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('recovery',ROOT/'tools/phase5g5-recover.py')
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
p=m.build_package()
assert len(p['app'])==34 and len(p['routes'])==20
assert len(p['units'])==4
context=p['app']['functions/classes/class.IntegrationVmwareInventoryContext.php']
assert m.NEW_CONFIG.encode() in context and m.NEW_DATA.encode() in context
assert b'/etc/phpipam-custom/vmware-inventory.json' not in context
assert p['release']['tools/phase5g4-rollout.py']==(ROOT/'tools/phase5g4-rollout.py').read_bytes()
assert b'new IntegrationVmwareInventoryPerformance(' in p['release']['layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryMetricSource.php']
source=p['release']['layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php']
assert b'new IntegrationVmwareInventoryGuestNetwork(' in source
assert b'ManagedObjectNotFound' in source and b"throw new RuntimeException('mirror_membership_changed');" in source
assert b'ManagedObjectNotFound' not in (ROOT/'layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php').read_bytes()
for name,unit in p['units'].items():
    if name.endswith('service'):
        assert m.NEW_RELEASE.encode() in unit and m.NEW_CONFIG.encode() in unit and m.NEW_DATA.encode() in unit
        assert b'MemoryMax=512M' in unit and b'User=www-data' in unit and b'ProtectSystem=strict' in unit
        assert (b'--bootstrap' in unit)==('-initial@' in name)
    assert b'phpipam-vmware-inventory@' not in unit
with tempfile.TemporaryDirectory(prefix='recovery-generated-') as tmp:
    root=Path(tmp)
    for rel,data in p['release'].items():
        out=root/rel;out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(data)
        if rel.endswith('.php'):subprocess.run(['php','-l',str(out)],check=True,stdout=subprocess.DEVNULL)
        if rel.endswith('.py'):compile(data,rel,'exec')
    for rel,data in p['app'].items():
        out=root/'app-source'/rel;out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(data)
        if rel.endswith('.php'):subprocess.run(['php','-l',str(out)],check=True,stdout=subprocess.DEVNULL)
    subprocess.run(['php',str(ROOT/'tests/phase5g5-runtime-policy.test.php'),str(root)],check=True)
    subprocess.run(['php',str(ROOT/'tests/phase5g5-stale-host-membership.test.php'),str(root)],check=True)
    subprocess.run(['php',str(ROOT/'tests/phase5g2-native.test.php'),str(root/'app-source')],check=True)
    subprocess.run(['php',str(ROOT/'tests/phase5g5-recovery-ui.test.php'),str(root/'app-source')],check=True)
    for path,data in p['units'].items():(root/Path(path).name).write_bytes(data)
    # The real ExecStart binary exists; sidecar source paths are intentionally not installed here.
    subprocess.run(['systemd-analyze','verify','--man=no',*[str(root/(unit+'@1.service')) for unit in ['phpipam-vmware-recovery5g5','phpipam-vmware-recovery5g5-initial']]],check=True)
print('PHASE5G5_PACKAGE_OK=36_WEB_TARGETS_4_ISOLATED_UNITS')
