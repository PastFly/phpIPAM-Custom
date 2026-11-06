#!/usr/bin/env python3
"""Replace only the disabled Windows collector runner, with an atomic backup."""
import base64
import copy
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
from dns9_winrm_common import protocol, run_ps, stage_bytes, remove_tree, json_line, ps_quote, ensure_ntlm_md4_runtime

ROOT=Path(__file__).resolve().parents[2]
BACKUP_ROOT=Path('/var/backups/phpipam-custom')
RECEIPT=BACKUP_ROOT/'dns9-winrm-bootstrap-20260930-122257'/'receipt.json'
GUARD_ROOT=Path('/var/lib/phpipam-custom')
SERVER='srv-dc01.rbank.local'
USERNAME='rbank\\kadaev.admin'
JOB='3bf6ee81197e2be7ff5452bc0b4b84c7'
RUNNER_KEY='collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1'
NAMES=('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')

def updated_receipt(original,data,nonce):
    if not re.fullmatch('[0-9a-f]{16}',nonce): raise ValueError('nonce_invalid')
    doc=copy.deepcopy(original)
    item=doc['package'][RUNNER_KEY]
    old=item['sha256']
    item.update(sha256=hashlib.sha256(data).hexdigest(),bytes=len(data))
    doc['upgrade']={'kind':'runner_tls12','nonce':nonce,'old_sha256':old,'timestamp':int(time.time())}
    return doc

def control_script(nonce,hashes,new_hash):
    if not re.fullmatch('[0-9a-f]{16}',nonce) or not re.fullmatch('[0-9a-f]{64}',new_hash): raise ValueError('input_invalid')
    if set(hashes)!=set(NAMES) or any(not re.fullmatch('[0-9a-f]{64}',v) for v in hashes.values()): raise ValueError('hashes_invalid')
    stage=rf'C:\Windows\Temp\phpipam-dns9-tls-upgrade-{nonce}\runner.ps1'
    backup=rf'C:\ProgramData\phpIPAM\DnsCollector\Invoke-PhpIpamDnsCollectorScheduled.ps1.pre-tls-{nonce}.bak'
    expected=json.dumps(hashes,sort_keys=True,separators=(',',':'))
    return f"""
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$Root='C:\\ProgramData\\phpIPAM\\DnsCollector'
$Runner=Join-Path $Root 'Invoke-PhpIpamDnsCollectorScheduled.ps1'
$Staged={ps_quote(stage)}
$Backup={ps_quote(backup)}
$Expected=ConvertFrom-Json -InputObject {ps_quote(expected)}
if([string](Get-ScheduledTask -TaskName 'phpIPAM Microsoft DNS Collector' -ErrorAction Stop).State -ne 'Disabled'){{throw 'task_not_disabled'}}
if(Get-ScheduledTask | Where-Object {{$_.TaskName -like 'phpIPAM Microsoft DNS Collector DNS9 Pilot Once *'}}){{throw 'pilot_task_present'}}
$ps=@(Get-CimInstance -ClassName Win32_Process -ErrorAction Stop | Where-Object {{$_.Name -match '^(powershell|pwsh)\\.exe$'}})
if(@($ps | Where-Object {{[string]::IsNullOrWhiteSpace($_.CommandLine)}}).Count -ne 0){{throw 'process_commandline_unknown'}}
if(@($ps | Where-Object {{$_.CommandLine -like '*Invoke-PhpIpamDnsCollectorScheduled.ps1*' -or $_.CommandLine -like '*phpipam-dns9-phase-b3-*'}}).Count -ne 0){{throw 'collector_process_present'}}
foreach($path in @($Root,$Runner,(Split-Path -Parent $Staged),$Staged)){{
    $item=Get-Item -LiteralPath $path -Force -ErrorAction Stop
    if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){{throw 'reparse_point'}}
}}
if(Test-Path -LiteralPath $Backup){{throw 'backup_exists'}}
foreach($name in @('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')){{
    $actual=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $Root $name)).Hash.ToLowerInvariant()
    if($actual -cne [string]$Expected.$name){{throw 'runtime_hash_mismatch'}}
}}
if((Get-FileHash -Algorithm SHA256 -LiteralPath $Staged).Hash.ToLowerInvariant() -cne {ps_quote(new_hash)}){{throw 'stage_hash_mismatch'}}
$acl=(Get-Acl -LiteralPath $Runner -ErrorAction Stop).Sddl
Write-Output 'DNS9_TLS_UPGRADE_REPLACE_START=1'
[IO.File]::Replace($Staged,$Runner,$Backup)
if((Get-FileHash -Algorithm SHA256 -LiteralPath $Backup).Hash.ToLowerInvariant() -cne [string]$Expected.'Invoke-PhpIpamDnsCollectorScheduled.ps1'){{throw 'backup_hash_mismatch'}}
if((Get-FileHash -Algorithm SHA256 -LiteralPath $Runner).Hash.ToLowerInvariant() -cne {ps_quote(new_hash)}){{throw 'new_hash_mismatch'}}
if((Get-Acl -LiteralPath $Runner -ErrorAction Stop).Sddl -cne $acl){{throw 'runner_acl_changed'}}
if([string](Get-ScheduledTask -TaskName 'phpIPAM Microsoft DNS Collector' -ErrorAction Stop).State -ne 'Disabled'){{throw 'task_state_changed'}}
[PSCustomObject]@{{installed=$true;backup_verified=$true;acl_unchanged=$true;task_disabled=$true;new_sha256={ps_quote(new_hash)};backup=$Backup}} | ConvertTo-Json -Compress | ForEach-Object {{Write-Output ('DNS9_TLS_UPGRADE_JSON='+$_)}}
"""

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    obj=importlib.util.module_from_spec(spec); spec.loader.exec_module(obj); return obj

