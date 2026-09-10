#!/usr/bin/env python3
"""READ ONLY environment survey. This is NOT a cache installer/activation gate."""
import argparse
import json
import os
from pathlib import Path
import pwd
import stat
import subprocess
import sys

BASE = '9c40ef6029ebe0d9fb7bbea190fcaca16c67750f'
REPO = Path(__file__).resolve().parents[1]
ENGINE = 'functions/classes/class.IntegrationVmwareSnapshotCache.php'
DEFAULT_CACHE = '/var/cache/phpipam-custom/vmware'
DEFAULT_KEY = '/etc/phpipam-custom/vmware-cache.key'


def checked_path(raw):
    path = Path(raw)
    if not path.is_absolute() or '..' in path.parts or str(path) == '/':
        raise RuntimeError('absolute_non_root_path_required')
    for node in (path, *path.parents):
        if node.is_symlink():
            raise RuntimeError('symlink_path_rejected')
    return path


def inside(path, root):
    return path == root or root in path.parents


def inspect_path(raw, target, is_key=False):
    path = checked_path(raw)
    if inside(path, target):
        raise RuntimeError('cache_or_key_must_be_outside_webroot')
    if path.exists() and (not path.is_file() if is_key else not path.is_dir()):
        raise RuntimeError('unexpected_cache_path_type')
    nearest = path
    while not nearest.exists():
        nearest = nearest.parent
    s = nearest.lstat()
    if s.st_mode & 0o022:
        raise RuntimeError('cache_parent_group_or_world_writable')
    if s.st_uid not in (0, os.geteuid()):
        raise RuntimeError('cache_parent_owner_unreviewed')
    v = os.statvfs(nearest)
    return {'state': 'EXISTS_REQUIRES_REVIEW' if path.exists() else 'ABSENT',
        'path': str(path), 'nearest_existing': str(nearest), 'uid': s.st_uid, 'gid': s.st_gid,
        'mode': format(stat.S_IMODE(s.st_mode), '04o'), 'free_bytes': v.f_bavail * v.f_frsize}


def web_workers():
    result = subprocess.run(['ps', '-eo', 'uid=,comm='], capture_output=True, text=True, check=True)
    found = {}
    for line in result.stdout.splitlines():
        fields = line.strip().split(None, 1)
        if len(fields) != 2 or not fields[0].isdigit():
            continue
        uid, command = int(fields[0]), fields[1]
        if uid == 0 or not (command in ('apache2', 'httpd') or command.startswith('php-fpm')):
            continue
        user = pwd.getpwuid(uid)
        found[uid] = {'uid': uid, 'gid': user.pw_gid, 'name': user.pw_name}
    return list(found.values())


def filesystem(path):
    nearest = path
    while not nearest.exists():
        nearest = nearest.parent
    candidates = []
    for line in Path('/proc/self/mountinfo').read_text().splitlines():
        fields = line.split()
        if '-' not in fields or len(fields) < 7:
            continue
        mount = Path(fields[4].replace('\\040', ' '))
        if inside(nearest, mount):
            candidates.append((len(str(mount)), fields[fields.index('-') + 1]))
    return max(candidates, default=(0, 'unknown'))[1]


def source_gate():
    def git(*args):
        return subprocess.check_output(['git', '-C', str(REPO), *args], text=True).strip()
    git('merge-base', '--is-ancestor', BASE, 'HEAD')
    if git('status', '--porcelain', '--untracked-files=no'):
        raise RuntimeError('tracked_working_source_drift')
    allowed = {'layers/phase5f34/' + ENGINE, 'tools/live-vmware-phase5f34-cache-preflight.py',
               'tools/live-vmware-phase5f34-cache-preflight.sh'}
    changed = set(git('diff', '--name-only', BASE, 'HEAD', '--', 'layers', 'installer', 'tools').splitlines())
    if not changed.issubset(allowed):
        raise RuntimeError('cache_foundation_protected_source_drift')
    print('PHASE5F34_SOURCE_HEAD=' + git('rev-parse', 'HEAD'), flush=True)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('target')
    args = parser.parse_args(argv)
    target = checked_path(args.target)
    if not target.is_dir() or not (target / 'config.php').is_file():
        raise RuntimeError('valid_phpipam_target_required')
    source_gate()
    print('=== PHASE 5F.3.4 CACHE FOUNDATION / ENVIRONMENT PREFLIGHT ===', flush=True)
    print('READ ONLY; no cache/key creation; no installer activation; no vCenter requests', flush=True)
    subprocess.run(['bash', str(REPO / 'installer/verify-vmware-vcenter-phase5f332-folders.sh'), str(target)], check=True)
    print('PHASE5F34_BASE_PHASE5F332_OK=1', flush=True)
    if (target / ENGINE).exists() or (target / ENGINE).is_symlink():
        raise RuntimeError('cache_foundation_runtime_already_present_requires_review')
    print('PHASE5F34_RUNTIME_ABSENT=1')
    php = "echo json_encode(['version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'sodium'=>extension_loaded('sodium'),'posix'=>extension_loaded('posix'),'dom'=>extension_loaded('dom'),'curl'=>extension_loaded('curl'),'fsync'=>function_exists('fsync')], JSON_THROW_ON_ERROR);"
    cli = json.loads(subprocess.check_output(['php', '-r', php], text=True))
    print('PHP_CLI_CAPABILITIES=' + json.dumps(cli, sort_keys=True))
    print('PHP_WEB_SAPI_NOT_PROBED=1')
    cache = inspect_path(DEFAULT_CACHE, target)
    key = inspect_path(DEFAULT_KEY, target, True)
    print('CACHE_PATH_METADATA=' + json.dumps(cache, sort_keys=True))
    print('CACHE_KEY_PATH_METADATA=' + json.dumps(key, sort_keys=True))
    fs = filesystem(Path(DEFAULT_CACHE))
    print('CACHE_FILESYSTEM=' + fs)
    workers = web_workers()
    print('WEB_RUNTIME_CANDIDATES=' + json.dumps(workers, sort_keys=True))
    print('CACHE_PERMISSIONS_PROPOSED=connector_directory:0700,snapshot:0600,key:root-owned_web-group-readable')
    print('CACHE_CONTENTS_READ=0\nCACHE_KEY_CONTENTS_READ=0\nPREFLIGHT_WRITE_OPERATIONS=0\nPREFLIGHT_VCENTER_REQUESTS=0')
    ready = (all(cli.get(x) is True for x in ('sodium', 'posix', 'dom', 'curl', 'fsync'))
             and len(workers) == 1 and cache['state'] == key['state'] == 'ABSENT'
             and min(cache['free_bytes'], key['free_bytes']) >= 134217728
             and fs in ('ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'overlay', 'tmpfs'))
    print('PREFLIGHT_RESULT=' + ('CACHE_ENVIRONMENT_READY_FOR_INTEGRATION' if ready else 'CACHE_ENVIRONMENT_REVIEW_REQUIRED'))
    print('CACHE_ACTIVATION_APPROVED=0\nCACHE_ENDPOINTS_CONNECTED=0\nUNATTENDED_SCHEDULER_INSTALLED=0')
    return 0 if ready else 3


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, RuntimeError, subprocess.CalledProcessError, ValueError) as error:
        # Paths/metadata only, never stdout of configuration files or environment values.
        print('PREFLIGHT_RESULT=FAILED\nERROR_CATEGORY=' + type(error).__name__, file=sys.stderr)
        if isinstance(error, RuntimeError):
            print('ERROR=' + str(error), file=sys.stderr)
        sys.exit(1)
