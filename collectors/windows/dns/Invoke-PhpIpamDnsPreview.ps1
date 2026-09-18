#requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [ValidatePattern('^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$')]
    [string]$Server,

    [Parameter(Mandatory=$true)]
    [System.Management.Automation.PSCredential]$Credential,

    [Parameter(Mandatory=$false)]
    [ValidateCount(1,64)]
    [string[]]$Zone,

    [Parameter(Mandatory=$false)]
    [ValidateRange(15,180)]
    [int]$OperationTimeoutSeconds = 60,

    [Parameter(Mandatory=$false)]
    [string]$OutputPath
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function ConvertTo-DnsPreviewName {
    param(
        [Parameter(Mandatory=$true)][string]$Name,
        [Parameter(Mandatory=$false)][string]$ZoneName
    )
    $value = $Name.Trim()
    if ($value -eq '') { throw 'dns_preview_name_invalid' }

    if ($ZoneName) {
        $zone = $ZoneName.Trim().TrimEnd('.').ToLowerInvariant()
        if ($value -eq '@') { return $zone + '.' }
        $candidate = $value.TrimEnd('.').ToLowerInvariant()
        if ($candidate -eq $zone -or $candidate.EndsWith('.' + $zone)) {
            return $candidate + '.'
        }
        return $candidate + '.' + $zone + '.'
    }

    return $value.TrimEnd('.').ToLowerInvariant() + '.'
}

function ConvertTo-DnsPreviewAddress {
    param([Parameter(Mandatory=$true)][string]$Value)
    return ([System.Net.IPAddress]::Parse($Value)).ToString()
}

function ConvertTo-DnsPreviewTimestamp {
    param($Value)
    if ($null -eq $Value) { return $null }
    try {
        $date = [DateTime]$Value
        if ($date.Year -le 1601) { return $null }
        return ([DateTimeOffset]$date.ToUniversalTime()).ToUnixTimeSeconds()
    }
    catch {
        return $null
    }
}

function ConvertTo-DnsPreviewRecord {
    param(
        [Parameter(Mandatory=$true)]$Record,
        [Parameter(Mandatory=$true)][string]$ZoneName,
        [Parameter(Mandatory=$true)][ValidateSet('A','AAAA','CNAME','PTR')][string]$Type
    )

    $ownerDisplay = [string]$Record.HostName
    if ([string]::IsNullOrWhiteSpace($ownerDisplay)) { $ownerDisplay = '@' }
    $owner = ConvertTo-DnsPreviewName -Name $ownerDisplay -ZoneName $ZoneName

    $displayData = $null
    $normalizedData = $null
    switch ($Type) {
        'A' {
            $displayData = [string]$Record.RecordData.IPv4Address
            $normalizedData = ConvertTo-DnsPreviewAddress -Value $displayData
        }
        'AAAA' {
            $displayData = [string]$Record.RecordData.IPv6Address
            $normalizedData = ConvertTo-DnsPreviewAddress -Value $displayData
        }
        'CNAME' {
            $displayData = [string]$Record.RecordData.HostNameAlias
            $normalizedData = ConvertTo-DnsPreviewName -Name $displayData
        }
        'PTR' {
            $displayData = [string]$Record.RecordData.PtrDomainName
            $normalizedData = ConvertTo-DnsPreviewName -Name $displayData
        }
    }

    $ttl = 0
    if ($null -ne $Record.TimeToLive) {
        $ttl = [int64][Math]::Floor(([TimeSpan]$Record.TimeToLive).TotalSeconds)
        if ($ttl -lt 0) { throw 'dns_preview_ttl_invalid' }
    }

    [PSCustomObject][ordered]@{
        owner = $owner
        display_owner = $ownerDisplay
        type = $Type
        record_class = 'IN'
        rdata = $normalizedData
        display_rdata = $displayData
        ttl_seconds = $ttl
        source_aging_timestamp = ConvertTo-DnsPreviewTimestamp -Value $Record.Timestamp
    }
}

function Write-DnsPreviewFile {
    param(
        [Parameter(Mandatory=$true)]$Value,
        [Parameter(Mandatory=$true)][string]$LiteralPath
    )
    if (-not [System.IO.Path]::IsPathRooted($LiteralPath)) {
        throw 'dns_preview_output_path_must_be_absolute'
    }
    if (Test-Path -LiteralPath $LiteralPath -PathType Container) {
        throw 'dns_preview_output_path_is_directory'
    }
    $parent = Split-Path -Parent $LiteralPath
    if ([string]::IsNullOrWhiteSpace($parent) -or -not (Test-Path -LiteralPath $parent -PathType Container)) {
        throw 'dns_preview_output_parent_missing'
    }

    $json = $Value | ConvertTo-Json -Depth 8 -Compress
    Set-Content -LiteralPath $LiteralPath -Value $json -Encoding UTF8
}

