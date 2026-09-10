#!/usr/bin/env python3
"""Disposable apply/verify/rollback mechanics. Inherited runtime DB checks are stubbed."""
import importlib.util
import os
from pathlib import Path
import shutil
import tempfile
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('folders_deploy', ROOT / 'tools/phase5f332-ui.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
if os.environ.get('LOCAL_FIXTURE'):
    data = {}
    for rel in m.FILES:
        p = ROOT / 'layers/phase5f332' / rel
        data[rel] = p.read_bytes() if p.exists() else b'<?php // collector fixture\n'
else:
    data = m.sources()
m.sources = lambda: dict(data)
m.base_verify = lambda target: None

def setup(root):
    value = b'<?php // fixture index\n?>\n<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F33_ENRICHED_SEARCH_UI_V1 -->\n'
    p = root / m.INDEX
    p.parent.mkdir(parents=True)
    p.write_bytes(value)
    p.chmod(0o640)
    return value

def reject(call):
    try:
        call()
    except (RuntimeError, OSError):
        return
    raise AssertionError('Operation should have been rejected')

with tempfile.TemporaryDirectory() as tmp:
    target = Path(tmp)/'runtime'; target.mkdir()
    old = setup(target)
    before = sorted(str(p.relative_to(target)) for p in target.rglob('*'))
    m.preflight(target)
    assert before == sorted(str(p.relative_to(target)) for p in target.rglob('*')), 'preflight wrote files'
    backup = m.install(target)
    assert (target/m.INDEX).read_bytes() == old + m.HOOK
    assert (target/m.INDEX).stat().st_mode & 0o777 == 0o640
    for rel in m.FILES: assert (target/rel).read_bytes() == data[rel]
    assert backup.stat().st_mode & 0o777 == 0o700
    reject(lambda: m.preflight(target))
    foreign = target/m.FILES[1]
    foreign.write_bytes(b'<?php // unrelated newer deployment')
    reject(lambda: m.rollback(backup))
    assert (target/m.INDEX).read_bytes() == old + m.HOOK
    foreign.write_bytes(data[m.FILES[1]])
    m.rollback(backup)
    assert (target/m.INDEX).read_bytes() == old
    assert all(not (target/rel).exists() for rel in m.FILES)
    assert (target/m.INDEX).stat().st_mode & 0o777 == 0o640
    previous = m.verify
    def fail_verify(target): raise RuntimeError('injected verification failure')
    m.verify = fail_verify
    reject(lambda: m.install(target))
    m.verify = previous
    assert (target/m.INDEX).read_bytes() == old
    assert all(not (target/rel).exists() for rel in m.FILES)
    bad = target/m.FILES[1]
    bad.symlink_to(target/'absent')
    reject(lambda: m.preflight(target))
    bad.unlink()
    (target/m.INDEX).write_bytes(old + b'<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F332_OBJECT_FOLDERS_UI_V1 -->')
    reject(lambda: m.preflight(target))
print('PHASE5F332_DEPLOYMENT_FIXTURES_OK')