def write_private(path,doc):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as fh:
        json.dump(doc,fh,sort_keys=True,indent=2); fh.write('\n'); fh.flush(); os.fsync(fh.fileno())

def preflight(b3,post):
    if post.guard_state(GUARD_ROOT)!={'count':1,'global_matches':True}: raise RuntimeError('guard_state_unexpected')
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_state_uncertain')
    if b3.job_state(JOB)!='pending|0||0|||0': raise RuntimeError('job_state_changed')
    if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_state_changed')
    b3.legacy_pilot_expired()

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    base=Path(__file__).resolve().parent
    b3=module('phase_b3',base/'dns9-phase-b3-first-cycle.py')
    post=module('post',base/'dns9-phase-b3-post-check.py')
    original=b3.receipt(RECEIPT,SERVER)
    hashes=b3.expected_runtime_hashes(original)
    data=(ROOT/RUNNER_KEY).read_bytes()
    new_hash=hashlib.sha256(data).hexdigest()
    if new_hash==hashes[NAMES[2]]: raise RuntimeError('runner_already_matches_receipt')
    preflight(b3,post)
    print('DNS9_TLS_UPGRADE_MD4_PROVIDER='+ensure_ntlm_md4_runtime(),flush=True)
    password=getpass.getpass('WinRM upgrade password: ')
    if not password: raise RuntimeError('password_empty')
    preflight(b3,post)
    p=protocol(SERVER,USERNAME,password); password=''
    nonce=secrets.token_hex(8)
    st=BACKUP_ROOT.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o022: raise RuntimeError('backup_root_invalid')
    receipt_dir=BACKUP_ROOT/('dns9-runner-tls-upgrade-'+nonce)
    receipt_dir.mkdir(mode=0o700)
    prospective=updated_receipt(original,data,nonce)
    plan={'stage':'prepared_before_remote_change','nonce':nonce,'old_sha256':hashes[NAMES[2]],'new_sha256':new_hash,'old_receipt':str(RECEIPT),'guard_retained':True}
    write_private(receipt_dir/'plan.json',plan)
    print('DNS9_TLS_UPGRADE_PLAN='+str(receipt_dir/'plan.json'),flush=True)
    stage=rf'C:\Windows\Temp\phpipam-dns9-tls-upgrade-{nonce}'
    staged=stage+r'\runner.ps1'
    stage_bytes(p,staged,data)
    preflight(b3,post)
    script=control_script(nonce,hashes,new_hash)
    packed=base64.b64encode(gzip.compress(script.encode(),mtime=0)).decode('ascii')
    loader=("$b=[Convert]::FromBase64String('"+packed+"');$m=New-Object IO.MemoryStream(,$b);"
            "$g=New-Object IO.Compression.GzipStream($m,[IO.Compression.CompressionMode]::Decompress);"
            "$r=New-Object IO.StreamReader($g);& ([scriptblock]::Create($r.ReadToEnd()))")
    if len(base64.b64encode(loader.encode('utf-16le')))>7500: raise RuntimeError('encoded_command_limit')
    rc,out,_=run_ps(p,loader)
    if 'DNS9_TLS_UPGRADE_REPLACE_START=1' in out: print('DNS9_TLS_UPGRADE_REPLACE_START=1',flush=True)
    if rc!=0: raise RuntimeError('remote_apply_outcome_requires_review')
    result=json_line(out,'DNS9_TLS_UPGRADE_JSON=')
    expected_backup=rf'C:\ProgramData\phpIPAM\DnsCollector\Invoke-PhpIpamDnsCollectorScheduled.ps1.pre-tls-{nonce}.bak'
    if not isinstance(result,dict) or set(result)!={'installed','backup_verified','acl_unchanged','task_disabled','new_sha256','backup'} or any(result[k] is not True for k in ('installed','backup_verified','acl_unchanged','task_disabled')) or result['new_sha256']!=new_hash or result['backup']!=expected_backup: raise RuntimeError('remote_result_requires_review')
    prospective['upgrade']['windows_backup']=expected_backup
    target=receipt_dir/'receipt.json'
    write_private(target,prospective)
    print('DNS9_TLS_UPGRADE_RECEIPT='+str(target),flush=True)
    print('DNS9_TLS_UPGRADE_WINDOWS_BACKUP='+expected_backup,flush=True)
    print('DNS9_TLS_UPGRADE_NEW_SHA256='+new_hash,flush=True)
    try:
        remove_tree(p,stage)
        print('DNS9_TLS_UPGRADE_STAGE_CLEANED=1')
    except Exception:
        print('DNS9_TLS_UPGRADE_STAGE_CLEANED=0')
        raise RuntimeError('stage_cleanup_requires_review')
    preflight(b3,post)
    print('DNS9_TLS_UPGRADE_GUARD_RETAINED=1')
    print('DNS9_TLS_UPGRADE_TASK_STARTED=0')
    print('DNS9_TLS_UPGRADE_DB_MUTATION=0')
    print('DNS9_TLS_UPGRADE_DNS_WRITE=0')
    print('DNS9_TLS_UPGRADE_OK=1')

if __name__=='__main__':
    try: main()
    except Exception as exc:
        safe=str(exc) if str(exc) in ('root_required','guard_state_unexpected','local_process_state_uncertain','job_state_changed','timer_state_changed','password_empty','backup_root_invalid','runner_already_matches_receipt','encoded_command_limit','remote_apply_outcome_requires_review','remote_result_requires_review','stage_cleanup_requires_review') else 'preflight_or_transport_requires_review'
        print('DNS9_TLS_UPGRADE_ERROR='+safe,file=sys.stderr)
        raise SystemExit(1)
