#requires -Version 5.1
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$StageDir)
Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'

$phase='identity'
$result=[ordered]@{phase='identity';error='none';token_valid=$false;token_reference_match=$null;http_status=$null;http_error_marker=$false;web_status='none'}
$plain=$null; $plainBytes=$null; $protected=$null; $entropy=$null; $secure=$null; $ptr=[IntPtr]::Zero
$oldProxy=$null; $proxyChanged=$false; $oldTls=$null; $tlsChanged=$false
try {
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    if($sid -ne 'S-1-5-18'){throw 'system_identity_required'}
    Add-Type -AssemblyName System.Security -ErrorAction Stop
    $phase='config'
    $root=Join-Path $env:ProgramData 'phpIPAM\DnsCollector'
    $config=Get-Content -Raw -LiteralPath (Join-Path $root 'collector.json') | ConvertFrom-Json
    $base=[Uri][string]$config.phpipam_base_uri
    if(-not $base.IsAbsoluteUri -or $base.AbsoluteUri -cne 'https://ipam.realistbank.ru/') {throw 'target_invalid'}
    $uri=New-Object Uri($base,'app/integrations/dns/machine-control.php')
    $phase='token_read'
    $protected=[IO.File]::ReadAllBytes((Join-Path $root 'control-token.dpapi'))
    if($protected.Length -lt 32 -or $protected.Length -gt 8192){throw 'blob_invalid'}
    $phase='dpapi'
    $entropy=[Text.Encoding]::UTF8.GetBytes('phpipam-dns-control-token-v1')
    $plainBytes=[System.Security.Cryptography.ProtectedData]::Unprotect($protected,$entropy,[System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    $phase='token_format'
    $plain=[Text.Encoding]::UTF8.GetString($plainBytes)
    if(-not [regex]::IsMatch($plain,'^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$')){throw 'token_invalid'}
    $result.token_valid=$true
    $reference=Get-Content -Raw -LiteralPath (Join-Path $StageDir 'reference.json') | ConvertFrom-Json
    if([bool]$reference.enabled){
        $phase='token_compare'
        if([string]$reference.nonce -notmatch '^[0-9a-f]{64}$' -or [string]$reference.digest -notmatch '^[0-9a-f]{64}$'){throw 'reference_invalid'}
        $key=New-Object byte[] 32
        for($i=0;$i -lt 32;$i++){$key[$i]=[Convert]::ToByte(([string]$reference.nonce).Substring($i*2,2),16)}
        $hmac=[System.Security.Cryptography.HMACSHA256]::new($key)
        try {$actual=[BitConverter]::ToString($hmac.ComputeHash($plainBytes)).Replace('-','').ToLowerInvariant()}
        finally {$hmac.Dispose();[Array]::Clear($key,0,$key.Length)}
        $result.token_reference_match=($actual -ceq [string]$reference.digest)
        $actual=$null
    }
    $phase='secure_string'
    $secure=ConvertTo-SecureString -String $plain -AsPlainText -Force
    $plain=$null
    [Array]::Clear($plainBytes,0,$plainBytes.Length); $plainBytes=$null
    $ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    $roundtrip=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    if(-not [regex]::IsMatch($roundtrip,'^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$')){throw 'secure_string_invalid'}
    $roundtrip=$null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr); $ptr=[IntPtr]::Zero
    $phase='proxy'
    $oldProxy=[Net.WebRequest]::DefaultWebProxy
    [Net.WebRequest]::DefaultWebProxy=[Net.GlobalProxySelection]::GetEmptyWebProxy()
    $proxyChanged=$true
    $phase='tls12'
    $oldTls=[Net.ServicePointManager]::SecurityProtocol
    [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
    $tlsChanged=$true
    $phase='http'
    $fakeToken='dnsctl1.'+('0'*32)+'.'+('A'*43)
    try {
        $null=Invoke-RestMethod -Uri $uri -Method Post -Headers @{Authorization=('Bearer '+$fakeToken)} -ContentType 'application/json' -Body '{"action":"diagnostic_unsupported"}' -TimeoutSec 20 -MaximumRedirection 0 -ErrorAction Stop
        $result.web_status='unexpected_success'
    } catch {
        $web=$_.Exception
        while($null -ne $web -and -not ($web -is [Net.WebException])){$web=$web.InnerException}
        if($null -eq $web){$result.web_status='other'} else {
            $category=[string]$web.Status
            if($category -in @('ProtocolError','SecureChannelFailure','TrustFailure','ConnectFailure','NameResolutionFailure','Timeout','ConnectionClosed','ReceiveFailure','SendFailure')){$result.web_status=$category}else{$result.web_status='other'}
            if($null -ne $web.Response){
                $response=$web.Response
                try {
                    $result.http_status=[int]$response.StatusCode
                    $stream=$response.GetResponseStream()
                    if($null -ne $stream){
                        $reader=New-Object IO.StreamReader($stream)
                        try {
                            $chars=New-Object char[] 4096
                            $count=$reader.Read($chars,0,$chars.Length)
                            if($count -gt 0){
                                $text=[string]::new($chars,0,$count)
                                try {$body=ConvertFrom-Json -InputObject $text -ErrorAction Stop; $result.http_error_marker=([string]$body.error -ceq 'action_not_supported')} catch {}
                                $text=$null
                            }
                        } finally {$reader.Dispose()}
                    }
                } finally {$response.Close()}
            }
        }
    }
    $fakeToken=$null
    $phase='complete'
} catch {
    $e=$_.Exception
    $category='other'
    for($i=0;$i -lt 8 -and $null -ne $e;$i++){
        if($e -is [UnauthorizedAccessException]){$category='access_denied';break}
        if($e -is [Security.Cryptography.CryptographicException]){$category='cryptographic';break}
        if($e -is [IO.IOException]){$category='io';break}
        if($e -is [System.Management.Automation.CommandNotFoundException]){$category='command_missing';break}
        $e=$e.InnerException
    }
    $result.error=$category
} finally {
    $result.phase=$phase
    if($tlsChanged){[Net.ServicePointManager]::SecurityProtocol=$oldTls}
    if($proxyChanged){[Net.WebRequest]::DefaultWebProxy=$oldProxy}
    if($ptr -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)}
    $plain=$null; $roundtrip=$null; $secure=$null
    if($null -ne $plainBytes){[Array]::Clear($plainBytes,0,$plainBytes.Length)}
    if($null -ne $protected){[Array]::Clear($protected,0,$protected.Length)}
    if($null -ne $entropy){[Array]::Clear($entropy,0,$entropy.Length)}
    [IO.File]::WriteAllText((Join-Path $StageDir 'result.json'),(ConvertTo-Json -InputObject $result -Compress),[Text.UTF8Encoding]::new($false))
}
