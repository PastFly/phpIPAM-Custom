#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns9-windows-fixture-stage.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"fixture stage tool exists")
src=TOOL.read_text()
ast.parse(src)
ok(True,"fixture stage python syntax")

for required in [
    "--publish","--cleanup",
    "dns9-windows-fixture-",
    "autonomous-windows.test.ps1",
    "Install-PhpIpamDnsCollector.ps1",
    "Invoke-PhpIpamDnsWorkerOnce.ps1",
    "Invoke-PhpIpamDnsPreview.ps1",
    "Invoke-PhpIpamDnsCollectorScheduled.ps1",
    "Get-PhpIpamDnsCollectorStatus.ps1",
    "Uninstall-PhpIpamDnsCollector.ps1",
    "--noproxy","--resolve","ipam.realistbank.ru:443:127.0.0.1",
    "DNS9_WINDOWS_FIXTURE_ENCODED_COMMAND=powershell.exe -NoProfile -EncodedCommand ",
    "DNS9_WINDOWS_FIXTURE_TOKEN_USED=0",
    "DNS9_WINDOWS_FIXTURE_DB_MUTATION=0",
    "DNS9_WINDOWS_FIXTURE_TASK_MUTATION=0",
]:
    ok(required in src,"fixture stage: "+required)

ok("mariadb" not in src.lower(),"fixture stage has no DB access")
ok("phpipam-dns-e2e-pilot-token" not in src,"fixture stage has no pilot token access")
ok("Register-ScheduledTask" not in src,"fixture staging does not register task")
print(f"DNS9_WINDOWS_FIXTURE_STAGE_ASSERTIONS={n}")
print("DNS9_WINDOWS_FIXTURE_STAGE_TESTS_OK=1")
