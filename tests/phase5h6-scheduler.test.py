#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
wrapper = (ROOT / 'tools/phase5h6-scheduled-cycle.sh').read_text()
installer = (ROOT / 'tools/install-phase5h6-scheduler.py').read_text()
service = (ROOT / 'systemd/phpipam-vmware-fast-shadow@.service').read_text()
timer = (ROOT / 'systemd/phpipam-vmware-fast-shadow@.timer').read_text()
checks = 0

def ok(value, label):
    global checks
    checks += 1
    if not value:
        raise AssertionError(label)

# Wrapper: UID33 only, one known connector, exact shared lock, bounded lock/runtime.
for required in [
    "[ \"$INSTANCE\" = '1' ]",
    '"$(id -u)" -eq 33', '"$(id -g)" -eq 33',
    "LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'",
    'LOCK_WAIT_LIMIT=45', 'RUN_TIMEOUT=125',
    'while ! flock -x -n 9', 'PHASE5H6_SCHED_LOCK_BUSY_SKIP=1',
    'timeout --signal=TERM --kill-after=5s "${RUN_TIMEOUT}s" php -d memory_limit=512M "$RUNNER"',
]: ok(required in wrapper, 'wrapper:' + required)
for forbidden in ['runuser', 'sudo', 'systemctl stop', 'systemctl disable', 'base64']:
    ok(forbidden not in wrapper, 'wrapper_forbidden:' + forbidden)

# Avoid the set -u bug seen during live acceptance: no local declaration references a variable
# declared earlier in the same local statement.
unsafe_local = re.compile(r'^\s*local\s+[^\n]*\b([A-Za-z_][A-Za-z0-9_]*)="[^\n]*"[^\n]*\$\1', re.M)
ok(unsafe_local.search(wrapper) is None, 'wrapper_unsafe_local')

# Service executes as www-data, is oneshot/non-serving, and grants write only to shadow data + exact lock.
for required in [
    'Type=oneshot', 'User=www-data', 'Group=www-data', 'UMask=0077',
    'ExecStart=/opt/phpipam-custom/vmware-fast-shadow/tools/phase5h6-scheduled-cycle.sh %i',
    'TimeoutStartSec=180s', 'SuccessExitStatus=75',
    'NoNewPrivileges=true', 'PrivateTmp=true', 'PrivateDevices=true', 'ProtectSystem=strict',
    'ReadWritePaths=/var/lib/phpipam-custom/vmware-fast-shadow',
    'ReadWritePaths=/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock',
    'ReadOnlyPaths=/var/www/html', 'ReadOnlyPaths=/opt/phpipam-custom/inventory-recovery5g5',
]: ok(required in service, 'service:' + required)
for forbidden in ['Environment=', 'EnvironmentFile=', 'User=root', 'ExecStart=/bin/sh', 'ExecStart=/bin/bash']:
    ok(forbidden not in service, 'service_forbidden:' + forbidden)

# Timer is autonomous and approximately one minute, while service semantics prevent overlap.
for required in [
    'OnBootSec=90s', 'OnUnitActiveSec=60s', 'RandomizedDelaySec=3s', 'AccuracySec=1s',
    'Persistent=true', 'Unit=phpipam-vmware-fast-shadow@%i.service', 'WantedBy=timers.target',
]: ok(required in timer, 'timer:' + required)
ok('OnCalendar=' not in timer, 'timer_no_calendar')

# Installer is create-only for scheduler files and preserves serving 5G.5 boundaries.
for required in [
    "SERVING_TIMER = 'phpipam-vmware-recovery5g5@1.timer'",
    "TIMER_INSTANCE = 'phpipam-vmware-fast-shadow@1.timer'",
    "'create_only': True",
    "'website_changed': False",
    "'active_5g5_data_changed': False",
    "'serving_5g5_unit_changed': False",
    "run(['systemctl', 'enable', '--now', TIMER_INSTANCE]",
    'PHASE5H6_SCHEDULER_INSTALL_OK=1',
]: ok(required in installer, 'installer:' + required)
for forbidden in [
    "systemctl', 'stop', SERVING_TIMER", "systemctl', 'disable', SERVING_TIMER",
    '/var/www/html/', 'base64', 'secret_envelope=', 'password=',
]: ok(forbidden not in installer, 'installer_forbidden:' + forbidden)

# Pin the already live-accepted runtime exact blobs before enabling autonomy.
for blob in [
    '7ecaa1af168933456069e057bd23de2d2a185dcb',
    '5c95cd68601a6175d9127aae980d492316c03dc9',
    '898d9bf64cfe328dc2243fc938b1c709e234c486',
    '9064e26f6d76f6e09ee7e615f2ae7e5c2506e129',
]: ok(blob in installer, 'runtime_blob:' + blob)

print(f'PHASE5H6_SCHEDULER_ASSERTIONS={checks}')
print('PHASE5H6_UID33_NON_SERVING_BOUNDARY_OK')
print('PHASE5H6_SHARED_LOCK_AND_CADENCE_OK')
