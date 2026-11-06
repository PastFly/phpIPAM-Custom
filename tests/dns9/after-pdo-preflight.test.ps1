#requires -Version 5.1
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$temp=Join-Path $env:TEMP ('dns9-after-pdo-fixture-'+[Guid]::NewGuid().ToString('N'))
$script:FixtureProcess=$false
function Get-ScheduledTask {
    param($TaskName,$ErrorAction)
    if([string]::IsNullOrEmpty($TaskName)){return [PSCustomObject]@{TaskName='phpIPAM Microsoft DNS Collector';State='Disabled'}}
    return [PSCustomObject]@{TaskName=$TaskName;State='Disabled'}
}
function Get-CimInstance {
    param($ClassName,$ErrorAction)
    if($script:FixtureProcess){return [PSCustomObject]@{Name='powershell.exe';CommandLine='Invoke-PhpIpamDnsCollectorScheduled.ps1'}}
    return @()
}
try {
    New-Item -ItemType Directory -Path $temp | Out-Null
    $names=@('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')
    $hashes=@{}
    foreach($name in $names){
        $path=Join-Path $temp $name;[IO.File]::WriteAllText($path,'fixture-'+$name)
        $hashes[$name]=(Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $statusPath=Join-Path $temp 'status.json'
    [IO.File]::WriteAllText($statusPath,'{"state":"error","result_code":"dns9_runtime_error","updated_at":1793955444}')
    (Get-Item -LiteralPath $statusPath).LastWriteTimeUtc=[DateTimeOffset]::FromUnixTimeSeconds(1793955444).UtcDateTime
    $encoded=& python (Join-Path $root 'tests\dns9\after-pdo-preflight-fixture.py')
    if($LASTEXITCODE -ne 0){throw 'preflight_generator_failed'}
    $source=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($encoded))
    $source=$source.Replace('C:\ProgramData\phpIPAM\DnsCollector',$temp)
    $source=$source.Replace("ConvertFrom-Json -InputObject '{}'",("ConvertFrom-Json -InputObject '"+(ConvertTo-Json $hashes -Compress)+"'"))
    $lines=@(& ([scriptblock]::Create($source)))
    $line=@($lines|Where-Object {$_ -like 'DNS9_AFTER_PDO_REMOTE_JSON=*'})
    if($line.Count -ne 1){throw 'preflight_output_missing'}
    $doc=$line[0].Substring('DNS9_AFTER_PDO_REMOTE_JSON='.Length)|ConvertFrom-Json
    if(-not $doc.permanent_disabled -or -not $doc.files_match -or -not $doc.previous_status -or $doc.active_processes -ne 0){throw 'preflight_success_invalid'}
    $script:FixtureProcess=$true
    $lines=@(& ([scriptblock]::Create($source)))
    $line=@($lines|Where-Object {$_ -like 'DNS9_AFTER_PDO_REMOTE_JSON=*'})
    $doc=$line[0].Substring('DNS9_AFTER_PDO_REMOTE_JSON='.Length)|ConvertFrom-Json
    if($doc.active_processes -ne 1){throw 'active_process_not_reported'}
    Write-Output 'DNS9_AFTER_PDO_PREFLIGHT_WINDOWS_FIXTURE_OK=1'
} finally {
    if(Test-Path -LiteralPath $temp){Remove-Item -LiteralPath $temp -Recurse -Force}
}
