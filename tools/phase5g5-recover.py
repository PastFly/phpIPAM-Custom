#!/usr/bin/env python3
"""Receipt-aware side-by-side recovery. Never rewrite the paused 5G4 parent release or receipt.
recover provisions a child; resume/status/rollback require its printed backup path.
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
import time
sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
PARENT_BACKUP = Path('/var/backups/phpipam-custom/vmware-inventory-20260914-074950-_qwuwnep')
PARENT_SOURCE = 'f7aedad68781bccfcac1985a1b240c3c515715ef'
PARENT_SCRIPT_BLOB = '11a28455933a2ff2b2bddeb5854ba14f3fea2536'
PARENT_DATA = Path('/var/lib/phpipam-custom/vmware-inventory')
NEW_RELEASE = '/opt/phpipam-custom/inventory-recovery5g5'
NEW_CONFIG = '/etc/phpipam-custom/vmware-inventory-recovery5g5.json'
NEW_DATA = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5'
NEW_UNIT = 'phpipam-vmware-recovery5g5@1'
INIT_UNIT = 'phpipam-vmware-recovery5g5-initial@1'


def import_engine(name):
    path = ROOT / 'tools/phase5g4-rollout.py'; raw = path.read_bytes()
    if hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() != PARENT_SCRIPT_BLOB:
        raise RuntimeError('original_engine_source_mismatch')
    spec = importlib.util.spec_from_file_location(name, path)
    m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m


parent_engine = import_engine('recovery_parent')
engine = import_engine('recovery_child')
engine.RELEASE, engine.CONFIG, engine.DATA = NEW_RELEASE, NEW_CONFIG, NEW_DATA
engine.UNIT, engine.TEMPLATE = NEW_UNIT, 'phpipam-vmware-recovery5g5@.'


def unit_state(unit):
    raw = engine.command(['systemctl', 'show', unit, '-p', 'ActiveState', '-p', 'SubState',
        '-p', 'MainPID', '-p', 'UnitFileState', '-p', 'LoadState'], capture=True).stdout.decode()
    return dict(line.split('=', 1) for line in raw.splitlines() if '=' in line)


def require_stopped(unit, timer=False):
    info = unit_state(unit)
    if info.get('ActiveState') not in ['inactive', 'failed'] or info.get('MainPID', '0') != '0':
        raise RuntimeError('worker_must_be_stopped:' + unit)
    if timer and info.get('UnitFileState') != 'disabled': raise RuntimeError('timer_must_be_disabled:' + unit)


def verify_parent(web=True):
    p = parent_engine.Rollout(parent_engine.Host()); p.load(PARENT_BACKUP)
    if p.receipt.get('source') != PARENT_SOURCE or p.receipt['stage'] != 'paused':
        raise RuntimeError('original_paused_parent_required')
    p.verify_resources()
    if web: p.assert_app()
    for suffix in ['service', 'timer']: require_stopped(parent_engine.UNIT + '.' + suffix, suffix == 'timer')
    return p


class Host(engine.Host):
    def __init__(self): self.initial = True
    def preflight(self):
        super().preflight()
        for suffix in ['service', 'timer']:
            for root in ['/etc/systemd/system', '/run/systemd/system']:
                for name in ['phpipam-vmware-recovery5g5-initial@.' + suffix, INIT_UNIT + '.' + suffix]:
                    for path in [Path(root) / name, Path(root) / (name + '.d')]:
                        if path.exists() or path.is_symlink(): raise RuntimeError('existing_initial_unit')
            state = self.systemctl('show', INIT_UNIT + '.' + suffix, '-p', 'LoadState', '--value').strip()
            if state != b'not-found': raise RuntimeError('existing_initial_unit_loaded')
    def start_worker(self):
        selected = INIT_UNIT if self.initial else NEW_UNIT
        other = NEW_UNIT if self.initial else INIT_UNIT
        for suffix in ['service', 'timer']: require_stopped(parent_engine.UNIT + '.' + suffix, suffix == 'timer')
        for suffix in ['service', 'timer']:
            if unit_state(other + '.' + suffix).get('ActiveState') not in ['inactive', 'failed']:
                raise RuntimeError('other_child_worker_active')
        self.systemctl('enable', '--now', selected + '.timer')
        self.systemctl('start', '--no-block', selected + '.service')
    def stop_worker(self):
        for unit in [INIT_UNIT, NEW_UNIT]:
            self.systemctl('disable', '--now', unit + '.timer')
            self.systemctl('stop', unit + '.service')
            require_stopped(unit + '.timer', True); require_stopped(unit + '.service')


def snapshot_files(root):
    """Only encrypted slots and existing zero-byte locks; filenames are never output to public logs."""
    engine.safe(root)
    if engine.meta(root) != {'uid': 0, 'gid': 33, 'mode': 0o750}:
        raise RuntimeError('private_seed_root_metadata')
    files = {}; directories = []
    for path in sorted(root.rglob('*')):
        engine.safe(path); relative = path.relative_to(root).as_posix(); st = path.lstat()
        if stat.S_ISDIR(st.st_mode):
            if relative not in ['connector-1', 'connector-1/telemetry'] and not re.fullmatch(r'connector-1/telemetry/(vm|host)-[0-9]{1,20}', relative):
                raise RuntimeError('foreign_private_directory')
            if (st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode)) != (33, 33, 0o700): raise RuntimeError('private_directory_metadata')
            directories.append(relative); continue
        if not stat.S_ISREG(st.st_mode) or (st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode), st.st_nlink) != (33, 33, 0o600, 1):
            raise RuntimeError('private_file_metadata')
        if re.fullmatch(r'\.write-[0-9a-f]{24}', path.name) and st.st_size <= 33554560:
            continue  # Unpublished orphan remains untouched in the parent.
        if path.name == 'worker.lock':
            if st.st_size != 0: raise RuntimeError('private_lock_nonempty')
            continue
        if not re.fullmatch(r'connector-1/(?:telemetry/(?:(?:vm|host)-[0-9]{1,20}/)?)?(current|pending)\.enc', relative):
            raise RuntimeError('foreign_private_file')
        if st.st_size > 33554560: raise RuntimeError('private_size_limit')
        files[relative] = {'sha256': engine.digest(path.read_bytes()), 'size': st.st_size}
    if len(files) > 4010 or sum(item['size'] for item in files.values()) > 536870912:
        raise RuntimeError('private_seed_limit')
    if 'connector-1/current.enc' not in files: raise RuntimeError('existing_publication_required')
    return files, directories


class Recovery(engine.Rollout):
    def save(self):
        self.receipt.setdefault('recovery', '5g5')
        self.receipt.setdefault('parent_backup', str(PARENT_BACKUP))
        super().save()
    def load(self, backup):
        super().load(backup)
        if self.receipt.get('recovery') != '5g5' or self.receipt.get('parent_backup') != str(PARENT_BACKUP):
            raise RuntimeError('child_recovery_receipt_required')
        if self.receipt.get('release_files', {}).get('tools/phase5g5-recover.py') != engine.digest(Path(__file__).read_bytes()):
            raise RuntimeError('use_original_child_recovery_script')
        return self.receipt
    def verify_parent_receipt(self):
        if engine.digest((PARENT_BACKUP / 'receipt.json').read_bytes()) != self.receipt['parent_receipt_sha256']:
            raise RuntimeError('original_parent_receipt_changed')
    def verify_resources(self, allow_disabled=False):
        super().verify_resources(allow_disabled)
        if self.receipt.get('parent_receipt_sha256'): self.verify_parent_receipt()
    def seed(self, source_root=PARENT_DATA):
        self.receipt['stage'] = 'seeding'; self.save()
        files, directories = snapshot_files(source_root)
        if self.system == Path('/'):
            v = os.statvfs(self.path(NEW_DATA))
            if v.f_bavail * v.f_frsize < 1073741824 + sum(v['size'] for v in files.values()):
                raise RuntimeError('insufficient_seed_and_runtime_space')
        for rel in directories:
            path = self.path(NEW_DATA) / rel
            if not path.exists(): engine.directory(path, 0o700, 33, 33)
        for rel, expected in files.items():
            source = engine.safe(source_root / rel); target = self.path(NEW_DATA) / rel
            if target.exists(): raise RuntimeError('child_seed_not_empty')
            raw = source.read_bytes()
            if engine.digest(raw) != expected['sha256']: raise RuntimeError('parent_seed_changed')
            engine.atomic(target, raw, {'uid': 33, 'gid': 33, 'mode': 0o600})
        after, after_directories = snapshot_files(source_root)
        if after != files or after_directories != directories: raise RuntimeError('parent_seed_changed')
        for rel, expected in files.items():
            if engine.digest((self.path(NEW_DATA) / rel).read_bytes()) != expected['sha256']: raise RuntimeError('child_seed_corrupt')
        self.receipt['seed'] = files; self.receipt['seed_complete'] = True
        self.receipt['stage'] = 'prepared'; self.save()
        print('PARENT_ENCRYPTED_FILES_RETAINED=1\nCHILD_ENCRYPTED_SEED_VERIFIED=1', flush=True)
    def wait_ready(self, seconds=14400, stalled_seconds=900):
        if not self.receipt.get('seed_complete'): raise RuntimeError('incomplete_seed_requires_review')
        self.verify_resources(); self.assert_app(); self.host.check_web()
        self.host.initial = True; self.receipt['stage'] = 'collecting'; self.save(); self.host.start_worker()
        end = time.monotonic() + seconds; last = None; failures = 0; progress_key = None; changed = time.monotonic()
        while time.monotonic() < end:
            try: result = self.host.inspect(self.path(NEW_RELEASE)); failures = 0
            except Exception as error:
                if str(error) == 'rollout_interrupted': raise
                failures += 1
                if failures >= 5: raise RuntimeError('readiness_probe_failed')
                time.sleep(15); continue
            if result.get('state') == 'access_denied': raise RuntimeError('initial_access_denied')
            if result.get('state') != 'busy':
                # Progress of an unrelated recurring inventory must not conceal stalled metric repair.
                if result.get('publication_complete'):
                    key = (result.get('generation'), result.get('metric_sections'), result.get('guest_sections_pending'))
                else:
                    key = (result.get('state'), json.dumps(result.get('refresh_progress', result.get('progress')), sort_keys=True))
                if key != progress_key: changed = time.monotonic(); progress_key = key
            if time.monotonic() - changed >= stalled_seconds: raise RuntimeError('initial_repair_no_progress_900s')
            message = engine.encoded(result).decode().strip()
            if message != last: print('RECOVERY_PROGRESS=' + message, flush=True); last = message
            if result.get('ready') is True:
                self.receipt['readiness'] = result; self.receipt['stage'] = 'ready'; self.save(); return
            time.sleep(15)
        raise RuntimeError('initial_population_timeout')
    def activate(self):
        self.verify_parent_receipt(); self.host.initial = False
        super().activate()
        print('PHASE5G5_RECOVERY_INSTALL_OK\nORIGINAL_PARENT_RETAINED=1\nAUTHENTICATED_BROWSER_ACCEPTANCE=PENDING', flush=True)
    def pause(self):
        if self.receipt and self.receipt['stage'] == 'seeding':
            self.host.stop_worker(); self.receipt['stage'] = 'seed_failed'; self.save()
            print('PHASE5G5_SEED_FAILED_NO_CUTOVER=1', flush=True); return
        super().pause()
    def rollback(self):
        # Parent remains paused; do not restart a known-broken parent sampler.
        if self.receipt['stage'] in ['seeding', 'seed_failed']:
            self.host.stop_worker(); self.assert_app()
            engine.atomic(self.path(NEW_CONFIG), engine.encoded(dict(self.receipt['config'], enabled=False)), {'uid': 0, 'gid': 33, 'mode': 0o640})
            self.receipt['stage'] = 'rolled_back'; self.save()
            print('PHASE5G5_UNACTIVATED_CHILD_RETAINED=1', flush=True); return
        super().rollback()
        print('PHASE5G5_CHILD_ROLLBACK_OK\nORIGINAL_PARENT_RETAINED=1', flush=True)


def build_package():
    package = parent_engine.source_package()
    builder = engine.load_module('recovery_runtime', ROOT / 'tools/build-phase5g5-runtime.py')
    return builder.generate(package)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['recover', 'resume', 'status', 'rollback'])
    parser.add_argument('backup', nargs='?')
    args = parser.parse_args()
    if os.geteuid() != 0: raise RuntimeError('root_required')
    if (args.action == 'recover') != (args.backup is None): raise RuntimeError('child_backup_required_except_recover')
    fd = os.open(engine.safe(Path(engine.APP)), os.O_RDONLY | os.O_DIRECTORY); lock = None
    r = Recovery(Host())
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        def interrupted(signum, frame): raise RuntimeError('rollout_interrupted')
        for signum in [signal.SIGINT, signal.SIGTERM, signal.SIGHUP]: signal.signal(signum, interrupted)
        parent = verify_parent(web=args.action == 'recover')
        # Keep the frozen parent encrypted publication locked against an accidental old worker start.
        path = engine.safe(PARENT_DATA / 'connector-1/worker.lock'); st = path.stat()
        if (st.st_uid, stat.S_IMODE(st.st_mode), st.st_size) != (33, 0o600, 0): raise RuntimeError('parent_lock_profile')
        lock = open(path, 'rb'); opened = os.fstat(lock.fileno())
        if (opened.st_ino, opened.st_dev) != (st.st_ino, st.st_dev): raise RuntimeError('parent_lock_changed')
        fcntl.flock(lock.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
        if args.action == 'recover':
            package = build_package()
            try:
                r.prepare(package)
                r.receipt['parent_receipt_sha256'] = engine.digest((PARENT_BACKUP / 'receipt.json').read_bytes()); r.save()
                print('RECOVERY_BACKUP=' + str(r.backup), flush=True)
                r.seed()
            except BaseException:
                if r.receipt and r.receipt['stage'] == 'preparing': r.abort_preparing()
                elif r.receipt: r.pause()
                raise
        else: r.load(Path(args.backup))
        if args.action == 'status':
            print('RECOVERY_STAGE=' + r.receipt['stage']); r.verify_resources(allow_disabled=r.receipt['stage'] in ['restoring', 'rolled_back'])
            r.assert_app(mixed=True)
            print('CHILD_AND_PARENT_RECEIPTS_VERIFIED=1'); return
        if args.action == 'rollback': r.rollback(); return
        if r.receipt['stage'] == 'active':
            r.verify_resources(); r.assert_app(new=True); print('PHASE5G5_ALREADY_ACTIVE=1'); return
        if r.receipt['stage'] not in ['prepared', 'paused', 'collecting', 'ready']: raise RuntimeError('child_stage_requires_review')
        try: r.wait_ready(); r.activate()
        except BaseException: r.pause(); raise
    finally:
        if lock is not None: lock.close()
        os.close(fd)


if __name__ == '__main__':
    rc = 0
    try: main()
    except Exception as error:
        print('PHASE5G5_ERROR=' + str(error), file=sys.stderr); rc = 1
    print('PHASE5G5_RECOVERY_EXIT=' + str(rc), flush=True)
    sys.exit(rc)
