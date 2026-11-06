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
    "DNS9_PHASE_B3_TARGET_JOB_ID=",
    "DNS9_PHASE_B3_TEMP_TASK_TRIGGER_COUNT=1",
    "DNS9_PHASE_B3_TEMP_TASK_TRIGGER_ENABLED=1",
    "DNS9_PHASE_B3_TEMP_TASK_TRIGGER_FUTURE=1",
    "DNS9_PHASE_B3_TEMP_TASK_TRIGGER_AUTORUN_DAYS=3650",
    "DNS9_PHASE_B3_TEMP_TASK_ALLOW_ON_DEMAND=1",
    "temp_task_trigger_future",
    "temp_task_allow_on_demand",
    "dns9_phase_b3_temp_task_trigger_not_far_future",
    "dns9_phase_b3_temp_task_trigger_disabled",
    "dns9_phase_b3_temp_task_trigger_count",
    "dns9_phase_b3_temp_task_disabled",
    "dns9_phase_b3_on_demand_disabled",
    "Register-ScheduledTask -TaskName $PilotTask -Action $action -Trigger $trigger",
    "$trigger.Enabled=$true",
    "$trigger=New-ScheduledTaskTrigger -Once -At ((Get-Date).AddDays(3650))",
    "DNS9_PHASE_B3_SERVER_TIMER_ENABLE=0",
    "DNS9_PHASE_B3_PERMANENT_TASK_ENABLE=0",
    "DNS9_PHASE_B3_DRY_RUN=1",
    "DNS9_PHASE_B3_REMOTE_MUTATION=0",
    "DNS9_PHASE_B3_DB_MUTATION=0",
    "DNS9_PHASE_B3_PREFLIGHT_OK=1",
    "phpIPAM Microsoft DNS Collector DNS9 Pilot Once ",
    "Register-ScheduledTask -TaskName $PilotTask",
    "schtasks.exe /Run /TN $PilotTask",
    "DNS9_PHASE_B3_START_METHOD=schtasks_run",
    "dns9_phase_b3_schtasks_run_failed_",
    "Unregister-ScheduledTask -TaskName $PilotTask",
    "New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest",
    "dns9_phase_b3_permanent_task_not_disabled",
    "dns9_phase_b3_runtime_hash_mismatch",
    "dns9_phase_b3_status_missing_or_stale",
    "dns9_phase_b3_task_start_unconfirmed",
    "dns9_phase_b3_collector_error_",
    "DNS9_PHASE_B3_COLLECTOR_ERROR=",
    "DNS9_PHASE_B3_FRESH_STATUS_OBSERVED=",
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
    "stage_bytes(p,control_path,script.encode(\"utf-8\"))",
    "DNS9_PHASE_B3_CONTROL_STAGE_OK=1",
    "DNS9_PHASE_B3_CONTROL_STAGE_CLEANED=1",
    "DNS9_PHASE_B3_CONTROL_STAGE_CLEANED=0",
    "rc,out,err=run(",
    "p,\"powershell.exe\"",
    "\"-File\",control_path",
    "remove_tree(p,control_dir)",
    "phpipam-dns9-phase-b3-{suffix}",
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
    "run_ps",
    "-EncodedCommand",
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

ok("New-ScheduledTaskTrigger -Once -At ((Get-Date).AddDays(3650))" in src,
   "temporary task has one explicit far-future trigger")
ok("$trigger.Enabled=$true" in src,
   "temporary task trigger is explicitly enabled for on-demand runnable task state")
ok("if([string]$pilot.State -eq 'Disabled')" in src,
   "temporary task rejects Disabled task state before on-demand start")
ok("pilot.Settings.AllowDemandStart" in src,
   "temporary task requires CIM AllowDemandStart")
ok("pilot.Settings.AllowStartOnDemand" not in src,
   "Phase B3 does not confuse XML AllowStartOnDemand with CIM AllowDemandStart")
ok("-DisallowDemandStart" not in src,
   "temporary task settings never disable on-demand execution")
ok("Start-ScheduledTask -TaskName $PilotTask" not in src,
   "Phase B3 avoids Start-ScheduledTask on production Server 2016")
ok("& schtasks.exe /Run /TN $PilotTask | Out-Null" in src,
   "Phase B3 uses schtasks on-demand run path")
ok("if($LASTEXITCODE -ne 0)" in src,
   "Phase B3 checks schtasks process exit code")
ok("Register-ScheduledTask -TaskName $PilotTask -Action $action -Trigger $trigger" in src,
   "temporary task registration uses the explicit far-future trigger")
ok("$trigger.Enabled=$false" not in src,
   "Phase B3 no longer uses disabled trigger that blocked on-demand execution on Server 2016")
ok(src.index("if($freshStatus -and [string]$status.state -eq 'error')") < src.index("if(-not $observedRun -and -not $freshStatus)"), "fresh error precedes LastRunTime guard")
ok("Remove-Item -LiteralPath $Status" not in src,
   "Phase B3 does not delete prior status evidence")

ok(src.index('stage_bytes(p,control_path,script.encode("utf-8"))') < src.index('rc,out,err=run('),
   "Phase B3 control script is staged before execution")
ok(src.index('"DNS9_PHASE_B3_CONTROL_STAGE_OK=1"') < src.index('rc,out,err=run('),
   "Phase B3 reports staged control script before execution")
ok(src.index('remove_tree(p,control_dir)') > src.index('rc,out,err=run('),
   "Phase B3 cleans control staging after execution")

ok("ap.add_argument(\"--attempt-sha\")" in src,
   "live attempt guard binds to exact SHA")
ok(src.index('password=getpass.getpass') < src.index('reserve_attempt(Path('),
   "attempt guard is created only after password prompt")
ok("existing_attempt_requires_review" in src,
   "old unresolved attempts are never silently cleared")
print(f"DNS9_PHASE_B3_SOURCE_ASSERTIONS={n}")
print("DNS9_PHASE_B3_SOURCE_TESTS_OK=1")
