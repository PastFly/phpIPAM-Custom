#!/usr/bin/env python3
"""Create-only Phase5H.10 disabled reader candidate staging installer.

It stages a pinned reader overlay under /opt and records a receipt. It never writes
/var/www/html, never provisions the live reader config, and never changes systemd/DB/TLS/connector state.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import stat
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
CANDIDATE = Path('/opt/phpipam-custom/vmware-fast-reader-candidate')
LIVE_CONFIG = Path('/etc/phpipam-custom/vmware-fast-reader.json')
BACKUPS = Path('/var/backups/phpipam-custom')
ACTIVE_RELEASE = Path('/opt/phpipam-custom/inventory-recovery5g5')
ACTIVE_BACKUP = Path('/var/backups/phpipam-custom/vmware-inventory-20260914-144127-w63cp9jv')
ACTIVE_RECOVERY_REL = Path('tools/phase5g5-recover.py')
ACTIVE_RECOVERY_BLOB = '36b19f12e95b2f4884b4f625c755ce3df0db460a'
BUILDER = ROOT / 'tools/build-phase5h10-disabled-reader-candidate.py'
BUILDER_BLOB = 'cbc4d5ba4d5370f6ee8aee37b08b73aff7652b1e'
SERVING_TIMER = 'phpipam-vmware-recovery5g5@1.timer'
SHADOW_TIMER = 'phpipam-vmware-fast-shadow@1.timer'
SHADOW_DATA = Path('/var/lib/phpipam-custom/vmware-fast-shadow')
LOCK = Path('/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock')
WEBROOT = Path('/var/www/html')
WEB_TARGETS = (
    Path('functions/classes/class.IntegrationVmwareInventoryContext.php'),
    Path('functions/classes/class.IntegrationVmwareInventoryNative.php'),
    Path('app/integrations/vmware/mirror/native.php'),
)


def git_blob(raw: bytes) -> str:
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def mapped(system: Path, absolute: Path) -> Path:
    if not absolute.is_absolute(): raise RuntimeError('phase5h10_path_not_absolute')
    return absolute if system == Path('/') else system / absolute.relative_to('/')


def no_links(path: Path) -> None:
    if not path.is_absolute() or '..' in path.parts:
        raise RuntimeError('phase5h10_path_invalid')
    current = path
    while current != Path('/'):
        if current.is_symlink(): raise RuntimeError('phase5h10_path_invalid')
        current = current.parent


def regular(path: Path, *, uid=None, gid=None, mode=None, size_zero=False) -> os.stat_result:
    no_links(path); st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1: raise RuntimeError('phase5h10_file_boundary')
    if uid is not None and st.st_uid != uid: raise RuntimeError('phase5h10_file_boundary')
    if gid is not None and st.st_gid != gid: raise RuntimeError('phase5h10_file_boundary')
    if mode is not None and stat.S_IMODE(st.st_mode) != mode: raise RuntimeError('phase5h10_file_boundary')
    if size_zero and st.st_size != 0: raise RuntimeError('phase5h10_file_boundary')
    return st


def directory(path: Path, *, uid=None, gid=None, mode=None) -> os.stat_result:
    no_links(path); st = path.lstat()
    if not stat.S_ISDIR(st.st_mode): raise RuntimeError('phase5h10_directory_boundary')
    if uid is not None and st.st_uid != uid: raise RuntimeError('phase5h10_directory_boundary')
    if gid is not None and st.st_gid != gid: raise RuntimeError('phase5h10_directory_boundary')
    if mode is not None and stat.S_IMODE(st.st_mode) != mode: raise RuntimeError('phase5h10_directory_boundary')
    return st


def fsync_dir(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)


def load_builder():
    raw = BUILDER.read_bytes()
    if git_blob(raw) != BUILDER_BLOB: raise RuntimeError('phase5h10_builder_blob_drift')
    spec = importlib.util.spec_from_file_location('phase5h10_builder', BUILDER)
    mod = importlib.util.module_from_spec(spec); spec.loader.exec_module(mod); return mod


def run(args, timeout=90):
    return subprocess.run([str(a) for a in args], stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        text=True, timeout=timeout, check=False)


def unit_state(unit: str) -> dict:
    result = run(['systemctl','show',unit,'-p','ActiveState','-p','SubState','-p','UnitFileState'])
    if result.returncode != 0: raise RuntimeError('phase5h10_systemd_query')
    return dict(line.split('=',1) for line in result.stdout.splitlines() if '=' in line)


def verify_live_boundary() -> dict:
    if os.geteuid() != 0: raise RuntimeError('root_required')
    if shutil.which('systemctl') is None: raise RuntimeError('phase5h10_capability_missing')

    recovery = ACTIVE_RELEASE / ACTIVE_RECOVERY_REL
    regular(recovery, uid=0, gid=0, mode=0o644)
    if git_blob(recovery.read_bytes()) != ACTIVE_RECOVERY_BLOB:
        raise RuntimeError('phase5h10_active_recovery_script_drift')
    status = run([sys.executable, '-B', recovery, 'status', ACTIVE_BACKUP])
    if status.returncode != 0 or 'RECOVERY_STAGE=active' not in status.stdout or 'CHILD_AND_PARENT_RECEIPTS_VERIFIED=1' not in status.stdout:
        raise RuntimeError('phase5h10_active_recovery_not_verified')

    for timer in [SERVING_TIMER, SHADOW_TIMER]:
        state = unit_state(timer)
        if state.get('ActiveState') != 'active' or state.get('UnitFileState') != 'enabled':
            raise RuntimeError('phase5h10_timer_boundary:' + timer)

    regular(LOCK, uid=33, gid=33, mode=0o600, size_zero=True)
    directory(SHADOW_DATA, uid=33, gid=33, mode=0o700)
    for name in ['properties','metrics','capabilities','roster']:
        directory(SHADOW_DATA / name, uid=33, gid=33, mode=0o700)

    if LIVE_CONFIG.exists() or LIVE_CONFIG.is_symlink():
        raise RuntimeError('phase5h10_live_reader_config_already_exists')
    if CANDIDATE.exists() or CANDIDATE.is_symlink():
        raise RuntimeError('phase5h10_candidate_already_exists')

    targets = {}
    for rel in WEB_TARGETS:
        path = WEBROOT / rel
        regular(path)
        targets[str(rel)] = digest(path.read_bytes())
    return targets


def require_parent(system: Path, absolute: Path) -> Path:
    path = mapped(system, absolute)
    directory(path)
    return path


def write_file(path: Path, raw: bytes, mode: int, uid: int, gid: int, apply_owner: bool) -> None:
    """Publish through an exclusive hard link so a racing target is never overwritten."""
    no_links(path.parent)
    if path.exists() or path.is_symlink(): raise RuntimeError('phase5h10_target_exists')
    tmp = path.parent / ('.install-' + secrets.token_hex(12))
    linked = False
    try:
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            with os.fdopen(fd, 'wb', closefd=False) as out:
                out.write(raw); out.flush(); os.fsync(out.fileno())
        finally: os.close(fd)
        if apply_owner: os.chown(tmp, uid, gid)
        os.chmod(tmp, mode)
        regular(tmp, uid=uid if apply_owner else None, gid=gid if apply_owner else None, mode=mode)
        if path.exists() or path.is_symlink(): raise RuntimeError('phase5h10_target_exists')
        os.link(tmp, path, follow_symlinks=False); linked = True
        tmp.unlink()
        regular(path, uid=uid if apply_owner else None, gid=gid if apply_owner else None, mode=mode)
        if digest(path.read_bytes()) != digest(raw): raise RuntimeError('phase5h10_publish_hash')
        fsync_dir(path.parent)
    except BaseException:
        if linked:
            try:
                if path.is_file() and not path.is_symlink() and digest(path.read_bytes()) == digest(raw): path.unlink()
            except Exception: pass
        try:
            if tmp.exists() and not tmp.is_symlink(): tmp.unlink()
        except Exception: pass
        raise


def ensure_dir(path: Path, mode: int, uid: int, gid: int, apply_owner: bool, created_dirs: list[Path]) -> None:
    """Only reuse directories already created and tracked by this transaction."""
    if path.exists() or path.is_symlink():
        if path not in created_dirs: raise RuntimeError('phase5h10_target_exists')
        directory(path, uid=uid if apply_owner else None, gid=gid if apply_owner else None, mode=mode)
        return
    no_links(path.parent)
    try: path.mkdir(mode=mode)
    except FileExistsError as error: raise RuntimeError('phase5h10_target_exists') from error
    if apply_owner: os.chown(path, uid, gid)
    os.chmod(path, mode)
    directory(path, uid=uid if apply_owner else None, gid=gid if apply_owner else None, mode=mode)
    created_dirs.append(path)
    fsync_dir(path.parent)


def write_receipt(path: Path, receipt: dict, apply_owner: bool) -> None:
    raw = (json.dumps(receipt, sort_keys=True, separators=(',', ':')) + '\n').encode()
    tmp = path.parent / ('.receipt-' + secrets.token_hex(8))
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, 'wb', closefd=False) as out:
            out.write(raw); out.flush(); os.fsync(out.fileno())
    finally: os.close(fd)
    if apply_owner: os.chown(tmp, 0, 0)
    os.chmod(tmp, 0o600); os.replace(tmp, path); fsync_dir(path.parent)


def rollback(created_files: list[tuple[Path,str]], created_dirs: list[Path]) -> bool:
    clean = True
    for path, expected in reversed(created_files):
        try:
            if path.is_symlink() or not path.is_file() or digest(path.read_bytes()) != expected:
                clean = False; continue
            parent = path.parent; path.unlink(); fsync_dir(parent)
        except FileNotFoundError: pass
        except Exception: clean = False
    for path in reversed(created_dirs):
        try:
            parent = path.parent; path.rmdir(); fsync_dir(parent)
        except FileNotFoundError: pass
        except OSError: clean = False
    return clean


def install(system: Path = Path('/'), verify_live: bool = True, apply_owner: bool = True, fault_after: int | None = None) -> Path:
    if system == Path('/') and os.geteuid() != 0: raise RuntimeError('root_required')
    if not system.is_absolute(): raise RuntimeError('phase5h10_test_root_invalid')
    if not apply_owner and system == Path('/'): raise RuntimeError('phase5h10_owner_bypass_forbidden')

    require_parent(system, Path('/opt/phpipam-custom'))
    backup_parent = require_parent(system, BACKUPS)
    require_parent(system, Path('/etc/phpipam-custom'))
    candidate = mapped(system, CANDIDATE)
    live_config = mapped(system, LIVE_CONFIG)
    if candidate.exists() or candidate.is_symlink(): raise RuntimeError('phase5h10_candidate_already_exists')
    if live_config.exists() or live_config.is_symlink(): raise RuntimeError('phase5h10_live_reader_config_already_exists')

    live_targets = verify_live_boundary() if verify_live else {}
    bundle = load_builder().generate()
    files = bundle['files']; manifest = bundle['manifest']
    if manifest.get('config_enabled') is not False or manifest.get('serving_cutover') is not False:
        raise RuntimeError('phase5h10_bundle_not_disabled')

    stamp = time.strftime('%Y%m%d-%H%M%S', time.gmtime())
    backup = backup_parent / ('vmware-fast-reader-candidate-' + stamp + '-' + secrets.token_hex(4))
    backup.mkdir(mode=0o700)
    if apply_owner: os.chown(backup, 0, 0)
    os.chmod(backup, 0o700); fsync_dir(backup.parent)
    receipt_path = backup / 'receipt.json'
    receipt = {
        'schema':1, 'stage':'preparing', 'created_at':int(time.time()), 'create_only':True,
        'candidate':str(CANDIDATE), 'live_config_created':False, 'website_changed':False,
        'systemd_changed':False, 'database_changed':False, 'connector_tls_changed':False,
        'active_backup':str(ACTIVE_BACKUP), 'source_head':manifest['source_head'],
        'bundle_manifest':manifest, 'live_target_sha256_before':live_targets,
    }
    write_receipt(receipt_path, receipt, apply_owner)

    created_files: list[tuple[Path,str]] = []
    created_dirs: list[Path] = []
    writes = 0
    try:
        try: candidate.mkdir(mode=0o755)
        except FileExistsError as error: raise RuntimeError('phase5h10_candidate_already_exists') from error
        if apply_owner: os.chown(candidate, 0, 0)
        os.chmod(candidate, 0o755)
        directory(candidate, uid=0 if apply_owner else None, gid=0 if apply_owner else None, mode=0o755)
        created_dirs.append(candidate); fsync_dir(candidate.parent)
        for rel in sorted(files):
            raw = files[rel]; path = candidate / rel
            parts = path.parent.relative_to(candidate).parts
            cur = candidate
            for part in parts:
                cur = cur / part
                config_tree = cur == candidate / 'config'
                ensure_dir(cur, 0o750 if config_tree else 0o755, 0, 33 if config_tree else 0, apply_owner, created_dirs)
            is_config = rel == 'config/vmware-fast-reader.json'
            mode, uid, gid = (0o640,0,33) if is_config else (0o644,0,0)
            write_file(path, raw, mode, uid, gid, apply_owner)
            created_files.append((path, digest(raw))); writes += 1
            if fault_after is not None and writes >= fault_after: raise RuntimeError('phase5h10_injected_failure')

        for rel, raw in files.items():
            path = candidate / rel; st = regular(path, mode=0o640 if rel == 'config/vmware-fast-reader.json' else 0o644)
            if apply_owner:
                expected_gid = 33 if rel == 'config/vmware-fast-reader.json' else 0
                if (st.st_uid, st.st_gid) != (0, expected_gid): raise RuntimeError('phase5h10_installed_metadata')
            if digest(path.read_bytes()) != digest(raw): raise RuntimeError('phase5h10_installed_hash')
        if json.loads((candidate/'config/vmware-fast-reader.json').read_text())['enabled'] is not False:
            raise RuntimeError('phase5h10_installed_config_not_disabled')

        if verify_live:
            after = verify_live_boundary_after(candidate)
            if after != live_targets: raise RuntimeError('phase5h10_webroot_changed')

        receipt['stage'] = 'prepared'; receipt['prepared_at'] = int(time.time())
        receipt['candidate_files'] = {rel:digest(raw) for rel,raw in files.items()}
        write_receipt(receipt_path, receipt, apply_owner)
        print('PHASE5H10_CANDIDATE_BACKUP=' + str(backup), flush=True)
        print('PHASE5H10_CANDIDATE_RELEASE=' + str(CANDIDATE), flush=True)
        print('PHASE5H10_CONFIG_STAGED_DISABLED=1', flush=True)
        print('PHASE5H10_LIVE_CONFIG_CREATED=0', flush=True)
        print('PHASE5H10_WEBSITE_CHANGED=0', flush=True)
        print('PHASE5H10_SYSTEMD_CHANGED=0', flush=True)
        print('PHASE5H10_DATABASE_CHANGED=0', flush=True)
        print('PHASE5H10_CONNECTOR_TLS_CHANGED=0', flush=True)
        print('PHASE5H10_CANDIDATE_PREPARED=1', flush=True)
        return backup
    except BaseException:
        clean = rollback(created_files, created_dirs)
        receipt['stage'] = 'rolled_back' if clean else 'rollback_incomplete'
        receipt['rollback_clean'] = clean
        try: write_receipt(receipt_path, receipt, apply_owner)
        except Exception: pass
        print('PHASE5H10_AUTO_ROLLBACK=' + ('1' if clean else '0'), flush=True)
        raise


def verify_live_boundary_after(candidate_real: Path) -> dict:
    # Candidate must exist now, so re-check every other production boundary explicitly.
    if not candidate_real.is_dir(): raise RuntimeError('phase5h10_candidate_missing')
    recovery = ACTIVE_RELEASE / ACTIVE_RECOVERY_REL
    regular(recovery, uid=0, gid=0, mode=0o644)
    if git_blob(recovery.read_bytes()) != ACTIVE_RECOVERY_BLOB: raise RuntimeError('phase5h10_active_recovery_script_drift')
    status = run([sys.executable, '-B', recovery, 'status', ACTIVE_BACKUP])
    if status.returncode != 0 or 'RECOVERY_STAGE=active' not in status.stdout or 'CHILD_AND_PARENT_RECEIPTS_VERIFIED=1' not in status.stdout:
        raise RuntimeError('phase5h10_active_recovery_not_verified')
    for timer in [SERVING_TIMER, SHADOW_TIMER]:
        state = unit_state(timer)
        if state.get('ActiveState') != 'active' or state.get('UnitFileState') != 'enabled': raise RuntimeError('phase5h10_timer_boundary:' + timer)
    regular(LOCK, uid=33, gid=33, mode=0o600, size_zero=True)
    if LIVE_CONFIG.exists() or LIVE_CONFIG.is_symlink(): raise RuntimeError('phase5h10_live_reader_config_changed')
    out = {}
    for rel in WEB_TARGETS:
        path = WEBROOT / rel; regular(path); out[str(rel)] = digest(path.read_bytes())
    return out


def main() -> int:
    if len(sys.argv) != 1: raise RuntimeError('arguments_not_supported')
    install()
    return 0

if __name__ == '__main__':
    rc=0
    try: rc=main()
    except Exception as error:
        print('PHASE5H10_INSTALL_ERROR=' + str(error), file=sys.stderr); rc=1
    print('PHASE5H10_INSTALL_EXIT=' + str(rc), flush=True)
    sys.exit(rc)
