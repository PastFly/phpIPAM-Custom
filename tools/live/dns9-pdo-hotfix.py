#!/usr/bin/env python3
"""Verify cold-PDO failure and atomically update two backed-up DNS adapters."""
import hashlib
import importlib.util
import json
import os
import re
import secrets
import stat
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
WEB=Path('/var/www/html')
BACKUP=Path('/var/backups/phpipam-custom')
GUARDS=Path('/var/lib/phpipam-custom')
JOB='1c7ed02c3f6ca1d2a6a525e56481d243'
RETRY_SHA='bbd3ef04137a7f2268167b0850788ea4a46d1b28'
SYS_SHA='9594050c786e0b503ed7fabd2adf6018588cbb66'
SYS_NONCE='06a614ac9f509ceb'
MARKER='dns9-pdo-hotfix-attempt-global'
TARGETS={
'layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php':'8ea744c4acb030d8ae28baff7cf9449ded408f8922482063cfea998be9d0b149',
'layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php':'1fef2cf06087042e835190c61257788c637ff7dc41ef08792b98a42e1c73eebc',
}
BOUNDARY={
'layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php':WEB/'functions/classes/class.IntegrationDnsCollectorMachineService.php',
'layers/dns5/functions/classes/class.IntegrationDnsCollectorMachineHttpController.php':WEB/'functions/classes/class.IntegrationDnsCollectorMachineHttpController.php',
'layers/dns5/app/integrations/dns/machine-control.php':WEB/'app/integrations/dns/machine-control.php',
}

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    obj=importlib.util.module_from_spec(spec); spec.loader.exec_module(obj); return obj

def xattrs(fd):
    if not hasattr(os,'listxattr'): return {}
    return {name:os.getxattr(fd,name) for name in os.listxattr(fd)}

def snapshot(path):
    try: fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    except OSError as exc: raise RuntimeError('target_boundary_invalid') from exc
    try:
        st=os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_nlink!=1: raise RuntimeError('target_boundary_invalid')
        chunks=[]
        while True:
            chunk=os.read(fd,65536)
            if not chunk: break
            chunks.append(chunk)
            if sum(map(len,chunks))>2*1024*1024: raise RuntimeError('target_too_large')
        after=os.fstat(fd)
        if (st.st_dev,st.st_ino,st.st_size,st.st_mtime_ns,st.st_ctime_ns)!=(after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns): raise RuntimeError('target_changed')
        return {'data':b''.join(chunks),'uid':st.st_uid,'gid':st.st_gid,'mode':stat.S_IMODE(st.st_mode),
                'dev':st.st_dev,'ino':st.st_ino,'mtime':st.st_mtime_ns,'ctime':st.st_ctime_ns,'xattrs':xattrs(fd)}
    finally: os.close(fd)

def atomic_replace(path,data,expected):
    current=snapshot(path)
    if current!=expected: raise RuntimeError('target_changed')
    fd,tmp=tempfile.mkstemp(prefix='.'+path.name+'.dns9-',dir=path.parent)
    try:
        tmpstat=os.fstat(fd)
        if (tmpstat.st_uid,tmpstat.st_gid)!=(expected['uid'],expected['gid']): os.fchown(fd,expected['uid'],expected['gid'])
        os.fchmod(fd,expected['mode'])
        if expected['xattrs'] and not hasattr(os,'setxattr'): raise RuntimeError('xattrs_unavailable')
        for name,value in expected['xattrs'].items(): os.setxattr(fd,name,value)
        if xattrs(fd)!=expected['xattrs'] or stat.S_IMODE(os.fstat(fd).st_mode)!=expected['mode']: raise RuntimeError('metadata_copy_failed')
        view=memoryview(data)
        while view:
            n=os.write(fd,view)
            if n<=0: raise RuntimeError('write_failed')
            view=view[n:]
        os.fsync(fd); os.close(fd);fd=-1
        if snapshot(path)!=expected: raise RuntimeError('target_changed')
        os.replace(tmp,path); tmp=''
        directory=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try: os.fsync(directory)
        finally: os.close(directory)
        result=snapshot(path)
        if result['data']!=data or any(result[k]!=expected[k] for k in ('uid','gid','mode','xattrs')): raise RuntimeError('installed_file_invalid')
        return result
    finally:
        if fd>=0: os.close(fd)
        if tmp:
            try: os.unlink(tmp)
            except FileNotFoundError: pass

def parse_response(raw):
    body,marker=raw.rsplit(b'\nDNS9_HTTP:',1)
    code=marker.strip()
    if not re.fullmatch(rb'[1-5][0-9][0-9]',code): raise ValueError('http_output_invalid')
    error='other'
    try:
        obj=json.loads(body)
        if isinstance(obj,dict) and obj.get('error') in ('collector_auth_failed','control_unavailable'): error=obj['error']
    except (ValueError,UnicodeDecodeError): pass
    return int(code),error

