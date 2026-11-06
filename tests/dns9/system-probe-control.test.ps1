#requires -Version 5.1
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$temp=Join-Path $env:TEMP ('dns9-system-control-fixture-'+[Guid]::NewGuid().ToString('N'))
$script:FixtureMode='success'; $script:TaskCreated=$false; $script:UnregisterCalls=0; $script:ClockCalls=0; $script:Stage=$temp
$taskName='phpIPAM Microsoft DNS Collector DNS9 SYSTEM Probe aaaaaaaaaaaaaaaa'
function Get-Date {
    $d=Microsoft.PowerShell.Utility\Get-Date
    if($script:FixtureMode -eq 'timeout'){$script:ClockCalls++;if($script:ClockCalls -ge 4){return $d.AddSeconds(300)}}
    return $d
}
function Start-Sleep {param($Milliseconds)}
function New-ScheduledTaskAction {param($Execute,$Argument) [PSCustomObject]@{Execute=$Execute;Argument=$Argument}}
function New-ScheduledTaskPrincipal {param($UserId,$LogonType,$RunLevel) [PSCustomObject]@{UserId=$UserId}}
function New-ScheduledTaskSettingsSet {param($MultipleInstances,$ExecutionTimeLimit,[switch]$AllowStartIfOnBatteries,[switch]$DontStopIfGoingOnBatteries) [PSCustomObject]@{AllowDemandStart=$true}}
function New-ScheduledTaskTrigger {param([switch]$Once,$At) [PSCustomObject]@{Enabled=$true;StartBoundary=$At.ToString('s')}}
function Register-ScheduledTask {param($TaskName,$Action,$Trigger,$Principal,$Settings) $script:TaskCreated=$true;$script:Trigger=$Trigger;$script:Settings=$Settings}
function Unregister-ScheduledTask {param($TaskName,$Confirm,$ErrorAction) $script:TaskCreated=$false;$script:UnregisterCalls++}
function Get-ScheduledTask {
    param($TaskName,$ErrorAction)
    if([string]::IsNullOrEmpty($TaskName)){return [PSCustomObject]@{TaskName='phpIPAM Microsoft DNS Collector';State='Disabled'}}
    if($TaskName -eq 'phpIPAM Microsoft DNS Collector'){return [PSCustomObject]@{TaskName=$TaskName;State='Disabled'}}
    if($TaskName -like '*SYSTEM Probe*' -and $script:TaskCreated){
        $state=if($script:FixtureMode -eq 'timeout'){'Running'}else{'Ready'}
        return [PSCustomObject]@{TaskName=$TaskName;State=$state;Triggers=@($script:Trigger);Settings=$script:Settings}
    }
    if($ErrorAction -eq 'Stop'){throw 'fixture_task_missing'}
    return $null
}
function Get-CimInstance {param($ClassName,$ErrorAction) return @()}
function schtasks.exe {
    param($Action,$NameFlag,$TaskName)
    $global:LASTEXITCODE=0
    if($script:FixtureMode -eq 'success'){
        $doc=@{phase='complete';error='none';token_valid=$true;token_reference_match=$true;http_status=400;http_error_marker=$true;web_status='ProtocolError'}
        [IO.File]::WriteAllText((Join-Path $script:Stage 'result.json'),(ConvertTo-Json $doc -Compress))
    }
}
try {
    New-Item -ItemType Directory -Path $temp -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $temp 'probe.ps1'),'fixture-probe')
    [IO.File]::WriteAllText((Join-Path $temp 'reference.json'),'fixture-reference')
    $probeHash=(Get-FileHash -Algorithm SHA256 (Join-Path $temp 'probe.ps1')).Hash.ToLowerInvariant()
    $refHash=(Get-FileHash -Algorithm SHA256 (Join-Path $temp 'reference.json')).Hash.ToLowerInvariant()
    $source=Get-Content -Raw (Join-Path $root 'tools\live\dns9-system-probe-control.ps1')
    $line=@($source -split "`r?`n" | Where-Object {$_ -like '*$StageDir -notmatch*invalid_stage*'})
    if($line.Count -ne 1){throw 'fixture_stage_validation_missing'}
    $source=$source.Replace($line[0],'')
    $tokens=$null; $errors=$null
    [void][System.Management.Automation.Language.Parser]::ParseInput($source,[ref]$tokens,[ref]$errors)
    if(@($errors).Count -ne 0){throw 'probe_control_parser_failed'}
    $output=@(& ([scriptblock]::Create($source)) -StageDir $temp -Nonce 'aaaaaaaaaaaaaaaa' -ProbeHash $probeHash -ReferenceHash $refHash)
    if(-not ($output -contains 'DNS9_SYS_TASK_STARTED=1') -or -not ($output -contains 'DNS9_SYS_TASK_CLEANED=1') -or $script:UnregisterCalls -ne 1){throw 'probe_success_cleanup_invalid'}
    Remove-Item -LiteralPath (Join-Path $temp 'result.json')
    $script:FixtureMode='timeout'; $script:ClockCalls=0; $script:UnregisterCalls=0
    $failed=$false
    try {& ([scriptblock]::Create($source)) -StageDir $temp -Nonce 'aaaaaaaaaaaaaaaa' -ProbeHash $probeHash -ReferenceHash $refHash} catch {$failed=$true}
    if(-not $failed -or -not $script:TaskCreated -or $script:UnregisterCalls -ne 0){throw 'running_probe_removed'}
    Write-Output 'DNS9_SYSTEM_CONTROL_WINDOWS_FIXTURE_OK=1'
} finally {
    if(Test-Path -LiteralPath $temp){Remove-Item -LiteralPath $temp -Recurse -Force}
}
