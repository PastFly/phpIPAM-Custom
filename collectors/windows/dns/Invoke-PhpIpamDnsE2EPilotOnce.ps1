#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [System.Uri]$TransferBaseUri,

    [Parameter(Mandatory=$true)]
    [System.Uri]$PhpIpamBaseUri,

    [Parameter(Mandatory=$false)]
    [switch]$TransportPreflightOnly
)

Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'

$ExpectedWorkerBlob='92d3a38c7e8415b44f92d35e6fa10e468ce77cbd'
$ExpectedPreviewBlob='f96f1f378cc6dd8217399acfda4c98c79ab5c428'
$ExpectedCollectorId='f57057d6677e1ca7a032aab8b68796ac'
$ExpectedJobId='a4ee3e5987dd15495d7e2419e853d9c8'

function Get-Dns8GitBlobSha1 {
    param([Parameter(Mandatory=$true)][string]$Path)
    [byte[]]$data=[System.IO.File]::ReadAllBytes($Path)
    [byte[]]$prefix=[System.Text.Encoding]::ASCII.GetBytes(('blob '+$data.Length+[char]0))
    [byte[]]$all=New-Object byte[] ($prefix.Length+$data.Length)
    [Array]::Copy($prefix,0,$all,0,$prefix.Length)
    [Array]::Copy($data,0,$all,$prefix.Length,$data.Length)
    $sha=New-Object System.Security.Cryptography.SHA1Managed
    try {
        return ([BitConverter]::ToString($sha.ComputeHash($all))).Replace('-','').ToLowerInvariant()
    }
    finally {
        $sha.Dispose()
        [Array]::Clear($all,0,$all.Length)
        [Array]::Clear($data,0,$data.Length)
    }
}

if (-not $TransferBaseUri.IsAbsoluteUri -or $TransferBaseUri.Scheme -ne 'https' -or
    -not $PhpIpamBaseUri.IsAbsoluteUri -or $PhpIpamBaseUri.Scheme -ne 'https' -or
    $TransferBaseUri.Authority -ne $PhpIpamBaseUri.Authority) {
    throw 'dns8_e2e_uri_invalid'
}

[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
$wc=New-Object Net.WebClient
$wc.Proxy=[Net.GlobalProxySelection]::GetEmptyWebProxy()

$temp=Join-Path $env:TEMP ('phpipam-dns-e2e-'+[Guid]::NewGuid().ToString('N'))
$worker=Join-Path $temp 'Invoke-PhpIpamDnsWorkerOnce.ps1'
$preview=Join-Path $temp 'Invoke-PhpIpamDnsPreview.ps1'
$tokenText=$null
$tokenSecure=$null
$oldDefaultProxy=$null
$defaultProxyChanged=$false

try {
    New-Item -ItemType Directory -Path $temp -ErrorAction Stop | Out-Null
    $workerUri=New-Object System.Uri($TransferBaseUri,'worker.txt')
    $previewUri=New-Object System.Uri($TransferBaseUri,'preview.txt')
    $tokenUri=New-Object System.Uri($TransferBaseUri,'token.php')

    [System.IO.File]::WriteAllBytes($worker,$wc.DownloadData($workerUri))
    [System.IO.File]::WriteAllBytes($preview,$wc.DownloadData($previewUri))

    $workerBlob=Get-Dns8GitBlobSha1 -Path $worker
    $previewBlob=Get-Dns8GitBlobSha1 -Path $preview
    if ($workerBlob -ne $ExpectedWorkerBlob) { throw 'dns8_e2e_worker_blob_mismatch' }
    if ($previewBlob -ne $ExpectedPreviewBlob) { throw 'dns8_e2e_preview_blob_mismatch' }

    $tokens=$null
    $errors=$null
    [void][System.Management.Automation.Language.Parser]::ParseFile($worker,[ref]$tokens,[ref]$errors)
    if (@($errors).Count -ne 0) { throw 'dns8_e2e_worker_parse_failed' }
    $tokens=$null
    $errors=$null
    [void][System.Management.Automation.Language.Parser]::ParseFile($preview,[ref]$tokens,[ref]$errors)
    if (@($errors).Count -ne 0) { throw 'dns8_e2e_preview_parse_failed' }

    if ($TransportPreflightOnly) {
        Write-Output 'DNS8_E2E_TRANSPORT_PREFLIGHT_OK=1'
        Write-Output ('DNS8_E2E_TRANSPORT_WORKER_BLOB='+$workerBlob)
        Write-Output ('DNS8_E2E_TRANSPORT_PREVIEW_BLOB='+$previewBlob)
        Write-Output 'DNS8_E2E_TRANSPORT_TOKEN_USED=0'
        Write-Output 'DNS8_E2E_TRANSPORT_WORKER_EXECUTED=0'
        return
    }

    $tokenText=$wc.DownloadString($tokenUri).Trim()
    if (-not [regex]::IsMatch($tokenText,('^dnsctl1\.'+$ExpectedCollectorId+'\.[A-Za-z0-9_-]{43}$'))) {
        throw 'dns8_e2e_token_invalid'
    }
    $tokenSecure=ConvertTo-SecureString -String $tokenText -AsPlainText -Force
    $tokenText=$null

    $oldDefaultProxy=[System.Net.WebRequest]::DefaultWebProxy
    [System.Net.WebRequest]::DefaultWebProxy=[Net.GlobalProxySelection]::GetEmptyWebProxy()
    $defaultProxyChanged=$true
    $workerOutput=@(& $worker -PhpIpamBaseUri $PhpIpamBaseUri -ControlToken $tokenSecure)
    if ($workerOutput.Count -ne 1) { throw 'dns8_e2e_worker_result_count_invalid' }
    $result=$workerOutput[0]
    if ([string]$result.state -ne 'submitted' -or
        [string]$result.job_id -ne $ExpectedJobId -or
        [string]$result.job_type -ne 'test_connection') {
        throw 'dns8_e2e_worker_result_invalid'
    }

    Write-Output 'DNS8_E2E_WINDOWS_EXECUTED=1'
    Write-Output ('DNS8_E2E_WINDOWS_STATE='+[string]$result.state)
    Write-Output ('DNS8_E2E_WINDOWS_JOB_ID='+[string]$result.job_id)
    Write-Output ('DNS8_E2E_WINDOWS_JOB_TYPE='+[string]$result.job_type)
    Write-Output ('DNS8_E2E_WINDOWS_RESULT_OK='+([int][bool]$result.result_ok))
    Write-Output ('DNS8_E2E_WINDOWS_RESULT_CODE='+[string]$result.result_code)
    Write-Output 'DNS8_E2E_WINDOWS_TOKEN_PRINTED=0'
}
finally {
    if ($defaultProxyChanged) {
        [System.Net.WebRequest]::DefaultWebProxy=$oldDefaultProxy
    }
    $oldDefaultProxy=$null
    $defaultProxyChanged=$false
    $tokenText=$null
    $tokenSecure=$null
    if (Test-Path -LiteralPath $temp) {
        Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
    }
    $wc.Dispose()
}
