#!/usr/bin/env python3
"""Additive VM-card context layer. Never reprovision or disable the accepted cache."""
import sys
sys.dont_write_bytecode = True
import fcntl
import importlib.util
import json
import os
from pathlib import Path
import signal
import shutil
import tempfile
from datetime import datetime, timezone
ROOT = Path(__file__).resolve().parents[1]
BASE = 'c33c762f24048fb7976312389779047ad2b64b97'
BACKUPS = Path('/var/backups/phpipam-custom')
HOOK = b'<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F35_CONTEXT_UI_V1 -->\n<script src="app/integrations/vmware/phase5f35-vmcard.js"></script>\n'
TRACKED = ('functions/classes/class.IntegrationVmwareVmCardContext.php', 'app/integrations/vmware/phase5f35-vmcard.js')

def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m

parent = module('phase5f35_parent', 'tools/phase5f34-cache-deploy.py')
INDEXES = parent.INDEXES

def sources():
    for rel in parent.git('ls-tree', '-r', '--name-only', BASE, 'layers', 'tools', 'installer').decode().splitlines():
        if parent.safe(ROOT / rel).read_bytes() != parent.git('show', BASE + ':' + rel):
            raise RuntimeError('protected_source_drift: ' + rel)
    out = {}
    extra = ['tools/build-phase5f35-vmcard.py', 'tools/phase5f35-vmcard-deploy.py',
        'tools/live-vmware-phase5f35-preflight.sh'] + [
        'installer/' + action + '-vmware-vcenter-phase5f35-vmcard.sh' for action in ('install', 'verify', 'rollback')]
    for rel in ['layers/phase5f35/' + p for p in TRACKED] + extra:
        value = parent.git('show', 'HEAD:' + rel)
        if parent.safe(ROOT / rel).read_bytes() != value: raise RuntimeError('working_source_drift: ' + rel)
        if rel.startswith('layers/'): out[rel[len('layers/phase5f35/'):]] = value
    out.update(module('phase5f35_generate', 'tools/build-phase5f35-vmcard.py').generate())
    if len(out) != 3: raise RuntimeError('unexpected_file_set')
    return out

def base_verify(target, installed=False, readonly=False):
    # Explicit composition: both old cache hooks acquire exactly this prefix.
    # No ancestor files or arbitrary hash overrides are accepted.
    old_hook, old_test = parent.HOOK, parent.cli_key_selftest
    try:
        if installed: parent.HOOK = HOOK + old_hook
        if readonly: parent.cli_key_selftest = lambda paths: None
        parent.verify(target)
    finally:
        parent.HOOK, parent.cli_key_selftest = old_hook, old_test

def patched(indexes):
    out = {}
    for rel, value in indexes.items():
        if HOOK in value or value.count(parent.HOOK) != 1: raise RuntimeError('context_hook_anchor: ' + rel)
        out[rel] = value.replace(parent.HOOK, HOOK + parent.HOOK, 1)
    return out

def preflight(target):
    target = parent.safe(target); data = sources()
    base_verify(target, readonly=True)
    originals = {rel: parent.safe(target / rel).read_bytes() for rel in INDEXES}
    patched(originals)
    for rel in data:
        if parent.safe(target / rel).exists(): raise RuntimeError('new_context_target_present: ' + rel)
    print('PHASE5F35_BASE_VERIFY_MODE=SOURCE_AND_PRIVATE_METADATA')
    print('PREFLIGHT_WRITE_OPERATIONS=0\nPREFLIGHT_KEY_CONTENTS_READ=0\nPREFLIGHT_VCENTER_REQUESTS=0')
    print('PREFLIGHT_RESULT=READY_FOR_PHASE5F35_CONTEXT_OWNER_APPROVAL', flush=True)
    return originals

def verify(target):
    base_verify(target, installed=True)
    for rel, value in sources().items():
        if parent.safe(target / rel).read_bytes() != value: raise RuntimeError('context_runtime_mismatch: ' + rel)
    current = {rel: parent.safe(target / rel).read_bytes() for rel in INDEXES}
    if any(value.count(HOOK) != 1 for value in current.values()): raise RuntimeError('context_hook_count')
    if patched({rel: value.replace(HOOK, b'', 1) for rel, value in current.items()}) != current:
        raise RuntimeError('context_hook_order')
    print('PHASE5F35_CONTEXT_RUNTIME_FILES_OK=3\nPHASE5F35_CONTEXT_VERIFY_OK', flush=True)

