#!/usr/bin/env python3
import argparse
import getpass
import json
import os
import re

from dns9_winrm_common import protocol, run_ps, json_line, ps_quote, ensure_ntlm_md4_runtime

SERVER="srv-dc01.rbank.local"
PERMANENT_TASK="phpIPAM Microsoft DNS Collector"
PILOT_PREFIX="phpIPAM Microsoft DNS Collector DNS9 Pilot Once "
INSTALL_ROOT=r"C:\\ProgramData\\phpIPAM\\DnsCollector"

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--username",required=True)
    ap.add_argument("--suffix",required=True)
    ap.add_argument("--server",default=SERVER)
    args=ap.parse_args()

    if os.geteuid()!=0:
        raise SystemExit("DNS9_PHASE_B3_DIAG_ERROR=root_required")
    if not re.fullmatch(r"[0-9a-f]{12}",args.suffix):
        raise SystemExit("DNS9_PHASE_B3_DIAG_ERROR=suffix_invalid")

    md4_mode=ensure_ntlm_md4_runtime()
    print("DNS9_PHASE_B3_DIAG_MD4_PROVIDER="+md4_mode)
    password=getpass.getpass("WinRM diagnostic password: ")
    if not password:
        raise SystemExit("DNS9_PHASE_B3_DIAG_ERROR=password_empty")
    p=protocol(args.server,args.username,password)
    password=""

    task=PILOT_PREFIX+args.suffix
    script=f"""
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$TaskName={ps_quote(task)}
$PermanentTask={ps_quote(PERMANENT_TASK)}
$Status=Join-Path {ps_quote(INSTALL_ROOT)} 'status.json'

$perm=Get-ScheduledTask -TaskName $PermanentTask -ErrorAction Stop
$statusDoc=$null
$statusMtime=$null
if(Test-Path -LiteralPath $Status -PathType Leaf){{
    $item=Get-Item -LiteralPath $Status -ErrorAction Stop
    $statusMtime=$item.LastWriteTimeUtc.ToString('o')
    try {{
        $raw=Get-Content -LiteralPath $Status -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
        $statusDoc=[PSCustomObject][ordered]@{{
            state=[string]$raw.state
            job_id=$raw.job_id
            job_type=$raw.job_type
            result_ok=$raw.result_ok
            result_code=$raw.result_code
            updated_at=$raw.updated_at
        }}
    }} catch {{}}
}}

$log=Get-WinEvent -ListLog 'Microsoft-Windows-TaskScheduler/Operational' -ErrorAction SilentlyContinue
$events=@()
if($null -ne $log -and $log.IsEnabled){{
    $start=(Get-Date).AddDays(-7)
    $candidate=@(Get-WinEvent -FilterHashtable @{{LogName='Microsoft-Windows-TaskScheduler/Operational';StartTime=$start}} -ErrorAction SilentlyContinue)
    foreach($evt in $candidate){{
        $matched=$false
        foreach($prop in @($evt.Properties)){{
            $v=[string]$prop.Value
            if($v -eq $TaskName -or $v -eq ('\\'+$TaskName) -or $v.Contains($TaskName)){{$matched=$true;break}}
        }}
        if(-not $matched){{
            $msg=[string]$evt.Message
            if($msg.Contains($TaskName)){{$matched=$true}}
        }}
        if($matched){{
            $events += [PSCustomObject][ordered]@{{
                id=[int]$evt.Id
                time=$evt.TimeCreated.ToUniversalTime().ToString('o')
                level=[string]$evt.LevelDisplayName
                message=([string]$evt.Message -replace '[\r\n]+',' ')
            }}
            if($events.Count -ge 30){{break}}
        }}
    }}
}}

[PSCustomObject][ordered]@{{
    server_time_utc=[DateTime]::UtcNow.ToString('o')
    queried_task=$TaskName
    permanent_task_state=[string]$perm.State
    permanent_allow_demand_start=[bool]$perm.Settings.AllowDemandStart
    status_exists=[bool](Test-Path -LiteralPath $Status -PathType Leaf)
    status_mtime_utc=$statusMtime
    status=$statusDoc
    operational_log_present=[bool]($null -ne $log)
    operational_log_enabled=[bool]($null -ne $log -and $log.IsEnabled)
    operational_event_count=@($events).Count
    operational_events=@($events)
}} | ConvertTo-Json -Depth 8 -Compress | ForEach-Object {{Write-Output ('DNS9_PHASE_B3_DIAG_JSON='+$_)}}
"""
    rc,out,err=run_ps(p,script)
    if rc!=0:
        raise RuntimeError("phase_b3_diag_remote_failed")
    doc=json_line(out,"DNS9_PHASE_B3_DIAG_JSON=")
    print("DNS9_PHASE_B3_DIAG_JSON="+json.dumps(doc,sort_keys=True,separators=(',',':')))
    print("DNS9_PHASE_B3_DIAG_REMOTE_MUTATION=0")
    print("DNS9_PHASE_B3_DIAG_DB_MUTATION=0")
    print("DNS9_PHASE_B3_DIAG_DNS_WRITE=0")
    print("DNS9_PHASE_B3_DIAG_OK=1")

if __name__=="__main__":
    main()
