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
    "if digest(path.read_bytes()) != digest(raw): raise RuntimeError('phase5h10_publish_hash')",
    "if path.is_symlink() or not path.is_file() or digest(path.read_bytes()) != expected",
    "if len(sys.argv) != 1: raise RuntimeError('arguments_not_supported')",
]: ok(token in s, 'required:' + token)

# The installer may query unit state, but must not mutate service state.
for forbidden in [
    "['systemctl', 'enable'", "['systemctl','enable'", "['systemctl', 'disable'", "['systemctl','disable'",
    "['systemctl', 'start'", "['systemctl','start'", "['systemctl', 'stop'", "['systemctl','stop'",
    'daemon-reload', 'systemctl restart', 'systemctl reload',
    'mysql', 'ALTER TABLE', 'UPDATE custom_integrations', 'secret_envelope',
    'curl_exec(', 'SoapClient', 'QueryPerf', 'PropertyCollector',
]: ok(forbidden not in s, 'forbidden:' + forbidden)

# Live config is a pre/post guard only. The only config write is beneath the isolated candidate tree.
ok(s.count("LIVE_CONFIG") >= 4, 'live config guarded')
ok("candidate/'config/vmware-fast-reader.json'" in s, 'staged config verified')
ok("write_file(live_config" not in s and "write_receipt(live_config" not in s, 'no live config write')
ok("WEBROOT / rel" in s and "write_file(WEBROOT" not in s and "write_file(mapped(system, WEBROOT" not in s, 'webroot read only')

# Test hooks exist only as Python-call parameters, never as command-line options or environment switches.
ok('fault_after' in s, 'fault injection internal')
ok('argparse' not in s and 'getenv(' not in s and 'os.environ' not in s, 'no runtime override channel')

# Rollback deliberately retains the audit receipt/backup but only deletes exact transaction-owned payload.
ok("receipt['stage'] = 'rolled_back' if clean else 'rollback_incomplete'" in s, 'rollback receipt')
ok("rollback(created_files, created_dirs)" in s, 'tracked rollback')
ok("shutil.rmtree" not in s, 'no broad recursive delete')

print(f'PHASE5H10_INSTALLER_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H10_NO_WEBROOT_SYSTEMD_DB_TLS_MUTATION_OK')
print('PHASE5H10_ATOMIC_EXCLUSIVE_PUBLICATION_OK')
print('PHASE5H10_TRACKED_ROLLBACK_ONLY_OK')
print('PHASE5H10_LIVE_CONFIG_GUARD_ONLY_OK')