def fake_poll(b3):
    collector=secrets.token_hex(16)
    if b3.one("SELECT COUNT(*) FROM custom_dns_collectors WHERE id='"+collector+"';")!='0': raise RuntimeError('fake_collector_collision')
    fake='dnsctl1.'+collector+'.'+('A'*43)
    proc=subprocess.run(['curl','--noproxy','*','--resolve','ipam.realistbank.ru:443:127.0.0.1',
        '--silent','--show-error','--max-time','15','--max-filesize','16384',
        '-H','Authorization: Bearer '+fake,'-H','Content-Type: application/json',
        '--data-binary','{"action":"poll"}','--write-out','\nDNS9_HTTP:%{http_code}\n',
        'https://ipam.realistbank.ru/app/integrations/dns/machine-control.php'],
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=20,check=False)
    if proc.returncode!=0 or len(proc.stdout)>20000: raise RuntimeError('fake_http_failed')
    return parse_response(proc.stdout)

def cold_check():
    proc=subprocess.run(['php',str(ROOT/'tools/live/dns9-cold-db-check.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,timeout=15,check=False)
    if proc.returncode!=0: raise RuntimeError('cold_db_check_failed')
    lines=[x for x in proc.stdout.splitlines() if x.startswith('DNS9_COLD_DB_JSON=')]
    if len(lines)!=1: raise RuntimeError('cold_db_check_failed')
    obj=json.loads(lines[0].split('=',1)[1])
    if obj!={'supported':True,'before_connected':False,'began':False,'null_error':True,'rollback_ok':False}:
        raise RuntimeError('cold_db_hypothesis_not_confirmed')

def wait_for_fixed_response(b3):
    result=None
    for index in range(6):
        result=fake_poll(b3)
        if result==(401,'collector_auth_failed') or result!=(503,'control_unavailable'): return result
        if index<5: time.sleep(2)
    return result

def marker_doc(path):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or stat.S_IMODE(st.st_mode)!=0o600 or st.st_nlink!=1: raise RuntimeError('system_marker_invalid')
    data=snapshot(path)['data']
    doc=json.loads(data)
    if doc!={'source_sha':SYS_SHA,'nonce':SYS_NONCE,'job_id':JOB}: raise RuntimeError('system_marker_invalid')

def local_preflight(b3,post,require_no_marker=True):
    b3.validate_retry(GUARDS,RETRY_SHA)
    marker_doc(GUARDS/'dns9-system-probe-attempt-global')
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_uncertain')
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit() or int(proc.name)==os.getpid(): continue
        try: args=(proc/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError: continue
        except OSError as exc: raise RuntimeError('local_process_uncertain') from exc
        if any(Path(os.fsdecode(x)).name in {'dns9-b3-auto-once.py','dns9-system-probe.py','dns9-pdo-hotfix.py'} for x in args if x): raise RuntimeError('another_process_exists')
    if b3.job_state(JOB)!='pending|0||0|||0': raise RuntimeError('job_changed')
    if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_changed')
    ttl,active=b3.pilot_details(JOB)
    if ttl>=0 or active!=0: raise RuntimeError('queue_uncertain')
    if require_no_marker and ((GUARDS/MARKER).exists() or (GUARDS/MARKER).is_symlink()): raise RuntimeError('hotfix_marker_exists')

def write_private(path,data):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        view=memoryview(data)
        while view:
            n=os.write(fd,view)
            if n<=0: raise RuntimeError('backup_write_failed')
            view=view[n:]
        os.fsync(fd)
    finally: os.close(fd)

def reserve(sha,nonce):
    st=GUARDS.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o022: raise RuntimeError('guard_directory_invalid')
    data=(json.dumps({'source_sha':sha,'nonce':nonce,'job_id':JOB},sort_keys=True)+'\n').encode()
    write_private(GUARDS/MARKER,data)
    fd=os.open(GUARDS,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:os.fsync(fd)
    finally:os.close(fd)

def apply_changes(changes,verify):
    changed=[]; current_attempt=None
    try:
        for target,old,new in changes:
            current_attempt=(target,old,new)
            installed=atomic_replace(target,new,old)
            changed.append((target,old,installed)); current_attempt=None
            print('DNS9_PDO_FILE_UPDATED='+target.name,flush=True)
        verify()
    except Exception as exc:
        rollback_ok=True
        if current_attempt is not None:
            target,old,new=current_attempt
            try:
                current=snapshot(target)
                if current==old: pass
                elif current['data']==new and all(current[k]==old[k] for k in ('uid','gid','mode','xattrs')):
                    changed.append((target,old,current))
                else: rollback_ok=False
            except Exception:rollback_ok=False
        for target,old,installed in reversed(changed):
            try:atomic_replace(target,old['data'],installed)
            except Exception:rollback_ok=False
        print('DNS9_PDO_ROLLBACK_OK='+str(int(rollback_ok)),flush=True)
        raise RuntimeError('apply_failed_rolled_back' if rollback_ok else 'apply_failed_rollback_incomplete') from exc

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    sha=(ROOT/'EXPECTED_SHA').read_text().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha): raise RuntimeError('source_sha_invalid')
    b3=module('phase_b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    post=module('phase_b3_post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    local_preflight(b3,post)
    for src,dst in BOUNDARY.items():
        if hashlib.sha256(snapshot(ROOT/src)['data']).digest()!=hashlib.sha256(snapshot(dst)['data']).digest(): raise RuntimeError('boundary_hash_mismatch')
    changes=[]
    for src,old_sha in TARGETS.items():
        candidate=ROOT/src; target=WEB/'functions/classes'/candidate.name
        old=snapshot(target); new=snapshot(candidate)['data']
        if hashlib.sha256(old['data']).hexdigest()!=old_sha or hashlib.sha256(new).hexdigest()==old_sha: raise RuntimeError('target_hash_mismatch')
        if subprocess.run(['php','-l',str(candidate)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode!=0: raise RuntimeError('php_syntax_failed')
        changes.append((target,old,new))
    cold_check(); print('DNS9_PDO_COLD_DB_NULL_CONFIRMED=1',flush=True)
    before=fake_poll(b3)
    print('DNS9_PDO_FAKE_BEFORE='+str(before[0])+'|'+before[1],flush=True)
    if before!=(503,'control_unavailable'): raise RuntimeError('baseline_http_unexpected')
    local_preflight(b3,post)
    for target,old,_ in changes:
        if snapshot(target)!=old: raise RuntimeError('target_changed')
    bst=BACKUP.lstat()
    if not stat.S_ISDIR(bst.st_mode) or bst.st_uid!=0 or bst.st_gid!=0 or bst.st_mode&0o022: raise RuntimeError('backup_root_invalid')
    nonce=secrets.token_hex(8); reserve(sha,nonce)
    print('DNS9_PDO_MARKER_CREATED=1',flush=True)
    directory=BACKUP/('dns9-pdo-hotfix-'+nonce);directory.mkdir(mode=0o700)
    plan={'source_sha':sha,'job_id':JOB,'targets':[]}
    for target,old,new in changes:
        backup=directory/(target.name+'.original')
        write_private(backup,old['data'])
        if hashlib.sha256(snapshot(backup)['data']).digest()!=hashlib.sha256(old['data']).digest(): raise RuntimeError('backup_verify_failed')
        plan['targets'].append({'target':str(target),'backup':str(backup),'old_sha256':hashlib.sha256(old['data']).hexdigest(),
                                'new_sha256':hashlib.sha256(new).hexdigest(),'uid':old['uid'],'gid':old['gid'],'mode':old['mode']})
    write_private(directory/'plan.json',(json.dumps(plan,sort_keys=True,indent=2)+'\n').encode())
    for path in (directory,BACKUP):
        fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(fd)
        finally:os.close(fd)
    print('DNS9_PDO_BACKUP='+str(directory),flush=True)
    def verify():
        after=wait_for_fixed_response(b3)
        print('DNS9_PDO_FAKE_AFTER='+str(after[0])+'|'+after[1],flush=True)
        if after!=(401,'collector_auth_failed'): raise RuntimeError('post_http_unexpected')
        local_preflight(b3,post,require_no_marker=False)
    apply_changes(changes,verify)
    print('DNS9_PDO_REAL_TOKEN_USED=0')
    print('DNS9_PDO_REAL_JOB_POLL=0')
    print('DNS9_PDO_DB_MUTATION=0')
    print('DNS9_PDO_DNS_WRITE=0')
    print('DNS9_PDO_TASK_STARTED=0')
    print('DNS9_PDO_B3_GUARDS_RETAINED=1')
    print('DNS9_PDO_HOTFIX_OK=1')

if __name__=='__main__':
    try:main()
    except Exception as exc:
        allowed={'root_required','source_sha_invalid','target_boundary_invalid','target_too_large','target_changed','xattrs_unavailable',
                 'metadata_copy_failed','write_failed','installed_file_invalid','fake_collector_collision','fake_http_failed','http_output_invalid',
                 'cold_db_check_failed','cold_db_hypothesis_not_confirmed','system_marker_invalid','local_process_uncertain','another_process_exists',
                 'job_changed','timer_changed','queue_uncertain','hotfix_marker_exists','guard_directory_invalid','backup_root_invalid',
                 'boundary_hash_mismatch','target_hash_mismatch','php_syntax_failed','baseline_http_unexpected','backup_write_failed','backup_verify_failed',
                 'apply_failed_rolled_back','apply_failed_rollback_incomplete'}
        print('DNS9_PDO_ERROR='+(str(exc) if str(exc) in allowed else 'stopped_requires_review'),file=sys.stderr)
        raise SystemExit(1)
