#!/usr/bin/env python3
"""Exact, additive native-search deployment; all installation writes are reversible."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

REPO = Path(__file__).resolve().parents[1]
NATIVE = 'app/tools/search/index.php'
WORKSPACE = 'app/integrations/vmware/index.php'
NATIVE_BLOB = '87b8e1b0325eaaf6d0ede366c7fe1afb6590ac71'
NATIVE_ANCHOR = b'<!-- result -->\n'
AUTOLOAD = b'<script src="app/integrations/vmware/phase5f1-ux.js"></script>'
NATIVE_HOOK = b"<?php\n// PHPIPAM_CUSTOM_VMWARE_PHASE5F331_NATIVE_SEARCH_V1\ninclude(__DIR__ . '/vmware-global-panel.php');\n?>\n"
WORKSPACE_HOOK = b'<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F331_DEEPLINK_V1 -->\n<script src="app/integrations/vmware/phase5f331-deeplink.js"></script>\n'
FILES = (
    'app/tools/search/vmware-global-panel.php',
    'app/tools/search/vmware-global-result.php',
    'app/tools/search/phase5f331-global-search.js',
    'app/integrations/vmware/phase5f331-deeplink.js',
)


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def run(args):
    subprocess.run([str(arg) for arg in args], check=True)


def safe(target, rel):
    path = target
    for part in Path(rel).parts:
        if part in ('', '.', '..') or part.startswith('/'):
            raise RuntimeError('Unsafe target path')
        path = path / part
        if path.is_symlink():
            raise RuntimeError('Symlink in target path: ' + rel)
    return path


def source_files():
    result = {}
    for rel in FILES:
        source = 'layers/phase5f331/' + rel
        data = subprocess.check_output(['git', '-C', str(REPO), 'show', 'HEAD:' + source])
        if (REPO / source).read_bytes() != data:
            raise RuntimeError('Uncommitted runtime source drift: ' + rel)
        result[rel] = data
    return result


def base_verify(target):
    run(['bash', REPO / 'installer/verify-vmware-vcenter-phase5f33-enriched-search.sh', target])


def initial_indexes(target):
    native = safe(target, NATIVE).read_bytes()
    workspace = safe(target, WORKSPACE).read_bytes()
    if blob(native) != NATIVE_BLOB:
        raise RuntimeError('Native search index drift; observed blob=' + blob(native))
    if native.count(NATIVE_ANCHOR) != 1 or NATIVE_HOOK in native:
        raise RuntimeError('Native search insertion anchor mismatch')
    if workspace.count(AUTOLOAD) != 1 or WORKSPACE_HOOK in workspace:
        raise RuntimeError('Workspace auto-load anchor/hook mismatch')
    for rel in FILES:
        if safe(target, rel).exists():
            raise RuntimeError('New runtime target already exists: ' + rel)
    return {NATIVE: native, WORKSPACE: workspace}


def patched(indexes):
    return {
        NATIVE: indexes[NATIVE].replace(NATIVE_ANCHOR, NATIVE_ANCHOR + NATIVE_HOOK, 1),
        WORKSPACE: indexes[WORKSPACE].replace(AUTOLOAD, WORKSPACE_HOOK + AUTOLOAD, 1),
    }


def preflight(target):
    print('=== PHASE 5F.3.3.1 GLOBAL HEADER SEARCH LIVE PREFLIGHT ===', flush=True)
    print('phpIPAM Custom Phase 5F.3.3.1 preflight (READ ONLY)', flush=True)
    print('target=' + str(target), flush=True)
    base_verify(target)
    source_files()
    initial_indexes(target)
    print('PHASE5F331_BASE_PHASE5F33_OK=1')
    print('PHASE5F331_NATIVE_SEARCH_BASE_BLOB=' + NATIVE_BLOB)
    print('PHASE5F331_SOURCE_ABSENT=1')
    print('PREFLIGHT_WRITE_OPERATIONS=0')
    print('PREFLIGHT_VCENTER_REQUESTS=0')
    print('PREFLIGHT_RESULT=READY_FOR_PHASE5F331_GLOBAL_SEARCH_OWNER_APPROVAL', flush=True)


def verify(target):
    base_verify(target)
    for rel, data in source_files().items():
        if safe(target, rel).read_bytes() != data:
            raise RuntimeError('Phase 5F.3.3.1 exact runtime mismatch: ' + rel)
    native = safe(target, NATIVE).read_bytes()
    workspace = safe(target, WORKSPACE).read_bytes()
    if native.count(NATIVE_HOOK) != 1 or blob(native.replace(NATIVE_HOOK, b'', 1)) != NATIVE_BLOB:
        raise RuntimeError('Native search is not the exact additive successor')
    if workspace.count(WORKSPACE_HOOK) != 1 or workspace.count(AUTOLOAD) != 1:
        raise RuntimeError('Workspace deeplink hook count mismatch')
    if workspace.index(WORKSPACE_HOOK) >= workspace.index(AUTOLOAD):
        raise RuntimeError('Deeplink must initialize before the auto-load layer')
    for rel in FILES + (NATIVE, WORKSPACE):
        if rel.endswith('.php'):
            run(['php', '-l', safe(target, rel)])
    print('PHASE5F331_BASE_PHASE5F33_OK=1')
    print('Phase 5F.3.3.1 exact runtime source: OK (4); native search preserved')
    print('PHASE5F331_GLOBAL_SEARCH_VERIFY_OK', flush=True)


def atomic(path, data, metadata=None):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.' + path.name + '.phase5f331-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fchmod(stream.fileno(), metadata['mode'] if metadata else 0o644)
            if metadata and os.geteuid() == 0:
                os.fchown(stream.fileno(), metadata['uid'], metadata['gid'])
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def rollback(backup):
    receipt = json.loads((backup / 'receipt.json').read_text())
    target = Path(receipt['target'])
    # Validate all paths before restoring any. Never undo a later/foreign update.
    for rel, item in receipt['files'].items():
        current = safe(target, rel)
        if not current.exists():
            if item['old'] is not None:
                raise RuntimeError('Rollback refused: original target disappeared: ' + rel)
            continue
        allowed = {item['new']}
        if item['old'] is not None:
            allowed.add(item['old'])
            if blob(safe(backup, rel).read_bytes()) != item['old']:
                raise RuntimeError('Rollback backup corruption: ' + rel)
        if blob(current.read_bytes()) not in allowed:
            raise RuntimeError('Rollback refused: newer/unknown runtime: ' + rel)
    for rel, item in receipt['files'].items():
        if item['old'] is not None:
            atomic(safe(target, rel), safe(backup, rel).read_bytes(), item['metadata'])
    for rel, item in receipt['files'].items():
        if item['old'] is None:
            path = safe(target, rel)
            if path.exists():
                path.unlink()
    print('PHASE5F331_GLOBAL_SEARCH_ROLLBACK_OK', flush=True)


def install(target):
    preflight(target)
    originals = initial_indexes(target)
    candidates = source_files()
    candidates.update(patched(originals))
    with tempfile.TemporaryDirectory(prefix='phpipam-phase5f331-') as stage:
        for rel, data in candidates.items():
            file = Path(stage) / rel
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(data)
            if rel.endswith('.php'):
                run(['php', '-l', file])
            elif rel.endswith('.js') and shutil.which('node'):
                run(['node', '--check', file])
        print('Phase 5F.3.3.1 pre-commit checks: OK', flush=True)
    # The first live write occurs only after the read-only preflight and staging checks.
    lockpath = safe(target, '.phpipam-custom-deploy.lock')
    fd = os.open(lockpath, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'r+') as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        if initial_indexes(target) != originals:
            raise RuntimeError('Target changed while staging the candidate')
        stamp = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S-%f')
        backup = target / ('.phpipam-custom-vcenter-phase5f331-global-search-backup-' + stamp)
        backup.mkdir(mode=0o700)
        receipt = {'target': str(target), 'files': {}}
        for rel, data in candidates.items():
            old = originals.get(rel)
            metadata = None
            if old is not None:
                stat = safe(target, rel).stat()
                metadata = {'mode': stat.st_mode & 0o777, 'uid': stat.st_uid, 'gid': stat.st_gid}
                dest = safe(backup, rel)
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(old)
            receipt['files'][rel] = {'old': blob(old) if old is not None else None, 'new': blob(data), 'metadata': metadata}
        (backup / 'receipt.json').write_text(json.dumps(receipt, indent=2))
        shutil.copyfile(Path(__file__), backup / 'rollback-tool.py')
        launcher = '#!/usr/bin/env bash\nset -euo pipefail\nB="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"\nexec python3 "$B/rollback-tool.py" rollback "$B"\n'
        (backup / 'rollback.sh').write_text(launcher)
        (backup / 'rollback.sh').chmod(0o700)
        print('Phase 5F.3.3.1 backup / rollback prepared: ' + str(backup), flush=True)
        try:
            for rel, data in candidates.items():
                atomic(safe(target, rel), data, receipt['files'][rel]['metadata'])
            verify(target)
        except BaseException:
            print('ERROR: deployment failed; attempting guarded rollback.', file=sys.stderr, flush=True)
            rollback(backup)
            raise
        print('PHASE5F331_GLOBAL_SEARCH_INSTALL_OK')
        print('Backup / rollback: ' + str(backup))
        print('Only native search provider/deeplink files changed; no SQL, credentials, scheduler or vCenter mutation.')
        return backup


def main():
    if len(sys.argv) != 3 or sys.argv[1] not in ('preflight', 'verify', 'install', 'rollback'):
        raise RuntimeError('Usage: phase5f331-global-search.py preflight|verify|install|rollback TARGET')
    mode, raw = sys.argv[1:]
    target = Path(raw)
    if target.is_symlink() or not target.is_dir():
        raise RuntimeError('Expected a real existing target directory')
    target = target.resolve()
    if mode in ('install', 'rollback') and os.geteuid() != 0:
        raise RuntimeError('Installation/rollback requires root')
    {'preflight': preflight, 'verify': verify, 'install': install, 'rollback': rollback}[mode](target)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
