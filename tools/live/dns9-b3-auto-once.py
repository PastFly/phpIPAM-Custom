#!/usr/bin/env python3
"""One supervised retry after the observed TLS failure; no UI interaction."""
import contextlib
import getpass
import hashlib
import importlib.util
import io
import json
import os
import re
import secrets
import stat
import subprocess
import sys
import time
from pathlib import Path
from dns9_winrm_common import ensure_ntlm_md4_runtime

ROOT=Path(__file__).resolve().parents[2]
WEB=Path('/var/www/html')
BACKUP=Path('/var/backups/phpipam-custom')
GUARDS=Path('/var/lib/phpipam-custom')
RECEIPT=BACKUP/'dns9-runner-tls-upgrade-a8d0fe00e1034084'/'receipt.json'
OLD_JOB='3bf6ee81197e2be7ff5452bc0b4b84c7'
RUNNER_SHA='0828bd77ed5cf0337c4fec71607614782a69853299287966a4ce8793389c4b13'
USER='rbank\\kadaev.admin'
FILES=(
'layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php',
'layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php',
'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php',
'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php',
'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php',
)

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    result=importlib.util.module_from_spec(spec); spec.loader.exec_module(result); return result

def check_installed_classes():
    for name in FILES:
        src=ROOT/name
        dst=WEB/'functions/classes'/src.name
        for p in (src,dst):
            st=p.lstat()
            if not stat.S_ISREG(st.st_mode) or p.is_symlink(): raise RuntimeError('class_file_invalid')
        if hashlib.sha256(src.read_bytes()).digest()!=hashlib.sha256(dst.read_bytes()).digest():
            raise RuntimeError('installed_class_hash_mismatch')

