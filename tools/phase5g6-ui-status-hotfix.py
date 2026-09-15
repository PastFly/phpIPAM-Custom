#!/usr/bin/env python3
"""Receipt-aware two-file UI hotfix for active 5G.5. No worker/data/TLS/DB/systemd mutation."""
import argparse
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
APP = Path('/var/www/html')
BACKUPS = Path('/var/backups/phpipam-custom')
ACTIVE_RELEASE = Path('/opt/phpipam-custom/inventory-recovery5g5')
ACTIVE_BACKUP = Path('/var/backups/phpipam-custom/vmware-inventory-20260914-144127-w63cp9jv')
ACTIVE_SCRIPT_BLOB = '36b19f12e95b2f4884b4f625c755ce3df0db460a'
BASE_SOURCE = '74a7fa9e7419830446b822ced2dcb3c1b86ec45b'
UI_SOURCE = '7295a8b9639fa5acf76398e9c7b9d3be4286c87b'
FILES = {
    'functions/classes/class.IntegrationVmwareInventoryNative.php': {
        'source': 'layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php',
        'old_blob': '75a556e8bef7a98dbca5ff9ae153fbc5f18b1bb8',
        'new_blob': '85b5422298f251ea199f8f949c011f458ee28ad4',
    },
    'app/integrations/vmware/phase5g3-status.js': {
        'source': 'layers/phase5g3/app/integrations/vmware/phase5g3-status.js',
        'old_blob': '4d177218f42721f48414f8c9635a8741b454037d',
        'new_blob': 'f69c7659da79604681cc2a5212871dba1f45825a',
    },
}


def blob_bytes(raw: bytes) -> str:
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()

def blob(path: Path) -> str:
    return blob_bytes(path.read_bytes())

def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def safe(path: Path) -> Path:
    if not path.is_absolute() or '..' in path.parts or '.' in path.parts:
        raise RuntimeError('ui_hotfix_path_invalid')
    p = path
    while p != Path('/'):
        if p.is_symlink(): raise RuntimeError('ui_hotfix_path_invalid')
        p = p.parent
    return path

def file_meta(path: Path) -> dict:
    safe(path); st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or stat.S_IMODE(st.st_mode) != 0o644 or st.st_uid != 0 or st.st_gid != 0:
        raise RuntimeError('ui_hotfix_file_profile')
    return {'uid': st.st_uid, 'gid': st.st_gid, 'mode': stat.S_IMODE(st.st_mode)}

def atomic(path: Path, raw: bytes, meta: dict) -> None:
    safe(path.parent)
    tmp = path.parent / ('.phase5g6-' + secrets.token_hex(12))
    mask = os.umask(0o077)
    try:
        with open(tmp, 'xb') as f:
            os.fchmod(f.fileno(), meta['mode']); os.fchown(f.fileno(), meta['uid'], meta['gid'])
            f.write(raw); f.flush(); os.fsync(f.fileno())
        os.replace(tmp, path)
        d = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(d)
        finally: os.close(d)
    finally:
        os.umask(mask)
        if tmp.exists(): tmp.unlink()

def active_receipt_sha() -> str:
    receipt = safe(ACTIVE_BACKUP / 'receipt.json')
    if not receipt.is_file(): raise RuntimeError('active_receipt_missing')
    return sha256(receipt)

