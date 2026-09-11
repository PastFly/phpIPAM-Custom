#!/usr/bin/env python3
"""Real isolated file transactions; systemd, DB and live initial collection are controlled doubles."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('rollout', ROOT / 'tools/phase5g4-rollout.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
assert os.geteuid() == 0, 'Run isolated ownership fixtures with sudo, never against production'
count = 0

def check(value):
    global count
    count += 1
    assert value, count

def fails(fn):
    try: fn()
    except (RuntimeError, FileNotFoundError): return True
    return False

class FakeHost:
    def __init__(self): self.events = []; self.ready = True; self.fail_web_once = False
    def preflight(self): self.events.append('profile')
    def check_web(self): self.events.append('check_web')
    def reload_units(self): self.events.append('reload')
    def start_worker(self): self.events.append('start_worker')
    def stop_worker(self): self.events.append('stop_worker')
    def stop_web(self): self.events.append('stop_web')
    def start_web(self):
        self.events.append('start_web')
        if self.fail_web_once: self.fail_web_once = False; raise RuntimeError('injected_web_start_failure')
    def inspect(self, release): return {'ready': self.ready, 'state': 'ready' if self.ready else 'initializing', 'native_contracts_rendered': 17}

def fixture(root):
    def path(p): return root / p.lstrip('/')
    for p in ['/var/www/html', '/var/lib', '/var/backups', '/etc/phpipam-custom', '/etc/systemd/system', '/opt']:
        path(p).mkdir(parents=True, exist_ok=True)
    path('/etc/phpipam-custom').chmod(0o750); os.chown(path('/etc/phpipam-custom'), 0, 33)
    hook = b'<!-- OLD-CACHE -->\n'
    files = {'app/integrations/vmware/vm-card-result.php': b"<?php echo 'local';\n",
        'functions/classes/class.IntegrationVmwareInventoryExample.php': b'<?php // harmless fixture\n'}
    for rel in [*m.INDEXES, 'app/integrations/vmware/vm-card-result.php', 'functions/functions.php']:
        p = path(m.APP) / rel; p.parent.mkdir(parents=True, exist_ok=True)
        p.write_bytes(b"<?php echo 'old'; ?>\n" + (hook if rel in m.INDEXES else b''))
        os.chown(p, 33, 33); p.chmod(0o644)
    release = {'tools/phase5g4-rollout.py': (ROOT / 'tools/phase5g4-rollout.py').read_bytes(),
        'tools/phase5g4-worker.py': (ROOT / 'tools/phase5g4-worker.py').read_bytes(),
        'tools/vmware-inventory-cycle.php': b'<?php // fixture only\n', 'tools/phase5g4-inspect.php': b'<?php // fixture only\n'}
    package = {'head': 'a'*40, 'app': files, 'routes': ['app/integrations/vmware/vm-card-result.php'],
        'release': release, 'units': {'/etc/systemd/system/' + m.TEMPLATE + 'service': b'[Service]\nType=oneshot\n',
        '/etc/systemd/system/' + m.TEMPLATE + 'timer': b'[Timer]\nOnBootSec=2min\n'},
        'dependencies': ['functions/functions.php'], 'cache_hook': hook, 'base_verify': lambda app: None}
    h = FakeHost(); r = m.Rollout(h, root)
    return r, package, h

with tempfile.TemporaryDirectory(prefix='phase5g4-transaction-') as tmp:
    base = Path(tmp)
    for case in range(12):
        root = base / str(case); root.mkdir(); r, p, h = fixture(root)
        if case == 0:
            r.prepare(p); check(r.receipt['stage'] == 'prepared'); r.assert_app(); check('start_worker' not in h.events)
            check(m.meta(r.path(m.DATA + '/connector-1')) == {'uid': 33, 'gid': 33, 'mode': 0o700})
            r.wait_ready(seconds=1); r.activate(); check(r.receipt['stage'] == 'active'); r.assert_app(new=True)
            check(h.events.index('stop_web') < h.events.index('start_web'))
            new = m.Rollout(h, root); new.load(r.backup); new.verify_resources(); new.assert_app(new=True)
            new.rollback(); new.assert_app(); check(new.receipt['stage'] == 'rolled_back')
            check(json.loads(new.path(m.CONFIG).read_text())['enabled'] is False)
            check(new.path(m.DATA + '/connector-1').is_dir()); new.rollback()
        elif case == 1:
            r.prepare(p); h.ready = False; check(fails(r.activate)); r.assert_app(); check('stop_web' not in h.events)
        elif case == 2:
            r.prepare(p); h.fail_web_once = True; check(fails(r.activate)); r.assert_app(); check(r.receipt['stage'] == 'rolled_back')
            check(h.events.count('start_web') == 2)
        elif case == 3:
            r.prepare(p); r.activate(); target = r.path(m.APP + '/app/integrations/vmware/vm-card-result.php')
            target.write_text('NEWER OWNER EDIT'); check(fails(r.rollback)); check(target.read_text() == 'NEWER OWNER EDIT')
        elif case == 4:
            r.prepare(p); newfile = r.path(m.RELEASE + '/tools/vmware-inventory-cycle.php')
            newfile.chmod(0o644); check(fails(r.verify_resources)); r.assert_app()
        elif case == 5:
            r.prepare(p); q = r.path(m.APP + '/functions/functions.php'); q.write_text('dependency changed')
            check(fails(r.activate)); r.assert_app(); check('stop_web' not in h.events)
        elif case == 6:
            q = r.path(m.RELEASE); q.parent.mkdir(); q.symlink_to(root / 'somewhere')
            check(fails(lambda: r.prepare(p))); check(not r.path(m.CONFIG).exists())
        elif case == 7:
            r.prepare(p); h.ready = False; check(fails(lambda: r.wait_ready(0))); r.pause()
            check(r.receipt['stage'] == 'paused'); r.assert_app()
            h.ready = True; r.wait_ready(1); r.activate(); check(r.receipt['stage'] == 'active')
        elif case == 8:
            # Interrupted write after one native target was replaced restores that exact target.
            r.prepare(p); original = m.atomic; fired = [False]
            def injected(path, value, metadata=None):
                if str(path).startswith(str(r.path(m.APP))) and str(path).endswith('class.IntegrationVmwareInventoryExample.php') and not fired[0]:
                    fired[0] = True; raise RuntimeError('injected_mid_switch_failure')
                return original(path, value, metadata)
            m.atomic = injected
            try: check(fails(r.activate))
            finally: m.atomic = original
            check(fired[0]); r.assert_app(); check(r.receipt['stage'] == 'rolled_back')
        elif case == 9:
            original = m.atomic
            def injected(path, value, metadata=None):
                if path == r.path(m.CONFIG): raise RuntimeError('injected_provision_failure')
                return original(path, value, metadata)
            m.atomic = injected
            try: check(fails(lambda: r.prepare(p)))
            finally: m.atomic = original
            check(r.receipt['stage'] == 'preparing'); r.abort_preparing(); r.assert_app()
            check('start_worker' not in h.events); check('stop_web' not in h.events)
        elif case == 10:
            # Failure restarting old Apache after restoring files/config remains recoverable.
            r.prepare(p); r.activate(); h.fail_web_once = True
            check(fails(r.rollback)); check(r.receipt['stage'] == 'restoring')
            r.pause(); check(r.receipt['stage'] == 'restoring')
            r.rollback(); check(r.receipt['stage'] == 'rolled_back'); r.assert_app()
        elif case == 11:
            r.prepare(p)
            h.inspect = lambda release: (_ for _ in ()).throw(RuntimeError('rollout_interrupted'))
            check(fails(lambda: r.wait_ready(1))); r.pause(); check(r.receipt['stage'] == 'paused')
    # Path and hardlink rejection are exercised separately from source verification.
    p = base / 'file'; p.write_text('public fixture'); q = base / 'hardlink'; os.link(p, q)
    check(fails(lambda: m.safe(p)))
    check(fails(lambda: m.safe(base / '..' / 'elsewhere')))
print('PHASE5G4_TRANSACTION_ASSERTIONS=' + str(count))
print('PHASE5G4_OFFLINE_TRANSACTIONS_OK; LIVE_SYSTEMD_DB_COLLECTION_NOT_TESTED')
