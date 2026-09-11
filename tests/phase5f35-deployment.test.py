#!/usr/bin/env python3
"""Disposable deployment mechanics; inherited live DB/key verifier is stubbed."""
import importlib.util
import os
from pathlib import Path
import tempfile
R=Path(__file__).resolve().parents[1]
def load(name,rel):
 spec=importlib.util.spec_from_file_location(name,R/rel);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
m=load('deploy','tools/phase5f35-vmcard-deploy.py')
data={rel:(R/'layers/phase5f35'/rel).read_bytes() for rel in m.TRACKED}
data.update(load('generate','tools/build-phase5f35-vmcard.py').generate())
if not os.environ.get('LOCAL_FIXTURE'):assert m.sources()==data
m.sources=lambda:dict(data)
# Assert explicit hook composition and restore both callbacks, even on errors.
original_hook=m.parent.HOOK; original_test=m.parent.cli_key_selftest
observed=[]
def parent_check(target):observed.append(m.parent.HOOK)
m.parent.verify=parent_check
m.base_verify(Path('/fixture'),installed=True,readonly=True)
assert observed==[m.HOOK+original_hook] and m.parent.HOOK==original_hook and m.parent.cli_key_selftest==original_test
m.base_verify=lambda target,installed=False,readonly=False:None

def reject(fn):
 try:fn()
 except (RuntimeError,OSError):return
 raise AssertionError('expected refusal')
with tempfile.TemporaryDirectory() as d:
 root=Path(d);target=root/'app';target.mkdir();m.BACKUPS=root/'backups';m.BACKUPS.mkdir(mode=0o700)
 originals={rel:b'<?php ?>\n'+original_hook for rel in m.INDEXES}
 for rel,value in originals.items():p=target/rel;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(value);p.chmod(0o640)
 before=sorted(str(p) for p in root.rglob('*'));m.preflight(target);assert before==sorted(str(p) for p in root.rglob('*'))
 backup=m.install(target)
 assert (backup/'receipt.json').stat().st_mode&0o777==0o600
 assert all((target/rel).read_bytes()==value for rel,value in data.items())
 assert all((target/rel).stat().st_mode&0o777==0o640 for rel in originals)
 q=target/next(iter(data));old=q.read_bytes();q.write_bytes(b'foreign');reject(lambda:m.rollback(backup));q.write_bytes(old)
 m.rollback(backup);assert all((target/rel).read_bytes()==value for rel,value in originals.items())
 assert all(not (target/rel).exists() for rel in data)
 verify=m.verify
 def fail(target):raise RuntimeError('injected verifier failure')
 m.verify=fail;reject(lambda:m.install(target));m.verify=verify
 assert all((target/rel).read_bytes()==value for rel,value in originals.items())
 assert all(not (target/rel).exists() for rel in data)
 # Symlink targets are refused, not overwritten.
 p=target/next(iter(data));p.parent.mkdir(parents=True,exist_ok=True);p.symlink_to(root/'missing')
 reject(lambda:m.preflight(target));p.unlink()
print('PHASE5F35_DEPLOYMENT_ROLLBACK_DRIFT_OK')
