#requires -Version 5.1
Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'

if ($PSVersionTable.PSVersion.Major -ne 5) {
    throw 'windows_powershell_5_required'
}

$root=Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$worker=Join-Path $root 'collectors\windows\dns\Invoke-PhpIpamDnsWorkerOnce.ps1'
$n=0
function Assert-Dns7 {
    param([bool]$Value,[string]$Label)
    $script:n++
    if (-not $Value) { throw $Label }
}

$global:DNS7_Mode='profile-list'
$global:DNS7_ControlCalls=0
$global:DNS7_CredentialCalls=0
$global:DNS7_ResultBody=$null
$global:DNS7_SessionCredentialMarker='unset'
$global:DNS7_RemoveSessionCount=0

function global:Import-Module {
    [CmdletBinding()] param([Parameter(Position=0)][string]$Name)
    if ($Name -ne 'DnsServer') { throw 'unexpected_module' }
}
function global:New-CimSession {
    [CmdletBinding()] param(
        [string]$ComputerName,
        [PSCredential]$Credential,
        [string]$Authentication,
        [uint32]$OperationTimeoutSec,
        [string]$ErrorAction
    )
    $global:DNS7_SessionCredentialMarker = if ($null -eq $Credential) { '<current>' } else { $Credential.UserName }
    if ($Authentication -ne 'Kerberos') { throw 'kerberos_required' }
    return [pscustomobject]@{ Id='fixture-session' }
}
function global:Remove-CimSession {
    [CmdletBinding()] param($CimSession,[string]$ErrorAction)
    $global:DNS7_RemoveSessionCount++
}
function global:Get-DnsServerZone {
    [CmdletBinding()] param($CimSession,[string]$ErrorAction)
    return @(
        [pscustomobject]@{ZoneName='Example.Invalid';ZoneType='Primary';IsDsIntegrated=$true;IsReverseLookupZone=$false},
        [pscustomobject]@{ZoneName='30.20.10.in-addr.arpa';ZoneType='Primary';IsDsIntegrated=$true;IsReverseLookupZone=$true}
    )
}
function global:Get-DnsServerResourceRecord {
    [CmdletBinding()] param($CimSession,[string]$ZoneName,[string]$RRType,[string]$ErrorAction)
    return @()
}
function global:Invoke-RestMethod {
    [CmdletBinding()] param(
        [Uri]$Uri,[string]$Method,[hashtable]$Headers,[string]$ContentType,
        [string]$Body,[int]$TimeoutSec,[string]$ErrorAction
    )
    Assert-Dns7 ($Method -eq 'Post') 'HTTP method is POST'
    Assert-Dns7 ($Uri.Scheme -eq 'https') 'HTTPS endpoint'
    Assert-Dns7 ($ContentType -eq 'application/json') 'JSON content type'
    Assert-Dns7 ($Headers.Authorization -match '^Bearer dnsctl1\.') 'Bearer collector token'
    $doc=$Body | ConvertFrom-Json

    if ($Uri.AbsolutePath.EndsWith('/machine-control.php')) {
        $global:DNS7_ControlCalls++
        if ($doc.action -eq 'poll') {
            if ($global:DNS7_Mode -eq 'idle') { return $null }
            $jobType = if ($global:DNS7_Mode -eq 'profile-list') { 'list_zones' } else { 'test_connection' }
            $credVersion = if ($global:DNS7_Mode -eq 'profile-list') { 3 } else { $null }
            return [pscustomobject]@{
                ok=$true
                data=[pscustomobject][ordered]@{
                    id=('a'*32)
                    job_type=$jobType
                    server='dc01.example.invalid'
                    configuration_revision=7
                    binding_epoch=4
                    credential_version=$credVersion
                    assignment_revision=2
                    lease_id=('b'*32)
                    lease_expires_at=2000000000
                }
            }
        }
        if ($doc.action -eq 'result') {
            $global:DNS7_ResultBody=$doc
            return [pscustomobject]@{ok=$true;data=[pscustomobject]@{state='succeeded'}}
        }
        throw 'unexpected_control_action'
    }

    if ($Uri.AbsolutePath.EndsWith('/machine-credential.php')) {
        $global:DNS7_CredentialCalls++
        Assert-Dns7 ($doc.job_id -eq ('a'*32) -and $doc.lease_id -eq ('b'*32)) 'credential bound to leased job'
        if ($global:DNS7_Mode -eq 'profile-list') {
            return [pscustomobject]@{
                ok=$true
                data=[pscustomobject][ordered]@{
                    credential_mode='profile'
                    username='svc@example.invalid'
                    password='fixture-only-password'
                }
            }
        }
        return [pscustomobject]@{ok=$true;data=[pscustomobject][ordered]@{credential_mode='collector_identity'}}
    }

    throw 'unexpected_uri'
}

