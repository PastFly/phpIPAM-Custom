#requires -Version 5.1
$ErrorActionPreference='Stop'
Set-StrictMode -Version 2.0
$Root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$testRoot=Join-Path $env:TEMP ('dns9-upgrade-test-'+[Guid]::NewGuid().ToString('N'))
$stage=Join-Path $testRoot 'stage'
$runner=Join-Path $testRoot 'Invoke-PhpIpamDnsCollectorScheduled.ps1'
$backup=Join-Path $testRoot 'Invoke-PhpIpamDnsCollectorScheduled.ps1.pre-tls-aaaaaaaaaaaaaaaa.bak'
$staged=Join-Path $stage 'runner.ps1'
function Get-ScheduledTask { param($TaskName,$ErrorAction) [PSCustomObject]@{State='Disabled';TaskName='phpIPAM Microsoft DNS Collector'} }
function Get-CimInstance { param($ClassName,$ErrorAction) return @() }
try {
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $testRoot 'Invoke-PhpIpamDnsWorkerOnce.ps1'),'worker')
    [IO.File]::WriteAllText((Join-Path $testRoot 'Invoke-PhpIpamDnsPreview.ps1'),'preview')
    [IO.File]::WriteAllText($runner,'old-runner')
    [IO.File]::WriteAllText($staged,'new-runner')
    $encoded=& python (Join-Path $Root 'tests\dns9\runner-tls-upgrade-fixture.py')
    if($LASTEXITCODE -ne 0){throw 'fixture_generator_failed'}
    $source=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($encoded))
    $source=$source.Replace('C:\ProgramData\phpIPAM\DnsCollector\Invoke-PhpIpamDnsCollectorScheduled.ps1.pre-tls-aaaaaaaaaaaaaaaa.bak',$backup)
    $source=$source.Replace('C:\Windows\Temp\phpipam-dns9-tls-upgrade-aaaaaaaaaaaaaaaa\runner.ps1',$staged)
    $source=$source.Replace('C:\ProgramData\phpIPAM\DnsCollector',$testRoot)
    $tokens=$null; $errors=$null
    [void][System.Management.Automation.Language.Parser]::ParseInput($source,[ref]$tokens,[ref]$errors)
    if(@($errors).Count -ne 0){throw 'upgrade_control_parse_failed'}
    $output=@(& ([scriptblock]::Create($source)))
    if(-not ($output -contains 'DNS9_TLS_UPGRADE_REPLACE_START=1')){throw 'replace_not_started'}
    if([IO.File]::ReadAllText($runner) -cne 'new-runner'){throw 'runner_not_updated'}
    if([IO.File]::ReadAllText($backup) -cne 'old-runner'){throw 'backup_invalid'}
    if(-not (Test-Path -LiteralPath $staged)){throw 'stage_missing'}
    if(Test-Path -LiteralPath (Join-Path $testRoot 'Invoke-PhpIpamDnsCollectorScheduled.ps1.tls-aaaaaaaaaaaaaaaa.new')){throw 'replacement_not_moved'}
    Write-Output 'DNS9_TLS_UPGRADE_WINDOWS_FIXTURE_OK=1'
} finally {
    if(Test-Path -LiteralPath $testRoot){Remove-Item -LiteralPath $testRoot -Recurse -Force}
}
