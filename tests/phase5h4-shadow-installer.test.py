#!/usr/bin/env python3
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('installer',ROOT/'tools/install-phase5h4-shadow.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
assert os.geteuid()==0
checks=0
def ok(v):
    global checks;checks+=1
    if not v: raise RuntimeError('assert_'+str(checks))

base=Path(tempfile.mkdtemp(prefix='phase5h-shadow-install-'))
try:
    m.RELEASE=base/'opt/phpipam-custom/vmware-fast-shadow'
    m.DATA=base/'var/lib/phpipam-custom/vmware-fast-shadow'
    m.BACKUPS=base/'var/backups/phpipam-custom'
    backup=m.prepare(False)
    ok(m.RELEASE.is_dir() and m.DATA.is_dir() and backup.is_dir())
    receipt=json.loads((backup/'receipt.json').read_text())
    ok(receipt['website_changed'] is False and receipt['systemd_changed'] is False)
    ok(receipt['release']==str(m.RELEASE) and receipt['data']==str(m.DATA))
    ok(set(receipt['files'])==set(m.FILES))
    for rel,meta in receipt['files'].items():
        p=m.RELEASE/rel; st=p.stat()
        ok(p.is_file() and stat.S_IMODE(st.st_mode)==0o644 and st.st_uid==0 and st.st_gid==0)
        ok(m.digest(p)==meta['sha256'])
    for p in [m.DATA,*[m.DATA/x for x in m.DOMAINS]]:
        st=p.stat();ok(p.is_dir() and stat.S_IMODE(st.st_mode)==0o700 and st.st_uid==33 and st.st_gid==33)
    ok(not any((base/'etc/systemd/system').glob('*')) if (base/'etc/systemd/system').exists() else True)
    try:m.prepare(False)
    except RuntimeError as e:ok(str(e)=='shadow_already_exists')
    else:raise RuntimeError('duplicate_install_not_rejected')
    raw=(ROOT/'tools/install-phase5h4-shadow.py').read_text()
    ok('systemctl enable' not in raw and 'systemctl start' not in raw and '/var/www/html/' not in raw)
    runner=(ROOT/'tools/phase5h4-shadow-run.php').read_text()
    for forbidden in ['file_put_contents(', 'unlink(', 'rename(', 'systemctl', 'shell_exec(', 'exec(']:
        ok(forbidden not in runner)
    ok("website_served_from_shadow" not in runner)  # Set only by the reviewed ShadowCycle result, not a serving path.
    print('PHASE5H4_SHADOW_INSTALL_ASSERTIONS='+str(checks))
    print('PHASE5H4_CREATE_ONLY_PACKAGE_OK')
finally:
    shutil.rmtree(base,ignore_errors=True)
