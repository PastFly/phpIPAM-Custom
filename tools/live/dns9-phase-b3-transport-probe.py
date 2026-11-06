#!/usr/bin/env python3
"""Bounded, unauthenticated transport and historical access-log diagnostic."""
import base64
import datetime
import getpass
import gzip
import json
import os
import re
from pathlib import Path
from dns9_winrm_common import ensure_ntlm_md4_runtime, json_line, protocol, run_ps

USERNAME='rbank\\kadaev.admin'
SERVER='srv-dc01.rbank.local'
LOGS=(Path('/var/log/apache2/access.log'),Path('/var/log/apache2/other_vhosts_access.log'),Path('/var/log/nginx/access.log'))
LIMIT=64*1024*1024
LINE=re.compile(r'\[([^]]+)\] "POST ([^ ?"]*/app/integrations/dns/machine-control\.php)(?:\?[^ "]*)? HTTP/[0-9.]+" ([1-5][0-9][0-9])(?: |$)')
START=datetime.datetime(2026,11,6,7,39,20,tzinfo=datetime.timezone.utc)
END=datetime.datetime(2026,11,6,7,39,45,tzinfo=datetime.timezone.utc)
WEB_STATUSES={'ConnectFailure','NameResolutionFailure','ProxyNameResolutionFailure','SecureChannelFailure','TrustFailure','Timeout','ConnectionClosed','ReceiveFailure','SendFailure','ProtocolError','UnknownError','other'}

POWERSHELL=r'''
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$config=Get-Content -LiteralPath (Join-Path $env:ProgramData 'phpIPAM\DnsCollector\collector.json') -Raw | ConvertFrom-Json
$base=[Uri][string]$config.phpipam_base_uri
if(-not $base.IsAbsoluteUri -or $base.Scheme -ne 'https' -or $base.UserInfo -or $base.Query -or $base.Fragment){throw 'invalid_config'}
$baseText=$base.AbsoluteUri
if(-not $baseText.EndsWith('/')){$baseText+='/'}
$uri=New-Object Uri([Uri]$baseText,'app/integrations/dns/machine-control.php')
if($uri.Authority -ne $base.Authority -or $uri.Scheme -ne 'https'){throw 'invalid_target'}
function Test-ConnectionBoundary {
    $request=$null; $response=$null
    try {
        $request=[Net.HttpWebRequest]::Create($uri)
        $request.Method='GET'
        $request.Proxy=$null
        $request.AllowAutoRedirect=$false
        $request.Timeout=8000
        $request.KeepAlive=$false
        $response=$request.GetResponse()
        return [PSCustomObject]@{outcome='http';status=[int]$response.StatusCode}
    } catch {
        $errorObject=$_.Exception
        while($null -ne $errorObject -and -not ($errorObject -is [Net.WebException])){$errorObject=$errorObject.InnerException}
        if($null -ne $errorObject -and $null -ne $errorObject.Response){
            $response=$errorObject.Response
            return [PSCustomObject]@{outcome='http';status=[int]$response.StatusCode}
        }
        $category='other'
        if($null -ne $errorObject){
            $candidate=[string]$errorObject.Status
            if($candidate -in @('ConnectFailure','NameResolutionFailure','ProxyNameResolutionFailure','SecureChannelFailure','TrustFailure','Timeout','ConnectionClosed','ReceiveFailure','SendFailure','ProtocolError','UnknownError')){$category=$candidate}
        }
        return [PSCustomObject]@{outcome='network';status=$category}
    } finally {if($null -ne $response){$response.Close()}}
}
$original=[Net.ServicePointManager]::SecurityProtocol
try {
    $default=Test-ConnectionBoundary
    [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
    $tls12=Test-ConnectionBoundary
    [PSCustomObject]@{identity='winrm';default=$default;tls12=$tls12} | ConvertTo-Json -Compress | ForEach-Object {Write-Output ('DNS9_TRANSPORT_JSON='+$_)}
} finally {[Net.ServicePointManager]::SecurityProtocol=$original}
'''

