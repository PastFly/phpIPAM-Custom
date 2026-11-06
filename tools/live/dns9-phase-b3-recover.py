#!/usr/bin/env python3
"""Archive only the known pre-auth B3 guard after fresh, bounded checks."""
import importlib.util
import json
import os
import stat
import subprocess
import sys
import time
from pathlib import Path

OLD_SHA='60ddc1545fd2b333b44ca149ddd90d6ebfb8e7d1'
GUARD_NAME='dns9-b3-live-attempt-'+OLD_SHA
GUARD_ROOT=Path('/var/lib/phpipam-custom')
BACKUP_ROOT=Path('/var/backups/phpipam-custom')
RECEIPT=BACKUP_ROOT/'dns9-winrm-bootstrap-20260930-122257'/'receipt.json'
USERNAME='rbank\\kadaev.admin'


def check_remote(doc):
    if not isinstance(doc,dict) or doc.get('permanent_task_state')!='Disabled':
        raise RuntimeError('permanent_task_not_disabled')
    for key in ('pilot_task_count','collector_process_count','powershell_unknown_commandline_count'):
        if type(doc.get(key)) is not int or doc[key]!=0:
            raise RuntimeError('windows_task_or_process_state_uncertain')


def _safe_directory(path,uid,gid):
    st=path.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=uid or st.st_gid!=gid or st.st_mode & 0o022:
        raise RuntimeError('directory_metadata_invalid')
    return st


def archive_guard(guard_root,backup_root,uid=0,gid=0):
    guard=guard_root/GUARD_NAME
    _safe_directory(guard_root,uid,gid)
    _safe_directory(backup_root,uid,gid)
    if set(guard_root.glob('dns9-b3-live-attempt-*'))!={guard}:
        raise RuntimeError('guard_set_unexpected')
    root_fd=os.open(guard_root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    backup_fd=None
    try:
        fd=os.open(GUARD_NAME,os.O_RDONLY|os.O_NOFOLLOW,dir_fd=root_fd)
        try:
            st=os.fstat(fd)
            if (not stat.S_ISREG(st.st_mode) or st.st_uid!=uid or st.st_gid!=gid or
                stat.S_IMODE(st.st_mode)!=0o600 or st.st_nlink!=1 or
                os.read(fd,128)!=(OLD_SHA+'\n').encode('ascii')):
                raise RuntimeError('guard_metadata_or_content_invalid')
        finally:
            os.close(fd)
        saved=backup_root/('dns9-b3-preauth-guard-'+str(time.time_ns()))
        saved.mkdir(mode=0o700)
        parent_fd=os.open(backup_root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try: os.fsync(parent_fd)
        finally: os.close(parent_fd)
        backup_fd=os.open(saved,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        current=os.stat(GUARD_NAME,dir_fd=root_fd,follow_symlinks=False)
        if (current.st_dev,current.st_ino,current.st_size,current.st_mtime_ns,current.st_ctime_ns,current.st_mode,current.st_uid,current.st_gid,current.st_nlink)!=(st.st_dev,st.st_ino,st.st_size,st.st_mtime_ns,st.st_ctime_ns,st.st_mode,st.st_uid,st.st_gid,st.st_nlink):
            raise RuntimeError('guard_changed_during_recovery')
        if set(guard_root.glob('dns9-b3-live-attempt-*'))!={guard}:
            raise RuntimeError('guard_set_changed')
        os.rename(GUARD_NAME,'original-guard',src_dir_fd=root_fd,dst_dir_fd=backup_fd)
        os.fsync(root_fd)
        os.fsync(backup_fd)
        return saved/'original-guard'
    finally:
        if backup_fd is not None: os.close(backup_fd)
        os.close(root_fd)


def check_local_processes():
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit(): continue
        try:
            args=(proc/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError:
            continue
        except OSError as exc:
            raise RuntimeError('local_process_state_uncertain') from exc
        if any(b'dns9-phase-b3-first-cycle.py' in a or b'--b3-live-once' in a for a in args):
            raise RuntimeError('local_live_process_exists')


def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    base=Path(__file__).resolve().parent
    spec=importlib.util.spec_from_file_location('phase_b3',base/'dns9-phase-b3-first-cycle.py')
    b3=importlib.util.module_from_spec(spec); spec.loader.exec_module(b3)
    b3.receipt(RECEIPT,b3.SERVER)
    def local_preflight():
        check_local_processes()
        if b3.job_state()!='pending|0||0|||0': raise RuntimeError('pilot_not_pristine')
        if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_not_disabled_inactive')
        b3.pilot_ttl()
    local_preflight()
    proc=subprocess.run([sys.executable,str(base/'dns9-phase-b3-diagnose.py'),
                         '--username',USERNAME,'--suffix','4aafbf3f8edc'],
                        stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False)
    if proc.returncode!=0: raise RuntimeError('windows_read_only_check_failed')
    lines=[line for line in proc.stdout.splitlines() if line.startswith('DNS9_PHASE_B3_DIAG_JSON=')]
    if len(lines)!=1: raise RuntimeError('windows_evidence_invalid')
    check_remote(json.loads(lines[0].split('=',1)[1]))
    print('DNS9_B3_RECOVERY_WINDOWS_CHECK=PASS')
    local_preflight()
    archived=archive_guard(GUARD_ROOT,BACKUP_ROOT)
    print('DNS9_B3_GUARD_ARCHIVED='+str(archived))
    print('DNS9_B3_RECOVERY_LIVE_CYCLE_STARTED=0')
    print('DNS9_B3_RECOVERY_OK=1')


if __name__=='__main__':
    try: main()
    except Exception as exc:
        print('DNS9_B3_RECOVERY_ERROR='+str(exc),file=sys.stderr)
        raise SystemExit(1)
