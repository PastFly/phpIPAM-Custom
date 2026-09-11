#!/usr/bin/env python3
"""Additive, source-pinned VM/datastore folder UI deployment. No vCenter requests."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
BASE = '55c664fb91bb361b6c691d5aed33d0f4064ca16b'
INDEX = 'app/integrations/vmware/index.php'
FILES = (
    'functions/classes/class.IntegrationVmwareVcenterObjectFolders.php',
    'app/integrations/vmware/object-folders-result.php',
    'app/integrations/vmware/phase5f332-object-folders.js',
    'app/integrations/vmware/phase5f332-object-folders.css',
)
HOOK = b'\n<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F332_OBJECT_FOLDERS_UI_V1 -->\n<link rel="stylesheet" href="app/integrations/vmware/phase5f332-object-folders.css">\n<script src="app/integrations/vmware/phase5f332-object-folders.js"></script>\n'


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def safe(root, rel):
    if not rel or Path(rel).is_absolute() or '..' in Path(rel).parts:
        raise RuntimeError('unsafe_path')
    p = root
    for part in Path(rel).parts:
        p = p / part
        if p.is_symlink():
            raise RuntimeError('symlink_rejected: ' + rel)
    return p


def run(args):
    subprocess.run([str(a) for a in args], check=True)


def git(args):
    return subprocess.check_output(['git', '-C', str(ROOT)] + args)


def sources():
    # Pin the proven collector, ancestor tools and the actual bytes selected by HEAD.
    if git(['rev-parse', 'HEAD:layers/phase5f332/' + FILES[0]]).decode().strip() != 'a46c03da333a5d1e2b1ab31ffd358a7b2101394d':
        raise RuntimeError('proven_collector_changed')
    inherited = git(['ls-tree', '-r', '--name-only', BASE, 'installer', 'tools']).decode().splitlines()
    for rel in inherited:
        expected = git(['show', BASE + ':' + rel])
        if safe(ROOT, rel).read_bytes() != expected:
            raise RuntimeError('ancestor_tool_drift: ' + rel)
    data = {}
    for rel in list(FILES) + ['@tools/phase5f332-ui.py']:
        src = rel[1:] if rel.startswith('@') else 'layers/phase5f332/' + rel
        expected = git(['show', 'HEAD:' + src])
        if safe(ROOT, src).read_bytes() != expected:
            raise RuntimeError('working_source_drift: ' + src)
        if not rel.startswith('@'):
            data[rel] = expected
    return data


def base_verify(target):
    run(['bash', ROOT / 'installer/verify-vmware-vcenter-phase5f331-global-search.sh', target])


def original(target):
    value = safe(target, INDEX).read_bytes()
    if b'PHPIPAM_CUSTOM_VMWARE_PHASE5F332_OBJECT_FOLDERS_UI_V1' in value:
        raise RuntimeError('folder_hook_already_present')
    if value.count(b'PHPIPAM_CUSTOM_VMWARE_PHASE5F33_ENRICHED_SEARCH_UI_V1') != 1:
        raise RuntimeError('workspace_base_marker_mismatch')
    for rel in FILES:
        if safe(target, rel).exists():
            raise RuntimeError('folder_target_already_present: ' + rel)
    return value


def patched_index(value):
    return value + HOOK


def preflight(target):
    print('=== PHASE 5F.3.3.2 VM / DATASTORE FOLDER UI LIVE PREFLIGHT ===', flush=True)
    print('phpIPAM Custom Phase 5F.3.3.2 preflight (READ ONLY)\ntarget=' + str(target), flush=True)
    sources()
    base_verify(target)
    original(target)
    print('PHASE5F332_BASE_PHASE5F331_OK=1\nPHASE5F332_SOURCE_ABSENT=1')
    print('PREFLIGHT_WRITE_OPERATIONS=0\nPREFLIGHT_VCENTER_REQUESTS=0')
    print('PREFLIGHT_RESULT=READY_FOR_PHASE5F332_FOLDER_UI_OWNER_APPROVAL', flush=True)


def verify(target):
    data = sources()
    base_verify(target)
    for rel, value in data.items():
        if safe(target, rel).read_bytes() != value:
            raise RuntimeError('folder_runtime_mismatch: ' + rel)
    index = safe(target, INDEX).read_bytes()
    if index.count(HOOK) != 1 or not index.endswith(HOOK):
        raise RuntimeError('folder_index_hook_mismatch')
    for rel in (FILES[0], FILES[1], INDEX):
        run(['php', '-l', safe(target, rel)])
    print('PHASE5F332_BASE_PHASE5F331_OK=1')
    print('Phase 5F.3.3.2 exact runtime source: OK (4); ancestor sources preserved')
    print('PHASE5F332_FOLDER_UI_VERIFY_OK', flush=True)


def atomic(path, data, meta=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.phase5f332-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fchmod(out.fileno(), meta['mode'] if meta else 0o644)
            if meta and os.geteuid() == 0:
                os.fchown(out.fileno(), meta['uid'], meta['gid'])
            os.fsync(out.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def rollback(backup):
    receipt = json.loads(safe(backup, 'receipt.json').read_text())
    target = Path(receipt['target'])
    if target.is_symlink() or not target.is_dir() or set(receipt['files']) != set(FILES + (INDEX,)):
        raise RuntimeError('rollback_receipt_invalid')
    # Verify every path before restoring any; refuse to undo another deployment.
    for rel, record in receipt['files'].items():
        path = safe(target, rel)
        if record['old'] is not None and blob(safe(backup, rel).read_bytes()) != record['old']:
            raise RuntimeError('rollback_backup_corrupt')
        if path.exists():
            if blob(path.read_bytes()) not in (record['old'], record['new']):
                raise RuntimeError('rollback_newer_or_unknown_file: ' + rel)
        elif record['old'] is not None:
            raise RuntimeError('rollback_original_disappeared: ' + rel)
    # Restore the hook-bearing index BEFORE removing its dependencies.
    record = receipt['files'][INDEX]
    atomic(safe(target, INDEX), safe(backup, INDEX).read_bytes(), record['meta'])
    for rel in FILES:
        path = safe(target, rel)
        if path.exists():
            path.unlink()
    print('PHASE5F332_FOLDER_UI_ROLLBACK_OK', flush=True)


def install(target):
    preflight(target)
    old = original(target)
    data = sources()
    data[INDEX] = patched_index(old)
    with tempfile.TemporaryDirectory(prefix='phpipam-5f332-stage-') as stage:
        for rel, value in data.items():
            p = Path(stage) / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(value)
            if rel.endswith('.php'):
                run(['php', '-l', p])
            if rel.endswith('.js') and shutil.which('node'):
                run(['node', '--check', p])
    lock = safe(target, '.phpipam-custom-deploy.lock')
    with os.fdopen(os.open(lock, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600), 'r+') as handle:
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if original(target) != old:
            raise RuntimeError('target_changed_during_staging')
        base_verify(target)
        backup = target / ('.phpipam-custom-vcenter-phase5f332-folders-backup-' + datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S-%f'))
        backup.mkdir(mode=0o700)
        stat = safe(target, INDEX).stat()
        meta = {'mode': stat.st_mode & 0o777, 'uid': stat.st_uid, 'gid': stat.st_gid}
        receipt = {'target': str(target), 'files': {}}
        for rel, value in data.items():
            receipt['files'][rel] = {'old': blob(old) if rel == INDEX else None, 'new': blob(value), 'meta': meta if rel == INDEX else None}
        dest = safe(backup, INDEX)
        dest.parent.mkdir(parents=True)
        dest.write_bytes(old)
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2))
        shutil.copyfile(Path(__file__), backup / 'rollback-tool.py')
        (backup / 'rollback.sh').write_text('#!/usr/bin/env bash\nset -euo pipefail\nB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"\nexec python3 "$B/rollback-tool.py" rollback "$B"\n')
        (backup / 'rollback.sh').chmod(0o700)
        print('Phase 5F.3.3.2 backup / rollback prepared: ' + str(backup), flush=True)
        try:
            for rel, value in data.items():
                atomic(safe(target, rel), value, meta if rel == INDEX else None)
            verify(target)
        except BaseException:
            print('ERROR: folder deployment failed; guarded rollback.', file=sys.stderr, flush=True)
            rollback(backup)
            raise
        print('PHASE5F332_FOLDER_UI_INSTALL_OK\nBackup / rollback: ' + str(backup), flush=True)
        print('Only folder presentation/source hooks installed; no SQL, credentials, scheduler or vCenter mutation.')
        return backup


def main():
    if len(sys.argv) != 3 or sys.argv[1] not in ('preflight', 'verify', 'install', 'rollback'):
        raise RuntimeError('Usage: phase5f332-ui.py preflight|verify|install|rollback TARGET')
    mode, raw = sys.argv[1:]
    target = Path(raw)
    if target.is_symlink() or not target.is_dir():
        raise RuntimeError('real_existing_target_required')
    target = target.resolve()
    if mode in ('install', 'rollback') and os.geteuid() != 0:
        raise RuntimeError('root_required')
    def interrupted(signum, frame):
        raise RuntimeError('deployment_interrupted')
    signal.signal(signal.SIGTERM, interrupted)
    {'preflight': preflight, 'verify': verify, 'install': install, 'rollback': rollback}[mode](target)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
