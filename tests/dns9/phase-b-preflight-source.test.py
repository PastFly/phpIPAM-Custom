#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns9-phase-b-preflight.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"Phase B preflight exists")
src=TOOL.read_text()
ast.parse(src)
ok(True,"Phase B preflight Python syntax")

for required in [
    "--receipt",
    "installed_inactive",
    "custom_dns_connection_enabled",
    "custom_dns_connection_legacy_collector_null",
    "DNS9_PHASE_B_FOUNDATION_MIGRATION_OK=1",
    "DNS9_PHASE_B_PILOT_JOB=",
    "DNS9_PHASE_B_TIMER_ENABLED=",
    "DNS9_PHASE_B_TIMER_ACTIVE=",
    "DNS9_PHASE_B_KEYRING_BOUNDARY_OK=1",
    "DNS9_PHASE_B_WINDOWS_PACKAGE_MANIFEST=",
    "DNS9_PHASE_B_SERVER_RESOLVES=",
    '(135,"RPC135")',
    '(445,"SMB445")',
    '(5985,"WINRM5985")',
    '(5986,"WINRM5986")',
    'print("DNS9_PHASE_B_"+name+"_OPEN="',
    "DNS9_PHASE_B_KERBEROS_CACHE=",
    "DNS9_PHASE_B_WINRM_HTTP_STATUS=",
    "DNS9_PHASE_B_WINRM_AUTH_NEGOTIATE=",
    "DNS9_PHASE_B_WINRM_AUTH_NTLM=",
    "DNS9_PHASE_B_WINRM_AUTH_KERBEROS=",
    "DNS9_PHASE_B_WINRM_AUTH_BASIC=",
    "DNS9_PHASE_B_PYTHON_PIP_READY=",
    "DNS9_PHASE_B_PYTHON_VENV_READY=",
    "DNS9_PHASE_B_APT_PYTHON3_WINRM=",
    "DNS9_PHASE_B_APT_PYTHON3_REQUESTS_NTLM=",
    "DNS9_PHASE_B_WINRM_CLIENT_BOOTSTRAP=",
    "DNS9_PHASE_B_WINRM_NTLM_ROUTE=",
    "DNS9_PHASE_B_TRANSPORT_SELECTED=",
    "DNS9_PHASE_B_DB_MUTATION=0",
    "DNS9_PHASE_B_FILE_MUTATION=0",
    "DNS9_PHASE_B_SYSTEMD_MUTATION=0",
    "DNS9_PHASE_B_WINDOWS_MUTATION=0",
    "def apt_candidate",
    "def winrm_auth_probe",
    "HTTPConnection(host,5985",
    ""python3-winrm","python3-requests-ntlm"",
    ""winrm_ntlm_message_encryption"",
    "DNS9_PHASE_B_PREFLIGHT_OK=1",
]:
    ok(required in src,"Phase B preflight contract: "+required)

for forbidden in [
    "INSERT INTO custom_dns_",
    "UPDATE custom_dns_",
    "DELETE FROM custom_dns_",
    "ALTER TABLE",
    "DROP TABLE",
    "Register-ScheduledTask",
    "Unregister-ScheduledTask",
    "schtasks",
    '["systemctl","enable"',
    '["systemctl","start"',
    '["systemctl","restart"',
    "write_text(",
    "write_bytes(",
    "unlink(",
    "mkdir(",
    "os.replace(",
    "shutil.copy",
    "Copy-Item",
    "Invoke-Command",
    "Enter-PSSession",
]:
    ok(forbidden not in src,"Phase B preflight has no mutation primitive: "+forbidden)

print(f"DNS9_PHASE_B_PREFLIGHT_SOURCE_ASSERTIONS={n}")
print("DNS9_PHASE_B_PREFLIGHT_SOURCE_TESTS_OK=1")
