#!/usr/bin/env python3
"""One resumable rollout: source checks -> initial fill -> validated offline routes.
No application switch on empty storage. Fixed Debian/phpIPAM/connector profile only.
"""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
BASE = '4214b055b782bdd40a87467ea83d5d640a1882bc'
C33 = 'c33c762f24048fb7976312389779047ad2b64b97'
APP = '/var/www/html'
RELEASE = '/opt/phpipam-custom/inventory'
CONFIG = '/etc/phpipam-custom/vmware-inventory.json'
DATA = '/var/lib/phpipam-custom/vmware-inventory'
BACKUPS = '/var/backups/phpipam-custom'
UNIT = 'phpipam-vmware-inventory@1'
TEMPLATE = 'phpipam-vmware-inventory@.'
INDEXES = ['app/integrations/vmware/index.php', 'app/tools/search/index.php']
STATUS_HOOK = b'<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5G4_LOCAL_INVENTORY -->\n<script src="app/integrations/vmware/phase5g3-status.js"></script>\n'


def digest(value): return hashlib.sha256(value).hexdigest()
def encoded(value): return (json.dumps(value, sort_keys=True, ensure_ascii=False) + '\n').encode()
def safe(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts: raise RuntimeError('invalid_path')
    for p in (path, *path.parents):
        if p.is_symlink(): raise RuntimeError('symlink_rejected')
    if path.exists() and path.is_file() and path.stat().st_nlink != 1: raise RuntimeError('hardlink_rejected')
    return path

def meta(path):
    s = safe(path).stat()
    return {'uid': s.st_uid, 'gid': s.st_gid, 'mode': stat.S_IMODE(s.st_mode)}

def syncdir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)

def atomic(path, value, metadata=None):
    path = safe(path)
    if not path.parent.is_dir(): raise RuntimeError('unprovisioned_parent')
    metadata = metadata or {'uid': 0, 'gid': 0, 'mode': 0o644}
    fd, name = tempfile.mkstemp(prefix='.rollout-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(value); out.flush()
            if os.geteuid() == 0: os.fchown(out.fileno(), metadata['uid'], metadata['gid'])
            os.fchmod(out.fileno(), metadata['mode']); os.fsync(out.fileno())
        safe(path); os.replace(name, path); syncdir(path.parent)
    finally:
        if os.path.exists(name): os.unlink(name)

def directory(path, mode=0o755, uid=0, gid=0):
    path = safe(path)
    if path.exists():
        m = meta(path)
        if not path.is_dir() or m['uid'] != uid or m['gid'] != gid or m['mode'] != mode:
            raise RuntimeError('directory_profile_mismatch:' + str(path))
    else:
        path.mkdir(mode=mode)
        if os.geteuid() == 0: os.chown(path, uid, gid)
        path.chmod(mode); syncdir(path.parent)
    return path

def parents(path, root):
    if path == root: return
    path.relative_to(root)
    parents(path.parent, root)
    if not path.exists(): directory(path)
    elif not path.is_dir() or safe(path).stat().st_mode & 0o022: raise RuntimeError('unsafe_parent')

def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module); return module

def git(*args): return subprocess.check_output(['git', '-C', str(ROOT), *args])

def command(args, timeout=180, capture=False, allowed=(0,)):
    p = subprocess.run([str(a) for a in args], stdout=subprocess.PIPE if capture else None,
        stderr=subprocess.PIPE if capture else None, timeout=timeout)
    if p.returncode not in allowed: raise RuntimeError('command_failed:' + str(args[0]))
    return p


