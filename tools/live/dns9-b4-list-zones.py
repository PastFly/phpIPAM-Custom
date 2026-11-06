#!/usr/bin/env python3
"""One supervised, read-only zone-list job following the accepted B3 test."""
import getpass
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
import time
from pathlib import Path
from dns9_winrm_common import ensure_ntlm_md4_runtime

ROOT=Path(__file__).resolve().parents[2]
GUARDS=Path('/var/lib/phpipam-custom')
PREVIOUS_SHA='617e80a4ec49cbd6ba09d6777ed16713ff993c8d'
PREVIOUS_JOB='cb0c9350c6b6f310a2cb99fcaf13f0f0'
PREVIOUS_RECEIPT=Path('/var/backups/phpipam-custom/dns9-phase-b3-first-cycle-20261106-095458/receipt.json')
MARKER='dns9-list-zones-attempt-global'

def validate_payload(data):
    if len(data)>65536: raise ValueError('payload_too_large')
    try: value=json.loads(data)
    except (UnicodeDecodeError,ValueError) as exc: raise ValueError('payload_invalid') from exc
    if not isinstance(value,dict) or set(value)!={'zones'} or not isinstance(value['zones'],list) or len(value['zones'])>1024:
        raise ValueError('payload_invalid')
    for zone in value['zones']:
        if not isinstance(zone,dict) or set(zone)!={'name','zone_type','ds_integrated','reverse_lookup'}:
            raise ValueError('payload_invalid')
        if not isinstance(zone['name'],str) or len(zone['name'].encode())>255:
            raise ValueError('payload_invalid')
        if not isinstance(zone['zone_type'],str) or len(zone['zone_type'].encode())>32:
            raise ValueError('payload_invalid')
        if type(zone['ds_integrated']) is not bool or type(zone['reverse_lookup']) is not bool:
            raise ValueError('payload_invalid')
    return len(value['zones'])

def check_queue_safety(b3, target=None):
    exception=''
    if target is not None:
        exception=" AND id<>'"+b3.checked_job_id(target)+"'"
    count=b3.one(f"""SELECT COUNT(*) FROM custom_dns_control_jobs WHERE
 (state IN ('succeeded','failed','expired','obsolete') AND COALESCE(finished_at,updated_at)<=UNIX_TIMESTAMP()+600-86400)
 OR (state IN ('pending','running'){exception});""")
    if count!='0': raise RuntimeError('unrelated_queue_or_pruning_risk')

def run_cli(mode):
    if mode not in ('--b4-preflight','--b4-queue'): raise ValueError('mode_invalid')
    p=subprocess.run(['php',str(ROOT/'tools/live/dns9-queue-cli.php'),mode],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False)
    if p.returncode: raise RuntimeError('cli_preflight_or_queue_failed')
    lines=[x for x in p.stdout.splitlines() if x.startswith('DNS9_CLI_JSON=')]
    if len(lines)!=1: raise RuntimeError('cli_output_invalid')
    doc=json.loads(lines[0].split('=',1)[1])
    if not isinstance(doc,dict) or set(doc)!=({'actor_id'} if mode=='--b4-preflight' else {'actor_id','job_id'}) or type(doc['actor_id']) is not int or doc['actor_id']<1:
        raise RuntimeError('cli_output_invalid')
    if mode=='--b4-queue' and (not isinstance(doc['job_id'],str) or not re.fullmatch('[0-9a-f]{32}',doc['job_id'])):
        raise RuntimeError('cli_output_invalid')
    return doc

