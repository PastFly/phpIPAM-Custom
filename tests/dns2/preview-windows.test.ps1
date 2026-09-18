#requires -Version 5.1
Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'

if ($PSVersionTable.PSVersion.Major -ne 5) {
    throw "windows_powershell_5_required"
}

$scriptPath=Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'collectors\windows\dns\Invoke-PhpIpamDnsPreview.ps1'
$n=0
function Assert-Dns2 {
    param([bool]$Value,[string]$Label)
    $script:n++
    if (-not $Value) { throw $Label }
}

$global:DNS2_NewSessionArgs=$null
$global:DNS2_RemoveSessionCount=0

function global:Import-Module {
    [CmdletBinding()] param([Parameter(Position=0)][string]$Name)
    if ($Name -ne 'DnsServer') { throw 'unexpected_module' }
}
function global:New-CimSession {
    [CmdletBinding()] param(
        [string]$ComputerName,
        [PSCredential]$Credential,
        [string]$Authentication,
        [uint32]$OperationTimeoutSec
    )
    $global:DNS2_NewSessionArgs=[pscustomobject]@{
        ComputerName=$ComputerName
        UserName=$Credential.UserName
        Authentication=$Authentication
        OperationTimeoutSec=$OperationTimeoutSec
    }
    return [pscustomobject]@{ Id='fixture-session' }
}
function global:Remove-CimSession {
    [CmdletBinding()] param($CimSession)
    $global:DNS2_RemoveSessionCount++
}
function global:Get-DnsServerZone {
    [CmdletBinding()] param($CimSession)
    return @(
        [pscustomobject]@{ZoneName='Example.Invalid';ZoneType='Primary';IsDsIntegrated=$true;IsReverseLookupZone=$false},
        [pscustomobject]@{ZoneName='30.20.10.in-addr.arpa';ZoneType='Primary';IsDsIntegrated=$true;IsReverseLookupZone=$true}
    )
}
function New-Dns2Record {
    param([string]$Host,[string]$Type,[string]$Data,[DateTime]$Timestamp=[DateTime]::MinValue)
    $recordData = switch ($Type) {
        'A' { [pscustomobject]@{IPv4Address=[System.Net.IPAddress]::Parse($Data)} }
        'AAAA' { [pscustomobject]@{IPv6Address=[System.Net.IPAddress]::Parse($Data)} }
        'CNAME' { [pscustomobject]@{HostNameAlias=$Data} }
        'PTR' { [pscustomobject]@{PtrDomainName=$Data} }
    }
    return [pscustomobject]@{
        HostName=$Host
        RecordData=$recordData
        TimeToLive=[TimeSpan]::FromSeconds(300)
        Timestamp=$Timestamp
    }
}
function global:Get-DnsServerResourceRecord {
    [CmdletBinding()] param($CimSession,[string]$ZoneName,[string]$RRType)
    if ($ZoneName -ieq 'Example.Invalid') {
        switch ($RRType) {
            'A' { return @(New-Dns2Record '@' 'A' '10.20.30.40') }
            'AAAA' { return @(New-Dns2Record 'hostv6' 'AAAA' '2001:0db8:0:0:0:0:0:1') }
            'CNAME' { return @(New-Dns2Record '_service' 'CNAME' 'Target.Example.Invalid.') }
            'PTR' { return @() }
        }
    }
    if ($ZoneName -ieq '30.20.10.in-addr.arpa') {
        if ($RRType -eq 'PTR') { return @(New-Dns2Record '40' 'PTR' 'Host.Example.Invalid.') }
        return @()
    }
    throw 'unexpected_zone'
}

$secure=ConvertTo-SecureString 'fixture-only' -AsPlainText -Force
$credential=New-Object System.Management.Automation.PSCredential('svc@example.invalid',$secure)
$temp=Join-Path $env:TEMP ('dns2-preview-'+[Guid]::NewGuid().ToString('N')+'.json')

