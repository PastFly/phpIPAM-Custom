#!/usr/bin/env python3
"""Install the queue-capacity fix while automatic collection stays disabled."""
import hashlib
import importlib.util
import json
import os
import re
import secrets
import stat
import subprocess
import sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
WEB=Path('/var/www/html')
GUARDS=Path('/var/lib/phpipam-custom')
BACKUP=Path('/var/backups/phpipam-custom')
UI_SHA='3a6d2b0ed512e3ae4848d5e8409cc29517618b41'
UI_PLAN=BACKUP/'dns9-zone-ui-40968dcabd23f173'/'plan.json'
MARKER=GUARDS/'dns9-scheduler-fix-deploy-global'
TARGETS=[
 ('layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php','functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php','aeb74b274620dcdf78e98329f517360defaa739839c53e89810e66b7ceded2da'),
 ('layers/dns9/functions/classes/class.IntegrationDnsAutonomousScheduler.php','functions/classes/class.IntegrationDnsAutonomousScheduler.php','1e89cee3f1da0ef71985627c673184ba18c47362e84865bb93cee04b519fbde9'),
 ('layers/dns9/functions/scripts/dns-autonomous-scheduler.php','functions/scripts/dns-autonomous-scheduler.php','ca3419dd6de2872a10f082dc16e6934753fbc237ceb28c329963f2c919c4c358'),
 ('layers/dns1/functions/classes/class.IntegrationDnsAdminView.php','functions/classes/class.IntegrationDnsAdminView.php','444f5835975cb4cb8dfcf56f6f784fd0da34858267d3fa9b32fb2cbe44132864'),
]

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    result=importlib.util.module_from_spec(spec); spec.loader.exec_module(result); return result

def check_timer(b3):
    if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_changed')