def source_package():
    """Uses the checked-out immutable commit, not remote master or staged git changes."""
    head = git('rev-parse', 'HEAD').decode().strip()
    if not re.fullmatch('[0-9a-f]{40}', head) or git('status', '--porcelain').strip(): raise RuntimeError('unclean_source')
    command(['git', '-C', ROOT, 'merge-base', '--is-ancestor', BASE, head], capture=True)
    for rel in git('ls-tree', '-r', '--name-only', C33, 'layers', 'tools', 'installer').decode().splitlines():
        if safe(ROOT / rel).read_bytes() != git('show', C33 + ':' + rel): raise RuntimeError('deployed_source_drift')
    parent = load_module('rollout_cache', ROOT / 'tools/phase5f34-cache-deploy.py')
    native = load_module('rollout_native', ROOT / 'tools/build-phase5g2-native.py')
    generated = native.generate()
    routes = sorted(p for p in generated if not p.endswith('/native-views.php'))
    if len(routes) != 20: raise RuntimeError('route_set_mismatch')
    app_files = dict(generated); release = {}
    for layer in ['phase5g', 'phase5g2', 'phase5g3', 'phase5g4']:
        prefix = 'layers/' + layer + '/'
        for rel in git('ls-tree', '-r', '--name-only', 'HEAD', prefix).decode().splitlines():
            value = safe(ROOT / rel).read_bytes()
            if value != git('show', 'HEAD:' + rel): raise RuntimeError('working_source_drift')
            release[rel] = value
            target = rel[len(prefix):]
            if target in app_files: raise RuntimeError('duplicate_runtime_output')
            app_files[target] = value
    for rel in ['tools/vmware-inventory-cycle.php', 'tools/phase5g4-inspect.php', 'tools/phase5g4-worker.py', 'tools/phase5g4-rollout.py']:
        release[rel] = safe(ROOT / rel).read_bytes()
        if release[rel] != git('show', 'HEAD:' + rel): raise RuntimeError('working_source_drift')
    release['staged-app/app/integrations/vmware/mirror/native-views.php'] = generated['app/integrations/vmware/mirror/native-views.php']
    pins = json.loads((ROOT / 'design/phase5g-source-pins.json').read_text())['sources']
    dependencies = sorted({p.split('/', 2)[2] for p in pins if '/functions/classes/' in p} |
        {'functions/functions.php', 'app/integrations/vmware/cache/search-provider.php'})
    units = {}
    for suffix in ['service', 'timer']:
        rel = 'deployment/systemd/' + TEMPLATE + suffix
        value = safe(ROOT / rel).read_bytes()
        if value != git('show', 'HEAD:' + rel): raise RuntimeError('unit_source_drift')
        if suffix == 'service':
            value, n = re.subn(rb'^ExecStart=.*$',
                b'ExecStart=/usr/bin/python3 /opt/phpipam-custom/inventory/tools/phase5g4-worker.py --tick --connector=%i', value, flags=re.M)
            if n != 1: raise RuntimeError('unit_anchor')
        units['/etc/systemd/system/' + TEMPLATE + suffix] = value
    return {'head': head, 'app': app_files, 'routes': routes, 'release': release, 'units': units,
        'dependencies': dependencies, 'cache_hook': parent.HOOK, 'base_verify': parent.verify}


