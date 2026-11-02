#!/usr/bin/env python3
import argparse
import getpass
import hashlib
import json
import os
import re
import stat
import subprocess
import time
from pathlib import Path

from dns9_winrm_common import protocol, run, stage_bytes, remove_tree, json_line, ps_quote, ensure_ntlm_md4_runtime

BACKUP_ROOT=Path("/var/backups/phpipam-custom")
DB="phpipam"
SERVER="srv-dc01.rbank.local"
PERMANENT_TASK="phpIPAM Microsoft DNS Collector"
PILOT_PREFIX="phpIPAM Microsoft DNS Collector DNS9 Pilot Once "
INSTALL_ROOT=r"C:\ProgramData\phpIPAM\DnsCollector"
PILOT_JOB_ID="a4ee3e5987dd15495d7e2419e853d9c8"

def sql(text: str)->str:
    p=subprocess.run(
        ["mariadb","--batch","--skip-column-names",DB],
        input=text,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False
    )
    if p.returncode!=0:
        raise RuntimeError("database_command_failed:"+p.stderr.strip())
    return p.stdout

def one(query: str)->str:
    rows=[line for line in sql(query).splitlines() if line.strip()]
    if len(rows)!=1:
        raise RuntimeError("database_row_count_invalid")
    return rows[0]

def job_state()->str:
    return one(f"""
SELECT CONCAT(
 state,'|',attempt_count,'|',IFNULL(lease_id,''),'|',
 IFNULL(credential_delivered_at,0),'|',IFNULL(result_code,''),'|',
 IFNULL(result_payload,''),'|',IFNULL(finished_at,0)
)
FROM custom_dns_control_jobs WHERE id='{PILOT_JOB_ID}';
""")

