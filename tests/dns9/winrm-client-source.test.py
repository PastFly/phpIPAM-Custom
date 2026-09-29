#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns9-winrm-client-install.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"WinRM client installer exists")
src=TOOL.read_text()
ast.parse(src)
ok(True,"WinRM client installer Python syntax")

for required in [
    "--apply",
    "python3-winrm",
    "python3-requests-ntlm",
    "python3-xmltodict",
    "apt-get",
    "apt-cache",
    "dpkg-query",
    '["apt-get","-s","install"',
    "--no-install-recommends",
    "message_encryption",
    "env_vars",
    "DNS9_WINRM_CLIENT_DRY_RUN=1",
    "DNS9_WINRM_CLIENT_PACKAGE_MUTATION=0",
    "DNS9_WINRM_CLIENT_DB_MUTATION=0",
    "DNS9_WINRM_CLIENT_SYSTEMD_MUTATION=0",
    "DNS9_WINRM_CLIENT_WINDOWS_MUTATION=0",
    "DNS9_WINRM_CLIENT_PREFLIGHT_OK=1",
    "dns9-winrm-client-install-",
    "receipt.json",
    "rollback_after_failure",
    '["apt-get","remove"',
    "DNS9_WINRM_CLIENT_INTERNAL_ROLLBACK_OK=1",
    "DNS9_WINRM_CLIENT_IMPORTS_OK=1",
    "DNS9_WINRM_CLIENT_OK=1",
]:
    ok(required in src,"WinRM client installer contract: "+required)

ok("'proxy' in s.parameters" not in src,"client installer does not require newer pywinrm proxy parameter")
for forbidden in [
    "mariadb",
    "systemctl",
    "Register-ScheduledTask",
    "Invoke-Command",
    "Set-DnsServer",
    "Add-DnsServer",
    "Remove-DnsServer",
    '["apt-get","upgrade"',
    '["apt-get","dist-upgrade"',
    '["apt-get","autoremove"',
]:
    ok(forbidden not in src,"WinRM client installer forbidden: "+forbidden)

print(f"DNS9_WINRM_CLIENT_SOURCE_ASSERTIONS={n}")
print("DNS9_WINRM_CLIENT_SOURCE_TESTS_OK=1")