def no_scheduler_process():
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit() or int(proc.name)==os.getpid(): continue
        try: args=(proc/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError: continue
        except OSError as exc: raise RuntimeError('local_process_uncertain') from exc
        if any(Path(os.fsdecode(arg)).name in {'dns-autonomous-scheduler.php','dns9-scheduler-deploy.py'} for arg in args if arg):
            raise RuntimeError('another_process_exists')

def preflight(b3,b4,pdo,ui,post):
    b3.validate_followup(GUARDS,ui.B3_SHA)
    b3._checked_guard(GUARDS/'dns9-list-zones-attempt-global',ui.B4_SHA,0,0)
    b3._checked_guard(GUARDS/'dns9-ui-zones-deploy-global',UI_SHA,0,0)
    pdo.marker_doc(GUARDS/'dns9-system-probe-attempt-global')
    doc=pdo.snapshot(UI_PLAN)
    if (doc['uid'],doc['gid'],doc['mode'])!=(0,0,0o600): raise RuntimeError('ui_receipt_invalid')
    plan=json.loads(doc['data'])
    if not isinstance(plan,dict) or plan.get('source_sha')!=UI_SHA or plan.get('job_id')!=ui.JOB or not isinstance(plan.get('files'),list) or len(plan['files'])!=4:
        raise RuntimeError('ui_receipt_invalid')
    check_timer(b3)
    no_scheduler_process()
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_uncertain')
    if b4.read_result(b3,ui.JOB)!=(172,ui.PAYLOAD_SHA): raise RuntimeError('zone_result_changed')
    enabled=b3.one("SELECT COUNT(*) FROM custom_dns_connections WHERE id='"+b3.PILOT_CONNECTION_ID+"' AND enabled=0;")
    if enabled!='1': raise RuntimeError('connection_state_changed')
    for src,dst in pdo.BOUNDARY.items():
        if hashlib.sha256(pdo.snapshot(ROOT/src)['data']).digest()!=hashlib.sha256(pdo.snapshot(dst)['data']).digest(): raise RuntimeError('boundary_hash_mismatch')
    if pdo.fake_poll(b3)!=(401,'collector_auth_failed'): raise RuntimeError('fake_auth_failed')

def smoke():
    result=subprocess.run(['php',str(ROOT/'tools/live/dns9-ui-smoke.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,timeout=60,check=False)
    if result.returncode or result.stdout.strip()!='DNS9_UI_SMOKE_ZONE_COUNT=172': raise RuntimeError('ui_smoke_failed')
    print(result.stdout.strip(),flush=True)

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    sha=(ROOT/'EXPECTED_SHA').read_text().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha): raise RuntimeError('source_sha_invalid')
    b3=module('b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    b4=module('b4',ROOT/'tools/live/dns9-b4-list-zones.py')
    pdo=module('pdo',ROOT/'tools/live/dns9-pdo-hotfix.py')
    ui=module('ui',ROOT/'tools/live/dns9-ui-deploy.py')
    post=module('post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    preflight(b3,b4,pdo,ui,post)
    if MARKER.exists() or MARKER.is_symlink(): raise RuntimeError('deploy_guard_exists')
    changes=[]
    for src,dst,expected in TARGETS:
        source=ROOT/src; target=WEB/dst
        old=pdo.snapshot(target); new=pdo.snapshot(source)['data']
        ui.validate_target(old['data'],new,{expected})
        if subprocess.run(['php','-l',str(source)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode:
            raise RuntimeError('php_syntax_failed')
        changes.append((target,old,new))
    for folder in (GUARDS,BACKUP):
        info=folder.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or info.st_gid!=0 or info.st_mode&0o022: raise RuntimeError('root_directory_invalid')
    preflight(b3,b4,pdo,ui,post)
    ui.reserve(MARKER,sha)
    print('DNS9_SCHED_FIX_GUARD_CREATED=1',flush=True)
    directory=BACKUP/('dns9-scheduler-fix-'+secrets.token_hex(8)); directory.mkdir(mode=0o700)
    plan={'source_sha':sha,'job_id':ui.JOB,'files':[]}
    for target,old,new in changes:
        saved=directory/(target.name+'.original')
        pdo.write_private(saved,old['data'])
        if hashlib.sha256(pdo.snapshot(saved)['data']).digest()!=hashlib.sha256(old['data']).digest(): raise RuntimeError('backup_verify_failed')
        plan['files'].append({'target':str(target),'backup':str(saved),'old_sha256':hashlib.sha256(old['data']).hexdigest(),
            'new_sha256':hashlib.sha256(new).hexdigest(),'uid':old['uid'],'gid':old['gid'],'mode':old['mode']})
    pdo.write_private(directory/'plan.json',(json.dumps(plan,sort_keys=True,indent=2)+'\n').encode())
    for folder in (directory,BACKUP):
        fd=os.open(folder,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(fd)
        finally:os.close(fd)
    print('DNS9_SCHED_FIX_BACKUP='+str(directory),flush=True)
    def verify():
        smoke()
        preflight(b3,b4,pdo,ui,post)
    pdo.apply_changes(changes,verify)
    print('DNS9_SCHED_FIX_TIMER_ENABLED=0')
    print('DNS9_SCHED_FIX_TIMER_ACTIVE=0')
    print('DNS9_SCHED_FIX_SCHEDULER_STARTED=0')
    print('DNS9_SCHED_FIX_DB_MUTATION=0')
    print('DNS9_SCHED_FIX_DNS_WRITE=0')
    print('DNS9_SCHED_FIX_ENABLE_APPROVAL_REQUIRED=1')
    print('DNS9_SCHED_FIX_OK=1')

if __name__=='__main__':
    try: main()
    except Exception as exc:
        allowed={'root_required','source_sha_invalid','ui_receipt_invalid','timer_changed','local_process_uncertain','another_process_exists',
            'zone_result_changed','connection_state_changed','boundary_hash_mismatch','fake_auth_failed','deploy_guard_exists','target_hash_mismatch',
            'php_syntax_failed','root_directory_invalid','backup_verify_failed','ui_smoke_failed','apply_failed_rolled_back','apply_failed_rollback_incomplete'}
        print('DNS9_SCHED_FIX_ERROR='+(str(exc) if str(exc) in allowed else 'stopped_requires_review'),file=sys.stderr)
        raise SystemExit(1)