def local_preflight(b3,post,pdo,auto,prior):
    b3.validate_followup(GUARDS,PREVIOUS_SHA)
    pdo.marker_doc(GUARDS/'dns9-system-probe-attempt-global')
    prior.verify_hotfix(pdo)
    auto.check_installed_classes()
    for src,dst in pdo.BOUNDARY.items():
        if hashlib.sha256(pdo.snapshot(ROOT/src)['data']).digest()!=hashlib.sha256(pdo.snapshot(dst)['data']).digest(): raise RuntimeError('boundary_hash_mismatch')
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_uncertain')
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit() or int(proc.name)==os.getpid(): continue
        try: args=(proc/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError: continue
        except OSError as exc: raise RuntimeError('local_process_uncertain') from exc
        if any(Path(os.fsdecode(x)).name in {'dns9-b4-list-zones.py','dns9-b3-after-pdo.py','dns9-b3-auto-once.py','dns9-system-probe.py','dns9-pdo-hotfix.py'} for x in args if x): raise RuntimeError('another_process_exists')
    if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_changed')
    if b3.job_state(PREVIOUS_JOB)!='succeeded|1||1793958885|ok||1793958889': raise RuntimeError('previous_job_changed')
    receipt=prior.protected_json(pdo,PREVIOUS_RECEIPT)
    expected={'pilot_job_id':PREVIOUS_JOB,'pilot_job_type':'test_connection','pilot_job_state':'succeeded','pilot_attempt_count':1,'pilot_result_code':'ok','temporary_task_removed':True,'permanent_task_state':'Disabled','server_timer_enabled':False,'server_timer_active':False,'dns_write':False}
    if any(receipt.get(k)!=v for k,v in expected.items()): raise RuntimeError('previous_receipt_invalid')
    if (GUARDS/MARKER).exists() or (GUARDS/MARKER).is_symlink(): raise RuntimeError('list_zones_guard_exists')
    check_queue_safety(b3)
    if pdo.fake_poll(b3)!=(401,'collector_auth_failed'): raise RuntimeError('pdo_fake_auth_boundary_failed')

def reserve(sha):
    st=GUARDS.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o022: raise RuntimeError('guard_directory_invalid')
    fd=os.open(GUARDS/MARKER,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        data=(sha+'\n').encode('ascii')
        if os.write(fd,data)!=len(data): raise RuntimeError('guard_write_failed')
        os.fsync(fd)
    finally: os.close(fd)
    fd=os.open(GUARDS,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)

def read_result(b3,job):
    job=b3.checked_job_id(job)
    value=b3.one(f"""SELECT HEX(j.result_payload) FROM custom_dns_control_jobs j
 JOIN custom_dns_collector_assignments a ON a.connection_id=j.connection_id AND a.collector_id=j.collector_id AND a.revision=j.assignment_revision
 JOIN custom_dns_connections c ON c.id=j.connection_id AND c.revision=j.configuration_revision AND c.binding_epoch=j.binding_epoch
 JOIN custom_dns_collectors k ON k.id=j.collector_id
 WHERE j.id='{job}' AND j.job_type='list_zones' AND j.connection_id='{b3.PILOT_CONNECTION_ID}' AND j.collector_id='{b3.PILOT_COLLECTOR_ID}'
 AND c.server='{b3.SERVER}' AND c.credential_mode='collector_identity' AND j.credential_version IS NULL AND k.revoked=0
 AND j.state='succeeded' AND j.attempt_count=1 AND j.lease_id IS NULL AND j.credential_delivered_at IS NOT NULL AND j.result_code='ok' AND j.finished_at IS NOT NULL;""")
    try: data=bytes.fromhex(value)
    except ValueError as exc: raise RuntimeError('payload_encoding_invalid') from exc
    return validate_payload(data),hashlib.sha256(data).hexdigest()

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    sha=(ROOT/'EXPECTED_SHA').read_text().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha): raise RuntimeError('source_sha_invalid')
    import importlib.util
    def module(name,path):
        spec=importlib.util.spec_from_file_location(name,path); obj=importlib.util.module_from_spec(spec); spec.loader.exec_module(obj); return obj
    b3=module('b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    post=module('post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    pdo=module('pdo',ROOT/'tools/live/dns9-pdo-hotfix.py')
    auto=module('auto',ROOT/'tools/live/dns9-b3-auto-once.py')
    prior=module('prior',ROOT/'tools/live/dns9-b3-after-pdo.py')
    local_preflight(b3,post,pdo,auto,prior)
    actor=run_cli('--b4-preflight')['actor_id']
    print('DNS9_B4_ACTOR_VERIFIED=1',flush=True)
    print('DNS9_B4_MD4_PROVIDER='+ensure_ntlm_md4_runtime(),flush=True)
    password=getpass.getpass('WinRM password: ')
    if not password: raise RuntimeError('password_empty')
    prior.remote_preflight(b3,password,expect_success=True)
    print('DNS9_B4_WINDOWS_PREFLIGHT=PASS',flush=True)
    local_preflight(b3,post,pdo,auto,prior)
    if run_cli('--b4-preflight')['actor_id']!=actor: raise RuntimeError('actor_changed')
    reserve(sha)
    print('DNS9_B4_GUARD_CREATED=1',flush=True)
    job=run_cli('--b4-queue')
    print('DNS9_B4_NEW_JOB_ID='+job['job_id'],flush=True)
    if job['actor_id']!=actor: raise RuntimeError('actor_changed_after_queue')
    check_queue_safety(b3,job['job_id'])
    old_args=sys.argv
    try:
        sys.argv=['dns9-phase-b3-first-cycle.py','--bootstrap-receipt',str(prior.RECEIPT),'--username',prior.USER,'--attempt-sha',sha,'--apply']
        b3.main(password_override=password,job_override=job['job_id'],reserved_list_zones=True)
    finally: password=''; sys.argv=old_args
    count,digest=read_result(b3,job['job_id'])
    b3.validate_followup(GUARDS,PREVIOUS_SHA)
    b3._checked_guard(GUARDS/MARKER,sha,0,0)
    print('DNS9_B4_ZONE_COUNT='+str(count))
    print('DNS9_B4_PAYLOAD_SHA256='+digest)
    print('DNS9_B4_PRIOR_GUARDS_RETAINED=1')
    print('DNS9_B4_DNS_WRITE=0')
    print('DNS9_B4_OK=1')

if __name__=='__main__':
    try: main()
    except Exception:
        print('DNS9_B4_ERROR=stopped_requires_review',file=sys.stderr)
        raise SystemExit(1)