Import-Module DnsServer -ErrorAction Stop

$session = $null
try {
    $session = New-CimSession -ComputerName $Server -Credential $Credential -Authentication Kerberos -OperationTimeoutSec $OperationTimeoutSeconds -ErrorAction Stop

    $available = @(Get-DnsServerZone -CimSession $session -ErrorAction Stop)
    $zoneIndex = @{}
    foreach ($item in $available) {
        $zoneName = [string]$item.ZoneName
        if ([string]::IsNullOrWhiteSpace($zoneName)) { continue }
        $key = $zoneName.Trim().TrimEnd('.').ToLowerInvariant()
        if ($zoneIndex.ContainsKey($key)) { throw 'dns_preview_duplicate_zone_identity' }
        $zoneIndex[$key] = $item
    }

    if (-not $Zone -or $Zone.Count -eq 0) {
        $zoneList = @()
        foreach ($key in @($zoneIndex.Keys | Sort-Object)) {
            $z = $zoneIndex[$key]
            $zoneList += [PSCustomObject][ordered]@{
                zone = ConvertTo-DnsPreviewName -Name ([string]$z.ZoneName)
                zone_type = [string]$z.ZoneType
                ds_integrated = [bool]$z.IsDsIntegrated
                reverse_lookup = [bool]$z.IsReverseLookupZone
            }
        }

        $summary = [PSCustomObject][ordered]@{
            schema_version = 1
            mode = 'preview'
            server = $Server.ToLowerInvariant()
            coverage = 'default_instance_default_scope'
            action = 'list_zones'
            complete = $true
            zone_count = $zoneList.Count
            zones = $zoneList
        }
        if ($OutputPath) { Write-DnsPreviewFile -Value $summary -LiteralPath $OutputPath }
        [PSCustomObject][ordered]@{
            mode = 'preview'
            server = $Server.ToLowerInvariant()
            action = 'list_zones'
            complete = $true
            zone_count = $zoneList.Count
            output_file = $(if ($OutputPath) { $OutputPath } else { $null })
        }
        return
    }

    $seen = @{}
    $snapshots = @()
    foreach ($requested in $Zone) {
        if ([string]::IsNullOrWhiteSpace($requested)) { throw 'dns_preview_zone_invalid' }
        $key = $requested.Trim().TrimEnd('.').ToLowerInvariant()
        if ($seen.ContainsKey($key)) { throw 'dns_preview_duplicate_requested_zone' }
        $seen[$key] = $true
        if (-not $zoneIndex.ContainsKey($key)) { throw 'dns_preview_zone_not_found' }

        $zoneName = [string]$zoneIndex[$key].ZoneName
        $records = @()
        foreach ($type in @('A','AAAA','CNAME','PTR')) {
            $items = @(Get-DnsServerResourceRecord -CimSession $session -ZoneName $zoneName -RRType $type -ErrorAction Stop)
            foreach ($record in $items) {
                $records += ConvertTo-DnsPreviewRecord -Record $record -ZoneName $zoneName -Type $type
            }
        }

        $snapshots += [PSCustomObject][ordered]@{
            schema_version = 1
            mode = 'preview'
            server = $Server.ToLowerInvariant()
            zone = ConvertTo-DnsPreviewName -Name $zoneName
            virtualization_instance = 'default'
            zone_scope = 'default'
            supported_types = @('A','AAAA','CNAME','PTR')
            complete = $true
            record_count = $records.Count
            collected_at = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
            records = $records
        }
    }

    if ($OutputPath) { Write-DnsPreviewFile -Value $snapshots -LiteralPath $OutputPath }

    [PSCustomObject][ordered]@{
        mode = 'preview'
        server = $Server.ToLowerInvariant()
        action = 'collect_selected_zones'
        complete = $true
        zone_count = $snapshots.Count
        record_count = [int64](($snapshots | Measure-Object -Property record_count -Sum).Sum)
        output_file = $(if ($OutputPath) { $OutputPath } else { $null })
    }
}
finally {
    if ($null -ne $session) {
        Remove-CimSession -CimSession $session -ErrorAction SilentlyContinue
    }
}
