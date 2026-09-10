#!/usr/bin/env python3
"""Source-pinned cache installation. Preflight never creates cache/key or calls vCenter."""
import sys
sys.dont_write_bytecode = True
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import signal
import stat
import subprocess
import tempfile
from datetime import datetime, timezone
ROOT = Path(__file__).resolve().parents[1]
BASE = '9c40ef6029ebe0d9fb7bbea190fcaca16c67750f'
NATIVE = 'app/tools/search/index.php'
WORKSPACE = 'app/integrations/vmware/index.php'
HOOK = b'<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F34_CACHE_V1 -->\n<script src="app/integrations/vmware/phase5f34-cache.js"></script>\n'
INDEXES = (NATIVE, WORKSPACE)
LAYER = ROOT/'layers/phase5f34'

def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT/'tools'/filename)
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m

def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()

def safe(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts: raise RuntimeError('invalid_path')
    for p in (path, *path.parents):
        if p.is_symlink(): raise RuntimeError('symlink_rejected: ' + str(path))
    if path.exists() and path.is_file() and path.stat().st_nlink != 1: raise RuntimeError('hardlink_rejected: ' + str(path))
    return path

def meta(path):
    s = safe(path).stat()
    return {'uid': s.st_uid, 'gid': s.st_gid, 'mode': stat.S_IMODE(s.st_mode)}

def run(args, **kwargs):
    return subprocess.run([str(a) for a in args], check=True, **kwargs)

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args])

def sources():
    for rel in git('ls-tree','-r','--name-only',BASE,'installer','tools','layers').decode().splitlines():
        if safe(ROOT/rel).read_bytes() != git('show', BASE+':'+rel): raise RuntimeError('ancestor_source_drift: '+rel)
    out = {}
    tracked = git('ls-tree','-r','--name-only','HEAD','layers/phase5f34').decode().splitlines()
    extra = ['tools/phase5f34-cache-deploy.py','tools/build-phase5f34-providers.py',
        'tools/live-vmware-phase5f34-integration-preflight.sh',
        'installer/install-vmware-vcenter-phase5f34-cache.sh','installer/verify-vmware-vcenter-phase5f34-cache.sh',
        'installer/rollback-vmware-vcenter-phase5f34-cache.sh']
    for rel in tracked + extra:
        expected = git('show','HEAD:'+rel)
        if safe(ROOT/rel).read_bytes() != expected: raise RuntimeError('working_source_drift: '+rel)
        if rel.startswith('layers/phase5f34/'): out[rel[len('layers/phase5f34/'):]] = expected
    gen = module('cache_generate', 'build-phase5f34-providers.py')
    for rel, value in gen.generate().items():
        if rel in out: raise RuntimeError('generated_provider_must_not_shadow_tracked_source: '+rel)
        out[rel] = value
    if len(out) != 13: raise RuntimeError('unexpected_runtime_file_set')
    return out

def ancestor_verify(target, installed=False):
    if not installed:
        run(['bash',ROOT/'installer/verify-vmware-vcenter-phase5f332-folders.sh',target]); return
    # Exact composition, not a wildcard/drift waiver. The original native blob must
    # match after removing the two canonical hooks. Inherited code remains unchanged.
    header = module('cache_header_verifier','phase5f331-global-search.py')
    folders = module('cache_folders_verifier','phase5f332-ui.py')
    header.NATIVE_HOOK = HOOK + header.NATIVE_HOOK
    folders.base_verify = header.verify
    folders.verify(target)

def paths(system=Path('/')):
    return {'parent':system/'var/cache/phpipam-custom', 'root':system/'var/cache/phpipam-custom/vmware',
        'etc':system/'etc/phpipam-custom', 'key':system/'etc/phpipam-custom/vmware-cache.key',
        'config':system/'etc/phpipam-custom/vmware-cache.json', 'backups':system/'var/backups/phpipam-custom'}

