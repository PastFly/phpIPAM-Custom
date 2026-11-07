#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][System.Uri]$PhpIpamBaseUri,
    [Parameter(Mandatory=$true)][System.Security.SecureString]$ControlToken,
    [Parameter(Mandatory=$false)][ValidateSet('System','ServiceAccount')][string]$PrincipalMode='System',
    [Parameter(Mandatory=$false)][ValidateSet('Profile','CollectorIdentity')][string]$DnsCredentialMode='Profile',
    [Parameter(Mandatory=$false)][string]$ServiceAccount,
    [Parameter(Mandatory=$false)][ValidateRange(1,15)][int]$PollIntervalMinutes=1,
    [Parameter(Mandatory=$false)][ValidateRange(5,120)][int]$HttpTimeoutSeconds=30,
    [Parameter(Mandatory=$false)][ValidateRange(15,180)][int]$DnsTimeoutSeconds=60,
    [Parameter(Mandatory=$false)][switch]$EnableTask,
    [Parameter(Mandatory=$false)][switch]$StartNow
)

Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'

Add-Type -AssemblyName System.Security -ErrorAction Stop

$TaskName='phpIPAM Microsoft DNS Collector'
$Root=Join-Path $env:ProgramData 'phpIPAM\DnsCollector'
$WorkerSource=Join-Path $PSScriptRoot 'Invoke-PhpIpamDnsWorkerOnce.ps1'
$PreviewSource=Join-Path $PSScriptRoot 'Invoke-PhpIpamDnsPreview.ps1'
$RunnerSource=Join-Path $PSScriptRoot 'Invoke-PhpIpamDnsCollectorScheduled.ps1'
$EntropyText='phpipam-dns-control-token-v1'

function Assert-Dns9Admin {
    $identity=[Security.Principal.WindowsIdentity]::GetCurrent()
    $principal=New-Object Security.Principal.WindowsPrincipal($identity)
    if(-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'dns9_admin_required'}
}

function Set-Dns9Acl {
    param([Parameter(Mandatory=$true)][string]$Path,[Parameter(Mandatory=$false)][string]$ExtraIdentity)
    & icacls.exe $Path /inheritance:r | Out-Null
    if($LASTEXITCODE -ne 0){throw 'dns9_acl_failed'}
    & icacls.exe $Path /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
    if($LASTEXITCODE -ne 0){throw 'dns9_acl_failed'}
    if(-not [string]::IsNullOrWhiteSpace($ExtraIdentity)){
        & icacls.exe $Path /grant:r ($ExtraIdentity+':(OI)(CI)M') | Out-Null
        if($LASTEXITCODE -ne 0){throw 'dns9_acl_failed'}
    }
}

Assert-Dns9Admin

if($StartNow -and -not $EnableTask){throw 'dns9_start_requires_enabled_task'}

if(-not $PhpIpamBaseUri.IsAbsoluteUri -or $PhpIpamBaseUri.Scheme -ne 'https' -or -not [string]::IsNullOrEmpty($PhpIpamBaseUri.UserInfo) -or -not [string]::IsNullOrEmpty($PhpIpamBaseUri.Query) -or -not [string]::IsNullOrEmpty($PhpIpamBaseUri.Fragment)){throw 'dns9_base_uri_invalid'}
if($PrincipalMode -eq 'ServiceAccount'){
    if([string]::IsNullOrWhiteSpace($ServiceAccount) -or $ServiceAccount -notmatch '^[^\\]+\\[^\\]+\$$'){throw 'dns9_service_account_invalid'}
}
elseif(-not [string]::IsNullOrEmpty($ServiceAccount)){throw 'dns9_service_account_invalid'}

foreach($source in @($WorkerSource,$PreviewSource,$RunnerSource)){
    if(-not (Test-Path -LiteralPath $source -PathType Leaf)){throw 'dns9_source_missing'}
}
if(Test-Path -LiteralPath $Root){throw 'dns9_install_exists'}
if(Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue){throw 'dns9_task_exists'}

$plain=$null
$plainBytes=$null
$entropy=$null
$protected=$null
$bstr=[IntPtr]::Zero
$taskCreated=$false