def timer_ok() -> bool:
    p = subprocess.run(['systemctl','show','phpipam-vmware-recovery5g5@1.timer','--no-pager',
        '-p','ActiveState','-p','SubState','-p','UnitFileState'], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
    values = dict(line.split('=',1) for line in p.stdout.decode().splitlines() if '=' in line)
    return p.returncode == 0 and values == {'ActiveState':'active','SubState':'waiting','UnitFileState':'enabled'}

def verify_active() -> None:
    script = safe(ACTIVE_RELEASE / 'tools/phase5g5-recover.py')
    if not script.is_file() or blob(script) != ACTIVE_SCRIPT_BLOB: raise RuntimeError('active_recovery_script_mismatch')
    p = subprocess.run([sys.executable,'-B',str(script),'status',str(ACTIVE_BACKUP)],
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90, check=False)
    text = p.stdout.decode('utf-8', errors='replace')
    if p.returncode != 0 or 'RECOVERY_STAGE=active' not in text or 'CHILD_AND_PARENT_RECEIPTS_VERIFIED=1' not in text:
        raise RuntimeError('active_5g5_not_verified')
    if not timer_ok(): raise RuntimeError('active_5g5_timer_not_verified')

def source_bytes(record: dict) -> bytes:
    p = ROOT / record['source']
    if not p.is_file() or p.is_symlink() or blob(p) != record['new_blob']:
        raise RuntimeError('ui_hotfix_source_mismatch')
    return p.read_bytes()

def install(verify_live: bool = True) -> Path:
    if os.geteuid() != 0: raise RuntimeError('root_required')
    if verify_live: verify_active()
    old_receipt = active_receipt_sha()
    records = {}
    for rel, spec in FILES.items():
        target = safe(APP / rel); meta = file_meta(target)
        if blob(target) != spec['old_blob']: raise RuntimeError('ui_hotfix_runtime_drift:' + rel)
        raw = source_bytes(spec)
        records[rel] = {'meta': meta, 'old_blob': spec['old_blob'], 'new_blob': spec['new_blob'],
            'old_sha256': sha256(target), 'new_sha256': hashlib.sha256(raw).hexdigest()}
    php = ROOT / FILES['functions/classes/class.IntegrationVmwareInventoryNative.php']['source']
    if subprocess.run(['php','-l',str(php)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False).returncode != 0:
        raise RuntimeError('ui_hotfix_php_lint_failed')
    stamp = time.strftime('%Y%m%d-%H%M%S', time.gmtime())
    backup = BACKUPS / ('vmware-ui-status-' + stamp + '-' + secrets.token_hex(4))
    backup.mkdir(mode=0o700, parents=True, exist_ok=False); os.chmod(backup,0o700)
    try:
        for rel, record in records.items():
            dst = backup / 'old' / rel; dst.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            shutil.copyfile(APP / rel, dst); os.chmod(dst,0o600)
        receipt = {'schema':1,'phase':'5g6-ui-status','created_at':int(time.time()),'base_source':BASE_SOURCE,
            'ui_source':UI_SOURCE,'active_backup':str(ACTIVE_BACKUP),'active_receipt_sha256':old_receipt,'files':records,
            'website_files_changed':True,'worker_changed':False,'data_changed':False,'systemd_changed':False}
        (backup/'receipt.json').write_text(json.dumps(receipt,sort_keys=True,separators=(',',':'))+'\n'); os.chmod(backup/'receipt.json',0o600)
        for rel, spec in FILES.items(): atomic(APP/rel, source_bytes(spec), records[rel]['meta'])
        for rel, spec in FILES.items():
            file_meta(APP/rel)
            if blob(APP/rel) != spec['new_blob']: raise RuntimeError('ui_hotfix_install_verify_failed')
        if active_receipt_sha() != old_receipt: raise RuntimeError('active_receipt_changed')
        if verify_live and not timer_ok(): raise RuntimeError('active_5g5_timer_changed')
    except BaseException:
        for rel, record in records.items():
            old = backup/'old'/rel
            if old.is_file(): atomic(APP/rel, old.read_bytes(), record['meta'])
        raise
    print('PHASE5G6_UI_BACKUP=' + str(backup))
    print('PHASE5G6_UI_FILES_OK=2')
    print('PHASE5G6_UI_WORKER_CHANGED=0')
    print('PHASE5G6_UI_DATA_CHANGED=0')
    print('PHASE5G6_UI_SYSTEMD_CHANGED=0')
    print('PHASE5G6_UI_INSTALL_OK=1')
    return backup

def load_receipt(backup: Path) -> dict:
    safe(backup)
    value = json.loads((backup/'receipt.json').read_text())
    if value.get('schema') != 1 or value.get('phase') != '5g6-ui-status' or set(value.get('files',{})) != set(FILES):
        raise RuntimeError('ui_hotfix_receipt_invalid')
    return value

def status(backup: Path) -> None:
    r = load_receipt(backup)
    state = 'active'
    for rel, spec in FILES.items():
        if blob(APP/rel) != spec['new_blob']: state = 'drift'
    if active_receipt_sha() != r['active_receipt_sha256']: state = 'drift'
    print('PHASE5G6_UI_STAGE=' + state)
    print('PHASE5G6_UI_TIMER_OK=' + ('1' if timer_ok() else '0'))

def rollback(backup: Path) -> None:
    if os.geteuid() != 0: raise RuntimeError('root_required')
    r = load_receipt(backup)
    for rel, spec in FILES.items():
        file_meta(APP/rel)
        if blob(APP/rel) != spec['new_blob']: raise RuntimeError('ui_hotfix_newer_edit_refused:' + rel)
    if active_receipt_sha() != r['active_receipt_sha256']: raise RuntimeError('active_receipt_changed')
    for rel, record in r['files'].items():
        old = backup/'old'/rel
        if not old.is_file() or hashlib.sha256(old.read_bytes()).hexdigest() != record['old_sha256']:
            raise RuntimeError('ui_hotfix_backup_corrupt')
    for rel, record in r['files'].items(): atomic(APP/rel, (backup/'old'/rel).read_bytes(), record['meta'])
    for rel, spec in FILES.items():
        if blob(APP/rel) != spec['old_blob']: raise RuntimeError('ui_hotfix_rollback_verify_failed')
    verify_active()
    print('PHASE5G6_UI_ROLLBACK_OK=1')


def main() -> int:
    p = argparse.ArgumentParser(); p.add_argument('action', choices=['install','status','rollback']); p.add_argument('backup', nargs='?'); a=p.parse_args()
    if a.action == 'install':
        if a.backup is not None: raise RuntimeError('backup_not_allowed_for_install')
        install(True)
    else:
        if not a.backup: raise RuntimeError('backup_required')
        (status if a.action == 'status' else rollback)(Path(a.backup))
    return 0

if __name__ == '__main__':
    rc=0
    try: rc=main()
    except Exception as e:
        print('PHASE5G6_UI_ERROR=' + str(e), file=sys.stderr); rc=1
    print('PHASE5G6_UI_EXIT=' + str(rc)); sys.exit(rc)
