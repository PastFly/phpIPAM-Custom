#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns-e2e-final-arm.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"tool exists")
src=TOOL.read_text()
ast.parse(src)
ok(True,"python syntax")

for required in [
    "--arm","--accept",
    "/root/phpipam-dns-e2e-final-stage.json",
    "/root/phpipam-dns-e2e-pilot-token",
    "dns-e2e-final-",
    "transport_preflight_ready",
    "armed_for_real_e2e",
    "a4ee3e5987dd15495d7e2419e853d9c8",
    "f57057d6677e1ca7a032aab8b68796ac",
    "33b6fade9323f15aa7b1f56a8a865a9d",
    "92d3a38c7e8415b44f92d35e6fa10e468ce77cbd",
    "f96f1f378cc6dd8217399acfda4c98c79ab5c428",
    "state='pending'",
    "attempt_count=0",
    "credential_delivered_at IS NULL",
    "new_expires = now + 300",
    "SELECT ROW_COUNT()",
    "DNS8_FINAL_ARM_SAME_STAGE=1",
    "DNS8_FINAL_ARM_TOKEN_PRINTED=0",
    "token_dir.mkdir(mode=0o700)",
    "os.chown(token_dir, www.pw_uid, www.pw_gid)",
    "state[\"token_dir\"] = str(token_dir)",
    "DNS8_FINAL_ACCEPT_ERROR=token_dir_boundary",
    "DNS8_FINAL_ACCEPT=",
    "audit_count == 1",
    "raise SystemExit(2)",
]:
    ok(required in src,"contract: "+required)

ok("secrets.token_hex" not in src,"arm does not create a new transfer path")
ok("DROP TABLE" not in src,"no schema teardown")
ok("ScheduledTask" not in src,"no scheduling primitive")
ok("Set-DnsServer" not in src and "Add-DnsServer" not in src and "Remove-DnsServer" not in src,"no DNS write cmdlets")
ok("print(token)" not in src,"token not printed")
ok("token_secret_recorded" in src and "False" in src,"receipt excludes token secret")
print(f"DNS8_FINAL_ARM_SOURCE_ASSERTIONS={n}")
print("DNS8_FINAL_ARM_SOURCE_TESTS_OK=1")
