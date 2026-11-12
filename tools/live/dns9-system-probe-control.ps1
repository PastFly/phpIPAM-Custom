#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$StageDir,
    [Parameter(Mandatory=$true)][string]$Nonce,
    [Parameter(Mandatory=$true)][string]$ProbeHash,
    [Parameter(Mandatory=$true)][string]$ReferenceHash
)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$created=$false; $started=$false; $phase='preflight'
$TaskName='phpIPAM Microsoft DNS Collector DNS9 SYSTEM Probe '+$Nonce
$scriptPath=Join-Path $StageDir 'probe.ps1'
$referencePath=Join-Path $StageDir 'reference.json'
$resultPath=Join-Path $StageDir 'result.json'
try {
    if($Nonce -notmatch '^[0-9a-f]{16}$'){throw 'invalid_task_name'}
    if($StageDir -notmatch '^C:\\Windows\\Temp\\phpipam-dns9-system-probe-[0-9a-f]{16}$'){throw 'invalid_stage'}
    if($ProbeHash -notmatch '^[0-9a-f]{64}$' -or $ReferenceHash -notmatch '^[0-9a-f]{64}$'){throw 'invalid_hash'}
    if([string](Get-ScheduledTask -TaskName 'phpIPAM Microsoft DNS Collector' -ErrorAction Stop).State -ne 'Disabled'){throw 'permanent_task_not_disabled'}
    $existing=@(Get-ScheduledTask | Where-Object {$_.TaskName -like 'phpIPAM Microsoft DNS Collector DNS9 Pilot Once *' -or $_.TaskName -like 'phpIPAM Microsoft DNS Collector DNS9 SYSTEM Probe *'})
    if($existing.Count -ne 0){throw 'task_exists'}
    $procs=@(Get-CimInstance -ClassName Win32_Process -ErrorAction Stop | Where-Object {$_.Name -match '^(powershell|pwsh)\.exe$'})
    $unknown=@($procs | Where-Object {[string]::IsNullOrWhiteSpace($_.CommandLine)})
    $active=@($procs | Where-Object {$_.ProcessId -ne $PID -and ($_.CommandLine -like '*Invoke-PhpIpamDnsCollectorScheduled.ps1*' -or $_.CommandLine -like '*phpipam-dns9-system-probe-*')})
    if($unknown.Count -ne 0 -or $active.Count -ne 0){throw 'process_state_uncertain'}
    if((Get-FileHash -LiteralPath $scriptPath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $ProbeHash){throw 'probe_hash_mismatch'}
    if((Get-FileHash -LiteralPath $referencePath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $ReferenceHash){throw 'reference_hash_mismatch'}
    if(Test-Path -LiteralPath $resultPath){throw 'result_exists'}
    $action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$scriptPath+'" -StageDir "'+$StageDir+'"')
    $principal=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 2) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    $trigger=New-ScheduledTaskTrigger -Once -At ((Get-Date).AddDays(3650)); $trigger.Enabled=$true
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings | Out-Null
    $created=$true
    $task=Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    if(@($task.Triggers).Count -ne 1 -or -not [bool]$task.Triggers[0].Enabled -or ([DateTime]$task.Triggers[0].StartBoundary -lt (Get-Date).AddDays(3600)) -or
       [string]$task.State -eq 'Disabled' -or -not [bool]$task.Settings.AllowDemandStart){throw 'trigger_invalid'}
    $phase='start'
    & schtasks.exe /Run /TN $TaskName | Out-Null
    if($LASTEXITCODE -ne 0){throw 'task_start_failed'}
    $started=$true
    Write-Output 'DNS9_SYS_TASK_STARTED=1'
    $phase='wait'
    $deadline=(Get-Date).AddSeconds(90); $result=$null
    do {
        Start-Sleep -Milliseconds 500
        $task=Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
        $procs=@(Get-CimInstance -ClassName Win32_Process -ErrorAction Stop | Where-Object {$_.Name -match '^(powershell|pwsh)\.exe$'})
        $unknown=@($procs | Where-Object {[string]::IsNullOrWhiteSpace($_.CommandLine)})
        $active=@($procs | Where-Object {$_.ProcessId -ne $PID -and $_.CommandLine -like ('*'+$StageDir+'*')})
        if((Test-Path -LiteralPath $resultPath -PathType Leaf) -and [string]$task.State -ne 'Running' -and $unknown.Count -eq 0 -and $active.Count -eq 0){
            try {$result=Get-Content -LiteralPath $resultPath -Raw | ConvertFrom-Json -ErrorAction Stop; break} catch {$result=$null}
        }
    } while((Get-Date) -lt $deadline)
    if($null -eq $result){throw 'probe_timeout_or_no_result'}
    if([string](Get-ScheduledTask -TaskName 'phpIPAM Microsoft DNS Collector' -ErrorAction Stop).State -ne 'Disabled'){throw 'permanent_task_changed'}
    $phase='result'
    $result | ConvertTo-Json -Compress | ForEach-Object {Write-Output ('DNS9_SYS_RESULT_JSON='+$_)}
} catch {
    Write-Output ('DNS9_SYS_CONTROL_FAILURE_PHASE='+$phase)
    throw 'dns9_system_probe_control_failed'
} finally {
    if($created){
        try {
            $task=Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
            $procs=@(Get-CimInstance -ClassName Win32_Process -ErrorAction Stop | Where-Object {$_.Name -match '^(powershell|pwsh)\.exe$'})
            $unknown=@($procs | Where-Object {[string]::IsNullOrWhiteSpace($_.CommandLine)})
            $active=@($procs | Where-Object {$_.ProcessId -ne $PID -and $_.CommandLine -like ('*'+$StageDir+'*')})
            if([string]$task.State -ne 'Running' -and $unknown.Count -eq 0 -and $active.Count -eq 0){
                Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction Stop
            }
        } catch {}
    }
    $left=Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Write-Output ('DNS9_SYS_TASK_CLEANED='+[int][bool]($null -eq $left))
    Write-Output ('DNS9_SYS_TASK_WAS_STARTED='+[int][bool]$started)
}
