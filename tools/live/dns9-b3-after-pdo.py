#!/usr/bin/env python3
"""One supervised SYSTEM access test after the verified DNS PDO repair."""
import base64
import getpass
import gzip
import hashlib
import importlib.util
import json
import os
import re
import secrets
import stat
import sys
import time
from pathlib import Path
from dns9_winrm_common import ensure_ntlm_md4_runtime,protocol,run_ps,json_line,ps_quote

ROOT=Path(__file__).resolve().parents[2]
GUARDS=Path('/var/lib/phpipam-custom')
BACKUP=Path('/var/backups/phpipam-custom')
PDO_BACKUP=BACKUP/'dns9-pdo-hotfix-80697634d6b24321'
PDO_SHA='bacb28acddea0ad098d395439451c4c96f611298'
PDO_NONCE='80697634d6b24321'
PREVIOUS_JOB='1c7ed02c3f6ca1d2a6a525e56481d243'
EARLIER_JOB='3bf6ee81197e2be7ff5452bc0b4b84c7'
RECEIPT=BACKUP/'dns9-runner-tls-upgrade-a8d0fe00e1034084'/'receipt.json'
USER='rbank\\kadaev.admin'

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    obj=importlib.util.module_from_spec(spec);spec.loader.exec_module(obj);return obj

def check_queue_safety(b3):
    result=b3.one(f"""
SELECT COUNT(*) FROM custom_dns_control_jobs
WHERE (state IN ('succeeded','failed','expired','obsolete') AND COALESCE(finished_at,updated_at)<=UNIX_TIMESTAMP()+300-86400)
 OR (state='pending' AND expires_at<UNIX_TIMESTAMP()+300 AND
     (collector_id<>'{b3.PILOT_COLLECTOR_ID}' OR connection_id<>'{b3.PILOT_CONNECTION_ID}' OR job_type<>'test_connection'))
 OR (state='running' AND (lease_expires_at IS NULL OR lease_expires_at<UNIX_TIMESTAMP()+300));
""")
    if result!='0':raise RuntimeError('unrelated_queue_or_pruning_risk')

def protected_json(pdo,path):
    snap=pdo.snapshot(path)
    if snap['uid']!=0 or snap['gid']!=0 or snap['mode']!=0o600:raise RuntimeError('hotfix_receipt_invalid')
    value=json.loads(snap['data'])
    if not isinstance(value,dict):raise RuntimeError('hotfix_receipt_invalid')
    return value

def verify_hotfix(pdo):
    marker=protected_json(pdo,GUARDS/'dns9-pdo-hotfix-attempt-global')
    if marker!={'source_sha':PDO_SHA,'nonce':PDO_NONCE,'job_id':PREVIOUS_JOB}:raise RuntimeError('hotfix_marker_invalid')
    directory=PDO_BACKUP.lstat()
    if not stat.S_ISDIR(directory.st_mode) or directory.st_uid!=0 or directory.st_gid!=0 or directory.st_mode&0o077:raise RuntimeError('hotfix_backup_invalid')
    plan=protected_json(pdo,PDO_BACKUP/'plan.json')
    if set(plan)!={'source_sha','job_id','targets'} or plan['source_sha']!=PDO_SHA or plan['job_id']!=PREVIOUS_JOB or not isinstance(plan['targets'],list) or len(plan['targets'])!=len(pdo.TARGETS):raise RuntimeError('hotfix_receipt_invalid')
    by_target={x.get('target'):x for x in plan['targets'] if isinstance(x,dict)}
    if len(by_target)!=len(pdo.TARGETS):raise RuntimeError('hotfix_receipt_invalid')
    for src,old_sha in pdo.TARGETS.items():
        candidate=ROOT/src;target=pdo.WEB/'functions/classes'/candidate.name
        old=PDO_BACKUP/(candidate.name+'.original')
        row=by_target.get(str(target))
        if not isinstance(row,dict) or set(row)!={'target','backup','old_sha256','new_sha256','uid','gid','mode'} or row['backup']!=str(old) or row['old_sha256']!=old_sha:raise RuntimeError('hotfix_receipt_invalid')
        current=pdo.snapshot(target);source=pdo.snapshot(candidate);backup=pdo.snapshot(old)
        if (backup['uid'],backup['gid'],backup['mode'])!=(0,0,0o600) or hashlib.sha256(backup['data']).hexdigest()!=old_sha:raise RuntimeError('hotfix_backup_invalid')
        new_sha=hashlib.sha256(source['data']).hexdigest()
        if row['new_sha256']!=new_sha or hashlib.sha256(current['data']).hexdigest()!=new_sha or any(current[k]!=row[k] for k in ('uid','gid','mode')):raise RuntimeError('hotfix_installed_state_changed')

