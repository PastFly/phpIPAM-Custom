#!/usr/bin/env python3
"""Once-only isolated SYSTEM probe, without a live collector or job poll."""
import contextlib
import getpass
import hashlib
import hmac
import importlib.util
import io
import json
import os
import re
import secrets
import stat
import sys
from pathlib import Path
from dns9_winrm_common import ensure_ntlm_md4_runtime,protocol,run,run_ps,stage_bytes,remove_tree,json_line,ps_quote

ROOT=Path(__file__).resolve().parents[2]
WEB=Path('/var/www/html')
BACKUP=Path('/var/backups/phpipam-custom')
GUARDS=Path('/var/lib/phpipam-custom')
RECEIPT=BACKUP/'dns9-runner-tls-upgrade-a8d0fe00e1034084'/'receipt.json'
TOKEN=Path('/root/phpipam-dns-e2e-pilot-token')
OLD_JOB='1c7ed02c3f6ca1d2a6a525e56481d243'
RETRY_SHA='bbd3ef04137a7f2268167b0850788ea4a46d1b28'
RUNNER_SHA='0828bd77ed5cf0337c4fec71607614782a69853299287966a4ce8793389c4b13'
USER='rbank\\kadaev.admin'
MARKER='dns9-system-probe-attempt-global'
PHASES={'identity','config','token_read','dpapi','token_format','token_compare','secure_string','proxy','tls12','http','complete'}
ERRORS={'none','other','access_denied','cryptographic','io','command_missing'}
WEB_STATUSES={'none','other','unexpected_success','ProtocolError','SecureChannelFailure','TrustFailure','ConnectFailure','NameResolutionFailure','Timeout','ConnectionClosed','ReceiveFailure','SendFailure'}
TOKEN_PATTERN=re.compile(r'dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}\Z')

def control_digest(token): return hashlib.sha256(('phpipam-custom|dns-control|v1|'+token).encode()).hexdigest()
def reference_hmac(token,stored,nonce):
    if not TOKEN_PATTERN.fullmatch(token) or not re.fullmatch('[0-9a-f]{64}',stored) or len(nonce)!=32 or not hmac.compare_digest(control_digest(token),stored):
        raise ValueError('reference_invalid')
    return hmac.new(nonce,token.encode(),hashlib.sha256).hexdigest()

def validate_result(doc):
    if not isinstance(doc,dict) or set(doc)!={'phase','error','token_valid','token_reference_match','http_status','http_error_marker','web_status'}: raise ValueError('result_invalid')
    if doc['phase'] not in PHASES or doc['error'] not in ERRORS or doc['web_status'] not in WEB_STATUSES: raise ValueError('result_invalid')
    if type(doc['token_valid']) is not bool or type(doc['http_error_marker']) is not bool: raise ValueError('result_invalid')
    if doc['token_reference_match'] is not None and type(doc['token_reference_match']) is not bool: raise ValueError('result_invalid')
    if doc['http_status'] is not None and (type(doc['http_status']) is not int or not 100<=doc['http_status']<=599): raise ValueError('result_invalid')
    return doc

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    obj=importlib.util.module_from_spec(spec); spec.loader.exec_module(obj); return obj

def checked_file(path):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or path.is_symlink(): raise RuntimeError('file_invalid')
    return path.read_bytes()

def check_endpoint_source():
    files={'layers/dns5/functions/classes/class.IntegrationDnsCollectorMachineHttpController.php':WEB/'functions/classes/class.IntegrationDnsCollectorMachineHttpController.php',
           'layers/dns5/app/integrations/dns/machine-control.php':WEB/'app/integrations/dns/machine-control.php'}
    for src,dst in files.items():
        if hashlib.sha256(checked_file(ROOT/src)).digest()!=hashlib.sha256(checked_file(dst)).digest(): raise RuntimeError('endpoint_hash_mismatch')

def check_other_probe():
    for proc in Path('/proc').iterdir():
        if not proc.name.isdigit() or int(proc.name)==os.getpid(): continue
        try: args=(proc/'cmdline').read_bytes().split(b'\0')
        except FileNotFoundError: continue
        except OSError as exc: raise RuntimeError('local_process_uncertain') from exc
        if any(Path(os.fsdecode(x)).name=='dns9-system-probe.py' for x in args if x): raise RuntimeError('probe_process_exists')

def local_preflight(b3,post):
    check_endpoint_source(); check_other_probe()
    b3.validate_retry(GUARDS,RETRY_SHA)
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_uncertain')
    if b3.job_state(OLD_JOB)!='pending|0||0|||0': raise RuntimeError('job_changed')
    if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_changed')
    ttl,active=b3.pilot_details(OLD_JOB)
    if ttl>=0 or active!=0: raise RuntimeError('queue_uncertain')
    receipt=b3.receipt(RECEIPT,b3.SERVER)
    if b3.expected_runtime_hashes(receipt)['Invoke-PhpIpamDnsCollectorScheduled.ps1']!=RUNNER_SHA: raise RuntimeError('runner_receipt_invalid')
    if (GUARDS/MARKER).exists() or (GUARDS/MARKER).is_symlink(): raise RuntimeError('probe_marker_exists')

