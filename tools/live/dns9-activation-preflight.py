#!/usr/bin/env python3
"""Read-only readiness check for the separately approved automation gate."""
import base64
import getpass
import gzip
import hashlib
import importlib.util
import json
import os
import subprocess
import sys
import time
from pathlib import Path
from dns9_winrm_common import ensure_ntlm_md4_runtime,json_line,protocol,ps_quote,run_ps

ROOT=Path(__file__).resolve().parents[2]
WEB=Path('/var/www/html')
GUARDS=Path('/var/lib/phpipam-custom')
FIX_SHA='97377897fe48a9e01a8bb844c4c19e37117cc3db'
FIX_BACKUP=Path('/var/backups/phpipam-custom/dns9-scheduler-fix-2612d4b1595b6cdb')
SERVER='srv-dc01.rbank.local'
USER='rbank\\kadaev.admin'
EXPIRED_REVIEWED_JOBS=(
    ('0f5fbf81fc71164cc7c624fc6101925f','list_zones',1793966475),
    ('edfcd75935083e51c4536a390174057e','test_connection',1793966491),
)

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    obj=importlib.util.module_from_spec(spec); spec.loader.exec_module(obj); return obj

def validate_queue(row):
    if row!='2|0|0|0|0|2': raise RuntimeError('queue_or_retention_requires_review')

def queue_summary(b3):
    if (b3.PILOT_CONNECTION_ID,b3.PILOT_COLLECTOR_ID)!=('33b6fade9323f15aa7b1f56a8a865a9d','f57057d6677e1ca7a032aab8b68796ac'):
        raise RuntimeError('queue_policy_invalid')
    return b3.one((ROOT/'tools/live/dns9-activation-queue.sql').read_text())

def validate_remote(doc,now):
    required_true=('permanent_disabled','principal_system','action_expected','trigger_enabled','repetition_active',
        'start_when_available','ignore_new','files_match','config_expected','token_blob_present','status_expected')
    required_int={'trigger_count':1,'interval_seconds':60,'execution_limit_seconds':180,
        'pilot_task_count':0,'unknown_process_count':0,'collector_process_count':0}
    if not isinstance(doc,dict) or any(doc.get(k) is not True for k in required_true): raise RuntimeError('windows_state_requires_review')
    if any(type(doc.get(k)) is not int or doc[k]!=v for k,v in required_int.items()): raise RuntimeError('windows_state_requires_review')
    epoch=doc.get('server_epoch')
    if type(epoch) is not int or abs(epoch-now)>120: raise RuntimeError('server_clock_skew')

def check_fix_receipt(pdo,fix):
    st=FIX_BACKUP.lstat()
    if not st or not os.path.isdir(FIX_BACKUP) or os.path.islink(FIX_BACKUP) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o077:
        raise RuntimeError('fix_backup_invalid')
    snap=pdo.snapshot(FIX_BACKUP/'plan.json')
    if (snap['uid'],snap['gid'],snap['mode'])!=(0,0,0o600): raise RuntimeError('fix_receipt_invalid')
    plan=json.loads(snap['data'])
    if not isinstance(plan,dict) or plan.get('source_sha')!=FIX_SHA or plan.get('job_id')!='3af8e09411c0e94a17ed19d3f0872359':
        raise RuntimeError('fix_receipt_invalid')
    entries=plan.get('files')
    if not isinstance(entries,list) or len(entries)!=len(fix.TARGETS): raise RuntimeError('fix_receipt_invalid')
    by_target={item.get('target'):item for item in entries if isinstance(item,dict)}
    if len(by_target)!=len(fix.TARGETS): raise RuntimeError('fix_receipt_invalid')
    for src,dst,old_sha in fix.TARGETS:
        source=ROOT/src; target=WEB/dst; backup=FIX_BACKUP/(target.name+'.original')
        row=by_target.get(str(target))
        if not isinstance(row,dict) or set(row)!={'target','backup','old_sha256','new_sha256','uid','gid','mode'} or row['backup']!=str(backup) or row['old_sha256']!=old_sha:
            raise RuntimeError('fix_receipt_invalid')
        old=pdo.snapshot(backup); current=pdo.snapshot(target); candidate=pdo.snapshot(source)
        if (old['uid'],old['gid'],old['mode'])!=(0,0,0o600) or hashlib.sha256(old['data']).hexdigest()!=old_sha:
            raise RuntimeError('fix_backup_invalid')
        new_sha=hashlib.sha256(candidate['data']).hexdigest()
        if row['new_sha256']!=new_sha or hashlib.sha256(current['data']).hexdigest()!=new_sha or any(current[k]!=row[k] for k in ('uid','gid','mode')):
            raise RuntimeError('installed_file_changed')

