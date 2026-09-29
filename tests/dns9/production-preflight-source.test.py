#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns9-production-preflight.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"production preflight exists")
src=TOOL.read_text()
ast.parse(src)
ok(True,"production preflight Python syntax")

for required in [
    "DNS9_PRODUCTION_PREFLIGHT_OK=1",
    "DNS9_PREFLIGHT_DB_MUTATION=0",
    "DNS9_PREFLIGHT_FILE_MUTATION=0",
    "DNS9_PREFLIGHT_SYSTEMD_MUTATION=0",
    "DNS9_PREFLIGHT_WINDOWS_TASK_MUTATION=0",
    "custom_dns_connection_disabled",
    "custom_dns_connection_enabled",
    "custom_dns_connection_legacy_collector_null",
    "PILOT_CONNECTION_ID",
    "PILOT_COLLECTOR_ID",
    "PILOT_JOB_ID",
    "pilot_job_consumed_or_drifted",
    "php -l",
    "systemctl",
    "is-enabled",
    "dns-credentials.key",
    "sodium_crypto_aead_xchacha20poly1305_ietf_encrypt",
    "DNS9_PREFLIGHT_WINDOWS_PACKAGE_MANIFEST=",
    "PHPIPAM_CUSTOM_DNS1_CONFIG_INDEX_APPEND_V1",
]:
    ok(required in src,"preflight contract: "+required)

for forbidden in [
    "ALTER TABLE custom_dns_connections",
    "Register-ScheduledTask",
    "systemctl enable",
    "systemctl start",
    "systemctl daemon-reload",
    "shutil.copy",
    "copy2(",
    "write_bytes(",
    "write_text(",
    "unlink(",
    "mkdir(",
]:
    ok(forbidden not in src,"preflight no mutation primitive: "+forbidden)

ok("UPDATE custom_dns_" not in src,"preflight has no custom DNS UPDATE")
ok("DELETE FROM custom_dns_" not in src,"preflight has no custom DNS DELETE")
ok("INSERT INTO custom_dns_" not in src,"preflight has no custom DNS INSERT")
ok("DROP TABLE" in src,"preflight checks migration text for forbidden DROP TABLE")
ok('"DROP TABLE" in migration' in src,"DROP TABLE appears only as migration guard")
print(f"DNS9_PRODUCTION_PREFLIGHT_SOURCE_ASSERTIONS={n}")
print("DNS9_PRODUCTION_PREFLIGHT_SOURCE_TESTS_OK=1")