def windows_preflight(diag,password):
    old=diag.getpass.getpass; old_args=sys.argv; output=io.StringIO()
    try:
        diag.getpass.getpass=lambda prompt:password
        sys.argv=['dns9-phase-b3-diagnose.py','--username',USER,'--suffix','4aafbf3f8edc']
        with contextlib.redirect_stdout(output): diag.main()
    finally:
        diag.getpass.getpass=old; sys.argv=old_args
    lines=[s for s in output.getvalue().splitlines() if s.startswith('DNS9_PHASE_B3_DIAG_JSON=')]
    if len(lines)!=1: raise RuntimeError('windows_preflight_invalid')
    doc=json.loads(lines[0].split('=',1)[1])
    if doc.get('permanent_task_state')!='Disabled' or any(type(doc.get(k)) is not int or doc[k]!=0 for k in ('pilot_task_count','collector_process_count','powershell_unknown_commandline_count')): raise RuntimeError('windows_state_uncertain')
    status=doc.get('status')
    if not isinstance(status,dict) or status.get('state')!='error' or status.get('result_code')!='dns9_runtime_error' or status.get('updated_at')!=1793955444 or doc.get('status_epoch_consistent') is not True: raise RuntimeError('previous_status_changed')

def make_reference(b3):
    try:
        st=TOKEN.lstat()
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or stat.S_IMODE(st.st_mode)!=0o600 or st.st_nlink!=1: raise RuntimeError('token_file_invalid')
        token=TOKEN.read_text().strip()
        digest=b3.one("SELECT token_digest FROM custom_dns_collectors WHERE id='f57057d6677e1ca7a032aab8b68796ac';")
        if TOKEN_PATTERN.fullmatch(token) and re.fullmatch('[0-9a-f]{64}',digest) and not hmac.compare_digest(control_digest(token),digest):
            token=''; digest=''
            return {'enabled':False,'nonce':'','digest':''},'db_mismatch'
        nonce=secrets.token_bytes(32)
        expected=reference_hmac(token,digest,nonce)
        token=''; digest=''
        return {'enabled':True,'nonce':nonce.hex(),'digest':expected},'verified'
    except Exception:
        return {'enabled':False,'nonce':'','digest':''},'unavailable'