class Host:
    def preflight(self):
        if os.geteuid() != 0: raise RuntimeError('root_required')
        os_release = Path('/etc/os-release').read_text()
        if not re.search(r'^ID=debian$', os_release, re.M) or not re.search(r'^VERSION_ID="?12"?$', os_release, re.M):
            raise RuntimeError('debian12_profile_required')
        import pwd
        user = pwd.getpwnam('www-data')
        if (user.pw_uid, user.pw_gid) != (33, 33): raise RuntimeError('uid_profile')
        for fn in ['/usr/bin/php', '/usr/bin/python3', '/usr/bin/systemctl', '/usr/sbin/apache2ctl', '/usr/sbin/runuser']:
            if not os.access(fn, os.X_OK): raise RuntimeError('missing_program')
        capability = "foreach(['sodium_crypto_secretbox','fsync','posix_geteuid','pcntl_async_signals','curl_init'] as $f) if(!function_exists($f)) exit(1); if(!class_exists('DOMDocument')) exit(1);"
        command(['php', '-r', capability], capture=True)
        memory = dict(re.findall(r'^(MemTotal|MemAvailable):\s+(\d+) kB', Path('/proc/meminfo').read_text(), re.M))
        if int(memory.get('MemTotal', 0)) < 1536 * 1024 or int(memory.get('MemAvailable', 0)) < 768 * 1024:
            raise RuntimeError('memory_profile_below_1536MiB_total_or_768MiB_available')
        for path, reserve in [('/var/lib', 1073741824), ('/var/backups', 134217728), ('/opt', 67108864)]:
            v = os.statvfs(path)
            if v.f_bavail * v.f_frsize < reserve: raise RuntimeError('insufficient_space:' + path)
        self.check_web()
        # Only safe connector metadata is read, never the credential envelope or password.
        probe = r"""try { require '/var/www/html/functions/functions.php'; $db=new Database_PDO();
$r=$db->getObjectsQuery('no_html_escape','SELECT `id`,`provider`,`enabled`,`verify_tls`,`endpoint` FROM `custom_integrations` WHERE `provider` = ?;', ['vmware_vcenter']);
if(!is_array($r)||count($r)!==1) exit(1);$r=(array)$r[0];$u=parse_url($r['endpoint']);
if((int)$r['id']!==1||(int)$r['enabled']!==1||(int)$r['verify_tls']!==1||($u['scheme']??'')!=='https'||strtolower($u['host']??'')!=='srv-vcenter01.rbank.local'||($u['port']??443)!==443||!in_array($u['path']??'', ['', '/'], true)||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])) exit(1);
echo 'CONNECTOR1_STRICT_TLS_METADATA_OK'; } catch(Throwable $e) { exit(1); }"""
        p = command(['php', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', probe], capture=True)
        if p.stdout != b'CONNECTOR1_STRICT_TLS_METADATA_OK': raise RuntimeError('connector_profile')
        for base in ['/etc/systemd/system', '/run/systemd/system']:
            for name in [TEMPLATE + 'service', TEMPLATE + 'timer', UNIT + '.service', UNIT + '.timer']:
                if (Path(base) / name).exists() or (Path(base) / (name + '.d')).exists(): raise RuntimeError('existing_units_require_review')
        for suffix in ['service', 'timer']:
            state = self.systemctl('show', UNIT + '.' + suffix, '-p', 'LoadState', '--value', allowed=(0, 1, 4)).strip()
            if state != b'not-found': raise RuntimeError('existing_unit_loaded')

    def systemctl(self, *args, allowed=(0,), timeout=180):
        return command(['systemctl', *args], capture=True, allowed=allowed, timeout=timeout).stdout

    def check_web(self):
        self.systemctl('is-active', '--quiet', 'apache2.service')
        command(['apache2ctl', 'configtest'], capture=True)
        modules = command(['apache2ctl', '-M'], capture=True).stdout
        if b'php_module' not in modules:
            # The supplied host shows Apache, but no web-SAPI profile for FPM was reviewed.
            # Refuse before provisioning rather than leave a stale FPM OPcache after cutover.
            raise RuntimeError('apache_mod_php_profile_required_fpm_needs_review')

    def reload_units(self): self.systemctl('daemon-reload')
    def start_worker(self):
        self.systemctl('enable', '--now', UNIT + '.timer')
        self.systemctl('start', '--no-block', UNIT + '.service')
    def stop_worker(self):
        self.systemctl('disable', '--now', UNIT + '.timer')
        self.systemctl('stop', UNIT + '.service')
        if self.systemctl('show', UNIT + '.timer', '-p', 'ActiveState', '--value').strip() not in [b'inactive', b'failed']:
            raise RuntimeError('timer_did_not_stop')
        if self.systemctl('show', UNIT + '.service', '-p', 'MainPID', '--value').strip() != b'0':
            raise RuntimeError('worker_did_not_stop')
    def stop_web(self):
        self.systemctl('stop', 'apache2.service')
        p = command(['systemctl', 'is-active', '--quiet', 'apache2.service'], capture=True, allowed=(0, 3))
        if p.returncode == 0: raise RuntimeError('apache_not_stopped')
    def start_web(self):
        command(['apache2ctl', 'configtest'], capture=True)
        self.systemctl('start', 'apache2.service')
        self.check_web()
    def inspect(self, release):
        args = ['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/python3', release / 'tools/phase5g4-worker.py', '--inspect', '--connector=1']
        p = subprocess.Popen([str(a) for a in args], stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
        try:
            stdout, stderr = p.communicate(timeout=120)
        except BaseException:
            # runuser can have a PHP child: terminate/reap the entire diagnostic group.
            try: os.killpg(p.pid, signal.SIGTERM)
            except ProcessLookupError: pass
            try: p.communicate(timeout=5)
            except subprocess.TimeoutExpired:
                try: os.killpg(p.pid, signal.SIGKILL)
                except ProcessLookupError: pass
                p.communicate()
            raise
        if p.returncode != 0: raise RuntimeError('readiness_subprocess_failed')
        if len(stdout) > 16384: raise RuntimeError('readiness_output_size')
        out = json.loads(stdout)
        if not isinstance(out, dict): raise RuntimeError('readiness_format')
        return out


class Rollout:
    """The optional system root is for isolated tests only; no CLI/environment override exists."""
    def __init__(self, host, system=Path('/')):
        self.host = host; self.system = system; self.receipt = None; self.backup = None
    def path(self, absolute): return safe(self.system / absolute.lstrip('/'))
    def save(self): atomic(self.backup / 'receipt.json', encoded(self.receipt), {'uid': 0, 'gid': 0, 'mode': 0o600})
    def load(self, backup):
        backup = safe(backup)
        if backup.parent != self.path(BACKUPS) or not backup.name.startswith('vmware-inventory-') or meta(backup) != {'uid': 0, 'gid': 0, 'mode': 0o700}:
            raise RuntimeError('invalid_backup')
        if meta(backup / 'receipt.json') != {'uid': 0, 'gid': 0, 'mode': 0o600}: raise RuntimeError('invalid_receipt')
        value = json.loads((backup / 'receipt.json').read_text())
        if value.get('schema') != 1 or value.get('phase') != '5g4' or value.get('application') != APP or value.get('connector') != 1:
            raise RuntimeError('invalid_receipt')
        for path in value['app_files']:
            p = Path(path)
            if p.is_absolute() or '..' in p.parts or not (path.startswith('app/integrations/vmware/') or path.startswith('app/tools/search/') or path.startswith('functions/classes/')):
                raise RuntimeError('receipt_path')
        if digest(Path(__file__).read_bytes()) != value['release_files'].get('tools/phase5g4-rollout.py'):
            raise RuntimeError('use_original_rollout_script')
        self.receipt = value; self.backup = backup
        return value
    def verify_release(self):
        release = self.path(RELEASE)
        for rel, expected in self.receipt['release_files'].items():
            p = safe(release / rel)
            if digest(p.read_bytes()) != expected or meta(p) != {'uid': 0, 'gid': 0, 'mode': 0o444}: raise RuntimeError('release_drift')
        for rel, expected in self.receipt['dependencies'].items():
            if digest(self.path(APP + '/' + rel).read_bytes()) != expected: raise RuntimeError('dependency_drift:' + rel)
    def verify_resources(self, allow_disabled=False):
        self.verify_release()
        for path, expected in self.receipt['units'].items():
            p = self.path(path)
            if digest(p.read_bytes()) != expected or meta(p) != {'uid': 0, 'gid': 0, 'mode': 0o644}: raise RuntimeError('unit_drift')
        configs = [encoded(self.receipt['config'])]
        if allow_disabled: configs.append(encoded(dict(self.receipt['config'], enabled=False)))
        if self.path(CONFIG).read_bytes() not in configs or meta(self.path(CONFIG)) != {'uid': 0, 'gid': 33, 'mode': 0o640}:
            raise RuntimeError('configuration_drift')
    def assert_app(self, new=False, mixed=False):
        for rel, record in self.receipt['app_files'].items():
            p = self.path(APP + '/' + rel)
            actual = digest(p.read_bytes()) if p.exists() else None
            accepted = [record['old'], record['new']] if mixed else [record['new'] if new else record['old']]
            if actual not in accepted: raise RuntimeError('application_drift:' + rel)
            if p.exists():
                expected_meta = record['metadata'] if actual == record['old'] else record['installed_metadata']
                if meta(p) != expected_meta: raise RuntimeError('application_metadata_drift:' + rel)
            if record['old'] is not None and digest(safe(self.backup / 'old' / rel).read_bytes()) != record['old']: raise RuntimeError('backup_corrupt')
            if digest(safe(self.backup / 'new' / rel).read_bytes()) != record['new']: raise RuntimeError('staging_corrupt')

    def prepare(self, package):
        self.host.preflight()
        app = self.path(APP); package['base_verify'](app)
        for path in [RELEASE, CONFIG, DATA]:
            if self.path(path).exists(): raise RuntimeError('existing_rollout_requires_resume_or_review:' + path)
        for suffix in ['service', 'timer']:
            if self.path('/etc/systemd/system/' + TEMPLATE + suffix).exists(): raise RuntimeError('existing_units')
        roots = [self.path('/opt/phpipam-custom'), self.path('/var/lib/phpipam-custom'), self.path(BACKUPS)]
        for p in roots:
            if p.exists():
                m = meta(p)
                if not p.is_dir() or m['uid'] != 0 or m['mode'] & 0o022: raise RuntimeError('unsafe_managed_parent')
        if meta(self.path('/etc/phpipam-custom')) != {'uid': 0, 'gid': 33, 'mode': 0o750}: raise RuntimeError('private_parent_profile')
        files = dict(package['app'])
        for rel in INDEXES:
            value = safe(app / rel).read_bytes()
            if value.count(package['cache_hook']) != 1 or STATUS_HOOK in value: raise RuntimeError('index_hook_drift')
            files[rel] = value.replace(package['cache_hook'], STATUS_HOOK, 1)
        records = {}
        for rel, value in files.items():
            path = safe(app / rel); exists = path.exists()
            must_exist = rel in package['routes'] or rel in INDEXES
            if must_exist != exists: raise RuntimeError('unexpected_application_target:' + rel)
            records[rel] = {'old': digest(path.read_bytes()) if exists else None, 'new': digest(value),
                'metadata': meta(path) if exists else None,
                'installed_metadata': meta(path) if exists else {'uid': 0, 'gid': 0, 'mode': 0o644}}
        dependencies = {rel: digest(safe(app / rel).read_bytes()) for rel in package['dependencies']}
        # Validate all staged outputs before the first persistent provisioning step.
        with tempfile.TemporaryDirectory(prefix='phpipam-rollout-lint-') as tmp:
            for rel, value in {**files, **{ 'worker/' + k: v for k, v in package['release'].items()}}.items():
                p = Path(tmp) / rel; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(value)
                if rel.endswith('.php'): command(['php', '-l', p], capture=True)
                if rel.endswith('.py'): compile(value, rel, 'exec')
        if not self.path(BACKUPS).exists(): directory(self.path(BACKUPS), 0o700)
        name = 'vmware-inventory-' + datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S') + '-'
        self.backup = Path(tempfile.mkdtemp(prefix=name, dir=self.path(BACKUPS)))
        self.backup.chmod(0o700)
        cfg = {'schema': 1, 'enabled': True, 'application': APP, 'root': DATA, 'uid': 33, 'gid': 33}
        manifest = {'schema': 1, 'source': package['head'], 'application': APP, 'dependencies': dependencies,
            'files': {p: digest(v) for p, v in package['release'].items()}}
        release_hashes = {**manifest['files'], 'release.json': digest(encoded(manifest))}
        self.receipt = {'schema': 1, 'phase': '5g4', 'application': APP, 'connector': 1,
            'source': package['head'], 'stage': 'preparing', 'config': cfg, 'app_files': records,
            'dependencies': dependencies, 'release_files': release_hashes, 'units': {p: digest(v) for p, v in package['units'].items()}}
        self.save()
        print('ROLLOUT_BACKUP=' + str(self.backup), flush=True)
        for side in ['old', 'new']:
            directory(self.backup / side, 0o700)
        for rel, value in files.items():
            for side in ['old', 'new']:
                if side == 'old' and records[rel]['old'] is None: continue
                path = self.backup / side / rel; parents(path.parent, self.backup / side)
                atomic(path, safe(app / rel).read_bytes() if side == 'old' else value, {'uid': 0, 'gid': 0, 'mode': 0o600})
        self.assert_app()
        for p in roots[:2]:
            if not p.exists(): directory(p)
        release = directory(self.path(RELEASE))
        for rel, value in package['release'].items():
            p = release / rel; parents(p.parent, release)
            atomic(p, value, {'uid': 0, 'gid': 0, 'mode': 0o444})
        atomic(release / 'release.json', encoded(manifest), {'uid': 0, 'gid': 0, 'mode': 0o444})
        self.receipt['release_files'] = {**manifest['files'], 'release.json': digest(encoded(manifest))}; self.save()
        directory(self.path(DATA), 0o750, 0, 33)
        directory(self.path(DATA + '/connector-1'), 0o700, 33, 33)
        directory(self.path(DATA + '/connector-1/telemetry'), 0o700, 33, 33)
        atomic(self.path(CONFIG), encoded(cfg), {'uid': 0, 'gid': 33, 'mode': 0o640})
        for p, value in package['units'].items(): atomic(self.path(p), value)
        self.verify_resources(); self.host.reload_units()
        self.receipt['stage'] = 'prepared'; self.save()
        print('PHASE5G4_PREPARED=1\nPRODUCTION_ROUTES_CHANGED=0', flush=True)

    def wait_ready(self, seconds=14400):
        self.verify_resources(); self.assert_app(); self.host.check_web()
        self.receipt['stage'] = 'collecting'; self.save(); self.host.start_worker()
        deadline = time.monotonic() + seconds; last = None; failures = 0
        while time.monotonic() < deadline:
            try:
                result = self.host.inspect(self.path(RELEASE)); failures = 0
            except Exception as error:
                if str(error) == 'rollout_interrupted': raise
                failures += 1
                if failures >= 5: raise RuntimeError('readiness_probe_failed')
                time.sleep(15); continue
            message = encoded(result).decode().strip()
            if message != last: print('INITIAL_INVENTORY=' + message, flush=True); last = message
            if result.get('state') == 'access_denied': raise RuntimeError('initial_access_denied')
            if result.get('ready') is True:
                self.receipt['readiness'] = result; self.receipt['stage'] = 'ready'; self.save(); return
            time.sleep(15)
        raise RuntimeError('initial_population_timeout')

    def activate(self):
        self.verify_resources(); self.assert_app(); self.host.check_web()
        self.host.stop_worker()
        result = self.host.inspect(self.path(RELEASE))
        if result.get('ready') is not True or result.get('native_contracts_rendered') != 17: raise RuntimeError('publication_not_ready')
        self.receipt['readiness'] = result
        self.receipt['stage'] = 'switching'; self.save()
        writes_started = False
        try:
            self.host.stop_web()
            self.assert_app()  # Reject drift while Apache was stopping.
            writes_started = True
            # Apache is fully stopped: users cannot hit a half-switched collection of routes.
            for rel, record in self.receipt['app_files'].items():
                target = self.path(APP + '/' + rel); parents(target.parent, self.path(APP))
                atomic(target, (self.backup / 'new' / rel).read_bytes(), record['installed_metadata'])
            self.assert_app(new=True); self.verify_resources()
            self.host.start_web(); self.host.start_worker()
            self.receipt['stage'] = 'active'; self.save()
        except BaseException:
            if writes_started:
                self.rollback()
            else:
                self.host.start_web(); self.receipt['stage'] = 'paused'; self.save()
            raise
        print('PHASE5G4_INITIAL_PUBLICATION_OK=1\nPHASE5G4_RUNTIME_FILES_OK=' + str(len(self.receipt['app_files'])), flush=True)
        print('PHASE5G4_INSTALL_OK\nAUTHENTICATED_BROWSER_ACCEPTANCE=PENDING', flush=True)

    def pause(self):
        if self.receipt is None or self.receipt['stage'] in ['active', 'rolled_back', 'preparing']: return
        if self.receipt['stage'] == 'restoring':
            print('PHASE5G4_RECOVERY_REQUIRED=1', flush=True); return
        if self.receipt['stage'] == 'switching': self.rollback(); return
        self.host.stop_worker(); self.receipt['stage'] = 'paused'; self.save()
        print('PHASE5G4_PAUSED=1\nPRODUCTION_ROUTES_CHANGED=0', flush=True)

    def abort_preparing(self):
        # Nothing has started and no native route has been written in this phase.
        cfg = self.path(CONFIG)
        if cfg.exists():
            if cfg.read_bytes() != encoded(self.receipt['config']): raise RuntimeError('configuration_drift')
            atomic(cfg, encoded(dict(self.receipt['config'], enabled=False)), {'uid': 0, 'gid': 33, 'mode': 0o640})
        self.receipt['stage'] = 'prepare_failed'; self.save()
        print('PHASE5G4_PREPARE_STOPPED=1\nPRODUCTION_ROUTES_CHANGED=0', flush=True)

    def rollback(self):
        if self.receipt['stage'] in ['preparing', 'prepare_failed']:
            if self.receipt['stage'] == 'preparing': self.abort_preparing()
            print('PHASE5G4_PREPARE_NOT_DEPLOYED=1', flush=True); return
        if self.receipt['stage'] == 'rolled_back':
            self.assert_app(); print('PHASE5G4_ALREADY_ROLLED_BACK=1', flush=True); return
        self.verify_resources(allow_disabled=self.receipt['stage'] == 'restoring'); self.assert_app(mixed=True)
        self.host.stop_worker()
        changed = self.receipt['stage'] in ['switching', 'active', 'restoring']
        self.receipt['stage'] = 'restoring'; self.save()
        if changed: self.host.stop_web()
        # All hashes are checked before any restoration; never overwrite newer application edits.
        self.assert_app(mixed=True)
        for rel, record in self.receipt['app_files'].items():
            target = self.path(APP + '/' + rel)
            if record['old'] is not None:
                atomic(target, (self.backup / 'old' / rel).read_bytes(), record['metadata'])
            elif target.exists(): target.unlink(); syncdir(target.parent)
        self.assert_app()
        cfg = dict(self.receipt['config'], enabled=False)
        atomic(self.path(CONFIG), encoded(cfg), {'uid': 0, 'gid': 33, 'mode': 0o640})
        if changed: self.host.start_web()
        self.receipt['stage'] = 'rolled_back'; self.save()
        print('PHASE5G4_ROLLBACK_OK\nPHASE5F34_CACHE_RETAINED=1\nTLS_CONFIGURATION_UNCHANGED=1\nPRIVATE_INVENTORY_RETAINED=1', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['install', 'resume', 'status', 'rollback'])
    parser.add_argument('backup', nargs='?')
    args = parser.parse_args()
    if os.geteuid() != 0: raise RuntimeError('root_required')
    if (args.action == 'install') != (args.backup is None): raise RuntimeError('backup_argument_required_except_install')
    rollout = Rollout(Host()); fd = os.open(safe(Path(APP)), os.O_RDONLY | os.O_DIRECTORY)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        def interrupted(signum, frame): raise RuntimeError('rollout_interrupted')
        for signum in [signal.SIGINT, signal.SIGTERM, signal.SIGHUP]: signal.signal(signum, interrupted)
        if args.action != 'install': rollout.load(Path(args.backup))
        if args.action == 'status':
            print('ROLLOUT_STAGE=' + rollout.receipt['stage'])
            if rollout.receipt['stage'] not in ['preparing', 'prepare_failed', 'restoring', 'rolled_back']:
                rollout.verify_resources(); print(encoded(rollout.host.inspect(rollout.path(RELEASE))).decode().strip())
            return
        if args.action == 'rollback': rollout.rollback(); return
        if args.action == 'install':
            try: rollout.prepare(source_package())
            except BaseException:
                if rollout.receipt is not None and rollout.receipt['stage'] == 'preparing': rollout.abort_preparing()
                raise
        if rollout.receipt['stage'] == 'active':
            rollout.verify_resources(); rollout.assert_app(new=True); print('PHASE5G4_ALREADY_ACTIVE=1'); return
        if rollout.receipt['stage'] not in ['prepared', 'collecting', 'ready', 'paused']:
            raise RuntimeError('receipt_stage_requires_review')
        try: rollout.wait_ready(); rollout.activate()
        except BaseException:
            rollout.pause(); raise
    finally: os.close(fd)

if __name__ == '__main__':
    rc = 0
    try: main()
    except Exception as e:
        # All provider/DB exceptions are already sanitized in the separate non-root probe.
        print('PHASE5G4_ERROR=' + str(e), file=sys.stderr); rc = 1
    print('PHASE5G4_EXIT=' + str(rc), flush=True)
    sys.exit(rc)