def check_actor_audit(b3,actor):
    if type(actor) is not int or actor<1:raise RuntimeError('actor_invalid')
    count=b3.one(f"""
SELECT COUNT(*) FROM custom_dns_control_jobs j JOIN custom_dns_control_events e ON e.object_id=j.id
WHERE j.id='{PREVIOUS_JOB}' AND j.updated_by={actor} AND e.actor_id={actor} AND e.action='control_job_cli_queued';
""")
    if count!='1':raise RuntimeError('previous_cli_actor_unverified')

def local_preflight(b3,post,pdo,auto):
    pdo.local_preflight(b3,post,require_no_marker=False)
    auto.check_installed_classes()
    verify_hotfix(pdo)
    for src,dst in pdo.BOUNDARY.items():
        if hashlib.sha256(pdo.snapshot(ROOT/src)['data']).digest()!=hashlib.sha256(pdo.snapshot(dst)['data']).digest():raise RuntimeError('boundary_hash_mismatch')
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit() or int(proc.name)==os.getpid():continue
        try:args=(proc/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError:continue
        except OSError as exc:raise RuntimeError('local_process_uncertain') from exc
        if any(Path(os.fsdecode(x)).name in {'dns9-b3-after-pdo.py','dns9-b3-auto-once.py','dns9-system-probe.py','dns9-pdo-hotfix.py'} for x in args if x):raise RuntimeError('another_process_exists')
    check_queue_safety(b3)
    if pdo.fake_poll(b3)!=(401,'collector_auth_failed'):raise RuntimeError('pdo_fake_auth_boundary_failed')

def remote_preflight(b3,password):
    receipt=b3.receipt(RECEIPT,b3.SERVER)
    hashes=b3.expected_runtime_hashes(receipt)
    expected=json.dumps(hashes,sort_keys=True,separators=(',',':'))
    script=f"""
$ErrorActionPreference='Stop'
$expected=ConvertFrom-Json -InputObject {ps_quote(expected)}
$root='C:\\ProgramData\\phpIPAM\\DnsCollector'
$task=Get-ScheduledTask -TaskName 'phpIPAM Microsoft DNS Collector' -ErrorAction Stop
$tasks=@(Get-ScheduledTask | Where-Object {{$_.TaskName -like 'phpIPAM Microsoft DNS Collector DNS9 Pilot Once *' -or $_.TaskName -like 'phpIPAM Microsoft DNS Collector DNS9 SYSTEM Probe *'}})
$ps=@(Get-CimInstance -ClassName Win32_Process -ErrorAction Stop | Where-Object {{$_.Name -match '^(powershell|pwsh)\\.exe$'}})
$unknown=@($ps | Where-Object {{[string]::IsNullOrWhiteSpace($_.CommandLine)}})
$active=@($ps | Where-Object {{$_.CommandLine -like '*Invoke-PhpIpamDnsCollectorScheduled.ps1*' -or $_.CommandLine -like '*phpipam-dns9-phase-b3-*' -or $_.CommandLine -like '*phpipam-dns9-system-probe-*'}})
$filesMatch=$true
foreach($name in @('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')){{
 $hash=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $root $name)).Hash.ToLowerInvariant()
 if($hash -cne [string]$expected.$name){{$filesMatch=$false}}
}}
$statusPath=Join-Path $root 'status.json'
$item=Get-Item -LiteralPath $statusPath -ErrorAction Stop
$status=Get-Content -LiteralPath $statusPath -Raw | ConvertFrom-Json -ErrorAction Stop
$consistent=([Math]::Abs(([DateTimeOffset]$item.LastWriteTimeUtc).ToUnixTimeSeconds()-[int64]$status.updated_at) -le 5)
[PSCustomObject]@{{permanent_disabled=([string]$task.State -eq 'Disabled');other_tasks=$tasks.Count;unknown_processes=$unknown.Count;active_processes=$active.Count;files_match=$filesMatch;previous_status=([string]$status.state -eq 'error' -and [string]$status.result_code -eq 'dns9_runtime_error' -and [int64]$status.updated_at -eq 1793955444 -and $consistent)}} | ConvertTo-Json -Compress | ForEach-Object {{Write-Output ('DNS9_AFTER_PDO_REMOTE_JSON='+$_)}}
"""
    packed=base64.b64encode(gzip.compress(script.encode(),mtime=0)).decode()
    loader=("$b=[Convert]::FromBase64String('"+packed+"');$m=New-Object IO.MemoryStream(,$b);"
            "$g=New-Object IO.Compression.GzipStream($m,[IO.Compression.CompressionMode]::Decompress);"
            "$r=New-Object IO.StreamReader($g);& ([scriptblock]::Create($r.ReadToEnd()))")
    if len(base64.b64encode(loader.encode('utf-16le')))>7500:raise RuntimeError('remote_preflight_too_long')
    p=protocol(b3.SERVER,USER,password)
    rc,out,_=run_ps(p,loader)
    if rc!=0:raise RuntimeError('remote_preflight_failed')
    doc=json_line(out,'DNS9_AFTER_PDO_REMOTE_JSON=')
    expected_doc={'permanent_disabled':True,'other_tasks':0,'unknown_processes':0,'active_processes':0,'files_match':True,'previous_status':True}
    if doc!=expected_doc or any(type(doc[k]) is not int for k in ('other_tasks','unknown_processes','active_processes')):raise RuntimeError('windows_state_changed')

def private_plan(b3,sha,actor):
    st=BACKUP.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o022:raise RuntimeError('backup_root_invalid')
    directory=BACKUP/('dns9-b3-after-pdo-'+secrets.token_hex(8));directory.mkdir(mode=0o700)
    rows=b3.sql(f"SELECT * FROM custom_dns_control_jobs WHERE id IN ('{EARLIER_JOB}','{PREVIOUS_JOB}') ORDER BY id;")
    lines=rows.splitlines()
    if len(lines)!=2 or {line.split('\t',1)[0] for line in lines}!={EARLIER_JOB,PREVIOUS_JOB}:raise RuntimeError('previous_job_backup_invalid')
    data=rows.encode()
    plan={'stage':'prepared_before_followup_reservation','source_sha':sha,'previous_job':PREVIOUS_JOB,
          'earlier_job':EARLIER_JOB,'previous_jobs_sha256':hashlib.sha256(data).hexdigest(),
          'actor_id':actor,'pdo_backup':str(PDO_BACKUP),'created_at':int(time.time())}
    for path,content in ((directory/'previous-jobs.tsv',data),(directory/'plan.json',(json.dumps(plan,sort_keys=True,indent=2)+'\n').encode())):
        fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        try:
            view=memoryview(content)
            while view:
                written=os.write(fd,view)
                if written<=0:raise RuntimeError('backup_write_failed')
                view=view[written:]
            os.fsync(fd)
        finally:os.close(fd)
    for path in (directory,BACKUP):
        fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(fd)
        finally:os.close(fd)
    return directory

def main():
    if os.geteuid()!=0:raise RuntimeError('root_required')
    sha=(ROOT/'EXPECTED_SHA').read_text().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha):raise RuntimeError('source_sha_invalid')
    b3=module('phase_b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    post=module('post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    pdo=module('pdo',ROOT/'tools/live/dns9-pdo-hotfix.py')
    auto=module('auto',ROOT/'tools/live/dns9-b3-auto-once.py')
    local_preflight(b3,post,pdo,auto)
    actor=auto.run_cli('--preflight')['actor_id'];check_actor_audit(b3,actor)
    print('DNS9_AFTER_PDO_ACTOR_VERIFIED=1',flush=True)
    print('DNS9_AFTER_PDO_MD4_PROVIDER='+ensure_ntlm_md4_runtime(),flush=True)
    password=getpass.getpass('WinRM password: ')
    if not password:raise RuntimeError('password_empty')
    remote_preflight(b3,password)
    print('DNS9_AFTER_PDO_WINDOWS_PREFLIGHT=PASS',flush=True)
    local_preflight(b3,post,pdo,auto)
    if auto.run_cli('--preflight')['actor_id']!=actor:raise RuntimeError('actor_changed')
    check_actor_audit(b3,actor)
    directory=private_plan(b3,sha,actor)
    print('DNS9_AFTER_PDO_PLAN='+str(directory/'plan.json'),flush=True)
    print('DNS9_AFTER_PDO_PRIOR_JOBS_BACKUP='+str(directory/'previous-jobs.tsv'),flush=True)
    b3.reserve_followup(GUARDS,sha)
    print('DNS9_AFTER_PDO_FOLLOWUP_GUARD_CREATED=1',flush=True)
    job=auto.run_cli('--queue')
    print('DNS9_AFTER_PDO_NEW_JOB_ID='+job['job_id'],flush=True)
    if job['actor_id']!=actor:raise RuntimeError('actor_changed_after_queue')
    old_args=sys.argv
    try:
        sys.argv=['dns9-phase-b3-first-cycle.py','--bootstrap-receipt',str(RECEIPT),'--username',USER,'--attempt-sha',sha,'--apply']
        b3.main(password_override=password,job_override=job['job_id'],reserved_followup=True)
    finally:password='';sys.argv=old_args
    b3.validate_followup(GUARDS,sha)
    preserved=b3.one(f"SELECT COUNT(*) FROM custom_dns_control_jobs WHERE id IN ('{b3.PILOT_JOB_ID}','{EARLIER_JOB}','{PREVIOUS_JOB}');")
    if preserved!='3':raise RuntimeError('previous_jobs_missing')
    print('DNS9_AFTER_PDO_PREVIOUS_JOBS_PRESERVED=1')
    print('DNS9_AFTER_PDO_ALL_GUARDS_RETAINED=1')
    print('DNS9_AFTER_PDO_OK=1')

if __name__=='__main__':
    try:main()
    except Exception as exc:
        allowed={'root_required','source_sha_invalid','unrelated_queue_or_pruning_risk','hotfix_receipt_invalid','hotfix_marker_invalid',
                 'hotfix_backup_invalid','hotfix_installed_state_changed','actor_invalid','previous_cli_actor_unverified','boundary_hash_mismatch',
                 'local_process_uncertain','another_process_exists','pdo_fake_auth_boundary_failed','remote_preflight_too_long','remote_preflight_failed',
                 'windows_state_changed','backup_root_invalid','previous_job_backup_invalid','backup_write_failed','password_empty','actor_changed',
                 'actor_changed_after_queue','followup_guard_directory_invalid','followup_guard_reservation_failed','followup_guard_set_invalid','previous_jobs_missing',
                 'system_marker_invalid','job_changed','timer_changed','queue_uncertain','target_boundary_invalid','retry_guard_invalid','retry_guard_set_invalid',
                 'cli_output_invalid','cli_preflight_or_queue_failed','fake_http_failed','fake_collector_collision'}
        print('DNS9_AFTER_PDO_ERROR='+(str(exc) if str(exc) in allowed else 'stopped_requires_review'),file=sys.stderr)
        raise SystemExit(1)
