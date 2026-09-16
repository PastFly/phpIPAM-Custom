#!/usr/bin/env python3
from pathlib import Path
import importlib.util
import json
import os
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / 'tools/install-phase5h10-disabled-reader-candidate.py'
spec = importlib.util.spec_from_file_location('phase5h10_install', PATH)
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

class Builder:
    def __init__(self, enabled=False): self.enabled = enabled
    def generate(self):
        config = (json.dumps({'schema':1,'enabled':self.enabled,'connector_id':1,
            'root':'/var/lib/phpipam-custom/vmware-fast-shadow','max_property_age':300,
            'max_metric_age':300,'max_roster_age':300}, sort_keys=True, separators=(',',':'))+'\n').encode()
        return {'manifest': {'schema':1,'source_head':'test-head','config_enabled':self.enabled,
            'serving_cutover':False}, 'files': {
            'overlay/functions/classes/class.A.php': b'<?php final class A {}\n',
            'overlay/app/integrations/vmware/mirror/native.php': b'<?php // candidate\n',
            'config/vmware-fast-reader.json': config,
            'manifest.json': b'{"schema":1}\n',
        }}

def fixture(root):
    for rel in ['opt/phpipam-custom','var/backups/phpipam-custom','etc/phpipam-custom']:
        (root/rel).mkdir(parents=True)

def backups(root): return sorted((root/'var/backups/phpipam-custom').glob('vmware-fast-reader-candidate-*'))

old_loader = mod.load_builder
try:
    mod.load_builder = lambda: Builder(False)
    with tempfile.TemporaryDirectory() as td:
        system=Path(td); fixture(system)
        backup=mod.install(system, verify_live=False, apply_owner=False)
        candidate=system/'opt/phpipam-custom/vmware-fast-reader-candidate'
        ok(candidate.is_dir(), 'candidate exists')
        ok((candidate/'overlay/functions/classes/class.A.php').is_file(), 'overlay staged')
        config_path=candidate/'config/vmware-fast-reader.json'
        ok(json.loads(config_path.read_text())['enabled'] is False, 'staged disabled')
        ok(config_path.stat().st_mode & 0o777 == 0o640, 'config mode')
        ok((candidate/'manifest.json').stat().st_mode & 0o777 == 0o644, 'manifest mode')
        ok(not (system/'etc/phpipam-custom/vmware-fast-reader.json').exists(), 'live config absent')
        ok(not (system/'var/www/html').exists(), 'webroot untouched')
        receipt=json.loads((backup/'receipt.json').read_text())
        ok(receipt['stage']=='prepared', 'prepared receipt')
        ok(receipt['create_only'] is True, 'create only receipt')
        for key in ['live_config_created','website_changed','systemd_changed','database_changed','connector_tls_changed']:
            ok(receipt[key] is False, key)
        fails(lambda: mod.install(system, verify_live=False, apply_owner=False), 'phase5h10_candidate_already_exists')

    with tempfile.TemporaryDirectory() as td:
        system=Path(td); fixture(system)
        try: mod.install(system, verify_live=False, apply_owner=False, fault_after=2)
        except RuntimeError as e: ok(str(e)=='phase5h10_injected_failure', 'fault surfaced')
        else: raise AssertionError('fault did not fire')
        ok(not (system/'opt/phpipam-custom/vmware-fast-reader-candidate').exists(), 'candidate rolled back')
        bs=backups(system); ok(len(bs)==1, 'audit backup retained')
        receipt=json.loads((bs[0]/'receipt.json').read_text())
        ok(receipt['stage']=='rolled_back' and receipt['rollback_clean'] is True, 'clean rollback receipt')
        ok(not (system/'etc/phpipam-custom/vmware-fast-reader.json').exists(), 'rollback no live config')

    with tempfile.TemporaryDirectory() as td:
        system=Path(td); fixture(system)
        (system/'etc/phpipam-custom/vmware-fast-reader.json').write_text('{}')
        fails(lambda: mod.install(system, verify_live=False, apply_owner=False), 'phase5h10_live_reader_config_already_exists')
        ok(backups(system)==[], 'preflight no backup')

    with tempfile.TemporaryDirectory() as td:
        system=Path(td); fixture(system)
        (system/'opt/phpipam-custom/vmware-fast-reader-candidate').mkdir()
        fails(lambda: mod.install(system, verify_live=False, apply_owner=False), 'phase5h10_candidate_already_exists')
        ok(backups(system)==[], 'existing target no backup')

    mod.load_builder = lambda: Builder(True)
    with tempfile.TemporaryDirectory() as td:
        system=Path(td); fixture(system)
        fails(lambda: mod.install(system, verify_live=False, apply_owner=False), 'phase5h10_bundle_not_disabled')
        ok(not (system/'opt/phpipam-custom/vmware-fast-reader-candidate').exists(), 'enabled bundle not staged')
        ok(backups(system)==[], 'enabled bundle no backup')

    # Rollback must not delete a replacement file even when it has identical bytes.
    # Create both inodes concurrently so the identity difference is deterministic.
    with tempfile.TemporaryDirectory() as td:
        root=Path(td); owned=root/'owned'; owned.mkdir()
        target=owned/'payload.php'; replacement=owned/'replacement.php'; raw=b'<?php // same bytes\n'
        target.write_bytes(raw); replacement.write_bytes(raw)
        original=target.lstat(); other=replacement.lstat()
        ok((other.st_dev,other.st_ino)!=(original.st_dev,original.st_ino), 'replacement inode differs')
        record=(target, mod.digest(raw), original.st_dev, original.st_ino)
        os.replace(replacement, target)
        replaced=target.lstat()
        ok((replaced.st_dev,replaced.st_ino)==(other.st_dev,other.st_ino), 'replacement identity preserved')
        clean=mod.rollback([record], [])
        ok(clean is False, 'replacement makes rollback incomplete')
        ok(target.is_file() and target.read_bytes()==raw, 'replacement survives rollback')
finally:
    mod.load_builder = old_loader

print(f'PHASE5H10_INSTALLER_TRANSACTION_ASSERTIONS={n}')
print('PHASE5H10_CREATE_ONLY_TRANSACTION_OK')
print('PHASE5H10_PARTIAL_FAILURE_AUTO_ROLLBACK_OK')
print('PHASE5H10_REPLACEMENT_INODE_PRESERVED_OK')
print('PHASE5H10_LIVE_CONFIG_WEBROOT_UNTOUCHED_OK')
print('PHASE5H10_ENABLED_BUNDLE_REJECTED_OK')
