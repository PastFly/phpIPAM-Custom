#!/usr/bin/env python3
"""Back up and install the validated DNS zone-result admin view once."""
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
B3_SHA='617e80a4ec49cbd6ba09d6777ed16713ff993c8d'
B4_SHA='abed9d946c79cdea463f799e2f9682a944285f08'
JOB='3af8e09411c0e94a17ed19d3f0872359'
PAYLOAD_SHA='f22f6cbcd8a2dd35b20a2a531550475b2e329b93323b8e7c1a31452db895eea0'
MARKER=GUARDS/'dns9-ui-zones-deploy-global'

# Accepted deployed versions come from the B4 source and the Phase A UI.
TARGETS=[
 ('layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php','functions/classes/class.IntegrationDnsCollectorControlService.php',
  {'d9f364209a5743b65c8c45b5e0323adc1dc62be50707260e0a8311f868b8a7e8'}),
 ('layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php','functions/classes/class.IntegrationDnsAutonomousAdminService.php',
  {'9ae60a491dccbc3d5e8b4c979b43beff4ced6a597aadc8e606c0d6607aac0294'}),
 ('layers/dns1/functions/classes/class.IntegrationDnsAdminView.php','functions/classes/class.IntegrationDnsAdminView.php',
  {'898acf10a9c58a81743d6ec0ff67f51b1f00a6cd6f80452a0e2d8bb92fc01333','d843710abee812bb7abb1649a9aa93f83179bf6aa61d2afe0e7c13204687f487'}),
 ('layers/dns1/app/admin/integrations/dns/config.js','app/admin/integrations/dns/config.js',
  {'be0bafd36be4413e646421dc1471956ccfceaa3777843fdde1b0fbc7db091b1e','bd331f3d1087444dca86ce227e5bb2a219d90b3fde927ec0e5bbdde25980eeaf'}),
]

def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path)
    obj=importlib.util.module_from_spec(spec); spec.loader.exec_module(obj); return obj

def validate_target(old,new,allowed):
    if hashlib.sha256(old).hexdigest() not in allowed or old==new: raise RuntimeError('target_hash_mismatch')

def reserve(path,sha):
    if not re.fullmatch('[0-9a-f]{40}',sha): raise ValueError('source_sha_invalid')
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        data=(sha+'\n').encode('ascii')
        if os.write(fd,data)!=len(data): raise RuntimeError('guard_write_failed')
        os.fsync(fd)
    finally: os.close(fd)
    fd=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)

def preflight(b3,b4,pdo,prior,post):
    b3.validate_followup(GUARDS,B3_SHA)
    b3._checked_guard(GUARDS/'dns9-list-zones-attempt-global',B4_SHA,0,0)
    pdo.marker_doc(GUARDS/'dns9-system-probe-attempt-global')
    prior.verify_hotfix(pdo)
    if b3.timer_enabled() or b3.timer_active(): raise RuntimeError('timer_changed')
    if post.local_process_state()!={'matching_processes':0,'unreadable':0}: raise RuntimeError('local_process_uncertain')
    if b4.read_result(b3,JOB)!=(172,PAYLOAD_SHA): raise RuntimeError('zone_result_changed')
    for src,dst in pdo.BOUNDARY.items():
        if hashlib.sha256(pdo.snapshot(ROOT/src)['data']).digest()!=hashlib.sha256(pdo.snapshot(dst)['data']).digest(): raise RuntimeError('boundary_hash_mismatch')
    if pdo.fake_poll(b3)!=(401,'collector_auth_failed'): raise RuntimeError('fake_auth_failed')

def smoke():
    proc=subprocess.run(['php',str(ROOT/'tools/live/dns9-ui-smoke.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,timeout=60,check=False)
    if proc.returncode or proc.stdout.strip()!='DNS9_UI_SMOKE_ZONE_COUNT=172': raise RuntimeError('ui_smoke_failed')
    print(proc.stdout.strip(),flush=True)

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    sha=(ROOT/'EXPECTED_SHA').read_text().strip()
    if not re.fullmatch('[0-9a-f]{40}',sha): raise RuntimeError('source_sha_invalid')
    b3=module('b3',ROOT/'tools/live/dns9-phase-b3-first-cycle.py')
    b4=module('b4',ROOT/'tools/live/dns9-b4-list-zones.py')
    pdo=module('pdo',ROOT/'tools/live/dns9-pdo-hotfix.py')
    prior=module('prior',ROOT/'tools/live/dns9-b3-after-pdo.py')
    post=module('post',ROOT/'tools/live/dns9-phase-b3-post-check.py')
    preflight(b3,b4,pdo,prior,post)
    if MARKER.exists() or MARKER.is_symlink(): raise RuntimeError('deploy_guard_exists')
    changes=[]
    for src,dst,allowed in TARGETS:
        source=ROOT/src; target=WEB/dst
        old=pdo.snapshot(target); new=pdo.snapshot(source)['data']
        validate_target(old['data'],new,allowed)
        if source.suffix=='.php' and subprocess.run(['php','-l',str(source)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode:
            raise RuntimeError('php_syntax_failed')
        changes.append((target,old,new))
    for folder in (GUARDS,BACKUP):
        st=folder.lstat()
        if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or st.st_mode&0o022: raise RuntimeError('root_directory_invalid')
    preflight(b3,b4,pdo,prior,post)
    reserve(MARKER,sha)
    print('DNS9_UI_DEPLOY_GUARD_CREATED=1',flush=True)
    directory=BACKUP/('dns9-zone-ui-'+secrets.token_hex(8)); directory.mkdir(mode=0o700)
    plan={'source_sha':sha,'job_id':JOB,'files':[]}
    for target,old,new in changes:
        saved=directory/(target.name+'.original')
        pdo.write_private(saved,old['data'])
        if hashlib.sha256(pdo.snapshot(saved)['data']).digest()!=hashlib.sha256(old['data']).digest(): raise RuntimeError('backup_verify_failed')
        plan['files'].append({'target':str(target),'backup':str(saved),'old_sha256':hashlib.sha256(old['data']).hexdigest(),
            'new_sha256':hashlib.sha256(new).hexdigest(),'uid':old['uid'],'gid':old['gid'],'mode':old['mode']})
    pdo.write_private(directory/'plan.json',(json.dumps(plan,sort_keys=True,indent=2)+'\n').encode())
    for path in (directory,BACKUP):
        fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try: os.fsync(fd)
        finally: os.close(fd)
    print('DNS9_UI_DEPLOY_BACKUP='+str(directory),flush=True)
    def verify():
        smoke()
        preflight(b3,b4,pdo,prior,post)
    pdo.apply_changes(changes,verify)
    print('DNS9_UI_JOB_ID='+JOB)
    print('DNS9_UI_DNS_WRITE=0')
    print('DNS9_UI_DB_MUTATION=0')
    print('DNS9_UI_PERMANENT_TASK_STARTED=0')
    print('DNS9_UI_DEPLOY_OK=1')

if __name__=='__main__':
    try: main()
    except Exception as exc:
        allowed={'root_required','source_sha_invalid','target_hash_mismatch','php_syntax_failed','root_directory_invalid',
            'deploy_guard_exists','guard_write_failed','backup_verify_failed','ui_smoke_failed',
            'apply_failed_rolled_back','apply_failed_rollback_incomplete','zone_result_changed','timer_changed','boundary_hash_mismatch'}
        print('DNS9_UI_DEPLOY_ERROR='+(str(exc) if str(exc) in allowed else 'stopped_requires_review'),file=sys.stderr)
        raise SystemExit(1)