try {
    New-Item -ItemType Directory -Path $Root -Force | Out-Null
    Set-Dns9Acl -Path $Root -ExtraIdentity $(if($PrincipalMode -eq 'ServiceAccount'){$ServiceAccount}else{$null})

    Copy-Item -LiteralPath $WorkerSource -Destination (Join-Path $Root 'Invoke-PhpIpamDnsWorkerOnce.ps1')
    Copy-Item -LiteralPath $PreviewSource -Destination (Join-Path $Root 'Invoke-PhpIpamDnsPreview.ps1')
    Copy-Item -LiteralPath $RunnerSource -Destination (Join-Path $Root 'Invoke-PhpIpamDnsCollectorScheduled.ps1')

    $config=[ordered]@{schema_version=1;phpipam_base_uri=$PhpIpamBaseUri.AbsoluteUri;http_timeout_seconds=$HttpTimeoutSeconds;dns_timeout_seconds=$DnsTimeoutSeconds}
    [IO.File]::WriteAllText((Join-Path $Root 'collector.json'),(ConvertTo-Json -InputObject $config -Compress)+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))

    $bstr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($ControlToken)
    $plain=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    if(-not [regex]::IsMatch($plain,'^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$')){throw 'dns9_token_invalid'}
    $plainBytes=[Text.Encoding]::UTF8.GetBytes($plain)
    $entropy=[Text.Encoding]::UTF8.GetBytes($EntropyText)
    $protected=[System.Security.Cryptography.ProtectedData]::Protect($plainBytes,$entropy,[System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    [IO.File]::WriteAllBytes((Join-Path $Root 'control-token.dpapi'),$protected)

    Set-Dns9Acl -Path $Root -ExtraIdentity $(if($PrincipalMode -eq 'ServiceAccount'){$ServiceAccount}else{$null})

    $runner=Join-Path $Root 'Invoke-PhpIpamDnsCollectorScheduled.ps1'
    $action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$runner+'"')
    $trigger=New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes $PollIntervalMinutes) -RepetitionDuration (New-TimeSpan -Days 3650)

    if($PrincipalMode -eq 'System'){
        $principal=New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
    } else {
        $principal=New-ScheduledTaskPrincipal -UserId $ServiceAccount -LogonType ServiceAccount -RunLevel Highest
    }

    $settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 3) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings | Out-Null
    $taskCreated=$true

    if(-not $EnableTask){
        Disable-ScheduledTask -TaskName $TaskName | Out-Null
    }
    if($StartNow){Start-ScheduledTask -TaskName $TaskName}

    Write-Output 'DNS9_AUTONOMOUS_INSTALL_OK=1'
    Write-Output ('DNS9_AUTONOMOUS_TASK='+$TaskName)
    Write-Output ('DNS9_AUTONOMOUS_PRINCIPAL_MODE='+$PrincipalMode)
    Write-Output ('DNS9_AUTONOMOUS_DNS_CREDENTIAL_MODE='+$DnsCredentialMode)
    if($PrincipalMode -eq 'ServiceAccount'){Write-Output ('DNS9_AUTONOMOUS_SERVICE_ACCOUNT='+$ServiceAccount)}
    Write-Output ('DNS9_AUTONOMOUS_INTERVAL_MINUTES='+$PollIntervalMinutes)
    Write-Output ('DNS9_AUTONOMOUS_TASK_ENABLED='+([int][bool]$EnableTask))
    Write-Output ('DNS9_AUTONOMOUS_START_NOW='+([int][bool]$StartNow))
    Write-Output 'DNS9_AUTONOMOUS_TOKEN_PRINTED=0'
}
catch {
    if($taskCreated){try{Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue}catch{}}
    if(Test-Path -LiteralPath $Root){Remove-Item -LiteralPath $Root -Recurse -Force -ErrorAction SilentlyContinue}
    throw
}
finally {
    $plain=$null
    if($bstr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)}
    if($null -ne $plainBytes){[Array]::Clear($plainBytes,0,$plainBytes.Length)}
    if($null -ne $entropy){[Array]::Clear($entropy,0,$entropy.Length)}
    if($null -ne $protected){[Array]::Clear($protected,0,$protected.Length)}
}
