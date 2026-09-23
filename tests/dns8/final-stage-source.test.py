#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns-e2e-final-stage.py"

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
    "--publish","--verify","--cleanup",
    "dns-e2e-final-",
    "Invoke-PhpIpamDnsE2EPilotOnce.ps1",
    "Invoke-PhpIpamDnsWorkerOnce.ps1",
    "Invoke-PhpIpamDnsPreview.ps1",
    "92d3a38c7e8415b44f92d35e6fa10e468ce77cbd",
    "f96f1f378cc6dd8217399acfda4c98c79ab5c428",
    "--noproxy","--resolve","ipam.realistbank.ru:443:127.0.0.1",
    "-TransportPreflightOnly",
    "DNS8_FINAL_STAGE_PUBLISH_OK=1",
    "DNS8_FINAL_STAGE_VERIFY_OK=1",
    "DNS8_FINAL_STAGE_READY_FOR_ARM=1",
    "DNS8_FINAL_STAGE_TOKEN_PRESENT=0",
    "DNS8_FINAL_STAGE_JOB_MUTATION=0",
]:
    ok(required in src,"contract: "+required)

ok("mariadb" not in src.lower(),"no DB access")
ok("TOKEN_PATH" not in src,"no token access")
ok("custom_dns_" not in src,"no custom DNS tables")
ok("expires_at" not in src,"no job rearm")
ok("token.php" not in src,"no token endpoint")
print(f"DNS8_FINAL_STAGE_SOURCE_ASSERTIONS={n}")
print("DNS8_FINAL_STAGE_SOURCE_TESTS_OK=1")
