#!/usr/bin/env python3
"""Create-only Phase5H.6 autonomous non-serving scheduler installer.

Installs only the Phase5H scheduler wrapper and its systemd service/timer.
It never changes the website, connector, TLS state, Phase5G.5 units or active data.
"""
import json
import os
from pathlib import Path
import secrets
import shutil
import stat
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
SHADOW = Path('/opt/phpipam-custom/vmware-fast-shadow')
SHADOW_DATA = Path('/var/lib/phpipam-custom/vmware-fast-shadow')
ACTIVE_DATA = Path('/var/lib/phpipam-custom/vmware-inventory-recovery5g5')
LOCK = ACTIVE_DATA / 'connector-1/worker.lock'
BACKUPS = Path('/var/backups/phpipam-custom')
WRAPPER_REL = Path('tools/phase5h6-scheduled-cycle.sh')
WRAPPER = SHADOW / WRAPPER_REL
SERVICE_SRC = ROOT / 'systemd/phpipam-vmware-fast-shadow@.service'
TIMER_SRC = ROOT / 'systemd/phpipam-vmware-fast-shadow@.timer'
SERVICE_DST = Path('/etc/systemd/system/phpipam-vmware-fast-shadow@.service')
TIMER_DST = Path('/etc/systemd/system/phpipam-vmware-fast-shadow@.timer')
SERVICE_INSTANCE = 'phpipam-vmware-fast-shadow@1.service'
TIMER_INSTANCE = 'phpipam-vmware-fast-shadow@1.timer'
SERVING_TIMER = 'phpipam-vmware-recovery5g5@1.timer'

# Exact live-accepted Phase5H.5 runtime blobs from merged PR60.
EXPECTED_RUNTIME_BLOBS = {
    'tools/phase5h4-shadow-run.php': '7ecaa1af168933456069e057bd23de2d2a185dcb',
    'layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php': 'b35127f8e28e503f558a08327b73cf9c6d41002a',
    'layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php': '5c95cd68601a6175d9127aae980d492316c03dc9',
    'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php': '380de222f7d437406ea8aa14807b09bbafa581b1',
    'layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php': 'bde978b7d8e2085e9e16b7a7f00b1e7a46096a27',
    'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php': '898d9bf64cfe328dc2243fc938b1c709e234c486',
    'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php': '9064e26f6d76f6e09ee7e615f2ae7e5c2506e129',
}


def run(args, *, check=True, timeout=60, stdout=subprocess.PIPE, stderr=subprocess.STDOUT):
    return subprocess.run(args, check=check, timeout=timeout, stdout=stdout, stderr=stderr, text=True)


def blob(path: Path) -> str:
    return run(['git', 'hash-object', str(path)], timeout=15).stdout.strip()


def safe_regular(path: Path, uid: int, gid: int, mode: int) -> None:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError('file_boundary')
    st = path.stat()
    if (st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode), st.st_nlink) != (uid, gid, mode, 1):
        raise RuntimeError('file_boundary')


def safe_dir(path: Path, uid: int, gid: int, mode: int) -> None:
    if not path.is_dir() or path.is_symlink():
        raise RuntimeError('dir_boundary')
    st = path.stat()
    if (st.st_uid, st.st_gid, stat.S_IMODE(st.st_mode)) != (uid, gid, mode):
        raise RuntimeError('dir_boundary')


def unit_state(unit: str) -> dict:
    result = run(['systemctl', 'show', unit, '-p', 'ActiveState', '-p', 'SubState', '-p', 'UnitFileState'], check=False)
    if result.returncode != 0:
        raise RuntimeError('systemd_query')
    return dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)


def service_result() -> dict:
    result = run(['systemctl', 'show', SERVICE_INSTANCE, '-p', 'Result', '-p', 'ExecMainStatus'], check=False)
    if result.returncode != 0:
        raise RuntimeError('systemd_query')
    return dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)


def verify_serving_boundary() -> None:
    state = unit_state(SERVING_TIMER)
    if state.get('ActiveState') != 'active' or state.get('UnitFileState') != 'enabled':
        raise RuntimeError('serving_timer_not_ready')
    safe_regular(LOCK, 33, 33, 0o600)
    if LOCK.stat().st_size != 0:
        raise RuntimeError('lock_boundary')


def verify_shadow_runtime() -> None:
    safe_dir(SHADOW_DATA, 33, 33, 0o700)
    for name in ['properties', 'metrics', 'capabilities', 'roster']:
        safe_dir(SHADOW_DATA / name, 33, 33, 0o700)
    for rel, expected in EXPECTED_RUNTIME_BLOBS.items():
        path = SHADOW / rel
        safe_regular(path, 0, 0, 0o644)
        if blob(path) != expected:
            raise RuntimeError('shadow_runtime_blob_mismatch')


def verify_sources() -> None:
    for path in [ROOT / WRAPPER_REL, SERVICE_SRC, TIMER_SRC]:
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('source_boundary')
    run(['bash', '-n', str(ROOT / WRAPPER_REL)], timeout=15)
    text = (ROOT / WRAPPER_REL).read_text()
    for required in [
        "LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'",
        'LOCK_WAIT_LIMIT=45', 'RUN_TIMEOUT=125', 'PHASE5H6_SCHED_LOCK_BUSY_SKIP=1',
    ]:
        if required not in text:
            raise RuntimeError('wrapper_contract')
    for forbidden in ['systemctl stop', 'systemctl disable', 'runuser', 'sudo']:
        if forbidden in text:
            raise RuntimeError('wrapper_contract')


def create_file(source: Path, target: Path, mode: int) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    try:
        with os.fdopen(fd, 'wb', closefd=False) as out:
            out.write(source.read_bytes())
            out.flush()
            os.fsync(out.fileno())
    finally:
        os.close(fd)
    os.chown(target, 0, 0)
    os.chmod(target, mode)


