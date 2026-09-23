#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
INSTALL=ROOT/"tools/live/dns-e2e-pilot-install.py"
CLEANUP=ROOT/"tools/live/dns-e2e-pilot-cleanup.py"
n=0
def ok(v,label):
    global n
    n+=1
    if not v: raise AssertionError(label)

ok(INSTALL.is_file(),"installer exists")
ok(CLEANUP.is_file(),"cleanup exists")
src=INSTALL.read_text()
clean=CLEANUP.read_text()

for required in [
    "custom_dns_config_guard","custom_dns_collectors","custom_dns_control_jobs",
    "credential_delivered_at","collector_identity","test_connection",
    "srv-dc01.rbank.local","dnsctl1.","phpipam-custom|dns-control|v1|",
    "DNS_E2E_PILOT_DRY_RUN=1","DNS_E2E_PILOT_MUTATION=0",
    "DNS_E2E_PILOT_INSTALL_OK=1","TOKEN_PATH","receipt.json",
]:
    ok(required in src,"installer contract: "+required)

ok("print(token)" not in src and "TOKEN_VALUE" not in src,"token not intentionally printed")
ok('print("DNS_E2E_PILOT_TOKEN_PATH="' in src,"only token path printed")
ok("schema_not_empty" in src and "target_exists" in src and "token_path_exists" in src,"create-only boundaries")
ok("php","-l"" in src or '["php","-l"' in src,"installed PHP linted")
ok("DROP TABLE IF EXISTS" in src,"installer rollback schema cleanup")
ok("rollback_incomplete" in src,"rollback errors fail closed")

for required in [
    "non_pilot_data_present","file_drift","DNS_E2E_CLEANUP_DRY_RUN=1",
    "DNS_E2E_CLEANUP_MUTATION=0","DNS_E2E_CLEANUP_APPLY_OK=1",
]:
    ok(required in clean,"cleanup contract: "+required)

ok("DROP TABLE custom_dns_control_jobs" in clean,"cleanup drops exact schema")
ok("TOKEN_PATH.unlink()" in clean,"cleanup removes pilot token")
print(f"DNS8_E2E_PILOT_SOURCE_ASSERTIONS={n}")
print("DNS8_E2E_PILOT_SOURCE_TESTS_OK=1")
