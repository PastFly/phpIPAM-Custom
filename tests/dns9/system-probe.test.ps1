#requires -Version 5.1
$ErrorActionPreference='Stop'
$root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$temp=Join-Path $env:TEMP ('dns9-system-probe-fixture-'+[Guid]::NewGuid().ToString('N'))
$oldProgramData=$env:ProgramData
$script:FakeCalled=$false
function Invoke-RestMethod {
    param($Uri,$Method,$Headers,$ContentType,$Body,$TimeoutSec,$MaximumRedirection,$ErrorAction)
    if($Method -ne 'Post' -or $Body -cne '{"action":"diagnostic_unsupported"}') {throw 'fixture_request_invalid'}
    if($Headers.Authorization -cne ('Bearer dnsctl1.'+('0'*32)+'.'+('A'*43))) {throw 'fixture_header_invalid'}
    if($MaximumRedirection -ne 0){throw 'fixture_redirect_invalid'}
    $script:FakeCalled=$true
    throw [Net.WebException]::new('fixture',[Net.WebExceptionStatus]::TrustFailure)
}
try {
    Add-Type -AssemblyName System.Security
    $collector=Join-Path $temp 'phpIPAM\DnsCollector'
    $stage=Join-Path $temp 'stage'
    New-Item -ItemType Directory -Path $collector -Force | Out-Null
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    $env:ProgramData=$temp
    [IO.File]::WriteAllText((Join-Path $collector 'collector.json'),'{"phpipam_base_uri":"https://ipam.realistbank.ru/"}')
    $token='dnsctl1.'+('1'*32)+'.'+('B'*43)
    $bytes=[Text.Encoding]::UTF8.GetBytes($token)
    $entropy=[Text.Encoding]::UTF8.GetBytes('phpipam-dns-control-token-v1')
    $blob=[Security.Cryptography.ProtectedData]::Protect($bytes,$entropy,[Security.Cryptography.DataProtectionScope]::LocalMachine)
    [IO.File]::WriteAllBytes((Join-Path $collector 'control-token.dpapi'),$blob)
    $nonce=[byte[]](0..31)
    $hmac=[Security.Cryptography.HMACSHA256]::new($nonce)
    try {$digest=[BitConverter]::ToString($hmac.ComputeHash($bytes)).Replace('-','').ToLowerInvariant()} finally {$hmac.Dispose()}
    $ref=@{enabled=$true;nonce=[BitConverter]::ToString($nonce).Replace('-','').ToLowerInvariant();digest=$digest}
    [IO.File]::WriteAllText((Join-Path $stage 'reference.json'),(ConvertTo-Json $ref -Compress))
    $source=Get-Content -Raw (Join-Path $root 'tools\live\dns9-system-probe.ps1')
    $source=$source.Replace('$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value',"`$sid='S-1-5-18'")
    & ([scriptblock]::Create($source)) -StageDir $stage
    $result=Get-Content -Raw (Join-Path $stage 'result.json') | ConvertFrom-Json
    if($result.phase -ne 'complete' -or $result.error -ne 'none' -or $result.token_valid -ne $true -or
       $result.token_reference_match -ne $true -or $result.web_status -ne 'TrustFailure' -or -not $script:FakeCalled){throw 'system_probe_result_invalid'}
    $raw=Get-Content -Raw (Join-Path $stage 'result.json')
    if($raw.Contains($token)){throw 'token_leaked'}
    Write-Output 'DNS9_SYSTEM_PROBE_WINDOWS_FIXTURE_OK=1'
} finally {
    $env:ProgramData=$oldProgramData
    if(Test-Path -LiteralPath $temp){Remove-Item -LiteralPath $temp -Recurse -Force}
}
