#requires -Version 5.1
# Caller supplies only reviewed constants: Root, PermanentName, ExpectedHashes and ExpectedJobId.
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$task=Get-ScheduledTask -TaskName $PermanentName -ErrorAction Stop
$triggers=@($task.Triggers)
$actions=@($task.Actions)
$otherTasks=@(Get-ScheduledTask -ErrorAction Stop | Where-Object {
    $_.TaskName -like 'phpIPAM Microsoft DNS Collector DNS9 Pilot Once *' -or
    $_.TaskName -like 'phpIPAM Microsoft DNS Collector DNS9 SYSTEM Probe *'
})
$processes=@(Get-CimInstance -ClassName Win32_Process -ErrorAction Stop | Where-Object { $_.Name -match '^(powershell|pwsh)\.exe$' })
$unknown=@($processes | Where-Object { [string]::IsNullOrWhiteSpace($_.CommandLine) })
$active=@($processes | Where-Object {
    $_.CommandLine -like '*Invoke-PhpIpamDnsCollectorScheduled.ps1*' -or
    $_.CommandLine -like '*phpipam-dns9-phase-b3-*' -or $_.CommandLine -like '*phpipam-dns9-system-probe-*'
})
$expectedArguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+(Join-Path $Root 'Invoke-PhpIpamDnsCollectorScheduled.ps1')+'"'
$actionExpected=($actions.Count -eq 1 -and [string]$actions[0].Execute -ieq 'powershell.exe' -and [string]$actions[0].Arguments -ceq $expectedArguments)
$principalSystem=([string]$task.Principal.UserId -in @('SYSTEM','NT AUTHORITY\SYSTEM','S-1-5-18') -and [string]$task.Principal.LogonType -eq 'ServiceAccount')
$interval=0; $triggerEnabled=$false; $repetitionActive=$false
if($triggers.Count -eq 1) {
    $trigger=$triggers[0]
    $triggerEnabled=[bool]$trigger.Enabled
    $interval=[int][System.Xml.XmlConvert]::ToTimeSpan([string]$trigger.Repetition.Interval).TotalSeconds
    $duration=[System.Xml.XmlConvert]::ToTimeSpan([string]$trigger.Repetition.Duration)
    $start=[datetime]::Parse([string]$trigger.StartBoundary).ToUniversalTime()
    $now=[datetime]::UtcNow
    $repetitionActive=($start -le $now -and $start.Add($duration) -gt $now.AddMinutes(20))
}
$limit=[int][System.Xml.XmlConvert]::ToTimeSpan([string]$task.Settings.ExecutionTimeLimit).TotalSeconds
$filesMatch=$true
foreach($name in @('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')) {
    $hash=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $Root $name)).Hash.ToLowerInvariant()
    if($hash -cne [string]$ExpectedHashes.$name){$filesMatch=$false}
}
$config=Get-Content -LiteralPath (Join-Path $Root 'collector.json') -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
$actualFields=@($config.PSObject.Properties.Name | Sort-Object)
$expectedFields=@(@('schema_version','phpipam_base_uri','http_timeout_seconds','dns_timeout_seconds') | Sort-Object)
$configExpected=(($actualFields -join [char]0) -eq ($expectedFields -join [char]0) -and [int]$config.schema_version -eq 1 -and
    [string]$config.phpipam_base_uri -eq 'https://ipam.realistbank.ru/' -and
    [int]$config.http_timeout_seconds -eq 30 -and [int]$config.dns_timeout_seconds -eq 60)
$tokenPath=Join-Path $Root 'control-token.dpapi'
$tokenItem=Get-Item -LiteralPath $tokenPath -ErrorAction Stop
$tokenBlobPresent=(-not $tokenItem.PSIsContainer -and [int64]$tokenItem.Length -ge 32 -and [int64]$tokenItem.Length -le 8192)
$statusPath=Join-Path $Root 'status.json'
$status=Get-Content -LiteralPath $statusPath -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
$statusItem=Get-Item -LiteralPath $statusPath -ErrorAction Stop
$consistent=([Math]::Abs(([DateTimeOffset]$statusItem.LastWriteTimeUtc).ToUnixTimeSeconds()-[int64]$status.updated_at) -le 5)
$statusExpected=([string]$status.state -eq 'submitted' -and [string]$status.job_id -eq $ExpectedJobId -and
    [string]$status.job_type -eq 'list_zones' -and $status.result_ok -eq $true -and [string]$status.result_code -eq 'ok' -and
    [int64]$status.updated_at -eq 1793959934 -and $consistent)
[PSCustomObject][ordered]@{
    permanent_disabled=([string]$task.State -eq 'Disabled')
    principal_system=[bool]$principalSystem
    action_expected=[bool]$actionExpected
    trigger_count=[int]$triggers.Count
    trigger_enabled=[bool]$triggerEnabled
    interval_seconds=[int]$interval
    repetition_active=[bool]$repetitionActive
    start_when_available=[bool]$task.Settings.StartWhenAvailable
    ignore_new=([string]$task.Settings.MultipleInstances -eq 'IgnoreNew')
    execution_limit_seconds=[int]$limit
    files_match=[bool]$filesMatch
    config_expected=[bool]$configExpected
    token_blob_present=[bool]$tokenBlobPresent
    status_expected=[bool]$statusExpected
    pilot_task_count=[int]$otherTasks.Count
    unknown_process_count=[int]$unknown.Count
    collector_process_count=[int]$active.Count
    server_epoch=[long][DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
} | ConvertTo-Json -Compress | ForEach-Object {Write-Output ('DNS9_ACTIVATION_REMOTE_JSON='+$_)}
