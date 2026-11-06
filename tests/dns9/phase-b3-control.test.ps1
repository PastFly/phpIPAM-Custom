#requires -Version 5.1
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$temp=Join-Path $env:TEMP ('dns9-b3-fixture-'+[Guid]::NewGuid().ToString('N'))
$script:FixtureMode='success'; $script:PilotCreated=$false; $script:UnregisterCalls=0; $script:ClockCalls=0
$script:FixtureTemp=$temp
function Get-Date {
    $d=Microsoft.PowerShell.Utility\Get-Date
    if($script:FixtureMode -eq 'timeout'){$script:ClockCalls++; if($script:ClockCalls -ge 5){return $d.AddSeconds(300)}}
    return $d
}
function Start-Sleep {param($Milliseconds) }
function New-ScheduledTaskAction {param($Execute,$Argument) [PSCustomObject]@{Execute=$Execute;Argument=$Argument} }
function New-ScheduledTaskPrincipal {param($UserId,$LogonType,$RunLevel) [PSCustomObject]@{UserId=$UserId} }
function New-ScheduledTaskSettingsSet {param($MultipleInstances,$ExecutionTimeLimit,[switch]$AllowStartIfOnBatteries,[switch]$DontStopIfGoingOnBatteries) [PSCustomObject]@{AllowDemandStart=$true} }
function New-ScheduledTaskTrigger {param([switch]$Once,$At) [PSCustomObject]@{Enabled=$true;StartBoundary=$At.ToString('s')} }
function Register-ScheduledTask {param($TaskName,$Action,$Trigger,$Principal,$Settings) $script:PilotCreated=$true; $script:FixtureTrigger=$Trigger; $script:FixtureSettings=$Settings }
function Unregister-ScheduledTask {param($TaskName,$Confirm,$ErrorAction) $script:PilotCreated=$false; $script:UnregisterCalls++ }
function Get-ScheduledTask {
    param($TaskName,$ErrorAction)
    if([string]::IsNullOrEmpty($TaskName)){return [PSCustomObject]@{TaskName='fixture-permanent';State='Disabled'}}
    if($TaskName -eq 'fixture-permanent'){return [PSCustomObject]@{TaskName=$TaskName;State='Disabled'}}
    if($TaskName -eq 'fixture-pilot' -and $script:PilotCreated){
        $state=if($script:FixtureMode -eq 'timeout'){'Running'}else{'Ready'}
        return [PSCustomObject]@{TaskName=$TaskName;State=$state;Triggers=@($script:FixtureTrigger);Settings=$script:FixtureSettings}
    }
    if($ErrorAction -eq 'Stop'){throw 'fixture_task_missing'}
    return $null
}
function Get-ScheduledTaskInfo {param($TaskName,$ErrorAction) return $null }
function Get-CimInstance {param($ClassName,$ErrorAction) return @() }
function schtasks.exe {
    param($Action,$NameFlag,$TaskName)
    $global:LASTEXITCODE=0
    if($script:FixtureMode -eq 'success'){
        $doc=@{updated_at=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds();state='submitted';job_id=('a'*32);job_type='test_connection';result_ok=$true;result_code='ok'}
        [IO.File]::WriteAllText((Join-Path $script:FixtureTemp 'status.json'),(ConvertTo-Json $doc -Compress))
    }
}
try {
    New-Item -ItemType Directory -Path $temp | Out-Null
    $names=@('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')
    $hashes=@{}
    foreach($name in $names){
        $path=Join-Path $temp $name
        [IO.File]::WriteAllText($path,'fixture-'+$name)
        $hashes[$name]=(Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
    }
    $encoded=& python (Join-Path $root 'tests\dns9\phase-b3-control-fixture.py')
    if($LASTEXITCODE -ne 0){throw 'control_generator_failed'}
    $source=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($encoded))
    $source=$source.Replace('__FIXTURE_ROOT__',$temp).Replace('__FIXTURE_HASHES__',(ConvertTo-Json $hashes -Compress))
    $tokens=$null; $errors=$null
    [void][System.Management.Automation.Language.Parser]::ParseInput($source,[ref]$tokens,[ref]$errors)
    if(@($errors).Count -ne 0){throw 'control_parser_failed'}
    $output=@(& ([scriptblock]::Create($source)))
    if(-not ($output -contains 'DNS9_PHASE_B3_FRESH_STATUS_OBSERVED=1')){throw 'fresh_status_not_used'}
    if(-not ($output -contains 'DNS9_PHASE_B3_TEMP_TASK_CLEANED=1')){throw 'completed_task_not_removed'}
    if($script:PilotCreated -or $script:UnregisterCalls -ne 1){throw 'completed_task_cleanup_invalid'}
    $script:FixtureMode='timeout'; $script:ClockCalls=0; $script:UnregisterCalls=0
    $failed=$false
    try {& ([scriptblock]::Create($source))} catch {$failed=$true}
    if(-not $failed -or -not $script:PilotCreated -or $script:UnregisterCalls -ne 0){throw 'running_task_was_removed'}
    Write-Output 'DNS9_B3_CONTROL_FIXTURE_OK=1'
} finally {
    if(Test-Path -LiteralPath $temp){Remove-Item -LiteralPath $temp -Force -Recurse}
}
