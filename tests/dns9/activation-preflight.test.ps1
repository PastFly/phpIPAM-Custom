#requires -Version 5.1
$ErrorActionPreference='Stop'
$repository=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Root=Join-Path $env:TEMP ('dns9-activation-fixture-'+[Guid]::NewGuid().ToString('N'))
$PermanentName='phpIPAM Microsoft DNS Collector'
$ExpectedJobId='3af8e09411c0e94a17ed19d3f0872359'
$global:Dns9FixtureDisabled=$true
$global:Dns9FixtureActiveProcess=$false
function Get-ScheduledTask {
    param($TaskName,$ErrorAction)
    $action=[PSCustomObject]@{Execute='powershell.exe';Arguments=('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+(Join-Path $Root 'Invoke-PhpIpamDnsCollectorScheduled.ps1')+'"')}
    $trigger=[PSCustomObject]@{Enabled=$true;StartBoundary=(Get-Date).AddMinutes(-5).ToString('s');Repetition=[PSCustomObject]@{Interval='PT1M';Duration='P3650D'}}
    return [PSCustomObject]@{TaskName=$PermanentName;State=$(if($global:Dns9FixtureDisabled){'Disabled'}else{'Ready'});
        Principal=[PSCustomObject]@{UserId='SYSTEM';LogonType='ServiceAccount'};Actions=@($action);Triggers=@($trigger);
        Settings=[PSCustomObject]@{StartWhenAvailable=$true;MultipleInstances='IgnoreNew';ExecutionTimeLimit='PT3M'}}
}
function Get-CimInstance {
    param($ClassName,$ErrorAction)
    if($global:Dns9FixtureActiveProcess){return [PSCustomObject]@{Name='powershell.exe';CommandLine='Invoke-PhpIpamDnsCollectorScheduled.ps1'}}
    return @()
}
try {
    New-Item -ItemType Directory -Path $Root | Out-Null
    $ExpectedHashes=@{}
    foreach($name in @('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')) {
        $path=Join-Path $Root $name
        [IO.File]::WriteAllText($path,'fixture-'+$name)
        $ExpectedHashes[$name]=(Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
    }
    [IO.File]::WriteAllText((Join-Path $Root 'collector.json'),'{"schema_version":1,"phpipam_base_uri":"https://ipam.realistbank.ru/","http_timeout_seconds":30,"dns_timeout_seconds":60}')
    [IO.File]::WriteAllBytes((Join-Path $Root 'control-token.dpapi'),(New-Object byte[] 64))
    $statusPath=Join-Path $Root 'status.json'
    [IO.File]::WriteAllText($statusPath,('{"state":"submitted","job_id":"'+$ExpectedJobId+'","job_type":"list_zones","result_ok":true,"result_code":"ok","updated_at":1793959934}'))
    (Get-Item -LiteralPath $statusPath).LastWriteTimeUtc=[DateTimeOffset]::FromUnixTimeSeconds(1793959934).UtcDateTime
    $path=Join-Path $repository 'tools\live\dns9-activation-preflight.ps1'
    $tokens=$null; $errors=$null
    [void][System.Management.Automation.Language.Parser]::ParseFile($path,[ref]$tokens,[ref]$errors)
    if(@($errors).Count -ne 0){throw 'activation_preflight_parse_failed'}
    $parts=[regex]::Split((Get-Content -LiteralPath $path -Raw),[regex]::Escape('# DNS9_PREFLIGHT_SPLIT'))
    if($parts.Count -ne 2){throw 'activation_preflight_split_invalid'}
    $taskLine=@(& ([scriptblock]::Create($parts[0])) | Where-Object {$_ -like 'DNS9_ACTIVATION_TASK_JSON=*'})
    $healthLine=@(& ([scriptblock]::Create($parts[1])) | Where-Object {$_ -like 'DNS9_ACTIVATION_HEALTH_JSON=*'})
    if($taskLine.Count -ne 1 -or $healthLine.Count -ne 1){throw 'activation_preflight_output_missing'}
    $taskDoc=$taskLine[0].Substring('DNS9_ACTIVATION_TASK_JSON='.Length)|ConvertFrom-Json
    $healthDoc=$healthLine[0].Substring('DNS9_ACTIVATION_HEALTH_JSON='.Length)|ConvertFrom-Json
    if(-not $taskDoc.permanent_disabled -or -not $taskDoc.principal_system -or -not $taskDoc.action_expected -or -not $healthDoc.files_match -or -not $healthDoc.config_expected -or -not $healthDoc.token_blob_present -or
       -not $healthDoc.status_expected -or -not $taskDoc.repetition_active -or $taskDoc.interval_seconds -ne 60 -or
       $taskDoc.execution_limit_seconds -ne 180 -or $healthDoc.collector_process_count -ne 0){throw 'activation_preflight_success_invalid'}
    $global:Dns9FixtureDisabled=$false; $global:Dns9FixtureActiveProcess=$true
    $taskLine=@(& ([scriptblock]::Create($parts[0])) | Where-Object {$_ -like 'DNS9_ACTIVATION_TASK_JSON=*'})
    $healthLine=@(& ([scriptblock]::Create($parts[1])) | Where-Object {$_ -like 'DNS9_ACTIVATION_HEALTH_JSON=*'})
    $taskDoc=$taskLine[0].Substring('DNS9_ACTIVATION_TASK_JSON='.Length)|ConvertFrom-Json
    $healthDoc=$healthLine[0].Substring('DNS9_ACTIVATION_HEALTH_JSON='.Length)|ConvertFrom-Json
    if($taskDoc.permanent_disabled -or $healthDoc.collector_process_count -ne 1){throw 'activation_preflight_unsafe_state_not_detected'}
    Write-Output 'DNS9_ACTIVATION_WINDOWS_FIXTURE_OK=1'
} finally {
    if(Test-Path -LiteralPath $Root){Remove-Item -LiteralPath $Root -Recurse -Force}
    Remove-Variable -Name Dns9FixtureDisabled,Dns9FixtureActiveProcess -Scope Global -ErrorAction SilentlyContinue
}