try {
    $discovery=@(& $scriptPath -Server 'dc01.example.invalid' -Credential $credential -OperationTimeoutSeconds 45)
    Assert-Dns2 ($discovery.Count -eq 1) 'discovery returns one summary'
    Assert-Dns2 ($discovery[0].action -eq 'list_zones' -and $discovery[0].zone_count -eq 2) 'discovery summary'
    Assert-Dns2 ($global:DNS2_NewSessionArgs.Authentication -eq 'Kerberos') 'kerberos enforced'
    Assert-Dns2 ($global:DNS2_NewSessionArgs.OperationTimeoutSec -eq 45) 'operation timeout propagated'
    Assert-Dns2 ($global:DNS2_NewSessionArgs.UserName -eq 'svc@example.invalid') 'credential object propagated'
    Assert-Dns2 ($global:DNS2_RemoveSessionCount -eq 1) 'discovery session removed'

    $result=@(& $scriptPath -Server 'DC01.EXAMPLE.INVALID' -Credential $credential -Zone @('Example.Invalid','30.20.10.in-addr.arpa') -OutputPath $temp)
    Assert-Dns2 ($result.Count -eq 1) 'collection returns one summary'
    Assert-Dns2 ($result[0].complete -eq $true -and $result[0].zone_count -eq 2 -and $result[0].record_count -eq 4) 'collection summary'
    Assert-Dns2 ($result[0].server -eq 'dc01.example.invalid') 'server normalized'
    Assert-Dns2 (Test-Path -LiteralPath $temp -PathType Leaf) 'explicit output created'

    $json=Get-Content -LiteralPath $temp -Raw | ConvertFrom-Json
    Assert-Dns2 ($json.Count -eq 2) 'multi-zone file is one JSON array'
    Assert-Dns2 ($json[0].schema_version -eq 1 -and $json[0].mode -eq 'preview') 'snapshot envelope'
    Assert-Dns2 ($json[0].virtualization_instance -eq 'default' -and $json[0].zone_scope -eq 'default') 'default scope explicit'
    Assert-Dns2 (($json[0].supported_types -join ',') -eq 'A,AAAA,CNAME,PTR') 'supported types explicit'
    Assert-Dns2 ($json[0].complete -eq $true -and $json[0].record_count -eq 3) 'forward snapshot complete'
    Assert-Dns2 ($json[1].record_count -eq 1) 'reverse snapshot complete'

    $records=@($json | ForEach-Object { $_.records })
    $a=@($records | Where-Object {$_.type -eq 'A'})[0]
    $aaaa=@($records | Where-Object {$_.type -eq 'AAAA'})[0]
    $cname=@($records | Where-Object {$_.type -eq 'CNAME'})[0]
    $ptr=@($records | Where-Object {$_.type -eq 'PTR'})[0]

    Assert-Dns2 ($a.owner -eq 'example.invalid.' -and $a.rdata -eq '10.20.30.40') 'apex A normalized'
    Assert-Dns2 ($a.source_aging_timestamp -eq $null) 'static timestamp remains null'
    Assert-Dns2 ($aaaa.owner -eq 'hostv6.example.invalid.' -and $aaaa.rdata -eq '2001:db8::1') 'IPv6 canonicalized'
    Assert-Dns2 ($cname.owner -eq '_service.example.invalid.' -and $cname.rdata -eq 'target.example.invalid.') 'underscore CNAME normalized'
    Assert-Dns2 ($ptr.owner -eq '40.30.20.10.in-addr.arpa.' -and $ptr.rdata -eq 'host.example.invalid.') 'PTR normalized'
    Assert-Dns2 (($records | Where-Object {$_.ttl_seconds -ne 300}).Count -eq 0) 'TTL preserved'
    Assert-Dns2 (-not ((Get-Content -LiteralPath $temp -Raw) -match 'fixture-only')) 'password absent from preview output'
    Assert-Dns2 ($global:DNS2_RemoveSessionCount -eq 2) 'collection session removed'
}
finally {
    Remove-Item -LiteralPath $temp -Force -ErrorAction SilentlyContinue
    foreach ($name in @('Import-Module','New-CimSession','Remove-CimSession','Get-DnsServerZone','Get-DnsServerResourceRecord')) {
        Remove-Item -Path ('function:global:'+ $name) -Force -ErrorAction SilentlyContinue
    }
}

Write-Host ('DNS2_PREVIEW_WINDOWS_ASSERTIONS='+$n)
Write-Host 'DNS2_PREVIEW_WINDOWS_TESTS_OK=1'
