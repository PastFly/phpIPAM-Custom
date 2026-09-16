#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
p = ROOT / 'tools/install-phase5h10-disabled-reader-candidate.py'
s = p.read_text()
n = 0

def ok(value, label):
    global n
    n += 1
    if not value: raise AssertionError(label)

for token in [
    "CANDIDATE = Path('/opt/phpipam-custom/vmware-fast-reader-candidate')",
    "LIVE_CONFIG = Path('/etc/phpipam-custom/vmware-fast-reader.json')",
    "ACTIVE_RELEASE = Path('/opt/phpipam-custom/inventory-recovery5g5')",
    "ACTIVE_BACKUP = Path('/var/backups/phpipam-custom/vmware-inventory-20260914-144127-w63cp9jv')",
    "ACTIVE_RECOVERY_BLOB = '36b19f12e95b2f4884b4f625c755ce3df0db460a'",
    "BUILDER_BLOB = 'cbc4d5ba4d5370f6ee8aee37b08b73aff7652b1e'",
    "SERVING_TIMER = 'phpipam-vmware-recovery5g5@1.timer'",
    "SHADOW_TIMER = 'phpipam-vmware-fast-shadow@1.timer'",
    "LOCK = Path('/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock')",
    "WEBROOT = Path('/var/www/html')",
    "'RECOVERY_STAGE=active'",
    "'CHILD_AND_PARENT_RECEIPTS_VERIFIED=1'",
    "regular(recovery, uid=0, gid=0, mode=0o444)",
    "regular(LOCK, uid=33, gid=33, mode=0o600, size_zero=True)",
    "directory(SHADOW_DATA, uid=33, gid=33, mode=0o700)",
    "manifest.get('config_enabled') is not False",
    "manifest.get('serving_cutover') is not False",
    "'live_config_created':False",
    "'website_changed':False",
    "'systemd_changed':False",
    "'database_changed':False",
    "'connector_tls_changed':False",
    "if not apply_owner and system == Path('/'): raise RuntimeError('phase5h10_owner_bypass_forbidden')",
    "if path not in created_dirs: raise RuntimeError('phase5h10_target_exists')",
    "os.link(tmp, path, follow_symlinks=False)",
    "identity = (published.st_dev, published.st_ino)",
    "(st.st_dev, st.st_ino) == identity",
    "created_files: list[tuple[Path,str,int,int]]",
    "(st.st_dev, st.st_ino) != (expected_dev, expected_ino)",
    "if len(sys.argv) != 1: raise RuntimeError('arguments_not_supported')",
]: ok(token in s, 'required:' + token)

# The installed recovery helper is a release artifact. Phase5G Rollout.verify_release() enforces root:root 0444.
ok(s.count("regular(recovery, uid=0, gid=0, mode=0o444)") == 2, 'recovery release mode pre/post exact')
ok("regular(recovery, uid=0, gid=0, mode=0o644)" not in s, 'no mutable recovery mode assumption')

# The installer may query unit state, but must not mutate service state.
for forbidden in [
    "['systemctl', 'enable'", "['systemctl','enable'", "['systemctl', 'disable'", "['systemctl','disable'",
    "['systemctl', 'start'", "['systemctl','start'", "['systemctl', 'stop'", "['systemctl','stop'",
    'daemon-reload', 'systemctl restart', 'systemctl reload',
    'mysql', 'ALTER TABLE', 'UPDATE custom_integrations', 'secret_envelope',
    'curl_exec(', 'SoapClient', 'QueryPerf', 'PropertyCollector',
]: ok(forbidden not in s, 'forbidden:' + forbidden)

# Live config is a pre/post guard only. The only config write is beneath the isolated candidate tree.
ok(s.count('LIVE_CONFIG') >= 4, 'live config guarded')
ok("candidate/'config/vmware-fast-reader.json'" in s, 'staged config verified')
ok('write_file(live_config' not in s and 'write_receipt(live_config' not in s, 'no live config write')
ok('WEBROOT / rel' in s and 'write_file(WEBROOT' not in s and 'write_file(mapped(system, WEBROOT' not in s, 'webroot read only')

# Test hooks exist only as Python-call parameters, never as command-line options or environment switches.
ok('fault_after' in s, 'fault injection internal')
ok('argparse' not in s and 'getenv(' not in s and 'os.environ' not in s, 'no runtime override channel')

# Transaction directories must be tracked before owner/mode mutation so metadata failures remain rollbackable.
ensure_start = s.index('def ensure_dir(')
ensure_end = s.index('\ndef write_receipt(', ensure_start)
ensure = s[ensure_start:ensure_end]
ok(ensure.index('created_dirs.append(path)') < ensure.index('if apply_owner: os.chown(path, uid, gid)'), 'nested dir tracked before chown')
install_start = s.index('def install(')
install_end = s.index('\ndef verify_live_boundary_after(', install_start)
install = s[install_start:install_end]
ok(install.index('created_dirs.append(candidate)') < install.index('if apply_owner: os.chown(candidate, 0, 0)'), 'candidate tracked before chown')

# File ownership is exact inode/device/hash, not merely path+content.
write_start = s.index('def write_file(')
write_end = s.index('\ndef ensure_dir(', write_start)
write = s[write_start:write_end]
ok("-> tuple[str,int,int]" in write, 'publisher returns inode identity')
ok('published = path.lstat()' in write and 'identity = (published.st_dev, published.st_ino)' in write, 'publisher captures inode')
ok('return expected, identity[0], identity[1]' in write, 'publisher returns exact identity')
rollback_start = s.index('def rollback(')
rollback_end = s.index('\ndef install(', rollback_start)
rollback = s[rollback_start:rollback_end]
ok('expected_dev, expected_ino' in rollback, 'rollback receives inode identity')
ok('(st.st_dev, st.st_ino) != (expected_dev, expected_ino)' in rollback, 'rollback rejects replacement inode')
ok('digest(path.read_bytes()) != expected' in rollback, 'rollback rejects content drift')

# Rollback retains audit receipt/backup and only deletes tracked exact transaction-owned payload.
ok("receipt['stage'] = 'rolled_back' if clean else 'rollback_incomplete'" in s, 'rollback receipt')
ok('rollback(created_files, created_dirs)' in s, 'tracked rollback')
ok('shutil.rmtree' not in s, 'no broad recursive delete')

print(f'PHASE5H10_INSTALLER_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H10_RECOVERY_RELEASE_MODE_0444_OK')
print('PHASE5H10_NO_WEBROOT_SYSTEMD_DB_TLS_MUTATION_OK')
print('PHASE5H10_ATOMIC_EXCLUSIVE_PUBLICATION_OK')
print('PHASE5H10_INODE_BOUND_ROLLBACK_OK')
print('PHASE5H10_TRACKED_ROLLBACK_ONLY_OK')
print('PHASE5H10_LIVE_CONFIG_GUARD_ONLY_OK')