def count_access_lines(lines):
    counts={}
    for line in lines:
        m=LINE.search(line)
        if not m: continue
        try: stamp=datetime.datetime.strptime(m.group(1),'%d/%b/%Y:%H:%M:%S %z').astimezone(datetime.timezone.utc)
        except ValueError: continue
        if START<=stamp<=END: counts[m.group(3)]=counts.get(m.group(3),0)+1
    return dict(sorted(counts.items()))

def log_summary(paths=LOGS):
    result=[]
    for path in paths:
        try:
            with path.open('rb') as fh:
                size=os.fstat(fh.fileno()).st_size
                truncated=size>LIMIT
                if truncated: fh.seek(-LIMIT,2); fh.readline()
                data=fh.read(LIMIT+1)
            counts=count_access_lines(data.decode('utf-8','replace').splitlines())
            result.append({'source':str(path),'truncated':truncated,'post_status_counts':counts})
        except FileNotFoundError: continue
        except OSError: result.append({'source':str(path),'unreadable':True})
    return result

def validate_remote(doc):
    if not isinstance(doc,dict) or set(doc)!={'identity','default','tls12'} or doc['identity']!='winrm': raise ValueError('remote_document_invalid')
    for mode in ('default','tls12'):
        item=doc[mode]
        if not isinstance(item,dict) or set(item)!={'outcome','status'}: raise ValueError('remote_document_invalid')
        if item['outcome']=='http':
            if type(item['status']) is not int or not 100<=item['status']<=599: raise ValueError('remote_document_invalid')
        elif item['outcome']=='network':
            if item['status'] not in WEB_STATUSES: raise ValueError('remote_document_invalid')
        else: raise ValueError('remote_document_invalid')
    return doc

def main():
    if os.geteuid()!=0: raise RuntimeError('root_required')
    print('DNS9_TRANSPORT_ACCESS_LOGS='+json.dumps(log_summary(),sort_keys=True,separators=(',',':')),flush=True)
    print('DNS9_TRANSPORT_MD4_PROVIDER='+ensure_ntlm_md4_runtime(),flush=True)
    password=getpass.getpass('WinRM diagnostic password: ')
    if not password: raise RuntimeError('password_empty')
    p=protocol(SERVER,USERNAME,password)
    password=''
    packed=base64.b64encode(gzip.compress(POWERSHELL.encode(),mtime=0)).decode('ascii')
    loader=("$b=[Convert]::FromBase64String('"+packed+"');$m=New-Object IO.MemoryStream(,$b);"
            "$g=New-Object IO.Compression.GzipStream($m,[IO.Compression.CompressionMode]::Decompress);"
            "$r=New-Object IO.StreamReader($g);& ([scriptblock]::Create($r.ReadToEnd()))")
    if len(base64.b64encode(loader.encode('utf-16le')))>7500: raise RuntimeError('encoded_command_limit')
    rc,out,_=run_ps(p,loader)
    if rc!=0: raise RuntimeError('windows_transport_probe_failed')
    doc=validate_remote(json_line(out,'DNS9_TRANSPORT_JSON='))
    print('DNS9_TRANSPORT_JSON='+json.dumps(doc,sort_keys=True,separators=(',',':')))
    print('DNS9_TRANSPORT_JOB_POLL=0')
    print('DNS9_TRANSPORT_TOKEN_USED=0')
    print('DNS9_TRANSPORT_TASK_CREATED=0')
    print('DNS9_TRANSPORT_DB_MUTATION=0')
    print('DNS9_TRANSPORT_DNS_WRITE=0')
    print('DNS9_TRANSPORT_OK=1')

if __name__=='__main__':
    try: main()
    except Exception as exc:
        safe=str(exc) if str(exc) in ('root_required','password_empty','encoded_command_limit','windows_transport_probe_failed','remote_document_invalid') else 'diagnostic_failed'
        raise SystemExit('DNS9_TRANSPORT_ERROR='+safe)
