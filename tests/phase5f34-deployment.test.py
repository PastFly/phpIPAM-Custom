#!/usr/bin/env python3
"""Disposable mechanics: no production DB/network; inherited runtime checker is stubbed."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
R=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('cache_deploy',R/'tools/phase5f34-cache-deploy.py')
m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
data={str(p.relative_to(m.LAYER)):p.read_bytes() for p in m.LAYER.rglob('*') if p.is_file()}
if not os.environ.get('LOCAL_FIXTURE'): assert m.sources()==data
m.sources=lambda:dict(data)
m.ancestor_verify=lambda target,installed=False:None

def reject(fn):
    try: fn()
    except (RuntimeError,OSError): return
    raise AssertionError('expected rejection')
with tempfile.TemporaryDirectory() as d:
    system=Path(d)/'system'; target=Path(d)/'app'; target.mkdir()
    p=m.paths(system)
    p['etc'].mkdir(parents=True,mode=0o750); os.chown(p['etc'],0,33)
    p['parent'].parent.mkdir(parents=True)
    p['backups'].parent.mkdir(parents=True)
    h=m.module('fixture_anchors','phase5f331-global-search.py')
    originals={m.NATIVE:b'<?php ?>\n'+h.NATIVE_HOOK,m.WORKSPACE:b'<?php ?>\n'+h.WORKSPACE_HOOK+h.AUTOLOAD}
    for rel,value in originals.items():
        q=target/rel; q.parent.mkdir(parents=True,exist_ok=True); q.write_bytes(value); q.chmod(0o640)
    before=sorted(str(q) for q in Path(d).rglob('*'))
    m.preflight(target,system)
    assert before==sorted(str(q) for q in Path(d).rglob('*')),'preflight writes'
    backup=m.install(target,system)
    assert len(p['key'].read_bytes())==32
    assert p['key'].stat().st_mode & 0o777==0o640
    assert p['root'].stat().st_uid==33
    assert json.loads(p['config'].read_text())['enabled'] is True
    assert p['key'].read_bytes() not in (backup/'receipt.json').read_bytes()
    for rel,value in data.items(): assert (target/rel).read_bytes()==value
    foreign=target/next(iter(data)); original=foreign.read_bytes(); foreign.write_bytes(b'foreign')
    reject(lambda:m.rollback(backup,system)); assert json.loads(p['config'].read_text())['enabled'] is True
    foreign.write_bytes(original); m.rollback(backup,system)
    assert all((target/rel).read_bytes()==v for rel,v in originals.items())
    assert all(not (target/rel).exists() for rel in data)
    assert p['key'].exists() and json.loads(p['config'].read_text())['enabled'] is False
    reject(lambda:m.preflight(target,system))
    p['key'].unlink(); p['config'].unlink(); p['root'].rmdir()
    check=m.verify
    def fail(*args): raise RuntimeError('injected post-commit failure')
    m.verify=fail; reject(lambda:m.install(target,system)); m.verify=check
    assert all((target/rel).read_bytes()==v for rel,v in originals.items())
    assert all(not (target/rel).exists() for rel in data)
    assert json.loads(p['config'].read_text())['enabled'] is False
print('PHASE5F34_DEPLOYMENT_FIXTURES_OK')