def reserve(sha,nonce):
    st=GUARDS.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o022: raise RuntimeError('guard_directory_invalid')
    data=json.dumps({'source_sha':sha,'nonce':nonce,'job_id':OLD_JOB},sort_keys=True).encode()+b'\n'
    fd=os.open(GUARDS/MARKER,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        if os.write(fd,data)!=len(data): raise RuntimeError('probe_marker_write_failed')
        os.fsync(fd)
    finally: os.close(fd)
    fd=os.open(GUARDS,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    sha=(ROOT/'EXPECTED_SHA').read_text().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha): raise RuntimeError('source_sha_invalid')
    b3=module('phase_b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    post=module('phase_b3_post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    diag=module('phase_b3_diag',ROOT/'tools/live/dns9-phase-b3-diagnose.py')
    local_preflight(b3,post)
    print('DNS9_SYS_MD4_PROVIDER='+ensure_ntlm_md4_runtime(),flush=True)
    password=getpass.getpass('WinRM password: ')
    if not password: raise RuntimeError('password_empty')
    windows_preflight(diag,password)
    print('DNS9_SYS_WINDOWS_PREFLIGHT=PASS',flush=True)
    local_preflight(b3,post)
    reference,ref_state=make_reference(b3)
    print('DNS9_SYS_REFERENCE='+ref_state,flush=True)
    p=protocol(b3.SERVER,USER,password); password=''
    nonce=secrets.token_hex(8)
    stage=rf'C:\Windows\Temp\phpipam-dns9-system-probe-{nonce}'
    task='phpIPAM Microsoft DNS Collector DNS9 SYSTEM Probe '+nonce
    reserve(sha,nonce)
    print('DNS9_SYS_MARKER_CREATED=1',flush=True)
    print('DNS9_SYS_STAGE='+stage,flush=True)
    prepare=f"""
$ErrorActionPreference='Stop'
$path={ps_quote(stage)}
if(Test-Path -LiteralPath $path){{throw 'stage_exists'}}
if([string](Get-ScheduledTask -TaskName 'phpIPAM Microsoft DNS Collector' -ErrorAction Stop).State -ne 'Disabled'){{throw 'task_changed'}}
$runner='C:\\ProgramData\\phpIPAM\\DnsCollector\\Invoke-PhpIpamDnsCollectorScheduled.ps1'
if((Get-FileHash -LiteralPath $runner -Algorithm SHA256).Hash.ToLowerInvariant() -cne {ps_quote(RUNNER_SHA)}){{throw 'runner_hash_mismatch'}}
New-Item -ItemType Directory -Path $path -ErrorAction Stop | Out-Null
& icacls.exe $path /inheritance:r | Out-Null
if($LASTEXITCODE -ne 0){{throw 'acl_failed'}}
& icacls.exe $path /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if($LASTEXITCODE -ne 0){{throw 'acl_failed'}}
Write-Output 'DNS9_SYS_STAGE_READY=1'
"""
    rc,out,_=run_ps(p,prepare)
    if rc!=0 or 'DNS9_SYS_STAGE_READY=1' not in out: raise RuntimeError('stage_prepare_failed')
    probe=checked_file(ROOT/'tools/live/dns9-system-probe.ps1')
    control=checked_file(ROOT/'tools/live/dns9-system-probe-control.ps1')
    ref=(json.dumps(reference,sort_keys=True,separators=(',',':'))+'\n').encode()
    stage_bytes(p,stage+r'\probe.ps1',probe)
    stage_bytes(p,stage+r'\control.ps1',control)
    stage_bytes(p,stage+r'\reference.json',ref)
    b3.validate_retry(GUARDS,RETRY_SHA)
    if b3.job_state(OLD_JOB)!='pending|0||0|||0' or b3.timer_enabled() or b3.timer_active(): raise RuntimeError('preflight_changed')
    rc,out,_=run(p,'powershell.exe',['-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',stage+r'\control.ps1',
        '-StageDir',stage,'-TaskName',task,'-ProbeHash',hashlib.sha256(probe).hexdigest(),'-ReferenceHash',hashlib.sha256(ref).hexdigest()])
    for marker in ('DNS9_SYS_TASK_STARTED=1','DNS9_SYS_TASK_CLEANED=1','DNS9_SYS_TASK_CLEANED=0','DNS9_SYS_TASK_WAS_STARTED=1','DNS9_SYS_TASK_WAS_STARTED=0'):
        if marker in out: print(marker,flush=True)
    if rc!=0 or 'DNS9_SYS_TASK_STARTED=1' not in out or 'DNS9_SYS_TASK_CLEANED=1' not in out: raise RuntimeError('remote_control_requires_review')
    doc=validate_result(json_line(out,'DNS9_SYS_RESULT_JSON='))
    verify=f"""
$ErrorActionPreference='Stop'
if(Get-ScheduledTask -TaskName {ps_quote(task)} -ErrorAction SilentlyContinue){{throw 'task_remains'}}
$ps=@(Get-CimInstance -ClassName Win32_Process -ErrorAction Stop | Where-Object {{$_.Name -match '^(powershell|pwsh)\\.exe$'}})
if(@($ps | Where-Object {{[string]::IsNullOrWhiteSpace($_.CommandLine) -or $_.CommandLine -like ('*'+{ps_quote(stage)}+'*')}}).Count -ne 0){{throw 'process_uncertain'}}
Write-Output 'DNS9_SYS_CLEANUP_SAFE=1'
"""
    rc,out,_=run_ps(p,verify)
    if rc!=0 or 'DNS9_SYS_CLEANUP_SAFE=1' not in out: raise RuntimeError('cleanup_state_uncertain')
    remove_tree(p,stage)
    print('DNS9_SYS_STAGE_CLEANED=1')
    b3.validate_retry(GUARDS,RETRY_SHA)
    if b3.job_state(OLD_JOB)!='pending|0||0|||0' or b3.timer_enabled() or b3.timer_active(): raise RuntimeError('poststate_changed')
    print('DNS9_SYS_RESULT_JSON='+json.dumps(doc,sort_keys=True,separators=(',',':')))
    print('DNS9_SYS_REAL_TOKEN_SENT_HTTP=0')
    print('DNS9_SYS_JOB_POLL=0')
    print('DNS9_SYS_DB_MUTATION=0')
    print('DNS9_SYS_DNS_WRITE=0')
    print('DNS9_SYS_B3_GUARDS_RETAINED=1')
    print('DNS9_SYS_PROBE_OK=1')

if __name__=='__main__':
    try: main()
    except Exception as exc:
        safe={'root_required','source_sha_invalid','file_invalid','endpoint_hash_mismatch','local_process_uncertain','probe_process_exists','job_changed','timer_changed','queue_uncertain','runner_receipt_invalid','probe_marker_exists','windows_preflight_invalid','windows_state_uncertain','previous_status_changed','guard_directory_invalid','probe_marker_write_failed','password_empty','stage_prepare_failed','preflight_changed','remote_control_requires_review','cleanup_state_uncertain','poststate_changed','result_invalid'}
        print('DNS9_SYS_ERROR='+(str(exc) if str(exc) in safe else 'stopped_requires_review'),file=sys.stderr)
        raise SystemExit(1)
