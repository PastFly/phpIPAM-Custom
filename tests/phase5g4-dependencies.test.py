#!/usr/bin/env python3
"""Repository-only preview classes are not live dependencies; real omissions still fail closed.
Uses real dependency source bytes and Rollout.prepare in disposable directories.
Host services, baseline DB verification and the miniature UI are controlled doubles.
"""
import importlib.util
import json
import os
from pathlib import Path
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('dependency_rollout', ROOT / 'tools/phase5g4-rollout.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
pins = json.loads((ROOT / 'design/phase5g-source-pins.json').read_text())['sources']
preview = 'functions/classes/class.IntegrationVmwareVcenterSoapHostResolver.php'
preview_source = 'layers/phase5f/' + preview
assert pins[preview_source] == '75e00f09a1cf6cd18fa6fff7e5d0464fc700da03'
assert m.REPOSITORY_ONLY_CLASSES == {preview}
dependencies = m.runtime_dependencies(pins)
all_classes = {p.split('/', 2)[2] for p in pins if '/functions/classes/' in p}
assert set(dependencies) == (all_classes - {preview}) | {
    'functions/functions.php', 'app/integrations/vmware/cache/search-provider.php'}
assert len(pins) == 34 and len(dependencies) == 32
# The class exists for manual repository previews and retains its original source pin.
raw = (ROOT / preview_source).read_bytes()
assert m.hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() == pins[preview_source]
assert 'SoapHostResolver' not in (ROOT / 'installer/install-vmware-vcenter-phase5f-ui.sh').read_text()
for source in [*(ROOT / 'layers').glob('phase5g*/**/*.php'),
        *[ROOT / 'tools' / name for name in ['vmware-inventory-cycle.php', 'phase5g4-inspect.php']]]:
    assert 'IntegrationVmwareVcenterSoapHostResolver' not in source.read_text(), str(source)
assert os.geteuid() == 0, 'Run disposable ownership fixtures with sudo'

class Host:
    def __init__(self): self.events = []
    def preflight(self): self.events.append('preflight')
    def reload_units(self): self.events.append('reload')
    def start_worker(self): raise AssertionError('must not start collection during preparation')
    def stop_web(self): raise AssertionError('must not stop Apache during preparation')

def setup(root):
    for rel in ['var/www/html', 'var/lib', 'var/backups', 'etc/phpipam-custom', 'etc/systemd/system', 'opt']:
        (root / rel).mkdir(parents=True, exist_ok=True)
    (root / 'etc/phpipam-custom').chmod(0o750)
    os.chown(root / 'etc/phpipam-custom', 0, 33)
    app = root / 'var/www/html'; hook = b'<!-- fixture cache hook -->\n'
    for source in pins:
        if '/functions/classes/' not in source or source == preview_source: continue
        target = app / source.split('/', 2)[2]
        target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes((ROOT / source).read_bytes())
    for rel in ['functions/functions.php', 'app/integrations/vmware/cache/search-provider.php']:
        target = app / rel; target.parent.mkdir(parents=True, exist_ok=True); target.write_bytes(b'<?php // fixture\n')
    route = 'app/integrations/vmware/vm-card-result.php'
    for rel in [route, *m.INDEXES]:
        target = app / rel; target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(b'<?php // old fixture\n?>\n' + (hook if rel in m.INDEXES else b''))
    package = {'head': 'a' * 40, 'app': {route: b'<?php // new fixture\n'}, 'routes': [route],
        'release': {'tools/phase5g4-rollout.py': (ROOT / 'tools/phase5g4-rollout.py').read_bytes()},
        'units': {}, 'dependencies': list(dependencies), 'cache_hook': hook, 'base_verify': lambda app: None}
    host = Host(); return m.Rollout(host, root), package, host

def snapshot(root):
    return {str(p.relative_to(root)): (m.meta(p), m.digest(p.read_bytes()) if p.is_file() else None)
        for p in root.rglob('*') if not p.is_symlink()}

checks = 0
with tempfile.TemporaryDirectory(prefix='phase5g4-dependencies-') as tmp:
    base = Path(tmp)
    # Reproduce the original manifest error without starting services or provisioning anything.
    r, p, h = setup(base / 'original'); p['dependencies'].append(preview)
    before = snapshot(r.system)
    try: r.prepare(p)
    except RuntimeError as error: assert str(error) == 'runtime_dependencies_missing:' + preview
    else: raise AssertionError('preview-only dependency was not reproduced')
    assert r.receipt is None and r.backup is None and snapshot(r.system) == before
    assert h.events == ['preflight']; checks += 1
    # Corrected package must prepare and retain every real dependency hash, with no helper copy.
    r, p, h = setup(base / 'fixed'); r.prepare(p); r.assert_app(); r.verify_resources()
    assert r.receipt['stage'] == 'prepared' and set(r.receipt['dependencies']) == set(dependencies)
    assert not (r.path(m.APP) / preview).exists() and h.events == ['preflight', 'reload']; checks += 1
    # Every other missing dependency is still fatal, before backup/config/release changes.
    for i, rel in enumerate(dependencies):
        r, p, h = setup(base / ('missing-' + str(i))); (r.path(m.APP) / rel).unlink()
        before = snapshot(r.system)
        try: r.prepare(p)
        except RuntimeError as error: assert str(error) == 'runtime_dependencies_missing:' + rel
        else: raise AssertionError('missing runtime dependency accepted: ' + rel)
        assert r.receipt is None and r.backup is None and snapshot(r.system) == before
        assert h.events == ['preflight']; checks += 1
    r, p, h = setup(base / 'multiple'); missing = dependencies[:2]
    for rel in missing: (r.path(m.APP) / rel).unlink()
    try: r.prepare(p)
    except RuntimeError as error: assert str(error) == 'runtime_dependencies_missing:' + ','.join(missing)
    else: raise AssertionError('multiple omissions accepted')
    checks += 1
    for kind in ['symlink', 'hardlink', 'directory']:
        r, p, h = setup(base / kind); path = r.path(m.APP) / dependencies[0]
        raw = path.read_bytes(); path.unlink(); other = r.system / 'other'; other.write_bytes(raw)
        if kind == 'symlink': path.symlink_to(other)
        elif kind == 'hardlink': os.link(other, path)
        else: path.mkdir()
        try: r.prepare(p)
        except RuntimeError as error:
            expected = {'symlink': 'symlink_rejected', 'hardlink': 'hardlink_rejected',
                'directory': 'runtime_dependencies_missing:' + dependencies[0]}[kind]
            assert str(error) == expected
        else: raise AssertionError('unsafe dependency accepted')
        assert r.receipt is None and r.backup is None and h.events == ['preflight']; checks += 1
print('PHASE5G4_DEPENDENCY_CASES=' + str(checks))
print('PHASE5G4_REPOSITORY_ONLY_RESOLVER_NOT_REQUIRED; ALL_32_RUNTIME_DEPENDENCIES_GUARDED')
print('PHASE5G4_DEPENDENCY_FIX_OFFLINE_OK; LIVE_SERVICES_NOT_TESTED')