def timer_enabled()->bool:
    return subprocess.run(
        ["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
    ).returncode==0

def timer_active()->bool:
    return subprocess.run(
        ["systemctl","is-active","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
    ).returncode==0

def receipt(path: Path,server: str)->dict:
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("bootstrap_receipt_boundary")
    st=path.lstat()
    if st.st_uid!=0 or st.st_gid!=0 or stat.S_IMODE(st.st_mode)!=0o600 or st.st_nlink!=1:
        raise RuntimeError("bootstrap_receipt_metadata")
    doc=json.loads(path.read_text())
    if doc.get("stage")!="installed_disabled" or doc.get("server")!=server.lower():
        raise RuntimeError("bootstrap_receipt_state")
    if doc.get("transport")!="winrm_ntlm_message_encryption":
        raise RuntimeError("bootstrap_receipt_transport")
    if doc.get("principal_mode")!="System" or doc.get("dns_credential_mode")!="CollectorIdentity":
        raise RuntimeError("bootstrap_receipt_mode")
    if doc.get("task_enabled") is not False or doc.get("task_started") is not False:
        raise RuntimeError("bootstrap_receipt_task_state")
    if doc.get("control_token_recorded") is not False or doc.get("bootstrap_identity_recorded") is not False:
        raise RuntimeError("bootstrap_receipt_secret_boundary")
    package=doc.get("package")
    if not isinstance(package,dict) or len(package)!=6:
        raise RuntimeError("bootstrap_receipt_package")
    return doc

def expected_runtime_hashes(doc: dict)->dict:
    package=doc["package"]
    names=[
        "Invoke-PhpIpamDnsWorkerOnce.ps1",
        "Invoke-PhpIpamDnsPreview.ps1",
        "Invoke-PhpIpamDnsCollectorScheduled.ps1",
    ]
    result={}
    for name in names:
        key="collectors/windows/dns/"+name
        item=package.get(key)
        if not isinstance(item,dict) or not re.fullmatch(r"[0-9a-f]{64}",str(item.get("sha256",""))):
            raise RuntimeError("bootstrap_receipt_package")
        result[name]=item["sha256"]
    return result

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--bootstrap-receipt",required=True)
    ap.add_argument("--username")
    ap.add_argument("--server",default=SERVER)
    ap.add_argument("--apply",action="store_true")
    ap.add_argument("--attempt-sha")
    args=ap.parse_args()

    if os.geteuid()!=0:
        raise SystemExit("DNS9_PHASE_B3_ERROR=root_required")

    md4_mode=ensure_ntlm_md4_runtime()
    print("DNS9_PHASE_B3_MD4_PROVIDER="+md4_mode)

    doc=receipt(Path(args.bootstrap_receipt),args.server)
    hashes=expected_runtime_hashes(doc)
    print("DNS9_PHASE_B3_BOOTSTRAP_RECEIPT_OK=1")
    print("DNS9_PHASE_B3_SERVER="+args.server.lower())
    print("DNS9_PHASE_B3_PERMANENT_TASK_EXPECTED_DISABLED=1")
    print("DNS9_PHASE_B3_TEMP_TASK_TRIGGER_COUNT=1")
    print("DNS9_PHASE_B3_TEMP_TASK_TRIGGER_ENABLED=1")
    print("DNS9_PHASE_B3_TEMP_TASK_TRIGGER_FUTURE=1")
    print("DNS9_PHASE_B3_TEMP_TASK_TRIGGER_AUTORUN_DAYS=3650")
    print("DNS9_PHASE_B3_SERVER_TIMER_ENABLE=0")
    print("DNS9_PHASE_B3_PERMANENT_TASK_ENABLE=0")

    before=job_state()
    print("DNS9_PHASE_B3_PILOT_BEFORE="+before)
    if before!="pending|0||0|||0":
        raise SystemExit("DNS9_PHASE_B3_ERROR=pilot_job_not_pristine")
    if timer_enabled() or timer_active():
        raise SystemExit("DNS9_PHASE_B3_ERROR=server_timer_active")
    print("DNS9_PHASE_B3_SERVER_TIMER_ENABLED=0")
    print("DNS9_PHASE_B3_SERVER_TIMER_ACTIVE=0")

    if not args.apply:
        print("DNS9_PHASE_B3_DRY_RUN=1")
        print("DNS9_PHASE_B3_REMOTE_MUTATION=0")
        print("DNS9_PHASE_B3_DB_MUTATION=0")
        print("DNS9_PHASE_B3_PREFLIGHT_OK=1")
        return

    if not args.username:
        raise SystemExit("DNS9_PHASE_B3_ERROR=username_required")
    password=getpass.getpass("WinRM bootstrap password: ")
    if not password:
        raise SystemExit("DNS9_PHASE_B3_ERROR=password_empty")

    # Never reserve a live attempt before the interactive credential prompt.
    # Any prior attempt marker, including one from another bundle SHA, is
    # ambiguous and must be reviewed rather than silently removed.
    if args.attempt_sha:
        if not re.fullmatch(r"[0-9a-f]{40}",args.attempt_sha):
            raise SystemExit("DNS9_PHASE_B3_ERROR=attempt_sha_invalid")
        guard_root=Path("/var/lib/phpipam-custom")
        previous=list(guard_root.glob("dns9-b3-live-attempt-*"))
        if previous:
            raise SystemExit("DNS9_PHASE_B3_ERROR=existing_attempt_requires_review")
        guard=guard_root/("dns9-b3-live-attempt-"+args.attempt_sha)
        fd=os.open(guard,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
        try:
            os.write(fd,(args.attempt_sha+"\\n").encode("ascii"))
            os.fsync(fd)
        finally:
            os.close(fd)
        print("DNS9_B3_ONE_SHOT_GUARD_CREATED=1")
    p=protocol(args.server,args.username,password)
    password=""
    suffix=hashlib.sha256((str(time.time_ns())+str(os.getpid())).encode("ascii")).hexdigest()[:12]
    temp_task=PILOT_PREFIX+suffix
    expected_json=json.dumps(hashes,sort_keys=True,separators=(',',':'))

    script=f"""
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$PermanentTask={ps_quote(PERMANENT_TASK)}
$PilotTask={ps_quote(temp_task)}
$Root={ps_quote(INSTALL_ROOT)}
$Runner=Join-Path $Root 'Invoke-PhpIpamDnsCollectorScheduled.ps1'
$Status=Join-Path $Root 'status.json'
$ExpectedHashes=ConvertFrom-Json -InputObject {ps_quote(expected_json)}
$created=$false
$started=$false
$cleanupOk=$false
try {{
    $perm=Get-ScheduledTask -TaskName $PermanentTask -ErrorAction Stop
    if([string]$perm.State -ne 'Disabled'){{throw 'dns9_phase_b3_permanent_task_not_disabled'}}
    if(Get-ScheduledTask | Where-Object {{$_.TaskName -like ({ps_quote(PILOT_PREFIX+'*')})}}){{
        throw 'dns9_phase_b3_temp_task_exists'
    }}
    if(-not (Test-Path -LiteralPath $Runner -PathType Leaf)){{throw 'dns9_phase_b3_runner_missing'}}
    foreach($name in @('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')){{
        $actual=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $Root $name)).Hash.ToLowerInvariant()
        $expected=[string]$ExpectedHashes.$name
        if($actual -cne $expected){{throw 'dns9_phase_b3_runtime_hash_mismatch'}}
    }}

    $action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$Runner+'"')
    $principal=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 3) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    $trigger=New-ScheduledTaskTrigger -Once -At ((Get-Date).AddDays(3650))
    $trigger.Enabled=$true
    Register-ScheduledTask -TaskName $PilotTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings | Out-Null
    $created=$true

    $pilot=Get-ScheduledTask -TaskName $PilotTask -ErrorAction Stop
    $pilotTriggers=@($pilot.Triggers)
    if($pilotTriggers.Count -ne 1){{throw 'dns9_phase_b3_temp_task_trigger_count'}}
    if(-not [bool]$pilotTriggers[0].Enabled){{throw 'dns9_phase_b3_temp_task_trigger_disabled'}}
    $triggerStart=[DateTime]$pilotTriggers[0].StartBoundary
    if($triggerStart -lt (Get-Date).AddDays(3600)){{throw 'dns9_phase_b3_temp_task_trigger_not_far_future'}}
    if([string]$pilot.State -eq 'Disabled'){{throw 'dns9_phase_b3_temp_task_disabled'}}
    if(-not [bool]$pilot.Settings.AllowDemandStart){{throw 'dns9_phase_b3_on_demand_disabled'}}
    Write-Output ('DNS9_PHASE_B3_TEMP_TASK_PRESTART_STATE='+[string]$pilot.State)
    Write-Output 'DNS9_PHASE_B3_TEMP_TASK_TRIGGER_ENABLED=1'
    Write-Output 'DNS9_PHASE_B3_TEMP_TASK_TRIGGER_FAR_FUTURE=1'
    Write-Output 'DNS9_PHASE_B3_TEMP_TASK_ALLOW_ON_DEMAND=1'

    $startedAt=Get-Date
    $startedEpoch=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    & schtasks.exe /Run /TN $PilotTask | Out-Null
    if($LASTEXITCODE -ne 0){{throw ('dns9_phase_b3_schtasks_run_failed_'+[string]$LASTEXITCODE)}}
    Write-Output 'DNS9_PHASE_B3_START_METHOD=schtasks_run'
    $started=$true
    $deadline=(Get-Date).AddSeconds(150)
    $observedRun=$false
    do {{
        Start-Sleep -Milliseconds 500
        $pilot=Get-ScheduledTask -TaskName $PilotTask -ErrorAction Stop
        $info=Get-ScheduledTaskInfo -TaskName $PilotTask -ErrorAction Stop
        if($info.LastRunTime -ge $startedAt.AddSeconds(-2)){{$observedRun=$true}}
        if($observedRun -and [string]$pilot.State -ne 'Running'){{break}}
    }} while((Get-Date) -lt $deadline)

    # LastRunTime can lag or be unavailable on this Windows Server 2016
    # ScheduledTasks stack. Prefer a freshly written, redacted runner receipt.
    $freshStatus=$false
    $status=$null
    if(Test-Path -LiteralPath $Status -PathType Leaf){{
        $statusItem=Get-Item -LiteralPath $Status -ErrorAction Stop
        if($statusItem.LastWriteTimeUtc -ge $startedAt.ToUniversalTime().AddSeconds(-2)){{
            $status=Get-Content -LiteralPath $Status -Raw | ConvertFrom-Json -ErrorAction Stop
            $freshStatus=([int64]$status.updated_at -ge $startedEpoch)
        }}
    }}
    Write-Output ('DNS9_PHASE_B3_LASTRUN_OBSERVED='+([int][bool]$observedRun))
    Write-Output ('DNS9_PHASE_B3_FRESH_STATUS_OBSERVED='+([int][bool]$freshStatus))
    if([string]$pilot.State -eq 'Running'){{throw 'dns9_phase_b3_task_timeout'}}
    if($freshStatus -and [string]$status.state -eq 'error'){{
        # Do not expose raw exception text or any credential material.
        $safeCode=[string]$status.result_code
        if($safeCode -notmatch '^[a-z0-9_]{{1,80}}$'){{$safeCode='invalid_code'}}
        throw ('dns9_phase_b3_collector_error_'+$safeCode)
    }}
    if(-not $observedRun -and -not $freshStatus){{throw 'dns9_phase_b3_task_start_unconfirmed'}}
    if([int64]$info.LastTaskResult -ne 0){{throw ('dns9_phase_b3_task_result_'+[string]$info.LastTaskResult)}}
    if(-not $freshStatus){{throw 'dns9_phase_b3_status_missing_or_stale'}}
    $perm=Get-ScheduledTask -TaskName $PermanentTask -ErrorAction Stop
    if([string]$perm.State -ne 'Disabled'){{throw 'dns9_phase_b3_permanent_task_changed'}}

    [PSCustomObject][ordered]@{{
        permanent_task_state=[string]$perm.State
        temp_task_name=$PilotTask
        temp_task_trigger_count=@($pilot.Triggers).Count
        temp_task_trigger_enabled=[bool]$pilot.Triggers[0].Enabled
        temp_task_trigger_future=([DateTime]$pilot.Triggers[0].StartBoundary -gt (Get-Date).AddDays(3600))
        temp_task_allow_on_demand=[bool]$pilot.Settings.AllowDemandStart
        temp_task_last_result=[int64]$info.LastTaskResult
        collector_state=[string]$status.state
        job_id=$status.job_id
        job_type=$status.job_type
        result_ok=$status.result_ok
        result_code=$status.result_code
        collector_updated_at=[int64]$status.updated_at
    }} | ConvertTo-Json -Compress | ForEach-Object {{Write-Output ('DNS9_PHASE_B3_REMOTE_JSON='+$_)}}
}}
finally {{
    if($created){{
        try{{Unregister-ScheduledTask -TaskName $PilotTask -Confirm:$false -ErrorAction Stop}}catch{{}}
    }}
    $left=Get-ScheduledTask -TaskName $PilotTask -ErrorAction SilentlyContinue
    $cleanupOk=($null -eq $left)
    Write-Output ('DNS9_PHASE_B3_TEMP_TASK_CLEANED='+([int][bool]$cleanupOk))
    Write-Output ('DNS9_PHASE_B3_TEMP_TASK_STARTED='+([int][bool]$started))
}}
"""
    control_dir=rf"C:\Windows\Temp\phpipam-dns9-phase-b3-{suffix}"
    control_path=control_dir+r"\first-cycle.ps1"
    remote_error=None
    out=""
    err=""
    try:
        stage_bytes(p,control_path,script.encode("utf-8"))
        print("DNS9_PHASE_B3_CONTROL_STAGE_OK=1")
        rc,out,err=run(
            p,"powershell.exe",
            ["-NoProfile","-NonInteractive","-ExecutionPolicy","Bypass",
             "-File",control_path]
        )
        print(out,end="" if out.endswith("\n") else "\n")
        if rc!=0:
            remote_error=RuntimeError("phase_b3_remote_failed_redacted")
        elif "DNS9_PHASE_B3_TEMP_TASK_CLEANED=1" not in out:
            remote_error=RuntimeError("phase_b3_temp_task_cleanup_failed")
    except Exception as exc:
        remote_error=RuntimeError("phase_b3_transport_failed_redacted")

    try:
        remove_tree(p,control_dir)
        print("DNS9_PHASE_B3_CONTROL_STAGE_CLEANED=1")
    except Exception as cleanup_exc:
        print("DNS9_PHASE_B3_CONTROL_STAGE_CLEANED=0")
        if remote_error is None:
            remote_error=RuntimeError("phase_b3_control_stage_cleanup_failed_redacted")
        else:
            remote_error=RuntimeError(str(remote_error)+"; phase_b3_control_stage_cleanup_failed_redacted")

    if remote_error is not None:
        raise remote_error

    remote=json_line(out,"DNS9_PHASE_B3_REMOTE_JSON=")
    if remote.get("permanent_task_state")!="Disabled":
        raise RuntimeError("phase_b3_permanent_task_changed")
    if int(remote.get("temp_task_trigger_count",-1))!=1:
        raise RuntimeError("phase_b3_temp_task_trigger_count")
    if remote.get("temp_task_trigger_enabled") is not True:
        raise RuntimeError("phase_b3_temp_task_trigger_disabled")
    if remote.get("temp_task_trigger_future") is not True:
        raise RuntimeError("phase_b3_temp_task_trigger_not_far_future")
    if remote.get("temp_task_allow_on_demand") is not True:
        raise RuntimeError("phase_b3_on_demand_disabled")
    if int(remote.get("temp_task_last_result",-1))!=0:
        raise RuntimeError("phase_b3_temp_task_result")
    if remote.get("collector_state")!="submitted":
        raise RuntimeError("phase_b3_collector_state")
    if remote.get("job_id")!=PILOT_JOB_ID or remote.get("job_type")!="test_connection":
        raise RuntimeError("phase_b3_job_identity")
    if remote.get("result_ok") is not True or remote.get("result_code")!="ok":
        raise RuntimeError("phase_b3_job_result")

    after=job_state()
    print("DNS9_PHASE_B3_PILOT_AFTER="+after)
    parts=after.split("|")
    if len(parts)!=7:
        raise RuntimeError("phase_b3_job_state_shape")
    if parts[0]!="succeeded" or parts[1]!="1" or parts[2]!="" or parts[3]=="0" or parts[4]!="ok" or parts[5]!="" or parts[6]=="0":
        raise RuntimeError("phase_b3_job_state_invalid")
    if timer_enabled() or timer_active():
        raise RuntimeError("phase_b3_server_timer_changed")

    stamp=time.strftime("%Y%m%d-%H%M%S",time.gmtime())
    receipt_dir=BACKUP_ROOT/("dns9-phase-b3-first-cycle-"+stamp)
    receipt_dir.mkdir(parents=True,mode=0o700,exist_ok=False)
    receipt_path=receipt_dir/"receipt.json"
    result={
        "stage":"first_cycle_succeeded",
        "created_at":int(time.time()),
        "server":args.server.lower(),
        "pilot_job_id":PILOT_JOB_ID,
        "pilot_job_type":"test_connection",
        "pilot_job_state":"succeeded",
        "pilot_attempt_count":1,
        "pilot_result_code":"ok",
        "credential_delivered":True,
        "permanent_task_state":"Disabled",
        "temporary_task_removed":True,
        "temporary_task_trigger_count":1,
        "temporary_task_trigger_enabled":True,
        "temporary_task_trigger_future":True,
        "temporary_task_trigger_autorun_days":3650,
        "temporary_task_allow_on_demand":True,
        "server_timer_enabled":False,
        "server_timer_active":False,
        "dns_write":False,
    }
    receipt_path.write_text(json.dumps(result,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)

    print("DNS9_PHASE_B3_WINRM_AUTH_OK=1")
    print("DNS9_PHASE_B3_SYSTEM_CYCLE_OK=1")
    print("DNS9_PHASE_B3_PERMANENT_TASK_ENABLED=0")
    print("DNS9_PHASE_B3_TEMP_TASK_TRIGGER_COUNT=1")
    print("DNS9_PHASE_B3_TEMP_TASK_TRIGGER_ENABLED=1")
    print("DNS9_PHASE_B3_TEMP_TASK_TRIGGER_FUTURE=1")
    print("DNS9_PHASE_B3_TEMP_TASK_ALLOW_ON_DEMAND=1")
    print("DNS9_PHASE_B3_TEMP_TASK_REMOVED=1")
    print("DNS9_PHASE_B3_PILOT_SUCCEEDED=1")
    print("DNS9_PHASE_B3_SERVER_TIMER_ENABLED=0")
    print("DNS9_PHASE_B3_SERVER_TIMER_ACTIVE=0")
    print("DNS9_PHASE_B3_DNS_WRITE=0")
    print("DNS9_PHASE_B3_RECEIPT="+str(receipt_path))
    print("DNS9_PHASE_B3_OK=1")

if __name__=="__main__":
    main()
