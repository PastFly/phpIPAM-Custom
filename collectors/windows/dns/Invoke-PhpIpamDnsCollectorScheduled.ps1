#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$false)]
    [string]$InstallRoot = (Join-Path $env:ProgramData 'phpIPAM\DnsCollector')
)

Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'

Add-Type -AssemblyName System.Security -ErrorAction Stop

$ConfigPath=Join-Path $InstallRoot 'collector.json'
$TokenPath=Join-Path $InstallRoot 'control-token.dpapi'
$WorkerPath=Join-Path $InstallRoot 'Invoke-PhpIpamDnsWorkerOnce.ps1'
$PreviewPath=Join-Path $InstallRoot 'Invoke-PhpIpamDnsPreview.ps1'
$StatusPath=Join-Path $InstallRoot 'status.json'
$MutexName='Global\phpIPAM-DNS-Collector-v1'
$EntropyText='phpipam-dns-control-token-v1'

function Write-Dns9Status {
    param(
        [Parameter(Mandatory=$true)][string]$State,
        [Parameter(Mandatory=$false)][string]$JobId,
        [Parameter(Mandatory=$false)][string]$JobType,
        [Parameter(Mandatory=$false)][Nullable[bool]]$ResultOk,
        [Parameter(Mandatory=$false)][string]$ResultCode
    )
    $doc=[ordered]@{
        schema_version=1
        updated_at=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        state=$State
        job_id=$(if([string]::IsNullOrEmpty($JobId)){$null}else{$JobId})
        job_type=$(if([string]::IsNullOrEmpty($JobType)){$null}else{$JobType})
        result_ok=$(if($null -eq $ResultOk){$null}else{[bool]$ResultOk})
        result_code=$(if([string]::IsNullOrEmpty($ResultCode)){$null}else{$ResultCode})
    }
    $tmp=$StatusPath+'.tmp'
    $json=ConvertTo-Json -InputObject $doc -Compress
    [System.IO.File]::WriteAllText($tmp,$json+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))
    if(Test-Path -LiteralPath $StatusPath){Remove-Item -LiteralPath $StatusPath -Force}
    Move-Item -LiteralPath $tmp -Destination $StatusPath
}

if(-not (Test-Path -LiteralPath $InstallRoot -PathType Container)){throw 'dns9_install_root_missing'}
foreach($path in @($ConfigPath,$TokenPath,$WorkerPath,$PreviewPath)){
    if(-not (Test-Path -LiteralPath $path -PathType Leaf)){throw 'dns9_install_incomplete'}
}

$config=Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json -ErrorAction Stop
$expected=@('schema_version','phpipam_base_uri','http_timeout_seconds','dns_timeout_seconds')
$actual=@($config.PSObject.Properties.Name | Sort-Object)
if(($actual -join [char]0) -ne (($expected | Sort-Object) -join [char]0)){throw 'dns9_config_invalid'}
if([int]$config.schema_version -ne 1){throw 'dns9_config_invalid'}

$base=[Uri][string]$config.phpipam_base_uri
if(-not $base.IsAbsoluteUri -or $base.Scheme -ne 'https' -or
   -not [string]::IsNullOrEmpty($base.UserInfo) -or
   -not [string]::IsNullOrEmpty($base.Query) -or
   -not [string]::IsNullOrEmpty($base.Fragment)){
    throw 'dns9_config_invalid'
}

$httpTimeout=[int]$config.http_timeout_seconds
$dnsTimeout=[int]$config.dns_timeout_seconds
if($httpTimeout -lt 5 -or $httpTimeout -gt 120 -or $dnsTimeout -lt 15 -or $dnsTimeout -gt 180){
    throw 'dns9_config_invalid'
}

$mutex=New-Object Threading.Mutex($false,$MutexName)
$locked=$false
$plain=$null
$plainBytes=$null
$protected=$null
$entropy=$null
$secure=$null
$oldProxy=$null
$proxyChanged=$false
$oldSecurityProtocol=$null
$securityProtocolChanged=$false

