#!/usr/bin/env python3
"""Pinned source, deterministic variants and unchanged legacy implementation."""
import hashlib
import importlib.util
from pathlib import Path
import subprocess
import tempfile
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('compat', ROOT/'tools/build-phase5g5-provider-compat.py')
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
before={p:(ROOT/p).read_bytes() for p in m.PINS}
first=m.generate(); assert first==m.generate() and len(first)==2
for name,raw in first.items():
    assert name.startswith('class.IntegrationVmwareInventory') and b'eval(' not in raw
assert before=={p:(ROOT/p).read_bytes() for p in m.PINS}
with tempfile.TemporaryDirectory(prefix='compat-build-test-') as tmp:
    root=Path(tmp)
    for p,raw in before.items():
        target=root/p; target.parent.mkdir(parents=True,exist_ok=True); target.write_bytes(raw)
    assert m.generate(root)==first
    target=root/next(iter(m.PINS)); target.write_bytes(target.read_bytes()+b'\n')
    try: m.generate(root)
    except RuntimeError as e: assert str(e).startswith('provider_compat_source_drift:')
    else: raise AssertionError('unreviewed source accepted')
    try: m.replace_once('anchor anchor','anchor','new')
    except RuntimeError: pass
    else: raise AssertionError('ambiguous patch accepted')
    for path in ['/var/www/html/test','/opt/phpipam-custom/inventory','/etc/phpipam-custom/new','/var/lib/phpipam-custom/new']:
        p=subprocess.run(['python3','-B',str(ROOT/'tools/build-phase5g5-provider-compat.py'),'--output',path],capture_output=True)
        assert p.returncode != 0 and b'Live runtime output is not allowed' in p.stderr
    for name,raw in first.items():
        p=root/name; p.write_bytes(raw); subprocess.run(['php','-l',str(p)],check=True,stdout=subprocess.DEVNULL)
print('PHASE5G5_PROVIDER_BUILD_OK; LEGACY_SOURCE_UNCHANGED=1; LIVE_DEPLOYMENT=0')