def other_auto_processes():
    for p in Path('/proc').iterdir():
        if not p.name.isdigit() or int(p.name)==os.getpid(): continue
        try: args=(p/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError: continue
        except OSError as exc: raise RuntimeError('local_process_state_uncertain') from exc
        if any(Path(os.fsdecode(a)).name=='dns9-b3-auto-once.py' for a in args if a):
            raise RuntimeError('another_auto_process')

def run_cli(mode):
    if mode not in ('--preflight','--queue'): raise ValueError('mode_invalid')
    proc=subprocess.run(['php',str(ROOT/'tools/live/dns9-queue-cli.php'),mode],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False)
    if proc.returncode!=0: raise RuntimeError('cli_preflight_or_queue_failed')
    lines=[s for s in proc.stdout.splitlines() if s.startswith('DNS9_CLI_JSON=')]
    if len(lines)!=1: raise RuntimeError('cli_output_invalid')
    doc=json.loads(lines[0].split('=',1)[1])
    if not isinstance(doc,dict) or set(doc)!=({'actor_id'} if mode=='--preflight' else {'actor_id','job_id'}) or type(doc['actor_id']) is not int or doc['actor_id']<1:
        raise RuntimeError('cli_output_invalid')
    if mode=='--queue' and (not isinstance(doc['job_id'],str) or not re.fullmatch('[0-9a-f]{32}',doc['job_id'])):
        raise RuntimeError('cli_output_invalid')
    return doc

def local_preflight(b3,post):
    check_installed_classes(); other_auto_processes()
    if post.guard_state(GUARDS)!={'count':1,'global_matches':True}: raise RuntimeError('previous_guard_invalid')
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_state_uncertain')
    if b3.job_state(OLD_JOB)!='pending|0||0|||0': raise RuntimeError('previous_job_changed')
    if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_state_changed')
    ttl,active=b3.pilot_details(OLD_JOB)
    if ttl>=0 or active!=0: raise RuntimeError('queue_not_empty_or_job_not_expired')
    receipt=b3.receipt(RECEIPT,b3.SERVER)
    if b3.expected_runtime_hashes(receipt)['Invoke-PhpIpamDnsCollectorScheduled.ps1']!=RUNNER_SHA:
        raise RuntimeError('upgraded_runner_receipt_invalid')
    return receipt

def windows_preflight(diag,password):
    old_getpass=diag.getpass.getpass; old_argv=sys.argv; out=io.StringIO()
    try:
        diag.getpass.getpass=lambda prompt:password
        sys.argv=['dns9-phase-b3-diagnose.py','--username',USER,'--suffix','4aafbf3f8edc']
        with contextlib.redirect_stdout(out): diag.main()
    finally:
        diag.getpass.getpass=old_getpass; sys.argv=old_argv
    lines=[line for line in out.getvalue().splitlines() if line.startswith('DNS9_PHASE_B3_DIAG_JSON=')]
    if len(lines)!=1: raise RuntimeError('windows_preflight_invalid')
    doc=json.loads(lines[0].split('=',1)[1])
    if doc.get('permanent_task_state')!='Disabled': raise RuntimeError('windows_task_not_disabled')
    if any(type(doc.get(k)) is not int or doc[k]!=0 for k in ('pilot_task_count','collector_process_count','powershell_unknown_commandline_count')):
        raise RuntimeError('windows_process_or_task_uncertain')
    status=doc.get('status')
    if not isinstance(status,dict) or status.get('state')!='error' or status.get('result_code')!='dns9_runtime_error' or status.get('updated_at')!=1793950771:
        raise RuntimeError('previous_windows_status_changed')
    if doc.get('status_epoch_consistent') is not True: raise RuntimeError('windows_status_timestamp_uncertain')

def private_plan(sha,actor):
    st=BACKUP.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o022: raise RuntimeError('backup_root_invalid')
    directory=BACKUP/('dns9-b3-auto-once-'+secrets.token_hex(8)); directory.mkdir(mode=0o700)
    plan={'stage':'prepared_before_retry_reservation','source_sha':sha,'previous_job':OLD_JOB,'actor_id':actor,'tls_upgrade_receipt':str(RECEIPT),'previous_guard_retained':True,'created_at':int(time.time())}
    fd=os.open(directory/'plan.json',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as f:
        json.dump(plan,f,sort_keys=True,indent=2); f.write('\n'); f.flush(); os.fsync(f.fileno())
    return directory

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    sha=(ROOT/'EXPECTED_SHA').read_text().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha): raise RuntimeError('source_sha_invalid')
    b3=module('phase_b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    post=module('phase_b3_post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    diag=module('phase_b3_diag',ROOT/'tools/live/dns9-phase-b3-diagnose.py')
    local_preflight(b3,post)
    actor=run_cli('--preflight')['actor_id']
    print('DNS9_AUTO_PREVIOUS_ACTOR_VERIFIED=1',flush=True)
    print('DNS9_AUTO_MD4_PROVIDER='+ensure_ntlm_md4_runtime(),flush=True)
    password=getpass.getpass('WinRM password: ')
    if not password: raise RuntimeError('password_empty')
    windows_preflight(diag,password)
    print('DNS9_AUTO_WINDOWS_PREFLIGHT=PASS',flush=True)
    local_preflight(b3,post)
    if run_cli('--preflight')['actor_id']!=actor: raise RuntimeError('actor_changed')
    directory=private_plan(sha,actor)
    print('DNS9_AUTO_PLAN='+str(directory/'plan.json'),flush=True)
    b3.reserve_retry(GUARDS,sha)
    print('DNS9_AUTO_RETRY_GUARD_CREATED=1',flush=True)
    job=run_cli('--queue')
    print('DNS9_AUTO_NEW_JOB_ID='+job['job_id'],flush=True)
    if job['actor_id']!=actor: raise RuntimeError('actor_changed_after_queue')
    old_argv=sys.argv
    try:
        sys.argv=['dns9-phase-b3-first-cycle.py','--bootstrap-receipt',str(RECEIPT),'--username',USER,'--attempt-sha',sha,'--apply']
        b3.main(password_override=password,reserved_retry=True,job_override=job['job_id'])
    finally:
        password=''; sys.argv=old_argv
    b3.validate_retry(GUARDS,sha)
    print('DNS9_AUTO_PREVIOUS_GUARD_RETAINED=1')
    print('DNS9_AUTO_RETRY_GUARD_RETAINED=1')
    print('DNS9_AUTO_OK=1')

if __name__=='__main__':
    try: main()
    except Exception:
        print('DNS9_AUTO_ERROR=stopped_requires_review',file=sys.stderr)
        raise SystemExit(1)
