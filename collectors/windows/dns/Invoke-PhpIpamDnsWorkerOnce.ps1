#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [System.Uri]$PhpIpamBaseUri,

    [Parameter(Mandatory=$true)]
    [System.Security.SecureString]$ControlToken,

    [Parameter(Mandatory=$false)]
    [ValidateRange(5,120)]
    [int]$HttpTimeoutSeconds = 30,

    [Parameter(Mandatory=$false)]
    [ValidateRange(15,180)]
    [int]$DnsTimeoutSeconds = 60
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Get-Dns7PlainSecureString {
    param([Parameter(Mandatory=$true)][System.Security.SecureString]$Value)
    $ptr=[IntPtr]::Zero
    try {
        $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    }
    finally {
        if ($ptr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
        }
    }
}

function Test-Dns7ExactProperties {
    param(
        [Parameter(Mandatory=$true)]$Object,
        [Parameter(Mandatory=$true)][string[]]$Names
    )
    if ($null -eq $Object) { return $false }
    $actual=@($Object.PSObject.Properties.Name | Sort-Object)
    $expected=@($Names | Sort-Object)
    return (($actual -join [char]0) -eq ($expected -join [char]0))
}

function Test-Dns7PositiveInteger {
    param($Value)
    if ($null -eq $Value) { return $false }
    $parsed=[int64]0
    if (-not [int64]::TryParse([string]$Value,[ref]$parsed)) { return $false }
    return ($parsed -ge 1 -and $parsed -le 2147483647)
}

function ConvertTo-Dns7Json {
    param([Parameter(Mandatory=$true)]$Value)
    return ConvertTo-Json -InputObject $Value -Depth 8 -Compress
}

function Invoke-Dns7Post {
    param(
        [Parameter(Mandatory=$true)][System.Uri]$Uri,
        [Parameter(Mandatory=$true)][string]$Authorization,
        [Parameter(Mandatory=$true)][string]$Body,
        [Parameter(Mandatory=$true)][int]$TimeoutSeconds
    )
    Invoke-RestMethod -Uri $Uri -Method Post -Headers @{ Authorization=$Authorization } -ContentType 'application/json' -Body $Body -TimeoutSec $TimeoutSeconds -ErrorAction Stop
}

function Get-Dns7FailureCode {
    param([Parameter(Mandatory=$true)]$ErrorRecord)
    $type=''
    if ($null -ne $ErrorRecord.Exception) { $type=$ErrorRecord.Exception.GetType().FullName }
    $fqid=[string]$ErrorRecord.FullyQualifiedErrorId
    $text=($type+' '+$fqid).ToLowerInvariant()

    if ($text.Contains('timeout')) { return 'timeout' }
    if ($text.Contains('unauthorizedaccess') -or $text.Contains('accessdenied')) { return 'authorization' }
    if ($text.Contains('authentication')) { return 'authentication' }
    if ($text.Contains('commandnotfound') -or $text.Contains('modulenotfound')) { return 'configuration' }
    if ($text.Contains('cimexception')) { return 'provider' }
    if ($text.Contains('webexception') -or $text.Contains('socketexception')) { return 'network' }
    return 'unknown'
}

if (-not $PhpIpamBaseUri.IsAbsoluteUri -or
    $PhpIpamBaseUri.Scheme -ne 'https' -or
    -not [string]::IsNullOrEmpty($PhpIpamBaseUri.UserInfo) -or
    -not [string]::IsNullOrEmpty($PhpIpamBaseUri.Query) -or
    -not [string]::IsNullOrEmpty($PhpIpamBaseUri.Fragment)) {
    throw 'dns_worker_base_uri_invalid'
}

$baseText=$PhpIpamBaseUri.AbsoluteUri
if (-not $baseText.EndsWith('/')) { $baseText += '/' }
$base=[System.Uri]$baseText
$controlUri=New-Object System.Uri($base,'app/integrations/dns/machine-control.php')
$credentialUri=New-Object System.Uri($base,'app/integrations/dns/machine-credential.php')
if ($controlUri.Scheme -ne 'https' -or $credentialUri.Scheme -ne 'https' -or
    $controlUri.Authority -ne $base.Authority -or $credentialUri.Authority -ne $base.Authority) {
    throw 'dns_worker_endpoint_invalid'
}