def check_units(pdo):
    for kind in ('service','timer'):
        unit='phpipam-dns-autonomous.'+kind
        target=Path('/etc/systemd/system')/unit
        expected=ROOT/'deploy/dns9'/unit
        live=pdo.snapshot(target); source=pdo.snapshot(expected)
        if live['uid']!=0 or live['gid']!=0 or live['mode']&0o022 or live['data']!=source['data']:
            raise RuntimeError('unit_file_changed')
        for prop,expected_value in (('FragmentPath',str(target)),('DropInPaths','')):
            proc=subprocess.run(['systemctl','show',unit,'--property='+prop,'--value'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False)
            if proc.returncode or proc.stdout.strip()!=expected_value: raise RuntimeError('unit_configuration_changed')
    proc=subprocess.run(['systemctl','show','phpipam-dns-autonomous.service','--property=ActiveState','--value'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False)
    if proc.returncode or proc.stdout.strip()!='inactive': raise RuntimeError('scheduler_service_not_inactive')

def local_preflight(b3,b4,pdo,ui,fix,post):
    b3.validate_followup(GUARDS,ui.B3_SHA)
    for name,sha in [('dns9-list-zones-attempt-global',ui.B4_SHA),('dns9-ui-zones-deploy-global',fix.UI_SHA),('dns9-scheduler-fix-deploy-global',FIX_SHA)]:
        b3._checked_guard(GUARDS/name,sha,0,0)
    pdo.marker_doc(GUARDS/'dns9-system-probe-attempt-global')
    check_fix_receipt(pdo,fix)
    fix.check_timer(b3); fix.no_scheduler_process()
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_uncertain')
    check_units(pdo)
    if b4.read_result(b3,ui.JOB)!=(172,ui.PAYLOAD_SHA): raise RuntimeError('zone_result_changed')
    for src,dst in pdo.BOUNDARY.items():
        if hashlib.sha256(pdo.snapshot(ROOT/src)['data']).digest()!=hashlib.sha256(pdo.snapshot(dst)['data']).digest(): raise RuntimeError('boundary_hash_mismatch')
    for src,dst in [
        ('layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php','functions/classes/class.IntegrationDnsCollectorControlService.php'),
        ('layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php','functions/classes/class.IntegrationDnsAutonomousAdminService.php'),
        ('layers/dns1/app/admin/integrations/dns/config.js','app/admin/integrations/dns/config.js')]:
        if pdo.snapshot(ROOT/src)['data']!=pdo.snapshot(WEB/dst)['data']: raise RuntimeError('installed_file_changed')
    row=queue_summary(b3)
    print('DNS9_ACTIVATION_QUEUE_FIELDS=pending|running|terminal_due_20m|other_enabled|pressure|verified_expired_reviewed',flush=True)
    print('DNS9_ACTIVATION_QUEUE='+row,flush=True)
    validate_queue(row)
    if b3.one("SELECT COUNT(*) FROM custom_dns_connections WHERE id='"+b3.PILOT_CONNECTION_ID+"' AND enabled=0 AND server='"+SERVER+"' AND credential_mode='collector_identity' AND interval_seconds=300;")!='1':
        raise RuntimeError('connection_state_changed')
    if pdo.fake_poll(b3)!=(401,'collector_auth_failed'): raise RuntimeError('fake_auth_failed')
    actor=b4.run_cli('--b4-preflight')['actor_id']
    if actor!=4: raise RuntimeError('actor_changed')
    return actor

def remote_loaders(source,hashes):
    parts=source.split('# DNS9_PREFLIGHT_SPLIT')
    if len(parts)!=2: raise RuntimeError('remote_preflight_source_invalid')
    prefix='$Root='+ps_quote(r'C:\ProgramData\phpIPAM\DnsCollector')+'\n'
    prefix+='$PermanentName='+ps_quote('phpIPAM Microsoft DNS Collector')+'\n'
    prefix+='$ExpectedJobId='+ps_quote('3af8e09411c0e94a17ed19d3f0872359')+'\n'
    prefix+='$ExpectedHashes=ConvertFrom-Json -InputObject '+ps_quote(json.dumps(hashes,sort_keys=True,separators=(',',':')))+'\n'
    loaders=[]
    for part in parts:
        packed=base64.b64encode(gzip.compress((prefix+part).encode(),mtime=0)).decode()
        loader=("$b=[Convert]::FromBase64String('"+packed+"');$m=New-Object IO.MemoryStream(,$b);"
            "$g=New-Object IO.Compression.GzipStream($m,[IO.Compression.CompressionMode]::Decompress);"
            "$r=New-Object IO.StreamReader($g);& ([scriptblock]::Create($r.ReadToEnd()))")
        if len(base64.b64encode(loader.encode('utf-16le')))>7500: raise RuntimeError('remote_preflight_too_long')
        loaders.append(loader)
    return loaders

def remote_preflight(b3,password):
    receipt=b3.receipt(Path('/var/backups/phpipam-custom/dns9-runner-tls-upgrade-a8d0fe00e1034084/receipt.json'),SERVER)
    hashes=b3.expected_runtime_hashes(receipt)
    loaders=remote_loaders((ROOT/'tools/live/dns9-activation-preflight.ps1').read_text(),hashes)
    remote=protocol(SERVER,USER,password)
    parts=[]
    for loader,marker in zip(loaders,('DNS9_ACTIVATION_TASK_JSON=','DNS9_ACTIVATION_HEALTH_JSON=')):
        rc,out,_=run_ps(remote,loader)
        if rc!=0: raise RuntimeError('remote_preflight_failed')
        value=json_line(out,marker)
        if not isinstance(value,dict): raise RuntimeError('remote_preflight_failed')
        parts.append(value)
    if set(parts[0])&set(parts[1]): raise RuntimeError('remote_preflight_failed')
    doc={**parts[0],**parts[1]}
    validate_remote(doc,int(time.time()))
    return doc

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    b3=module('b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    b4=module('b4',ROOT/'tools/live/dns9-b4-list-zones.py')
    pdo=module('pdo',ROOT/'tools/live/dns9-pdo-hotfix.py')
    ui=module('ui',ROOT/'tools/live/dns9-ui-deploy.py')
    fix=module('fix',ROOT/'tools/live/dns9-scheduler-deploy.py')
    post=module('post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    actor=local_preflight(b3,b4,pdo,ui,fix,post)
    print('DNS9_ACTIVATION_LOCAL_PREFLIGHT=PASS',flush=True)
    print('DNS9_ACTIVATION_MD4_PROVIDER='+ensure_ntlm_md4_runtime(),flush=True)
    password=getpass.getpass('WinRM diagnostic password: ')
    if not password: raise RuntimeError('password_empty')
    try: doc=remote_preflight(b3,password)
    finally: password=''
    if local_preflight(b3,b4,pdo,ui,fix,post)!=actor: raise RuntimeError('actor_changed')
    print('DNS9_ACTIVATION_REMOTE_JSON='+json.dumps(doc,sort_keys=True,separators=(',',':')))
    print('DNS9_ACTIVATION_CONNECTION=srv-dc01.rbank.local|collector_identity|300s|disabled')
    print('DNS9_ACTIVATION_SERVER_TIMER=disabled_inactive')
    print('DNS9_ACTIVATION_WINDOWS_TASK=disabled')
    print('DNS9_ACTIVATION_VERIFIED_EXPIRED_PENDING=2')
    print('DNS9_ACTIVATION_RUNNING=0')
    print('DNS9_ACTIVATION_NORMAL_EXPIRY_REQUIRES_REVIEW=1')
    print('DNS9_ACTIVATION_PREVIOUS_ZONES=172')
    print('DNS9_ACTIVATION_DB_MUTATION=0')
    print('DNS9_ACTIVATION_REMOTE_MUTATION=0')
    print('DNS9_ACTIVATION_DNS_WRITE=0')
    print('DNS9_ACTIVATION_READY_FOR_REVIEW=1')

if __name__=='__main__':
    try: main()
    except Exception as exc:
        allowed={'root_required','fix_backup_invalid','fix_receipt_invalid','installed_file_changed','unit_file_changed',
            'unit_configuration_changed','scheduler_service_not_inactive','local_process_uncertain','zone_result_changed',
            'boundary_hash_mismatch','queue_or_retention_requires_review','connection_state_changed','fake_auth_failed',
            'windows_state_requires_review','server_clock_skew','remote_preflight_too_long','remote_preflight_failed',
            'password_empty','actor_changed','timer_changed','another_process_exists'}
        print('DNS9_ACTIVATION_PREFLIGHT_ERROR='+(str(exc) if str(exc) in allowed else 'stopped_requires_review'),file=sys.stderr)
        raise SystemExit(1)
