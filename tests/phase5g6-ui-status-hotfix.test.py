#!/usr/bin/env python3
import importlib.util, json, os, shutil, stat, subprocess, tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('hotfix',ROOT/'tools/phase5g6-ui-status-hotfix.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
assert os.geteuid()==0
checks=0
def ok(v):
    global checks;checks+=1
    if not v: raise RuntimeError('assert_'+str(checks))
base=Path(tempfile.mkdtemp(prefix='phase5g6-ui-'))
try:
    m.APP=base/'var/www/html';m.BACKUPS=base/'var/backups/phpipam-custom';m.ACTIVE_BACKUP=base/'active-backup'
    m.APP.mkdir(parents=True);m.BACKUPS.mkdir(parents=True);m.ACTIVE_BACKUP.mkdir(parents=True)
    (m.ACTIVE_BACKUP/'receipt.json').write_text('{"fixture":true}\n');os.chmod(m.ACTIVE_BACKUP/'receipt.json',0o600)
    for rel,spec in m.FILES.items():
        target=m.APP/rel;target.parent.mkdir(parents=True,exist_ok=True)
        raw=subprocess.check_output(['git','show',m.BASE_SOURCE+':'+spec['source']],cwd=ROOT)
        target.write_bytes(raw);os.chmod(target,0o644);os.chown(target,0,0)
        ok(m.blob(target)==spec['old_blob'])
    backup=m.install(False);ok(backup.is_dir())
    receipt=json.loads((backup/'receipt.json').read_text());ok(receipt['worker_changed'] is False and receipt['data_changed'] is False and receipt['systemd_changed'] is False)
    for rel,spec in m.FILES.items():ok(m.blob(m.APP/rel)==spec['new_blob'])
    m.timer_ok=lambda:True
    m.status(backup)
    m.verify_active=lambda:None
    m.rollback(backup)
    for rel,spec in m.FILES.items():ok(m.blob(m.APP/rel)==spec['old_blob'])
    raw=(ROOT/'tools/phase5g6-ui-status-hotfix.py').read_text()
    for forbidden in ['systemctl enable','systemctl disable','systemctl start','systemctl stop','/var/lib/phpipam-custom/vmware-inventory-recovery5g5']:
        ok(forbidden not in raw)
    print('PHASE5G6_UI_HOTFIX_ASSERTIONS='+str(checks))
    print('PHASE5G6_UI_ATOMIC_INSTALL_ROLLBACK_OK')
finally:shutil.rmtree(base,ignore_errors=True)