def patched(indexes):
    h = module('cache_header_anchors','phase5f331-global-search.py')
    result = {}
    for rel, anchor in [(NATIVE,h.NATIVE_HOOK),(WORKSPACE,h.WORKSPACE_HOOK)]:
        value = indexes[rel]
        if HOOK in value or value.count(anchor) != 1: raise RuntimeError('index_anchor_mismatch: '+rel)
        result[rel] = value.replace(anchor, HOOK+anchor, 1)
    return result

def preflight(target, system=Path('/')):
    print('=== PHASE 5F.3.4 CACHE INTEGRATION LIVE PREFLIGHT ===',flush=True)
    target = safe(target)
    if not target.is_dir(): raise RuntimeError('application_missing')
    data = sources(); ancestor_verify(target)
    indexes = {rel:safe(target/rel).read_bytes() for rel in INDEXES}; patched(indexes)
    for rel in data:
        if safe(target/rel).exists(): raise RuntimeError('new_cache_target_present: '+rel)
    p = paths(system)
    for name in ('root','key','config'):
        if safe(p[name]).exists(): raise RuntimeError('existing_cache_state_requires_review: '+name)
    if meta(p['etc']) != {'uid':0,'gid':33,'mode':0o750}: raise RuntimeError('review_config_parent_permissions')
    for name in ('parent','backups'):
        safe(p[name])
        if p[name].exists():
            s = meta(p[name])
            if not p[name].is_dir() or s['uid'] != 0 or s['mode'] & 0o022: raise RuntimeError('unsafe_parent: '+name)
    free = os.statvfs(p['parent'] if p['parent'].exists() else p['parent'].parent)
    if free.f_bavail * free.f_frsize < 268435456: raise RuntimeError('cache_free_space_below_256MiB')
    print('PHASE5F34_BASE_PHASE5F332_OK=1\nPHASE5F34_CACHE_SOURCE_ABSENT=1')
    print('PREFLIGHT_WRITE_OPERATIONS=0\nPREFLIGHT_KEY_CONTENTS_READ=0\nPREFLIGHT_VCENTER_REQUESTS=0')
    print('PREFLIGHT_RESULT=READY_FOR_PHASE5F34_CACHE_OWNER_APPROVAL',flush=True)
    return indexes

def atomic(path, data, metadata=None):
    path = safe(path); path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.cache-stage-', dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data); out.flush()
            if metadata and os.geteuid() == 0: os.fchown(out.fileno(),metadata['uid'],metadata['gid'])
            os.fchmod(out.fileno(),metadata['mode'] if metadata else 0o644); os.fsync(out.fileno())
        safe(path); os.replace(temporary,path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)

def create_private(path, data):
    path = safe(path)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd,'wb') as out:
        out.write(data); out.flush(); os.fchown(out.fileno(),0,33); os.fchmod(out.fileno(),0o640); os.fsync(out.fileno())

def configuration(target):
    return {'schema':1,'enabled':True,'application':str(target),'uid':33,'gid':33,
        'root':'/var/cache/phpipam-custom/vmware','key':'/etc/phpipam-custom/vmware-cache.key'}

def encoded(config):
    return (json.dumps(config,sort_keys=True)+'\n').encode()

def private_verify(p, config):
    if meta(p['key']) != {'uid':0,'gid':33,'mode':0o640} or p['key'].stat().st_size != 32: raise RuntimeError('cache_key_metadata_mismatch')
    if meta(p['config']) != {'uid':0,'gid':33,'mode':0o640} or p['config'].read_bytes() != encoded(config): raise RuntimeError('cache_config_mismatch')
    if meta(p['root']) != {'uid':33,'gid':33,'mode':0o700}: raise RuntimeError('cache_directory_metadata_mismatch')

