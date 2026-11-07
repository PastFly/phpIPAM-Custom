#requires -Version 5.1
$ErrorActionPreference='Stop'
Set-StrictMode -Version 2.0
Add-Type -AssemblyName System.Security -ErrorAction Stop
$Root=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Runner=Join-Path $Root 'collectors\windows\dns\Invoke-PhpIpamDnsCollectorScheduled.ps1'
$TempRoot=Join-Path $env:TEMP ('phpipam-dns9-tls-test-'+[Guid]::NewGuid().ToString('N'))
$OldProtocol=[Net.ServicePointManager]::SecurityProtocol
$OldFailure=$env:DNS9_TLS_TEST_FAILURE
$plain=$null; $bytes=$null; $entropy=$null; $blob=$null
try {
    New-Item -ItemType Directory -Path $TempRoot -ErrorAction Stop | Out-Null
    $config=@{schema_version=1;phpipam_base_uri='https://localhost/';http_timeout_seconds=5;dns_timeout_seconds=15}
    [IO.File]::WriteAllText((Join-Path $TempRoot 'collector.json'),(ConvertTo-Json $config -Compress))
    $plain='dnsctl1.'+('a'*32)+'.'+('B'*43)
    $bytes=[Text.Encoding]::UTF8.GetBytes($plain)
    $entropy=[Text.Encoding]::UTF8.GetBytes('phpipam-dns-control-token-v1')
    $blob=[System.Security.Cryptography.ProtectedData]::Protect($bytes,$entropy,[System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    [IO.File]::WriteAllBytes((Join-Path $TempRoot 'control-token.dpapi'),$blob)
    [IO.File]::WriteAllText((Join-Path $TempRoot 'Invoke-PhpIpamDnsPreview.ps1'),'# fixture')
    $worker=@'
param([Uri]$PhpIpamBaseUri,[Security.SecureString]$ControlToken,[int]$HttpTimeoutSeconds,[int]$DnsTimeoutSeconds)
if ([Net.ServicePointManager]::SecurityProtocol -ne [Net.SecurityProtocolType]::Tls12) { throw 'fixture_wrong_tls' }
if ($env:DNS9_TLS_TEST_FAILURE -eq '1') { throw 'fixture_worker_failed' }
[PSCustomObject]@{state='idle'}
'@
    [IO.File]::WriteAllText((Join-Path $TempRoot 'Invoke-PhpIpamDnsWorkerOnce.ps1'),$worker)
    [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls
    $env:DNS9_TLS_TEST_FAILURE='0'
    & $Runner -InstallRoot $TempRoot
    $status=Get-Content -Raw (Join-Path $TempRoot 'status.json') | ConvertFrom-Json
    if($status.state -ne 'idle'){throw 'success_status_invalid'}
    if([Net.ServicePointManager]::SecurityProtocol -ne [Net.SecurityProtocolType]::Tls){throw 'success_protocol_not_restored'}
    $env:DNS9_TLS_TEST_FAILURE='1'
    $threw=$false
    try {& $Runner -InstallRoot $TempRoot} catch {$threw=$true}
    if(-not $threw){throw 'failure_not_propagated'}
    $status=Get-Content -Raw (Join-Path $TempRoot 'status.json') | ConvertFrom-Json
    if($status.state -ne 'error' -or $status.result_code -ne 'dns9_runtime_error'){throw 'failure_status_invalid'}
    if([Net.ServicePointManager]::SecurityProtocol -ne [Net.SecurityProtocolType]::Tls){throw 'failure_protocol_not_restored'}
    Write-Output 'DNS9_RUNNER_TLS_TEST_OK=1'
} finally {
    [Net.ServicePointManager]::SecurityProtocol=$OldProtocol
    $env:DNS9_TLS_TEST_FAILURE=$OldFailure
    $plain=$null
    foreach($array in @($bytes,$entropy,$blob)){if($null -ne $array){[Array]::Clear($array,0,$array.Length)}}
    if(Test-Path -LiteralPath $TempRoot){Remove-Item -LiteralPath $TempRoot -Recurse -Force}
}
