#!/usr/bin/env python3
"""Real disposable filesystem transactions; Apache/systemd/DB remain test doubles."""
import importlib.util
import os
from pathlib import Path
import tempfile
ROOT=Path(__file__).resolve().parents[1]
s=importlib.util.spec_from_file_location('recovery_tx',ROOT/'tools/phase5g5-recover.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
e=m.engine
assert os.geteuid()==0
checks=0

def check(v):
    global checks
    checks+=1
    assert v,checks

def fails(f):
    try:f()
    except (RuntimeError,FileNotFoundError):return True
    return False

class Host:
    def __init__(self):self.events=[];self.initial=True;self.fail_start=False
    def preflight(self):self.events.append('preflight')
    def reload_units(self):self.events.append('reload')
    def check_web(self):self.events.append('check_web')
    def start_worker(self):self.events.append('start_initial' if self.initial else 'start_normal')
    def stop_worker(self):self.events.append('stop_child')
    def stop_web(self):self.events.append('stop_web')
    def start_web(self):
        self.events.append('start_web')
        if self.fail_start:self.fail_start=False;raise RuntimeError('injected_start_failure')
    def inspect(self,release):return {'ready':True,'native_contracts_rendered':17}

def fixture(root):
    def path(p):return root/p.lstrip('/')
    for rel in ['/var/www/html','/var/lib','/var/backups','/etc/phpipam-custom','/etc/systemd/system','/opt']:
        path(rel).mkdir(parents=True,exist_ok=True)
    os.chown(path('/etc/phpipam-custom'),0,33);path('/etc/phpipam-custom').chmod(0o750)
    hook=b'<!-- old -->\n';route='app/integrations/vmware/vm-card-result.php'
    for rel in [route,*e.INDEXES]:
        f=path(e.APP)/rel;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b'<?php // old\n?>\n'+(hook if rel in e.INDEXES else b''));f.chmod(0o644)
    release={'tools/phase5g4-rollout.py':(ROOT/'tools/phase5g4-rollout.py').read_bytes(),'tools/phase5g5-recover.py':(ROOT/'tools/phase5g5-recover.py').read_bytes()}
    package={'head':'a'*40,'app':{route:b'<?php // local only new\n'},'routes':[route], 'release':release,'units':{},'dependencies':[],'cache_hook':hook,'base_verify':lambda app:None}
    h=Host();r=m.Recovery(h,root)
    # Parent's production receipt is not read in isolated fixtures.
    r.verify_parent_receipt=lambda:None
    parent=root/'parent-data';parent.mkdir();parent.chmod(0o750);os.chown(parent,0,33)
    for rel in ['connector-1','connector-1/telemetry','connector-1/telemetry/vm-1']:
        p=parent/rel;p.mkdir();p.chmod(0o700);os.chown(p,33,33)
    for rel in ['connector-1/current.enc','connector-1/pending.enc','connector-1/telemetry/vm-1/current.enc','connector-1/worker.lock']:
        p=parent/rel;p.write_bytes(b'' if p.name=='worker.lock' else b'public encrypted fixture');p.chmod(0o600);os.chown(p,33,33)
    return r,package,h,parent

with tempfile.TemporaryDirectory(prefix='recovery-transactions-')as tmp:
    for case in range(9):
        root=Path(tmp)/str(case);root.mkdir();r,p,h,parent=fixture(root)
        parent_bytes={str(f.relative_to(parent)):f.read_bytes()for f in parent.rglob('*')if f.is_file()}
        r.prepare(p);r.seed(parent)
        check(r.receipt['seed_complete'] is True and r.receipt['stage']=='prepared')
        check('start_initial' not in h.events and 'stop_web' not in h.events)
        if case==0:
            r.wait_ready(1);r.activate();check(r.receipt['stage']=='active');r.assert_app(new=True)
            check('start_initial'in h.events and 'start_normal'in h.events)
            r.rollback();check(r.receipt['stage']=='rolled_back');r.assert_app()
        elif case==1:
            h.fail_start=True;check(fails(r.activate));check(r.receipt['stage']=='rolled_back');r.assert_app()
        elif case==2:
            r.activate();target=r.path(e.APP+'/app/integrations/vmware/vm-card-result.php');target.write_text('newer owner change')
            check(fails(r.rollback));check(target.read_text()=='newer owner change')
        elif case==3:
            r.receipt['seed_complete']=False;check(fails(lambda:r.wait_ready(1)));check('start_initial'not in h.events)
        elif case==4:
            r.receipt['stage']='seeding';r.pause();check(r.receipt['stage']=='seed_failed');r.rollback();check(r.receipt['stage']=='rolled_back')
        elif case==5:
            target=r.path(e.RELEASE+'/tools/phase5g4-rollout.py');target.chmod(0o644);check(fails(r.activate));check('stop_web'not in h.events)
        elif case==6:
            h.inspect=lambda release:{'ready':False,'state':'sampling','publication_complete':True,'generation':'a','metric_sections':10,'guest_sections_pending':1}
            check(fails(lambda:r.wait_ready(1,0)));r.pause();check(r.receipt['stage']=='paused');r.assert_app()
        elif case==7:
            r.activate();h.fail_start=True;check(fails(r.rollback));check(r.receipt['stage']=='restoring');r.pause();check(r.receipt['stage']=='restoring');r.rollback();check(r.receipt['stage']=='rolled_back')
        elif case==8:
            # Never seed into existing child slots on a second attempt.
            check(fails(lambda:r.seed(parent)));check(r.receipt['stage']=='seeding')
        check(parent_bytes=={str(f.relative_to(parent)):f.read_bytes()for f in parent.rglob('*')if f.is_file()})
    for bad in ['symlink','hardlink','foreign']:
        root=Path(tmp)/bad;root.mkdir();r,p,h,parent=fixture(root);f=parent/'connector-1/current.enc'
        if bad=='symlink': f.unlink();f.symlink_to(parent/'connector-1/pending.enc')
        elif bad=='hardlink': f.unlink();os.link(parent/'connector-1/pending.enc',f)
        else:
            f=parent/'connector-1/plain.json';f.write_text('{}');f.chmod(0o600);os.chown(f,33,33)
        check(fails(lambda:m.snapshot_files(parent)))
print('PHASE5G5_RECOVERY_TRANSACTION_ASSERTIONS='+str(checks))
print('PHASE5G5_PARENT_BYTES_RETAINED_ROLLBACK_RESUME_OFFLINE_OK')