def remove_if_exact(target: Path, expected_blob: str) -> None:
    if target.exists() and not target.is_symlink() and target.is_file() and blob(target) == expected_blob:
        target.unlink()


def install() -> int:
    if os.geteuid() != 0:
        raise RuntimeError('root_required')
    for cmd in ['git', 'bash', 'systemctl', 'systemd-analyze']:
        if shutil.which(cmd) is None:
            raise RuntimeError('capability_missing')

    verify_sources()
    verify_serving_boundary()
    verify_shadow_runtime()

    sources = {
        WRAPPER: ROOT / WRAPPER_REL,
        SERVICE_DST: SERVICE_SRC,
        TIMER_DST: TIMER_SRC,
    }
    for target in sources:
        if target.exists() or target.is_symlink():
            raise RuntimeError('scheduler_target_exists')

    source_blobs = {str(target): blob(source) for target, source in sources.items()}
    stamp = time.strftime('%Y%m%d-%H%M%S', time.gmtime())
    backup = BACKUPS / ('vmware-fast-shadow-scheduler-' + stamp + '-' + secrets.token_hex(4))
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    os.chmod(backup, 0o700)
    receipt = {
        'schema': 1,
        'created_at': int(time.time()),
        'create_only': True,
        'service_instance': SERVICE_INSTANCE,
        'timer_instance': TIMER_INSTANCE,
        'serving_timer': SERVING_TIMER,
        'wrapper': str(WRAPPER),
        'service': str(SERVICE_DST),
        'timer': str(TIMER_DST),
        'source_blobs': source_blobs,
        'website_changed': False,
        'active_5g5_data_changed': False,
        'serving_5g5_unit_changed': False,
    }
    (backup / 'receipt.json').write_text(json.dumps(receipt, sort_keys=True, separators=(',', ':')) + '\n')
    os.chmod(backup / 'receipt.json', 0o600)

    created = []
    enable_attempted = False
    try:
        create_file(ROOT / WRAPPER_REL, WRAPPER, 0o755); created.append(WRAPPER)
        create_file(SERVICE_SRC, SERVICE_DST, 0o644); created.append(SERVICE_DST)
        create_file(TIMER_SRC, TIMER_DST, 0o644); created.append(TIMER_DST)

        safe_regular(WRAPPER, 0, 0, 0o755)
        safe_regular(SERVICE_DST, 0, 0, 0o644)
        safe_regular(TIMER_DST, 0, 0, 0o644)
        for target, expected in source_blobs.items():
            if blob(Path(target)) != expected:
                raise RuntimeError('installed_blob_mismatch')

        run(['systemd-analyze', 'verify', str(SERVICE_DST), str(TIMER_DST)], timeout=30)
        run(['systemctl', 'daemon-reload'], timeout=30)

        # Exercise the real systemd sandbox once before making the schedule persistent.
        first = run(['systemctl', 'start', SERVICE_INSTANCE], check=False, timeout=190)
        if first.returncode != 0:
            raise RuntimeError('scheduler_first_run_failed')
        first_result = service_result()
        if first_result.get('Result') != 'success' or first_result.get('ExecMainStatus') not in {'0', '75'}:
            raise RuntimeError('scheduler_first_run_failed')

        enable_attempted = True
        run(['systemctl', 'enable', '--now', TIMER_INSTANCE], timeout=30)

        state = unit_state(TIMER_INSTANCE)
        if state.get('ActiveState') != 'active' or state.get('UnitFileState') != 'enabled':
            raise RuntimeError('scheduler_timer_not_ready')
        verify_serving_boundary()

        print('PHASE5H6_SCHEDULER_BACKUP=' + str(backup), flush=True)
        print('PHASE5H6_SCHEDULER_FIRST_RUN_STATUS=' + first_result.get('ExecMainStatus', 'unknown'), flush=True)
        print('PHASE5H6_SCHEDULER_TIMER_ACTIVE=1', flush=True)
        print('PHASE5H6_SCHEDULER_TIMER_ENABLED=1', flush=True)
        print('PHASE5H6_SCHEDULER_SERVICE_USER=www-data', flush=True)
        print('PHASE5H6_SCHEDULER_SHARED_LOCK=1', flush=True)
        print('PHASE5H6_SCHEDULER_WEBSITE_CHANGED=0', flush=True)
        print('PHASE5H6_SCHEDULER_ACTIVE_5G5_DATA_CHANGED=0', flush=True)
        print('PHASE5H6_SCHEDULER_SERVING_5G5_UNIT_CHANGED=0', flush=True)
        print('PHASE5H6_SCHEDULER_INSTALL_OK=1', flush=True)
        return 0
    except BaseException:
        if enable_attempted or TIMER_DST in created:
            subprocess.run(['systemctl', 'disable', '--now', TIMER_INSTANCE], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for target in reversed(created):
            expected = source_blobs.get(str(target))
            if expected:
                try:
                    remove_if_exact(target, expected)
                except Exception:
                    pass
        subprocess.run(['systemctl', 'daemon-reload'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        print('PHASE5H6_SCHEDULER_AUTO_ROLLBACK=1', flush=True)
        raise


def main() -> int:
    if len(sys.argv) != 1:
        raise RuntimeError('arguments_not_supported')
    return install()


if __name__ == '__main__':
    rc = 0
    try:
        rc = main()
    except Exception as exc:
        print('PHASE5H6_SCHEDULER_INSTALL_ERROR=' + str(exc), file=sys.stderr)
        rc = 1
    print('PHASE5H6_SCHEDULER_INSTALL_EXIT=' + str(rc), flush=True)
    sys.exit(rc)