def rollback(backup):
    backup = parent.safe(backup)
    if parent.meta(backup) != {'uid': 0, 'gid': 0, 'mode': 0o700}: raise RuntimeError('unsafe_receipt_directory')
    receipt = json.loads(parent.safe(backup / 'receipt.json').read_text())
    if receipt.get('schema') != 1 or receipt.get('phase') != '5f35': raise RuntimeError('wrong_receipt')
    target = parent.safe(Path(receipt['target'])); records = receipt['files']
    if set(records) != set(sources()) | set(INDEXES): raise RuntimeError('receipt_paths_mismatch')
    for rel, record in records.items():
        path = parent.safe(target / rel)
        if record['old'] is not None and parent.blob(parent.safe(backup / rel).read_bytes()) != record['old']:
            raise RuntimeError('backup_corrupt')
        if path.exists():
            if parent.blob(path.read_bytes()) not in (record['old'], record['new']): raise RuntimeError('newer_runtime_refuse_rollback: ' + rel)
        elif record['old'] is not None: raise RuntimeError('missing_original_runtime: ' + rel)
    for rel in INDEXES: parent.atomic(parent.safe(target / rel), parent.safe(backup / rel).read_bytes(), records[rel]['metadata'])
    for rel, record in records.items():
        if record['old'] is None and parent.safe(target / rel).exists(): parent.safe(target / rel).unlink()
    print('PHASE5F35_CONTEXT_ROLLBACK_OK\nPHASE5F34_CACHE_UNCHANGED=1', flush=True)

def install(target):
    if os.geteuid() != 0: raise RuntimeError('root_required')
    originals = preflight(target); data = sources(); indexes = patched(originals)
    with tempfile.TemporaryDirectory(prefix='phpipam-context-lint-') as temporary:
        for rel, value in {**data, **indexes}.items():
            p = Path(temporary) / rel; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(value)
            if rel.endswith('.php'): parent.run(['php', '-l', p])
            if rel.endswith('.js') and shutil.which('node'): parent.run(['node', '--check', p])
    # Staging does not grant permission to overwrite a later runtime change.
    if any(parent.safe(target / rel).read_bytes() != value for rel, value in originals.items()): raise RuntimeError('target_changed_during_staging')
    backups = parent.safe(BACKUPS)
    s = parent.meta(backups)
    if s['uid'] != 0 or s['mode'] & 0o022: raise RuntimeError('unsafe_backup_parent')
    backup = Path(tempfile.mkdtemp(prefix='vmware-context-' + datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S') + '-', dir=backups))
    backup.chmod(0o700); os.chown(backup, 0, 0)
    records = {}
    for rel, value in {**data, **indexes}.items():
        old = originals.get(rel)
        records[rel] = {'old': None if old is None else parent.blob(old), 'new': parent.blob(value),
            'metadata': parent.meta(target / rel) if old is not None else None}
        if old is not None: parent.atomic(backup / rel, old, {'uid': 0, 'gid': 0, 'mode': 0o600})
    parent.atomic(backup / 'receipt.json', parent.encoded({'schema': 1, 'phase': '5f35', 'target': str(target), 'files': records}), {'uid': 0, 'gid': 0, 'mode': 0o600})
    print('Backup / rollback: ' + str(backup), flush=True)
    try:
        for rel, value in data.items(): parent.atomic(parent.safe(target / rel), value)
        for rel, value in indexes.items(): parent.atomic(parent.safe(target / rel), value, records[rel]['metadata'])
        verify(target)
    except BaseException:
        rollback(backup); raise
    print('PHASE5F35_CONTEXT_INSTALL_OK\nVCENTER_REQUESTS_DURING_INSTALL=0\nPHASE5F34_CACHE_UNCHANGED=1', flush=True)
    print('Backup / rollback: ' + str(backup), flush=True)
    return backup

def main():
    if len(sys.argv) != 3 or sys.argv[1] not in ('preflight', 'verify', 'install', 'rollback'):
        raise SystemExit('Usage: phase5f35-vmcard-deploy.py preflight|verify|install APPLICATION or rollback BACKUP')
    action = sys.argv[1]; target = parent.safe(Path(sys.argv[2]).absolute())
    if not target.is_dir(): raise RuntimeError('directory_missing')
    if action not in ('install', 'rollback'): globals()[action](target); return
    if os.geteuid() != 0: raise RuntimeError('root_required')
    lock_target = target
    if action == 'rollback':
        if parent.meta(target) != {'uid': 0, 'gid': 0, 'mode': 0o700}: raise RuntimeError('unsafe_receipt_directory')
        lock_target = parent.safe(Path(json.loads(parent.safe(target / 'receipt.json').read_text())['target']))
    fd = os.open(lock_target, os.O_RDONLY | os.O_DIRECTORY)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        def interrupted(signum, frame): raise RuntimeError('deployment_interrupted')
        signal.signal(signal.SIGTERM, interrupted); signal.signal(signal.SIGINT, interrupted)
        globals()[action](target)
    finally: os.close(fd)

if __name__ == '__main__':
    try: main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr); sys.exit(1)
