#!/usr/bin/env python3
"""Read-only post-state for a controlled B3 attempt."""
import argparse
import importlib.util
import json
import os
import re
import stat
import subprocess
import sys
from pathlib import Path

EXPECTED_SOURCE_SHA='262972915047dda0d2c462f433409633070c3463'
GUARD_ROOT=Path('/var/lib/phpipam-custom')
USERNAME='rbank\\kadaev.admin'


def read_job(one,job_id):
    if not re.fullmatch(r'[0-9a-f]{32}',job_id): raise ValueError('job_id_invalid')
    return one(f"""
SELECT CONCAT(
 CASE WHEN job_type='test_connection' THEN job_type ELSE 'other' END,'|',
 CASE WHEN state IN ('pending','running','succeeded','failed','expired','obsolete') THEN state ELSE 'other' END,'|',
 attempt_count,'|',IF(lease_id IS NULL OR lease_id='',0,1),'|',IFNULL(lease_expires_at,0),'|',
 IF(credential_delivered_at IS NULL,0,1),'|',
 CASE WHEN result_code IS NULL THEN '' WHEN result_code IN ('ok','network','timeout','authentication','authorization','configuration','provider','unknown','job_expired','lease_expired','assignment_changed','job_obsolete') THEN result_code ELSE 'other' END,'|',
 OCTET_LENGTH(IFNULL(result_payload,'')),'|',IF(finished_at IS NULL,0,1),'|',
 created_at,'|',updated_at,'|',expires_at)
FROM custom_dns_control_jobs WHERE id='{job_id}';
""")


def guard_state(root,uid=0,gid=0):
    paths=list(root.glob('dns9-b3-live-attempt-*'))
    marker=root/'dns9-b3-live-attempt-global'
    matches=False
    try:
        fd=os.open(marker,os.O_RDONLY|os.O_NOFOLLOW)
        try:
            st=os.fstat(fd)
            matches=(stat.S_ISREG(st.st_mode) and st.st_uid==uid and st.st_gid==gid and
                     stat.S_IMODE(st.st_mode)==0o600 and st.st_nlink==1 and
                     os.read(fd,128)==(EXPECTED_SOURCE_SHA+'\n').encode('ascii'))
        finally: os.close(fd)
    except OSError:
        pass
    return {'count':len(paths),'global_matches':bool(matches)}


def local_process_state():
    active=0; unreadable=0
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit(): continue
        try: args=(proc/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError: continue
        except OSError:
            unreadable+=1; continue
        if any(b'dns9-phase-b3-first-cycle.py' in a or b'--b3-live-once' in a for a in args): active+=1
    return {'matching_processes':active,'unreadable':unreadable}


def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--job-id',required=True); args=ap.parse_args()
    if os.geteuid()!=0: raise RuntimeError('root_required')
    if not re.fullmatch(r'[0-9a-f]{32}',args.job_id): raise ValueError('job_id_invalid')
    base=Path(__file__).resolve().parent
    spec=importlib.util.spec_from_file_location('phase_b3',base/'dns9-phase-b3-first-cycle.py')
    b3=importlib.util.module_from_spec(spec); spec.loader.exec_module(b3)
    print('DNS9_POST_JOB_ID='+args.job_id)
    print('DNS9_POST_JOB_FIELDS=type|state|attempts|lease_present|lease_expires_at|credential_delivered|result_code|payload_bytes|finished|created_at|updated_at|expires_at')
    print('DNS9_POST_JOB='+read_job(b3.one,args.job_id))
    print('DNS9_POST_GUARD='+json.dumps(guard_state(GUARD_ROOT),sort_keys=True))
    print('DNS9_POST_LOCAL_PROCESSES='+json.dumps(local_process_state(),sort_keys=True))
    try:
        print('DNS9_POST_TIMER_ENABLED='+str(int(b3.timer_enabled())))
        print('DNS9_POST_TIMER_ACTIVE='+str(int(b3.timer_active())))
    except RuntimeError:
        print('DNS9_POST_TIMER_STATE=unknown')
    print('DNS9_POST_WINDOWS_CHECK_BEGIN',flush=True)
    result=subprocess.run([sys.executable,str(base/'dns9-phase-b3-diagnose.py'),
                           '--username',USERNAME,'--suffix','4aafbf3f8edc'],check=False)
    if result.returncode!=0: raise RuntimeError('windows_read_only_check_failed')
    print('DNS9_POST_DB_MUTATION=0')
    print('DNS9_POST_REMOTE_MUTATION=0')
    print('DNS9_POST_OK=1')


if __name__=='__main__':
    try: main()
    except Exception as exc:
        print('DNS9_POST_ERROR='+str(exc),file=sys.stderr)
        raise SystemExit(1)
