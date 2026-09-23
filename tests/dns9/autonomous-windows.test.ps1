#requires -Version 5.1
[CmdletBinding()]
param()

Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'

$Root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Files=@(
    (Join-Path $Root 'collectors\windows\dns\Install-PhpIpamDnsCollector.ps1'),
    (Join-Path $Root 'collectors\windows\dns\Invoke-PhpIpamDnsCollectorScheduled.ps1'),
    (Join-Path $Root 'collectors\windows\dns\Get-PhpIpamDnsCollectorStatus.ps1'),
    (Join-Path $Root 'collectors\windows\dns\Uninstall-PhpIpamDnsCollector.ps1')
)

$Count=0
function Assert-Dns9 {
    param([Parameter(Mandatory=$true)][bool]$Value,[Parameter(Mandatory=$true)][string]$Label)
    $script:Count++
    if(-not $Value){throw ('DNS9_WINDOWS_ASSERT_FAILED: '+$Label)}
}

foreach($file in $Files){
    Assert-Dns9 -Value (Test-Path -LiteralPath $file -PathType Leaf) -Label ('source exists '+$file)
    $tokens=$null
    $errors=$null
    [void][System.Management.Automation.Language.Parser]::ParseFile($file,[ref]$tokens,[ref]$errors)
    Assert-Dns9 -Value (@($errors).Count -eq 0) -Label ('PowerShell parser '+$file)
}

foreach($command in @(
    'New-ScheduledTaskAction','New-ScheduledTaskTrigger','New-ScheduledTaskPrincipal',
    'New-ScheduledTaskSettingsSet','Register-ScheduledTask','Get-ScheduledTask','Start-ScheduledTask'
)){
    Assert-Dns9 -Value ($null -ne (Get-Command $command -ErrorAction SilentlyContinue)) -Label ('ScheduledTasks API '+$command)
}

$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -NonInteractive -File "C:\ProgramData\phpIPAM\DnsCollector\Invoke-PhpIpamDnsCollectorScheduled.ps1"'
Assert-Dns9 -Value ($action.Execute -ieq 'powershell.exe') -Label 'task action object'

$trigger=New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 7)
Assert-Dns9 -Value ($null -ne $trigger) -Label 'task trigger object'

$principal=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Assert-Dns9 -Value ($principal.UserId -ieq 'SYSTEM') -Label 'SYSTEM task principal object'

$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 3)
Assert-Dns9 -Value ($null -ne $settings) -Label 'task settings object'

$plain='dnsctl1.'+('a'*32)+'.'+('B'*43)
$bytes=[Text.Encoding]::UTF8.GetBytes($plain)
$entropy=[Text.Encoding]::UTF8.GetBytes('phpipam-dns-control-token-v1')
$protected=$null
$roundtrip=$null
try {
    $protected=[Security.Cryptography.ProtectedData]::Protect(
        $bytes,$entropy,[Security.Cryptography.DataProtectionScope]::LocalMachine
    )
    Assert-Dns9 -Value ($protected.Length -gt 32) -Label 'DPAPI LocalMachine protect'
    $decoded=[Security.Cryptography.ProtectedData]::Unprotect(
        $protected,$entropy,[Security.Cryptography.DataProtectionScope]::LocalMachine
    )
    $roundtrip=[Text.Encoding]::UTF8.GetString($decoded)
    Assert-Dns9 -Value ($roundtrip -ceq $plain) -Label 'DPAPI LocalMachine roundtrip'
    [Array]::Clear($decoded,0,$decoded.Length)
}
finally {
    $plain=$null
    $roundtrip=$null
    [Array]::Clear($bytes,0,$bytes.Length)
    [Array]::Clear($entropy,0,$entropy.Length)
    if($null -ne $protected){[Array]::Clear($protected,0,$protected.Length)}
}

Assert-Dns9 -Value ($null -eq (Get-ScheduledTask -TaskName 'phpIPAM Microsoft DNS Collector DNS9 FIXTURE NEVER REGISTER' -ErrorAction SilentlyContinue)) -Label 'fixture creates no Scheduled Task'

Write-Output ('DNS9_WINDOWS_FIXTURE_ASSERTIONS='+$Count)
Write-Output 'DNS9_WINDOWS_FIXTURE_TASK_CREATED=0'
Write-Output 'DNS9_WINDOWS_FIXTURE_TOKEN_PRINTED=0'
Write-Output 'DNS9_WINDOWS_FIXTURE_OK=1'
