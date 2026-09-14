#!/usr/bin/env python3
"""Run at most three failed CPU and two failed guest-network examples on a verified paused rollout.
No install/resume, snapshot write, service restart or live source replacement.
"""
import fcntl
import hashlib
import importlib.util
import os
from pathlib import Path
import signal
import stat
import subprocess
import sys
sys.dont_write_bytecode = True

REPO = Path('/tmp/phpipam-custom-phase0')
BACKUP = Path('/var/backups/phpipam-custom/vmware-inventory-20260914-074950-_qwuwnep')
RUNNING = 'f7aedad68781bccfcac1985a1b240c3c515715ef'
ORIGINAL_BLOB = '11a28455933a2ff2b2bddeb5854ba14f3fea2536'
PHP_SHA256 = '247cbbd5985f467ddc3d2bb36bdc390aa348d5e99852acc54b4491705949964a'
UNITS = ['apache2.service', 'phpipam-vmware-inventory@1.timer', 'phpipam-vmware-inventory@1.service']


def run(args):
    p = subprocess.run([str(x) for x in args], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20)
    if p.returncode != 0:
        raise RuntimeError('local_command_failed')
    return p.stdout


def units():
    result = {}
    for unit in UNITS:
        text = run(['systemctl', 'show', unit, '--no-pager', '-p', 'LoadState', '-p', 'ActiveState',
                    '-p', 'SubState', '-p', 'MainPID', '-p', 'UnitFileState']).decode()
        result[unit] = dict(line.split('=', 1) for line in text.splitlines() if '=' in line)
    enforce_units(result)
    return result


def enforce_units(states):
    apache, timer, worker = (states[u] for u in UNITS)
    if any(s.get('LoadState') != 'loaded' for s in (apache, timer, worker)):
        raise RuntimeError('unit_not_loaded')
    if apache.get('ActiveState') != 'active' or apache.get('SubState') != 'running':
        raise RuntimeError('apache_not_running')
    if timer.get('ActiveState') != 'inactive' or timer.get('UnitFileState') != 'disabled':
        raise RuntimeError('timer_not_paused')
    if worker.get('MainPID') != '0' or worker.get('ActiveState') not in ['inactive', 'failed']:
        raise RuntimeError('worker_not_stopped')


def regular_root_file(path):
    for p in (path, *path.parents):
        if p.is_symlink():
            raise RuntimeError('source_link_rejected')
    st = path.stat()
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or st.st_uid != 0 or st.st_mode & 0o022:
        raise RuntimeError('source_metadata')
    return path.read_bytes()


def main():
    if sys.argv[1:] != ['--probe'] or os.geteuid() != 0:
        raise RuntimeError('root_and_explicit_probe_required')
    if run(['git', '-C', REPO, 'rev-parse', '--show-toplevel']).decode().strip() != str(REPO):
        raise RuntimeError('repository_path')
    if run(['git', '-C', REPO, 'rev-parse', 'HEAD']).decode().strip() != RUNNING:
        raise RuntimeError('keep_original_checkout')
    if run(['git', '-C', REPO, 'status', '--porcelain']).strip():
        raise RuntimeError('unclean_original_checkout')
    original = REPO / 'tools/phase5g4-rollout.py'
    raw = regular_root_file(original)
    if hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() != ORIGINAL_BLOB:
        raise RuntimeError('original_source_mismatch')
    php = Path(__file__).resolve().with_suffix('.php')
    if hashlib.sha256(regular_root_file(php)).hexdigest() != PHP_SHA256:
        raise RuntimeError('probe_source_mismatch')
    spec = importlib.util.spec_from_file_location('original_rollout_probe', original)
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    fd = os.open(m.safe(Path(m.APP)), os.O_RDONLY | os.O_DIRECTORY)
    try:
        # Same outer deployment lock: no simultaneous resume/cutover can start.
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        r = m.Rollout(m.Host())
        r.load(BACKUP)
        if r.receipt['stage'] != 'paused' or r.receipt['source'] != RUNNING:
            raise RuntimeError('original_paused_receipt_required')
        r.verify_resources()
        r.assert_app()
        units()
        print('PAUSED_ORIGINAL_RELEASE_VERIFIED=1\nWEBSITE_FILES=PRE_CUTOVER', flush=True)
        print('TARGETED_PROBE_LIMITS=3_CPU_EXAMPLES_2_GUEST_EXAMPLES_64_REQUESTS_160S_NETWORK_BUDGET', flush=True)
        p = subprocess.Popen(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/php',
            '-d', 'memory_limit=256M', '-d', 'display_errors=0', '-d', 'log_errors=0', str(php)],
            start_new_session=True)
        try:
            rc = p.wait(timeout=190)
        except BaseException:
            try:
                os.killpg(p.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                p.wait(timeout=5)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(p.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                p.wait()
            raise
        # Code/config and inactive units must still match after the read-only examples.
        r.verify_resources()
        r.assert_app()
        units()
        print('PAUSED_RELEASE_UNCHANGED=1\nSCHEDULER_REMAINS_STOPPED=1', flush=True)
        if rc != 0:
            raise RuntimeError('provider_probe_did_not_complete')
    finally:
        os.close(fd)


if __name__ == '__main__':
    rc = 0
    try:
        main()
    except Exception as e:
        # No subprocess stderr/provider errors are copied into launcher diagnostics.
        print('TARGETED_PROBE_LAUNCH_FAILED=1', flush=True)
        rc = 1
    print('PHASE5G4_TARGETED_PROBE_EXIT=' + str(rc), flush=True)
    sys.exit(rc)
