#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns-e2e-transport-diagnostic.py"

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
    "--publish","--cleanup",
    "bootstrap.ps1","bootstrap.txt","tiny.ps1","tiny.txt",
    "--noproxy","--resolve","ipam.realistbank.ru:443:127.0.0.1",
    "DNS8_TRANSPORT_DIAG_PUBLISH_OK=1",
    "DNS8_TRANSPORT_WINDOWS_COMMAND=",
    "DNS8_TRANSPORT_DIAG_CLEANUP_OK=1",
    "DNS8_TRANSPORT_TOKEN_USED=0",
    "DNS8_TRANSPORT_JOB_MUTATION=0",
]:
    ok(required in src,"contract: "+required)

ok("mariadb" not in src.lower(),"no database access")
ok("TOKEN_PATH" not in src,"no pilot token access")
ok("custom_dns_" not in src,"no DNS tables")
ok("Invoke-Expression" not in src,"no downloaded code execution")
print(f"DNS8_TRANSPORT_DIAG_SOURCE_ASSERTIONS={n}")
print("DNS8_TRANSPORT_DIAG_SOURCE_TESTS_OK=1")
