#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/dns9-phase-b3-first-cycle.py"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"Phase B3 tool exists")
src=TOOL.read_text()
tree=ast.parse(src)
ok(True,"Phase B3 tool Python syntax")

for required in [
    "--bootstrap-receipt",
    "--apply",
    "installed_disabled",
    "winrm_ntlm_message_encryption",
    "System",
    "CollectorIdentity",
    "pending|0||0|||0",
    "DNS9_PHASE_B3_PILOT_BEFORE=",
    "DNS9_PHASE_B3_TEMP_TASK_TRIGGER_COUNT=0",
    "DNS9_PHASE_B3_SERVER_TIMER_ENABLE=0",
    "DNS9_PHASE_B3_PERMANENT_TASK_ENABLE=0",
    "DNS9_PHASE_B3_DRY_RUN=1",
    "DNS9_PHASE_B3_REMOTE_MUTATION=0",
    "DNS9_PHASE_B3_DB_MUTATION=0",
    "DNS9_PHASE_B3_PREFLIGHT_OK=1",
    "phpIPAM Microsoft DNS Collector DNS9 Pilot Once ",
    "Register-ScheduledTask -TaskName $PilotTask",
    "Start-ScheduledTask -TaskName $PilotTask",
    "Unregister-ScheduledTask -TaskName $PilotTask",
    "New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest",
    "if(@($pilot.Triggers).Count -ne 0)",
    "dns9_phase_b3_temp_task_has_trigger",
    "dns9_phase_b3_permanent_task_not_disabled",
    "dns9_phase_b3_runtime_hash_mismatch",
    "dns9_phase_b3_status_stale",
    "DNS9_PHASE_B3_TEMP_TASK_CLEANED=",
    "DNS9_PHASE_B3_REMOTE_JSON=",
    "collector_state",
    "submitted",
    "test_connection",
    "succeeded",
    "credential_delivered",
    "DNS9_PHASE_B3_PILOT_AFTER=",
    "DNS9_PHASE_B3_PILOT_SUCCEEDED=1",
    "DNS9_PHASE_B3_PERMANENT_TASK_ENABLED=0",
    "DNS9_PHASE_B3_TEMP_TASK_REMOVED=1",
    "DNS9_PHASE_B3_SERVER_TIMER_ENABLED=0",
    "DNS9_PHASE_B3_SERVER_TIMER_ACTIVE=0",
    "DNS9_PHASE_B3_DNS_WRITE=0",
    "dns9-phase-b3-first-cycle-",
    "DNS9_PHASE_B3_OK=1",
]:
    ok(required in src,"Phase B3 contract: "+required)

for forbidden in [
    "Enable-ScheduledTask",
    "Disable-ScheduledTask -TaskName $PermanentTask",
    "Start-ScheduledTask -TaskName $PermanentTask",
    "Unregister-ScheduledTask -TaskName $PermanentTask",
    "UPDATE custom_dns_",
    "INSERT INTO custom_dns_",
    "DELETE FROM custom_dns_",
    "ALTER TABLE",
    "Set-DnsServer",
    "Add-DnsServer",
    "Remove-DnsServer",
    "TOKEN_FILE",
    "control-token.dpapi",
]:
    ok(forbidden not in src,"Phase B3 forbidden: "+forbidden)

ok(src.index("if not args.apply:") < src.index("getpass.getpass"),
   "dry-run returns before WinRM password prompt")
ok(src.index("if not args.apply:") < src.index("protocol(args.server"),
   "dry-run returns before WinRM session")
systemctl_calls=[]
for node in ast.walk(tree):
    if not isinstance(node,ast.Call) or not isinstance(node.func,ast.Attribute):
        continue
    if node.func.attr!="run" or not node.args:
        continue
    argv=node.args[0]
    if not isinstance(argv,(ast.List,ast.Tuple)):
        continue
    values=[]
    valid=True
    for item in argv.elts:
        if not isinstance(item,ast.Constant) or not isinstance(item.value,str):
            valid=False
            break
        values.append(item.value)
    if valid and values and values[0]=="systemctl":
        systemctl_calls.append(values)

ok(sorted(systemctl_calls)==sorted([
    ["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
    ["systemctl","is-active","phpipam-dns-autonomous.timer"],
]), "Phase B3 systemctl calls are read-only status checks only")
ok("shell=True" not in src and "shell = True" not in src,
   "Phase B3 subprocess calls do not use shell execution")

ok("New-ScheduledTaskTrigger" not in src,
   "temporary task is triggerless")
ok("Remove-Item -LiteralPath $Status" not in src,
   "Phase B3 does not delete prior status evidence")

print(f"DNS9_PHASE_B3_SOURCE_ASSERTIONS={n}")
print("DNS9_PHASE_B3_SOURCE_TESTS_OK=1")