try {
    try {
        $locked=$mutex.WaitOne(0,$false)
    } catch [Threading.AbandonedMutexException] {
        $locked=$true
    }
    if(-not $locked){
        Write-Dns9Status -State 'overlap_skipped'
        return
    }

    $protected=[IO.File]::ReadAllBytes($TokenPath)
    if($protected.Length -lt 32 -or $protected.Length -gt 8192){throw 'dns9_token_blob_invalid'}
    $entropy=[Text.Encoding]::UTF8.GetBytes($EntropyText)
    $plainBytes=[System.Security.Cryptography.ProtectedData]::Unprotect(
        $protected,$entropy,[System.Security.Cryptography.DataProtectionScope]::LocalMachine
    )
    $plain=[Text.Encoding]::UTF8.GetString($plainBytes)
    if(-not [regex]::IsMatch($plain,'^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$')){
        throw 'dns9_token_invalid'
    }
    $secure=ConvertTo-SecureString -String $plain -AsPlainText -Force
    $plain=$null
    [Array]::Clear($plainBytes,0,$plainBytes.Length)
    $plainBytes=$null

    $oldProxy=[Net.WebRequest]::DefaultWebProxy
    [Net.WebRequest]::DefaultWebProxy=[Net.GlobalProxySelection]::GetEmptyWebProxy()
    $proxyChanged=$true

    # Windows Server 2016 may default to protocols the phpIPAM endpoint rejects.
    $oldSecurityProtocol=[Net.ServicePointManager]::SecurityProtocol
    [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
    $securityProtocolChanged=$true

    $result=@(& $WorkerPath -PhpIpamBaseUri $base -ControlToken $secure -HttpTimeoutSeconds $httpTimeout -DnsTimeoutSeconds $dnsTimeout)
    if($result.Count -ne 1){throw 'dns9_worker_result_invalid'}
    $r=$result[0]
    $state=[string]$r.state
    if($state -eq 'idle'){
        Write-Dns9Status -State 'idle'
        return
    }
    if($state -ne 'submitted'){throw 'dns9_worker_result_invalid'}

    Write-Dns9Status -State 'submitted' -JobId ([string]$r.job_id) -JobType ([string]$r.job_type) -ResultOk ([bool]$r.result_ok) -ResultCode ([string]$r.result_code)
}
catch {
    $code=[string]$_.Exception.Message
    $allowed=@(
        'dns9_install_root_missing','dns9_install_incomplete','dns9_config_invalid',
        'dns9_token_blob_invalid','dns9_token_invalid','dns9_worker_result_invalid',
        'dns_worker_base_uri_invalid','dns_worker_endpoint_invalid','dns_worker_preview_missing',
        'dns_worker_token_invalid','dns_worker_poll_invalid','dns_worker_work_invalid',
        'dns_worker_credential_invalid','dns_worker_preview_invalid','dns_worker_result_submit_failed'
    )
    if($code -notin $allowed){$code='dns9_runtime_error'}
    try{Write-Dns9Status -State 'error' -ResultCode $code}catch{}
    throw $code
}
finally {
    if($securityProtocolChanged){[Net.ServicePointManager]::SecurityProtocol=$oldSecurityProtocol}
    if($proxyChanged){[Net.WebRequest]::DefaultWebProxy=$oldProxy}
    $oldProxy=$null
    $proxyChanged=$false
    $plain=$null
    $secure=$null
    if($null -ne $plainBytes){[Array]::Clear($plainBytes,0,$plainBytes.Length)}
    if($null -ne $protected){[Array]::Clear($protected,0,$protected.Length)}
    if($null -ne $entropy){[Array]::Clear($entropy,0,$entropy.Length)}
    if($locked){try{$mutex.ReleaseMutex()}catch{}}
    $mutex.Dispose()
}
