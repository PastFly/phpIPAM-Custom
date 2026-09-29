#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
INSTALL=ROOT/"tools/live/dns9-server-install.py"
ROLLBACK=ROOT/"tools/live/dns9-server-rollback.py"
ACCEPT=ROOT/"tools/live/dns9-server-post-install-accept.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for path in (INSTALL,ROLLBACK,ACCEPT):
    ok(path.is_file(),path.name+" exists")
    ast.parse(path.read_text())
    ok(True,path.name+" python syntax")

install=INSTALL.read_text()
rollback=ROLLBACK.read_text()
accept=ACCEPT.read_text()

for required in [
    "--apply",
    "DNS9_SERVER_INSTALL_DRY_RUN=1",
    "DNS9_SERVER_INSTALL_MUTATION=0",
    "DNS9_SERVER_INSTALL_PREFLIGHT_OK=1",
    "DNS9_SERVER_INSTALL_TIMER_ENABLE=0",
    "DNS9_SERVER_INSTALL_TIMER_START=0",
    "dns9-server-install-",
    "receipt.json",
    "integrations-index.php.before",
    "index_before_mode",
    "index_before_uid",
    "index_before_gid",
    "keyring_secret_recorded",
    "dns-credentials.key",
    "grp.getgrnam(\"www-data\").gr_gid",
    "os.chown(KEYRING.parent,0,www_gid)",
    "atomic_create(KEYRING,key_bytes,0o640,0,www_gid)",
    "systemctl",
    "daemon-reload",
    "timer_unexpectedly_enabled",
    "timer_unexpectedly_active",
    "installed_inactive",
    "TRACKED_DIRS",
    "DNS9_SERVER_INSTALL_CREATE_DIRS=",
    '"created_dirs":[]',
    "created_dirs.append(directory)",
    "os.chmod(directory,mode)",
    "DNS9_SERVER_INSTALL_INTERNAL_ROLLBACK_OK=1",
]:
    ok(required in install,"installer contract: "+required)

ok('["systemctl","enable"' not in install,"installer never enables timer")
ok('["systemctl","start"' not in install,"installer never starts timer")
ok("Register-ScheduledTask" not in install,"server installer never registers Windows task")
ok("print(key_hex)" not in install,"keyring secret not printed")
ok('"keys":{key_id:key_hex}' in install,"keyring source generated")
ok("DROP TABLE" not in install,"installer has no table drop")
ok("INSERT INTO custom_dns_" not in install,"installer does not seed new DNS data")
ok("DELETE FROM custom_dns_" not in install,"installer does not delete DNS data")

for required in [
    "--receipt",
    "--apply",
    "installed_inactive",
    "DNS9_SERVER_ROLLBACK_DRY_RUN=1",
    "DNS9_SERVER_ROLLBACK_MUTATION=0",
    "DNS9_SERVER_ROLLBACK_PREFLIGHT_OK=1",
    "DNS9_SERVER_ROLLBACK_TIMER_ACTIVE=0",
    "DNS9_SERVER_ROLLBACK_OK=1",
    "index_before_mode",
    "index_before_uid",
    "index_before_gid",
    "keyring_sha256",
    "custom_dns_connection_disabled",
    "TRACKED_DIRS",
    'created_dirs=receipt.get("created_dirs")',
    "tracked_dir_receipt",
    "path.rmdir()",
]:
    ok(required in rollback,"rollback contract: "+required)

ok('["systemctl","enable"' not in rollback,"rollback never enables timer")
ok("Register-ScheduledTask" not in rollback,"rollback has no Windows task registration")
ok("DROP TABLE" not in rollback,"rollback has no table drop")

for required in [
    "--receipt",
    "installed_inactive",
    "DNS9_SERVER_ACCEPT_MIGRATION_OK=1",
    "DNS9_SERVER_ACCEPT_FILES_EXACT=",
    "DNS9_SERVER_ACCEPT_PHP_LINTED=",
    "DNS9_SERVER_ACCEPT_INDEX_OK=1",
    "TRACKED_DIRS",
    "DNS9_SERVER_ACCEPT_TRACKED_DIRS_OK=1",
    "DNS9_SERVER_ACCEPT_KEYRING_OK=1",
    "grp.getgrnam(\"www-data\").gr_gid",
    "DNS9_SERVER_ACCEPT_SYSTEMD_FILES_OK=1",
    "DNS9_SERVER_ACCEPT_TIMER_ENABLED=0",
    "DNS9_SERVER_ACCEPT_TIMER_ACTIVE=0",
    "DNS9_SERVER_ACCEPT_DB_MUTATION=0",
    "DNS9_SERVER_ACCEPT_TIMER_MUTATION=0",
    "DNS9_SERVER_ACCEPT_WINDOWS_TASK_MUTATION=0",
    "DNS9_SERVER_ACCEPT_OK=1",
]:
    ok(required in accept,"post-install acceptance: "+required)

for forbidden in [
    "ALTER TABLE",
    "Register-ScheduledTask",
    "systemctl enable",
    "systemctl start",
    "write_text(",
    "write_bytes(",
    "unlink(",
]:
    ok(forbidden not in accept,"post-install acceptance read-only: "+forbidden)

print(f"DNS9_SERVER_FOUNDATION_SOURCE_ASSERTIONS={n}")
print("DNS9_SERVER_FOUNDATION_SOURCE_TESTS_OK=1")
