#!/usr/bin/env python3
"""Create-only Phase5H shadow benchmark package. No website/systemd/connector mutation."""
import hashlib
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
RELEASE = Path('/opt/phpipam-custom/vmware-fast-shadow')
DATA = Path('/var/lib/phpipam-custom/vmware-fast-shadow')
BACKUPS = Path('/var/backups/phpipam-custom')
ACTIVE_RELEASE = Path('/opt/phpipam-custom/inventory-recovery5g5')
ACTIVE_BACKUP = Path('/var/backups/phpipam-custom/vmware-inventory-20260914-144127-w63cp9jv')
ACTIVE_SCRIPT_BLOB = '36b19f12e95b2f4884b4f625c755ce3df0db460a'
FILES = [
    'tools/phase5h4-shadow-run.php',
    'layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php',
    'layers/phase5h/functions/classes/class.IntegrationVmwareBatchInventory.php',
    'layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php',
    'layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php',
    'layers/phase5h2/functions/classes/class.IntegrationVmwareRosterReconciler.php',
    'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php',
    'layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php',
    'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php',
    'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php',
]
DOMAINS = ['properties', 'metrics', 'capabilities', 'roster']


def blob_sha(path: Path) -> str:
    raw = path.read_bytes()
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def safe(path: Path) -> Path:
    if not path.is_absolute() or '..' in path.parts or '.' in path.parts:
        raise RuntimeError('shadow_path_invalid')
    current = path
    while current != Path('/'):
        if current.is_symlink(): raise RuntimeError('shadow_path_invalid')
        current = current.parent
    return path


def active_status() -> None:
    script = safe(ACTIVE_RELEASE / 'tools/phase5g5-recover.py')
    if not script.is_file() or blob_sha(script) != ACTIVE_SCRIPT_BLOB:
        raise RuntimeError('active_recovery_script_mismatch')
    result = subprocess.run([sys.executable, '-B', str(script), 'status', str(ACTIVE_BACKUP)],
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False)
    text = result.stdout.decode('utf-8', errors='replace')
    if result.returncode != 0 or 'RECOVERY_STAGE=active' not in text or 'CHILD_AND_PARENT_RECEIPTS_VERIFIED=1' not in text:
        raise RuntimeError('active_5g5_not_verified')
    timer = subprocess.run(['systemctl','show','phpipam-vmware-recovery5g5@1.timer',
        '-p','ActiveState','-p','SubState','-p','UnitFileState'], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    values = dict(line.split('=',1) for line in timer.stdout.decode().splitlines() if '=' in line)
    if timer.returncode != 0 or values != {'ActiveState':'active','SubState':'waiting','UnitFileState':'enabled'}:
        raise RuntimeError('active_5g5_timer_not_verified')


def source_manifest() -> dict:
    manifest = {}
    for rel in FILES:
        source = ROOT / rel
        if not source.is_file() or source.is_symlink(): raise RuntimeError('shadow_source_missing')
        raw = source.read_bytes()
        if len(raw) < 20 or len(raw) > 1048576: raise RuntimeError('shadow_source_size')
        manifest[rel] = {'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw)}
    return manifest


def install_file(source: Path, target: Path) -> None:
    safe(target.parent)
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists() or target.is_symlink(): raise RuntimeError('shadow_target_exists')
    tmp = target.parent / ('.install-' + secrets.token_hex(12))
    try:
        with open(tmp, 'xb') as f:
            os.fchmod(f.fileno(), 0o644)
            raw = source.read_bytes(); f.write(raw); f.flush(); os.fsync(f.fileno())
        os.rename(tmp, target)
        d = os.open(target.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(d)
        finally: os.close(d)
    finally:
        if tmp.exists(): tmp.unlink()


def prepare(verify_live: bool = True) -> Path:
    if os.geteuid() != 0: raise RuntimeError('root_required')
    if verify_live: active_status()
    for path in [RELEASE, DATA]:
        safe(path)
        if path.exists() or path.is_symlink(): raise RuntimeError('shadow_already_exists')
    manifest = source_manifest()
    stamp = time.strftime('%Y%m%d-%H%M%S', time.gmtime())
    backup = BACKUPS / ('vmware-fast-shadow-' + stamp + '-' + secrets.token_hex(4))
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    os.chmod(backup, 0o700)
    receipt = {'schema':1,'created_at':int(time.time()),'release':str(RELEASE),'data':str(DATA),
        'active_backup':str(ACTIVE_BACKUP),'files':manifest,'website_changed':False,'systemd_changed':False}
    (backup/'receipt.json').write_text(json.dumps(receipt, sort_keys=True, separators=(',',':'))+'\n')
    os.chmod(backup/'receipt.json',0o600)
    try:
        RELEASE.mkdir(mode=0o755, parents=True, exist_ok=False); os.chmod(RELEASE,0o755)
        for rel in FILES: install_file(ROOT/rel, RELEASE/rel)
        DATA.mkdir(mode=0o700, parents=True, exist_ok=False); os.chown(DATA,33,33); os.chmod(DATA,0o700)
        for name in DOMAINS:
            path=DATA/name; path.mkdir(mode=0o700); os.chown(path,33,33); os.chmod(path,0o700)
        for rel, record in manifest.items():
            target=RELEASE/rel
            st=target.stat()
            if (st.st_uid,st.st_gid,stat.S_IMODE(st.st_mode),st.st_nlink)!=(0,0,0o644,1) or digest(target)!=record['sha256']:
                raise RuntimeError('shadow_install_verify_failed')
        for path in [DATA,*[DATA/n for n in DOMAINS]]:
            st=path.stat()
            if (st.st_uid,st.st_gid,stat.S_IMODE(st.st_mode))!=(33,33,0o700): raise RuntimeError('shadow_data_verify_failed')
    except BaseException:
        # Create-only rollback is safe only while no benchmark ciphertext was produced.
        shutil.rmtree(RELEASE, ignore_errors=True)
        shutil.rmtree(DATA, ignore_errors=True)
        raise
    print('PHASE5H_SHADOW_BACKUP=' + str(backup), flush=True)
    print('PHASE5H_SHADOW_RELEASE=' + str(RELEASE), flush=True)
    print('PHASE5H_SHADOW_DATA=' + str(DATA), flush=True)
    print('PHASE5H_SHADOW_WEBSITE_CHANGED=0', flush=True)
    print('PHASE5H_SHADOW_SYSTEMD_CHANGED=0', flush=True)
    print('PHASE5H_SHADOW_INSTALL_OK=1', flush=True)
    return backup


def main() -> int:
    if len(sys.argv) != 1: raise RuntimeError('arguments_not_supported')
    prepare(True)
    return 0


if __name__ == '__main__':
    rc=0
    try: rc=main()
    except Exception as e:
        print('PHASE5H_SHADOW_INSTALL_ERROR=' + str(e), file=sys.stderr); rc=1
    print('PHASE5H_SHADOW_INSTALL_EXIT=' + str(rc), flush=True)
    sys.exit(rc)