def cli_key_selftest(p):
    # No key bytes enter arguments, receipt or logs. This is web UID using CLI,
    # not a claim about PHP-FPM/Apache SAPI. HTTP status reports actual web capabilities.
    code = r"""
$key = file_get_contents($argv[1]);
if (strlen($key) !== 32 || posix_geteuid() !== 33 || posix_getegid() !== 33) exit(1);
$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
$box = sodium_crypto_secretbox('cache-selftest', $nonce, $key);
if (sodium_crypto_secretbox_open($box, $nonce, $key) !== 'cache-selftest') exit(2);
$file = $argv[2] . '/.selftest-' . bin2hex(random_bytes(12));
$mask=umask(0077); $f=fopen($file,'xb'); umask($mask);
if ($f === false) exit(3);
try { if (fwrite($f,$box)!==strlen($box) || !fflush($f) || !fsync($f)) throw new RuntimeException('cache_selftest_failed'); }
finally { fclose($f); unlink($file); sodium_memzero($key); }
echo "CACHE_WEB_UID_CLI_SELFTEST_OK\n";
"""
    def drop():
        os.setgroups([33]); os.setgid(33); os.setuid(33)
    run(['php','-r',code,str(p['key']),str(p['root'])],preexec_fn=drop)

def verify(target, system=Path('/')):
    ancestor_verify(target,True)
    for rel, data in sources().items():
        if safe(target/rel).read_bytes() != data: raise RuntimeError('cache_runtime_mismatch: '+rel)
        if rel.endswith('.php'): run(['php','-l',target/rel],stdout=subprocess.DEVNULL)
    current = {name:safe(target/name).read_bytes() for name in INDEXES}
    if any(value.count(HOOK) != 1 for value in current.values()): raise RuntimeError('cache_hook_mismatch')
    if patched({name:value.replace(HOOK,b'',1) for name,value in current.items()}) != current: raise RuntimeError('cache_hook_order_mismatch')
    private_verify(paths(system), configuration(target))
    if system == Path('/'): cli_key_selftest(paths(system))
    print('PHASE5F34_CACHE_RUNTIME_FILES_OK=13\nPHASE5F34_CACHE_VERIFY_OK',flush=True)

def rollback(backup, system=Path('/')):
    backup = safe(backup)
    if meta(backup)['uid'] != 0 or meta(backup)['mode'] != 0o700: raise RuntimeError('unsafe_receipt_directory')
    receipt = json.loads(safe(backup/'receipt.json').read_text())
    if receipt.get('schema') != 1: raise RuntimeError('invalid_receipt')
    target = safe(receipt['target']); records = receipt['files']
    if set(records) != set(sources()) | set(INDEXES): raise RuntimeError('receipt_paths_mismatch')
    p = paths(system); config = configuration(target); disabled = dict(config,enabled=False)
    if p['config'].exists() and (meta(p['config']) != {'uid':0,'gid':33,'mode':0o640} or
        p['config'].read_bytes() not in (encoded(config),encoded(disabled))): raise RuntimeError('config_changed_after_deployment')
    for rel, record in records.items():
        path = safe(target/rel)
        if record['old'] is not None and blob(safe(backup/rel).read_bytes()) != record['old']: raise RuntimeError('backup_corrupt')
        if path.exists():
            if blob(path.read_bytes()) not in (record['old'],record['new']): raise RuntimeError('newer_runtime_refuse_rollback: '+rel)
        elif record['old'] is not None: raise RuntimeError('missing_original_runtime: '+rel)
    # Disable before removing routes. Retain encrypted data/key to avoid racing existing workers.
    if p['config'].exists(): atomic(p['config'],encoded(disabled),{'uid':0,'gid':33,'mode':0o640})
    for rel in INDEXES: atomic(safe(target/rel),safe(backup/rel).read_bytes(),records[rel]['metadata'])
    for rel, record in records.items():
        if record['old'] is None and safe(target/rel).exists(): safe(target/rel).unlink()
    print('PHASE5F34_CACHE_ROLLBACK_OK\nCACHE_KEY_RETAINED_PRIVATE='+str(int(p['key'].exists()))+'\nCACHE_ENABLED=0',flush=True)