$tokenText='dnsctl1.'+('1'*32)+'.'+('A'*43)
$secureToken=ConvertTo-SecureString $tokenText -AsPlainText -Force

try {
    $global:DNS7_Mode='profile-list'
    $global:DNS7_ResultBody=$null
    $out=@(& $worker -PhpIpamBaseUri 'https://ipam.example.invalid/' -ControlToken $secureToken)
    Assert-Dns7 ($out.Count -eq 1 -and $out[0].state -eq 'submitted') 'profile list-zones submitted'
    Assert-Dns7 ($out[0].job_type -eq 'list_zones' -and $out[0].result_ok -eq $true) 'list-zones success summary'
    Assert-Dns7 ($global:DNS7_SessionCredentialMarker -eq 'svc@example.invalid') 'profile credential passed to Preview'
    Assert-Dns7 ($global:DNS7_ResultBody.data.result.zones.Count -eq 2) 'two zone descriptors submitted'
    Assert-Dns7 ($global:DNS7_ResultBody.data.result.zones[0].PSObject.Properties.Name -join ',' -eq 'name,zone_type,ds_integrated,reverse_lookup') 'zone result allowlist'
    Assert-Dns7 (($global:DNS7_ResultBody | ConvertTo-Json -Depth 8) -notmatch 'fixture-only-password') 'domain password absent from result'
    Assert-Dns7 (($global:DNS7_ResultBody | ConvertTo-Json -Depth 8) -notmatch 'dnsctl1') 'collector token absent from result'
    Assert-Dns7 ($global:DNS7_RemoveSessionCount -eq 1) 'profile Preview session removed'

    $global:DNS7_Mode='collector-test'
    $global:DNS7_ResultBody=$null
    $out=@(& $worker -PhpIpamBaseUri 'https://ipam.example.invalid/' -ControlToken $secureToken)
    Assert-Dns7 ($out.Count -eq 1 -and $out[0].job_type -eq 'test_connection') 'collector identity test submitted'
    Assert-Dns7 ($global:DNS7_SessionCredentialMarker -eq '<current>') 'collector identity omits explicit credential'
    Assert-Dns7 ($global:DNS7_ResultBody.data.result.ok -eq $true -and $global:DNS7_ResultBody.data.result.error_code -eq $null) 'test result allowlisted'
    Assert-Dns7 ($global:DNS7_RemoveSessionCount -eq 2) 'collector identity Preview session removed'

    $beforeCred=$global:DNS7_CredentialCalls
    $global:DNS7_Mode='idle'
    $out=@(& $worker -PhpIpamBaseUri 'https://ipam.example.invalid/' -ControlToken $secureToken)
    Assert-Dns7 ($out.Count -eq 1 -and $out[0].state -eq 'idle') 'idle poll returns without work'
    Assert-Dns7 ($global:DNS7_CredentialCalls -eq $beforeCred) 'idle poll never requests credentials'
}
finally {
    foreach($name in @('Import-Module','New-CimSession','Remove-CimSession','Get-DnsServerZone','Get-DnsServerResourceRecord','Invoke-RestMethod')) {
        Remove-Item -Path ('function:global:'+ $name) -Force -ErrorAction SilentlyContinue
    }
}

Write-Host ('DNS7_ORCHESTRATION_WINDOWS_ASSERTIONS='+$n)
Write-Host 'DNS7_ORCHESTRATION_WINDOWS_TESTS_OK=1'
