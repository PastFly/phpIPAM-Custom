#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns9-phase-b3-diagnose.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"diagnostic tool exists")
src=TOOL.read_text()
ast.parse(src)
ok(True,"diagnostic Python syntax")

for required in [
    "--username","--suffix","WinRM diagnostic password:",
    "Microsoft-Windows-TaskScheduler/Operational",
    "Get-WinEvent -ListLog","Get-WinEvent -FilterHashtable",
    "status.json","status_epoch_consistent","status_error_generic","permanent_task_state","permanent_allow_demand_start",
    "operational_event_count","operational_events",
    "DNS9_PHASE_B3_DIAG_REMOTE_MUTATION=0",
    "DNS9_PHASE_B3_DIAG_DB_MUTATION=0",
    "DNS9_PHASE_B3_DIAG_DNS_WRITE=0",
    "DNS9_PHASE_B3_DIAG_OK=1",
]:
    ok(required in src,"diagnostic contract: "+required)

for forbidden in [
    "Register-ScheduledTask","Start-ScheduledTask","Unregister-ScheduledTask",
    "Set-ScheduledTask","Enable-ScheduledTask","Disable-ScheduledTask",
    "schtasks.exe /Run","schtasks.exe /Create","schtasks.exe /Delete",
    "Remove-Item","Set-Content","Add-Content","Out-File",
    "UPDATE custom_dns_","INSERT INTO custom_dns_","DELETE FROM custom_dns_",
    "Set-DnsServer","Add-DnsServer","Remove-DnsServer",
]:
    ok(forbidden not in src,"diagnostic forbidden: "+forbidden)

ok("result_payload" not in src,"diagnostic never exports result payload")
ok("control-token.dpapi" not in src,"diagnostic never reads collector token")
print(f"DNS9_PHASE_B3_DIAG_SOURCE_ASSERTIONS={n}")
print("DNS9_PHASE_B3_DIAG_SOURCE_TESTS_OK=1")