$preview=Join-Path $PSScriptRoot 'Invoke-PhpIpamDnsPreview.ps1'
if (-not (Test-Path -LiteralPath $preview -PathType Leaf)) {
    throw 'dns_worker_preview_missing'
}

$token=$null
$authorization=$null
$credentialResponse=$null
$domainPassword=$null
$domainSecure=$null
$domainCredential=$null
$tempPath=$null

try {
    $token=Get-Dns7PlainSecureString -Value $ControlToken
    if (-not [regex]::IsMatch($token,'^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$')) {
        throw 'dns_worker_token_invalid'
    }
    $authorization='Bearer '+$token

    $pollBody=ConvertTo-Dns7Json -Value ([ordered]@{ action='poll' })
    $poll=Invoke-Dns7Post -Uri $controlUri -Authorization $authorization -Body $pollBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $poll) {
        [PSCustomObject][ordered]@{ mode='worker_once'; state='idle' }
        return
    }
    if (($poll.ok -ne $true) -or $null -eq $poll.data) { throw 'dns_worker_poll_invalid' }

    $work=$poll.data
    $workFields=@('id','job_type','server','configuration_revision','binding_epoch','credential_version','assignment_revision','lease_id','lease_expires_at')
    if (-not (Test-Dns7ExactProperties -Object $work -Names $workFields)) { throw 'dns_worker_work_invalid' }
    if (-not [regex]::IsMatch([string]$work.id,'^[0-9a-f]{32}

    $credentialBody=ConvertTo-Dns7Json -Value ([ordered]@{ job_id=[string]$work.id; lease_id=[string]$work.lease_id })
    $credentialResponse=Invoke-Dns7Post -Uri $credentialUri -Authorization $authorization -Body $credentialBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $credentialResponse -or $credentialResponse.ok -ne $true -or $null -eq $credentialResponse.data) {
        throw 'dns_worker_credential_invalid'
    }

    $credentialData=$credentialResponse.data
    $mode=[string]$credentialData.credential_mode
    if ($mode -eq 'profile') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode','username','password')) -or
            [string]::IsNullOrWhiteSpace([string]$credentialData.username) -or
            [string]::IsNullOrEmpty([string]$credentialData.password)) {
            throw 'dns_worker_credential_invalid'
        }
        $domainPassword=[string]$credentialData.password
        $domainSecure=ConvertTo-SecureString -String $domainPassword -AsPlainText -Force
        $domainCredential=New-Object System.Management.Automation.PSCredential([string]$credentialData.username,$domainSecure)
        $credentialData.password=$null
        $domainPassword=$null
    }
    elseif ($mode -eq 'collector_identity') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode'))) {
            throw 'dns_worker_credential_invalid'
        }
    }
    else {
        throw 'dns_worker_credential_invalid'
    }

    $result=$null
    try {
        $previewArgs=@{
            Server=[string]$work.server
            OperationTimeoutSeconds=$DnsTimeoutSeconds
        }
        if ($null -ne $domainCredential) { $previewArgs.Credential=$domainCredential }

        if ([string]$work.job_type -eq 'test_connection') {
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones') {
                throw 'dns_worker_preview_invalid'
            }
            $result=[ordered]@{ ok=$true; error_code=$null }
        }
        else {
            $tempPath=Join-Path $env:TEMP ('phpipam-dns-zones-'+[Guid]::NewGuid().ToString('N')+'.json')
            $previewArgs.OutputPath=$tempPath
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones' -or
                -not (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
                throw 'dns_worker_preview_invalid'
            }
            $doc=Get-Content -LiteralPath $tempPath -Raw | ConvertFrom-Json -ErrorAction Stop
            if ($null -eq $doc -or $doc.complete -ne $true -or $doc.action -ne 'list_zones' -or $null -eq $doc.zones) {
                throw 'dns_worker_preview_invalid'
            }
            $zones=@()
            foreach ($z in @($doc.zones)) {
                if (-not (Test-Dns7ExactProperties -Object $z -Names @('zone','zone_type','ds_integrated','reverse_lookup')) -or
                    [string]::IsNullOrWhiteSpace([string]$z.zone) -or
                    ([string]$z.zone).Length -gt 255 -or
                    ([string]$z.zone_type).Length -gt 32) {
                    throw 'dns_worker_preview_invalid'
                }
                $zones += [ordered]@{
                    name=([string]$z.zone).TrimEnd('.').ToLowerInvariant()
                    zone_type=[string]$z.zone_type
                    ds_integrated=[bool]$z.ds_integrated
                    reverse_lookup=[bool]$z.reverse_lookup
                }
            }
            if ($zones.Count -gt 1024) { throw 'dns_worker_preview_invalid' }
            $result=[ordered]@{ ok=$true; error_code=$null; zones=$zones }
        }
    }
    catch {
        $result=[ordered]@{ ok=$false; error_code=(Get-Dns7FailureCode -ErrorRecord $_) }
    }

    $resultBody=ConvertTo-Dns7Json -Value ([ordered]@{
        action='result'
        data=[ordered]@{
            job_id=[string]$work.id
            lease_id=[string]$work.lease_id
            result=$result
        }
    })
    $submitted=Invoke-Dns7Post -Uri $controlUri -Authorization $authorization -Body $resultBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $submitted -or $submitted.ok -ne $true -or $null -eq $submitted.data) {
        throw 'dns_worker_result_submit_failed'
    }

    [PSCustomObject][ordered]@{
        mode='worker_once'
        state='submitted'
        job_id=[string]$work.id
        job_type=[string]$work.job_type
        result_ok=[bool]$result.ok
        result_code=$(if ($result.ok) { 'ok' } else { [string]$result.error_code })
    }
}
finally {
    if ($null -ne $tempPath -and (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
        Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
    }
    if ($null -ne $credentialResponse -and $null -ne $credentialResponse.data -and
        $credentialResponse.data.PSObject.Properties.Name -contains 'password') {
        $credentialResponse.data.password=$null
    }
    $domainPassword=$null
    $domainCredential=$null
    $domainSecure=$null
    $authorization=$null
    $token=$null
}
) -or
        -not [regex]::IsMatch([string]$work.lease_id,'^[0-9a-f]{32}

    $credentialBody=ConvertTo-Dns7Json -Value ([ordered]@{ job_id=[string]$work.id; lease_id=[string]$work.lease_id })
    $credentialResponse=Invoke-Dns7Post -Uri $credentialUri -Authorization $authorization -Body $credentialBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $credentialResponse -or $credentialResponse.ok -ne $true -or $null -eq $credentialResponse.data) {
        throw 'dns_worker_credential_invalid'
    }

    $credentialData=$credentialResponse.data
    $mode=[string]$credentialData.credential_mode
    if ($mode -eq 'profile') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode','username','password')) -or
            [string]::IsNullOrWhiteSpace([string]$credentialData.username) -or
            [string]::IsNullOrEmpty([string]$credentialData.password)) {
            throw 'dns_worker_credential_invalid'
        }
        $domainPassword=[string]$credentialData.password
        $domainSecure=ConvertTo-SecureString -String $domainPassword -AsPlainText -Force
        $domainCredential=New-Object System.Management.Automation.PSCredential([string]$credentialData.username,$domainSecure)
        $credentialData.password=$null
        $domainPassword=$null
    }
    elseif ($mode -eq 'collector_identity') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode'))) {
            throw 'dns_worker_credential_invalid'
        }
    }
    else {
        throw 'dns_worker_credential_invalid'
    }

    $result=$null
    try {
        $previewArgs=@{
            Server=[string]$work.server
            OperationTimeoutSeconds=$DnsTimeoutSeconds
        }
        if ($null -ne $domainCredential) { $previewArgs.Credential=$domainCredential }

        if ([string]$work.job_type -eq 'test_connection') {
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones') {
                throw 'dns_worker_preview_invalid'
            }
            $result=[ordered]@{ ok=$true; error_code=$null }
        }
        else {
            $tempPath=Join-Path $env:TEMP ('phpipam-dns-zones-'+[Guid]::NewGuid().ToString('N')+'.json')
            $previewArgs.OutputPath=$tempPath
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones' -or
                -not (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
                throw 'dns_worker_preview_invalid'
            }
            $doc=Get-Content -LiteralPath $tempPath -Raw | ConvertFrom-Json -ErrorAction Stop
            if ($null -eq $doc -or $doc.complete -ne $true -or $doc.action -ne 'list_zones' -or $null -eq $doc.zones) {
                throw 'dns_worker_preview_invalid'
            }
            $zones=@()
            foreach ($z in @($doc.zones)) {
                if (-not (Test-Dns7ExactProperties -Object $z -Names @('zone','zone_type','ds_integrated','reverse_lookup')) -or
                    [string]::IsNullOrWhiteSpace([string]$z.zone) -or
                    ([string]$z.zone).Length -gt 255 -or
                    ([string]$z.zone_type).Length -gt 32) {
                    throw 'dns_worker_preview_invalid'
                }
                $zones += [ordered]@{
                    name=([string]$z.zone).TrimEnd('.').ToLowerInvariant()
                    zone_type=[string]$z.zone_type
                    ds_integrated=[bool]$z.ds_integrated
                    reverse_lookup=[bool]$z.reverse_lookup
                }
            }
            if ($zones.Count -gt 1024) { throw 'dns_worker_preview_invalid' }
            $result=[ordered]@{ ok=$true; error_code=$null; zones=$zones }
        }
    }
    catch {
        $result=[ordered]@{ ok=$false; error_code=(Get-Dns7FailureCode -ErrorRecord $_) }
    }

    $resultBody=ConvertTo-Dns7Json -Value ([ordered]@{
        action='result'
        data=[ordered]@{
            job_id=[string]$work.id
            lease_id=[string]$work.lease_id
            result=$result
        }
    })
    $submitted=Invoke-Dns7Post -Uri $controlUri -Authorization $authorization -Body $resultBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $submitted -or $submitted.ok -ne $true -or $null -eq $submitted.data) {
        throw 'dns_worker_result_submit_failed'
    }

    [PSCustomObject][ordered]@{
        mode='worker_once'
        state='submitted'
        job_id=[string]$work.id
        job_type=[string]$work.job_type
        result_ok=[bool]$result.ok
        result_code=$(if ($result.ok) { 'ok' } else { [string]$result.error_code })
    }
}
finally {
    if ($null -ne $tempPath -and (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
        Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
    }
    if ($null -ne $credentialResponse -and $null -ne $credentialResponse.data -and
        $credentialResponse.data.PSObject.Properties.Name -contains 'password') {
        $credentialResponse.data.password=$null
    }
    $domainPassword=$null
    $domainCredential=$null
    $domainSecure=$null
    $authorization=$null
    $token=$null
}
) -or
        ([string]$work.job_type -notin @('test_connection','list_zones')) -or
        -not [regex]::IsMatch([string]$work.server,'^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?

    $credentialBody=ConvertTo-Dns7Json -Value ([ordered]@{ job_id=[string]$work.id; lease_id=[string]$work.lease_id })
    $credentialResponse=Invoke-Dns7Post -Uri $credentialUri -Authorization $authorization -Body $credentialBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $credentialResponse -or $credentialResponse.ok -ne $true -or $null -eq $credentialResponse.data) {
        throw 'dns_worker_credential_invalid'
    }

    $credentialData=$credentialResponse.data
    $mode=[string]$credentialData.credential_mode
    if ($mode -eq 'profile') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode','username','password')) -or
            [string]::IsNullOrWhiteSpace([string]$credentialData.username) -or
            [string]::IsNullOrEmpty([string]$credentialData.password)) {
            throw 'dns_worker_credential_invalid'
        }
        $domainPassword=[string]$credentialData.password
        $domainSecure=ConvertTo-SecureString -String $domainPassword -AsPlainText -Force
        $domainCredential=New-Object System.Management.Automation.PSCredential([string]$credentialData.username,$domainSecure)
        $credentialData.password=$null
        $domainPassword=$null
    }
    elseif ($mode -eq 'collector_identity') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode'))) {
            throw 'dns_worker_credential_invalid'
        }
    }
    else {
        throw 'dns_worker_credential_invalid'
    }

    $result=$null
    try {
        $previewArgs=@{
            Server=[string]$work.server
            OperationTimeoutSeconds=$DnsTimeoutSeconds
        }
        if ($null -ne $domainCredential) { $previewArgs.Credential=$domainCredential }

        if ([string]$work.job_type -eq 'test_connection') {
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones') {
                throw 'dns_worker_preview_invalid'
            }
            $result=[ordered]@{ ok=$true; error_code=$null }
        }
        else {
            $tempPath=Join-Path $env:TEMP ('phpipam-dns-zones-'+[Guid]::NewGuid().ToString('N')+'.json')
            $previewArgs.OutputPath=$tempPath
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones' -or
                -not (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
                throw 'dns_worker_preview_invalid'
            }
            $doc=Get-Content -LiteralPath $tempPath -Raw | ConvertFrom-Json -ErrorAction Stop
            if ($null -eq $doc -or $doc.complete -ne $true -or $doc.action -ne 'list_zones' -or $null -eq $doc.zones) {
                throw 'dns_worker_preview_invalid'
            }
            $zones=@()
            foreach ($z in @($doc.zones)) {
                if (-not (Test-Dns7ExactProperties -Object $z -Names @('zone','zone_type','ds_integrated','reverse_lookup')) -or
                    [string]::IsNullOrWhiteSpace([string]$z.zone) -or
                    ([string]$z.zone).Length -gt 255 -or
                    ([string]$z.zone_type).Length -gt 32) {
                    throw 'dns_worker_preview_invalid'
                }
                $zones += [ordered]@{
                    name=([string]$z.zone).TrimEnd('.').ToLowerInvariant()
                    zone_type=[string]$z.zone_type
                    ds_integrated=[bool]$z.ds_integrated
                    reverse_lookup=[bool]$z.reverse_lookup
                }
            }
            if ($zones.Count -gt 1024) { throw 'dns_worker_preview_invalid' }
            $result=[ordered]@{ ok=$true; error_code=$null; zones=$zones }
        }
    }
    catch {
        $result=[ordered]@{ ok=$false; error_code=(Get-Dns7FailureCode -ErrorRecord $_) }
    }

    $resultBody=ConvertTo-Dns7Json -Value ([ordered]@{
        action='result'
        data=[ordered]@{
            job_id=[string]$work.id
            lease_id=[string]$work.lease_id
            result=$result
        }
    })
    $submitted=Invoke-Dns7Post -Uri $controlUri -Authorization $authorization -Body $resultBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $submitted -or $submitted.ok -ne $true -or $null -eq $submitted.data) {
        throw 'dns_worker_result_submit_failed'
    }

    [PSCustomObject][ordered]@{
        mode='worker_once'
        state='submitted'
        job_id=[string]$work.id
        job_type=[string]$work.job_type
        result_ok=[bool]$result.ok
        result_code=$(if ($result.ok) { 'ok' } else { [string]$result.error_code })
    }
}
finally {
    if ($null -ne $tempPath -and (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
        Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
    }
    if ($null -ne $credentialResponse -and $null -ne $credentialResponse.data -and
        $credentialResponse.data.PSObject.Properties.Name -contains 'password') {
        $credentialResponse.data.password=$null
    }
    $domainPassword=$null
    $domainCredential=$null
    $domainSecure=$null
    $authorization=$null
    $token=$null
}
) -or
        -not (Test-Dns7PositiveInteger $work.configuration_revision) -or
        -not (Test-Dns7PositiveInteger $work.binding_epoch) -or
        -not (Test-Dns7PositiveInteger $work.assignment_revision) -or
        -not (Test-Dns7PositiveInteger $work.lease_expires_at) -or
        ($null -ne $work.credential_version -and -not (Test-Dns7PositiveInteger $work.credential_version))) {
        throw 'dns_worker_work_invalid'
    }

    $credentialBody=ConvertTo-Dns7Json -Value ([ordered]@{ job_id=[string]$work.id; lease_id=[string]$work.lease_id })
    $credentialResponse=Invoke-Dns7Post -Uri $credentialUri -Authorization $authorization -Body $credentialBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $credentialResponse -or $credentialResponse.ok -ne $true -or $null -eq $credentialResponse.data) {
        throw 'dns_worker_credential_invalid'
    }

    $credentialData=$credentialResponse.data
    $mode=[string]$credentialData.credential_mode
    if ($mode -eq 'profile') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode','username','password')) -or
            [string]::IsNullOrWhiteSpace([string]$credentialData.username) -or
            [string]::IsNullOrEmpty([string]$credentialData.password)) {
            throw 'dns_worker_credential_invalid'
        }
        $domainPassword=[string]$credentialData.password
        $domainSecure=ConvertTo-SecureString -String $domainPassword -AsPlainText -Force
        $domainCredential=New-Object System.Management.Automation.PSCredential([string]$credentialData.username,$domainSecure)
        $credentialData.password=$null
        $domainPassword=$null
    }
    elseif ($mode -eq 'collector_identity') {
        if (-not (Test-Dns7ExactProperties -Object $credentialData -Names @('credential_mode'))) {
            throw 'dns_worker_credential_invalid'
        }
    }
    else {
        throw 'dns_worker_credential_invalid'
    }

    $result=$null
    try {
        $previewArgs=@{
            Server=[string]$work.server
            OperationTimeoutSeconds=$DnsTimeoutSeconds
        }
        if ($null -ne $domainCredential) { $previewArgs.Credential=$domainCredential }

        if ([string]$work.job_type -eq 'test_connection') {
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones') {
                throw 'dns_worker_preview_invalid'
            }
            $result=[ordered]@{ ok=$true; error_code=$null }
        }
        else {
            $tempPath=Join-Path $env:TEMP ('phpipam-dns-zones-'+[Guid]::NewGuid().ToString('N')+'.json')
            $previewArgs.OutputPath=$tempPath
            $summary=@(& $preview @previewArgs)
            if ($summary.Count -ne 1 -or $summary[0].complete -ne $true -or $summary[0].action -ne 'list_zones' -or
                -not (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
                throw 'dns_worker_preview_invalid'
            }
            $doc=Get-Content -LiteralPath $tempPath -Raw | ConvertFrom-Json -ErrorAction Stop
            if ($null -eq $doc -or $doc.complete -ne $true -or $doc.action -ne 'list_zones' -or $null -eq $doc.zones) {
                throw 'dns_worker_preview_invalid'
            }
            $zones=@()
            foreach ($z in @($doc.zones)) {
                if (-not (Test-Dns7ExactProperties -Object $z -Names @('zone','zone_type','ds_integrated','reverse_lookup')) -or
                    [string]::IsNullOrWhiteSpace([string]$z.zone) -or
                    ([string]$z.zone).Length -gt 255 -or
                    ([string]$z.zone_type).Length -gt 32) {
                    throw 'dns_worker_preview_invalid'
                }
                $zones += [ordered]@{
                    name=([string]$z.zone).TrimEnd('.').ToLowerInvariant()
                    zone_type=[string]$z.zone_type
                    ds_integrated=[bool]$z.ds_integrated
                    reverse_lookup=[bool]$z.reverse_lookup
                }
            }
            if ($zones.Count -gt 1024) { throw 'dns_worker_preview_invalid' }
            $result=[ordered]@{ ok=$true; error_code=$null; zones=$zones }
        }
    }
    catch {
        $result=[ordered]@{ ok=$false; error_code=(Get-Dns7FailureCode -ErrorRecord $_) }
    }

    $resultBody=ConvertTo-Dns7Json -Value ([ordered]@{
        action='result'
        data=[ordered]@{
            job_id=[string]$work.id
            lease_id=[string]$work.lease_id
            result=$result
        }
    })
    $submitted=Invoke-Dns7Post -Uri $controlUri -Authorization $authorization -Body $resultBody -TimeoutSeconds $HttpTimeoutSeconds
    if ($null -eq $submitted -or $submitted.ok -ne $true -or $null -eq $submitted.data) {
        throw 'dns_worker_result_submit_failed'
    }

    [PSCustomObject][ordered]@{
        mode='worker_once'
        state='submitted'
        job_id=[string]$work.id
        job_type=[string]$work.job_type
        result_ok=[bool]$result.ok
        result_code=$(if ($result.ok) { 'ok' } else { [string]$result.error_code })
    }
}
finally {
    if ($null -ne $tempPath -and (Test-Path -LiteralPath $tempPath -PathType Leaf)) {
        Remove-Item -LiteralPath $tempPath -Force -ErrorAction SilentlyContinue
    }
    if ($null -ne $credentialResponse -and $null -ne $credentialResponse.data -and
        $credentialResponse.data.PSObject.Properties.Name -contains 'password') {
        $credentialResponse.data.password=$null
    }
    $domainPassword=$null
    $domainCredential=$null
    $domainSecure=$null
    $authorization=$null
    $token=$null
}