def install(target, system=Path('/')):
    if os.geteuid() != 0: raise RuntimeError('root_required_for_private_key_provisioning')
    user = pwd.getpwnam('www-data')
    if (user.pw_uid,user.pw_gid) != (33,33): raise RuntimeError('web_runtime_identity_requires_review')
    original = preflight(target,system); data = sources(); new_indexes = patched(original); p = paths(system)
    import shutil
    with tempfile.TemporaryDirectory(prefix='phpipam-cache-lint-') as d:
        for rel, value in {**data,**new_indexes}.items():
            path = Path(d)/rel; path.parent.mkdir(parents=True,exist_ok=True); path.write_bytes(value)
            if rel.endswith('.php'): run(['php','-l',path],stdout=subprocess.DEVNULL)
            if rel.endswith('.js') and shutil.which('node'): run(['node','--check',path],stdout=subprocess.DEVNULL)
    p['backups'].mkdir(mode=0o700,parents=True,exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='vmware-cache-'+datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')+'-',dir=p['backups']))
    backup.chmod(0o700)
    receipt = {'schema':1,'target':str(target),'files':{}}
    for rel,value in {**data,**new_indexes}.items():
        old = original.get(rel)
        receipt['files'][rel] = {'old':None if old is None else blob(old),'new':blob(value),'metadata':meta(target/rel) if old is not None else None}
        if old is not None: atomic(backup/rel,old,{'uid':0,'gid':0,'mode':0o600})
    atomic(backup/'receipt.json',encoded(receipt),{'uid':0,'gid':0,'mode':0o600})
    print('Backup / rollback: '+str(backup),flush=True)
    try:
        p['parent'].mkdir(mode=0o755,exist_ok=True)
        p['root'].mkdir(mode=0o700); os.chown(p['root'],33,33)
        create_private(p['key'],os.urandom(32))
        create_private(p['config'],encoded(dict(configuration(target),enabled=False)))
        for rel,value in data.items(): atomic(safe(target/rel),value)
        for rel,value in new_indexes.items(): atomic(safe(target/rel),value,receipt['files'][rel]['metadata'])
        atomic(p['config'],encoded(configuration(target)),{'uid':0,'gid':33,'mode':0o640})
        verify(target,system)
    except BaseException:
        print('ERROR: cache installation failed; restoring application sources.',file=sys.stderr,flush=True)
        rollback(backup,system); raise
    print('PHASE5F34_CACHE_INSTALL_OK\nCACHE_VCENTER_REQUESTS_DURING_INSTALL=0\nUNATTENDED_SCHEDULER_INSTALLED=0')
    print('PHP_WEB_SAPI_VERIFICATION=PENDING_AUTHENTICATED_BROWSER\nBackup / rollback: '+str(backup),flush=True)
    return backup

def main():
    if len(sys.argv) != 3 or sys.argv[1] not in ('preflight','install','verify','rollback'):
        raise SystemExit('Usage: phase5f34-cache-deploy.py preflight|install|verify APPLICATION or rollback BACKUP')
    action = sys.argv[1]; target = safe(Path(sys.argv[2]).absolute())
    if not target.is_dir(): raise RuntimeError('target_directory_missing')
    if action in ('install','rollback'):
        lock_target = target
        if action == 'rollback':
            if meta(target)['uid'] != 0 or meta(target)['mode'] != 0o700: raise RuntimeError('unsafe_receipt_directory')
            lock_target = safe(json.loads(safe(target/'receipt.json').read_text())['target'])
        fd = os.open(lock_target,os.O_RDONLY | os.O_DIRECTORY)
        try:
            fcntl.flock(fd,fcntl.LOCK_EX | fcntl.LOCK_NB)
            def interrupted(signum,frame): raise RuntimeError('deployment_interrupted')
            signal.signal(signal.SIGTERM,interrupted); signal.signal(signal.SIGINT,interrupted)
            globals()[action](target)
        finally: os.close(fd)
    else: globals()[action](target)
if __name__ == '__main__':
    try: main()
    except Exception as e:
        print('ERROR: '+str(e),file=sys.stderr); sys.exit(1)
