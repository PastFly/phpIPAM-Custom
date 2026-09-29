#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
COMMON=ROOT/"tools/live/dns9_winrm_common.py"
ACCEPT=ROOT/"tools/live/dns9-winrm-remote-accept.py"
BOOT=ROOT/"tools/live/dns9-winrm-bootstrap.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for path in (COMMON,ACCEPT,BOOT):
    ok(path.is_file(),path.name+" exists")
    ast.parse(path.read_text())
    ok(True,path.name+" python syntax")

common=COMMON.read_text()
accept=ACCEPT.read_text()
boot=BOOT.read_text()

for required in [
    'endpoint=f"http://{server}:5985/wsman"',
    'transport="ntlm"',
    'message_encryption="always"',
    'proxy=None',
    "send_command_input",
    "stdin_data",
    "Get-FileHash -Algorithm SHA256",
    "DNS9_REMOTE_STAGE_CLEAN=1",
]:
    ok(required in common,"common transport contract: "+required)

for forbidden in [
    'transport="basic"',
    'message_encryption="never"',
    "AllowUnencrypted",
    "server_cert_validation='ignore'",
]:
    ok(forbidden not in common,"common insecure transport absent: "+forbidden)

for required in [
    "--username",
    "getpass.getpass",
    "winrm_ntlm_message_encryption",
    "DNS9_WINDOWS_FIXTURE_TASK_CREATED=0",
    "DNS9_WINDOWS_FIXTURE_TOKEN_PRINTED=0",
    "DNS9_WINDOWS_FIXTURE_OK=1",
    "production_collector_present_before",
    "bootstrap_identity_recorded",
    "DNS9_WINRM_ACCEPT_TOKEN_USED=0",
    "DNS9_WINRM_ACCEPT_OK=1",
]:
    ok(required in accept,"WinRM accept contract: "+required)

ok("TOKEN_FILE" not in accept,"acceptance never reads collector token")
ok("control-token.dpapi" not in accept,"acceptance never reads DPAPI token")
ok("Register-ScheduledTask" not in accept,"acceptance has no task registration")
ok("DNS9_WINRM_ACCEPT_PASSWORD" not in accept,"acceptance never prints password marker")

for required in [
    "--acceptance-receipt",
    "--apply",
    "getpass.getpass",
    "/root/phpipam-dns-e2e-pilot-token",
    "st_nlink!=1",
    "winrm_ntlm_message_encryption",
    "stdin_data=(control_token+",
    "[Console]::In.ReadLine()",
    "-PrincipalMode System",
    "-DnsCredentialMode CollectorIdentity",
    "DNS9_AUTONOMOUS_TASK_ENABLED=0",
    "DNS9_AUTONOMOUS_START_NOW=0",
    "DNS9_AUTONOMOUS_TOKEN_PRINTED=0",
    "remote_task_not_disabled",
    "installed_disabled",
    "control_token_recorded",
    "bootstrap_identity_recorded",
    "DNS9_WINRM_BOOTSTRAP_TASK_ENABLED=0",
    "DNS9_WINRM_BOOTSTRAP_TASK_STARTED=0",
    "DNS9_WINRM_BOOTSTRAP_TOKEN_PRINTED=0",
    "DNS9_WINRM_BOOTSTRAP_OK=1",
]:
    ok(required in boot,"WinRM bootstrap contract: "+required)

for forbidden in [
    "print(control_token",
    "print(password",
    "password_recorded",
    '"control_token_recorded":true',
    "-EnableTask",
    "-StartNow",
    "systemctl",
    "ALTER TABLE",
    "UPDATE custom_dns_",
    "INSERT INTO custom_dns_",
    "DELETE FROM custom_dns_",
    "Set-DnsServer",
    "Add-DnsServer",
    "Remove-DnsServer",
]:
    ok(forbidden not in boot,"WinRM bootstrap forbidden: "+forbidden)

print(f"DNS9_WINRM_BOOTSTRAP_SOURCE_ASSERTIONS={n}")
print("DNS9_WINRM_BOOTSTRAP_SOURCE_TESTS_OK=1")
