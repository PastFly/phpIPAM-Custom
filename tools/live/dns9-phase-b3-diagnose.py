#!/usr/bin/env python3
import argparse
import base64
import gzip
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
$candidate=@()
if($null -ne $log -and $log.IsEnabled){{
    $start=[datetime]'2026-10-27T07:45:00Z'
    $end=[datetime]'2026-10-27T08:20:00Z'
    $candidate=@(Get-WinEvent -FilterHashtable @{{LogName='Microsoft-Windows-TaskScheduler/Operational';StartTime=$start;EndTime=$end}} -MaxEvents 2000 -ErrorAction SilentlyContinue)
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
                event_record_id=[long]$evt.RecordId
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
    diagnostic_window_start_utc='2026-10-27T07:45:00Z'
    diagnostic_window_end_utc='2026-10-27T08:20:00Z'
    operational_candidate_count=@($candidate).Count
    operational_candidate_limit_reached=[bool](@($candidate).Count -ge 2000)
    operational_event_count=@($events).Count
    operational_events=@($events)
}} | ConvertTo-Json -Depth 8 -Compress | ForEach-Object {{Write-Output ('DNS9_PHASE_B3_DIAG_JSON='+$_)}}
"""
    # Compress the read-only PowerShell source in memory to stay below Windows
    # process command-line limits. No remote file staging is performed.
    packed=base64.b64encode(gzip.compress(script.encode("utf-8"),mtime=0)).decode("ascii")
    loader=(
        "$b=[Convert]::FromBase64String('"+packed+"');"
        "$m=New-Object IO.MemoryStream(,$b);"
        "$g=New-Object IO.Compression.GzipStream($m,[IO.Compression.CompressionMode]::Decompress);"
        "$r=New-Object IO.StreamReader($g);"
        "& ([scriptblock]::Create($r.ReadToEnd()))"
    )
    if len(base64.b64encode(loader.encode("utf-16le")))>7500:
        raise RuntimeError("phase_b3_diag_encoded_command_limit")
    rc,out,err=run_ps(p,loader)
    if rc!=0:
        safe="remote_execution_failed"
        if "command line is too long" in (out+" "+err).lower():
            safe="encoded_command_too_long"
        elif "access is denied" in (out+" "+err).lower():
            safe="remote_access_denied"
        elif "cannot find" in (out+" "+err).lower():
            safe="remote_resource_missing"
        raise RuntimeError("phase_b3_diag_"+safe)
    doc=json_line(out,"DNS9_PHASE_B3_DIAG_JSON=")
    print("DNS9_PHASE_B3_DIAG_JSON="+json.dumps(doc,sort_keys=True,separators=(',',':')))
    print("DNS9_PHASE_B3_DIAG_REMOTE_MUTATION=0")
    print("DNS9_PHASE_B3_DIAG_DB_MUTATION=0")
    print("DNS9_PHASE_B3_DIAG_DNS_WRITE=0")
    print("DNS9_PHASE_B3_DIAG_OK=1")

if __name__=="__main__":
    main()
