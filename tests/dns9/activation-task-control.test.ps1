#requires -Version 5.1
$ErrorActionPreference='Stop'
$PermanentName='phpIPAM Microsoft DNS Collector'
$global:Dns9TaskEnabled=$false
$global:Dns9TaskCalls=@()
function Get-ScheduledTask {
    param($TaskName,$TaskPath,$ErrorAction)
    return [PSCustomObject]@{TaskName=$PermanentName;TaskPath='\';State=$(if($global:Dns9TaskEnabled){'Ready'}else{'Disabled'})}
}
function Get-ScheduledTaskInfo {
    param($TaskName,$TaskPath,$ErrorAction)
    return [PSCustomObject]@{LastRunTime=[datetime]'2026-11-06T12:00:00Z'}
}
function Enable-ScheduledTask {
    param($TaskName,$TaskPath,$ErrorAction)
    $global:Dns9TaskCalls+=@('enable'); $global:Dns9TaskEnabled=$true
}
function Disable-ScheduledTask {
    param($TaskName,$TaskPath,$ErrorAction)
    $global:Dns9TaskCalls+=@('disable'); $global:Dns9TaskEnabled=$false
}
$file=Join-Path (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path 'tools\live\dns9-activation-task-control.ps1'
$tokens=$null; $errors=$null
[void][System.Management.Automation.Language.Parser]::ParseFile($file,[ref]$tokens,[ref]$errors)
if(@($errors).Count -ne 0){throw 'task_control_parse_failed'}
$script=Get-Content -LiteralPath $file -Raw
$Action='enable'
$lines=@(& ([scriptblock]::Create($script)))
if(-not $global:Dns9TaskEnabled -or $global:Dns9TaskCalls.Count -ne 1 -or $lines.Count -ne 1){throw 'enable_failed'}
$doc=$lines[0].Substring('DNS9_ACTIVATION_TASK_STATE='.Length)|ConvertFrom-Json
if(-not $doc.enabled -or $doc.last_run_epoch -le 0){throw 'state_invalid'}
try {& ([scriptblock]::Create($script)); throw 'second_enable_not_rejected'} catch {if($_.Exception.Message -eq 'second_enable_not_rejected'){throw}}
if($global:Dns9TaskCalls.Count -ne 1){throw 'duplicate_enable'}
$Action='status'; [void](& ([scriptblock]::Create($script)))
if($global:Dns9TaskCalls.Count -ne 1){throw 'status_mutated'}
$Action='disable'; [void](& ([scriptblock]::Create($script)))
if($global:Dns9TaskEnabled -or $global:Dns9TaskCalls.Count -ne 2){throw 'disable_failed'}
Write-Output 'DNS9_ACTIVATION_TASK_CONTROL_TEST_OK=1'
