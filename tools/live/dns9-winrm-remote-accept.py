#!/usr/bin/env python3
import argparse
import getpass
import hashlib
import json
import os
import secrets
import stat
import time
from pathlib import Path

from dns9_winrm_common import protocol, regular, run_ps, stage_bytes, remove_tree, json_line

ROOT=Path(__file__).resolve().parents[2]
BACKUP_ROOT=Path("/var/backups/phpipam-custom")
SERVER="srv-dc01.rbank.local"
TASK_NAME="phpIPAM Microsoft DNS Collector"
INSTALL_ROOT=r"C:\ProgramData\phpIPAM\DnsCollector"

FILES={
    "tests/dns9/autonomous-windows.test.ps1": ROOT/"tests/dns9/autonomous-windows.test.ps1",
    "collectors/windows/dns/Install-PhpIpamDnsCollector.ps1": ROOT/"collectors/windows/dns/Install-PhpIpamDnsCollector.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1",
    "collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1": ROOT/"collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1",
    "collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1": ROOT/"collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1",
}

def sha256(data: bytes)->str:
    return hashlib.sha256(data).hexdigest()

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--username",required=True)
    ap.add_argument("--server",default=SERVER)
    args=ap.parse_args()

    if os.geteuid()!=0:
        raise SystemExit("DNS9_WINRM_ACCEPT_ERROR=root_required")

    source={name:regular(path) for name,path in FILES.items()}
    manifest={name:{"sha256":sha256(data),"bytes":len(data)} for name,data in source.items()}
    password=getpass.getpass("WinRM bootstrap password: ")
    if not password:
        raise SystemExit("DNS9_WINRM_ACCEPT_ERROR=password_empty")

    p=protocol(args.server,args.username,password)
    password=""

    nonce=secrets.token_hex(8)
    stage=rf"C:\Windows\Temp\phpipam-dns9-accept-{nonce}"
    accepted=False
    remote_info=None
    try:
        precheck=f"""
$ErrorActionPreference='Stop'
$identity=[Security.Principal.WindowsIdentity]::GetCurrent()
$principal=New-Object Security.Principal.WindowsPrincipal($identity)
$admin=$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
$fqdn=[System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).HostName.ToLowerInvariant()
$task=[bool](Get-ScheduledTask -TaskName '{TASK_NAME}' -ErrorAction SilentlyContinue)
$root=[bool](Test-Path -LiteralPath '{INSTALL_ROOT}')
[PSCustomObject][ordered]@{{
  computer=$env:COMPUTERNAME.ToLowerInvariant()
  fqdn=$fqdn
  admin=[bool]$admin
  ps=[string]$PSVersionTable.PSVersion
  task_exists=$task
  install_root_exists=$root
}} | ConvertTo-Json -Compress | ForEach-Object {{Write-Output ('DNS9_WINRM_PRECHECK_JSON='+$_)}}
"""
        rc,out,err=run_ps(p,precheck)
        if rc!=0:
            raise RuntimeError("winrm_precheck_failed:"+err[:512])
        remote_info=json_line(out,"DNS9_WINRM_PRECHECK_JSON=")
        if remote_info.get("fqdn","").lower()!=args.server.lower():
            raise RuntimeError("winrm_target_mismatch")
        if remote_info.get("admin") is not True:
            raise RuntimeError("winrm_not_administrator")
        if remote_info.get("task_exists") or remote_info.get("install_root_exists"):
            raise RuntimeError("winrm_collector_already_present")

        for name,data in source.items():
            remote=stage+chr(92)+name.replace("/",chr(92))
            stage_bytes(p,remote,data)

        fixture=stage+r"\tests\dns9\autonomous-windows.test.ps1"
        rc,out,err=run_ps(p,f"& {json.dumps(fixture)}")
        if rc!=0:
            raise RuntimeError("windows_fixture_failed:"+err[:512])
        for marker in [
            "DNS9_WINDOWS_FIXTURE_TASK_CREATED=0",
            "DNS9_WINDOWS_FIXTURE_TOKEN_PRINTED=0",
            "DNS9_WINDOWS_FIXTURE_OK=1",
        ]:
            if marker not in out:
                raise RuntimeError("windows_fixture_marker_missing:"+marker)

        accepted=True
    finally:
        try:
            remove_tree(p,stage)
        except Exception:
            if accepted:
                raise

    stamp=time.strftime("%Y%m%d-%H%M%S",time.gmtime())
    receipt_dir=BACKUP_ROOT/("dns9-winrm-accept-"+stamp)
    receipt_dir.mkdir(parents=True,mode=0o700,exist_ok=False)
    receipt_path=receipt_dir/"receipt.json"
    receipt={
        "stage":"accepted",
        "created_at":int(time.time()),
        "server":args.server.lower(),
        "transport":"winrm_ntlm_message_encryption",
        "bootstrap_identity_recorded":False,
        "package":manifest,
        "windows_fixture_ok":True,
        "windows_fixture_task_created":False,
        "windows_fixture_token_printed":False,
        "remote_ps_version":str(remote_info.get("ps","")),
        "remote_admin_verified":True,
        "remote_stage_cleaned":True,
        "production_collector_present_before":False,
    }
    receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)

    print("DNS9_WINRM_ACCEPT_SERVER="+args.server.lower())
    print("DNS9_WINRM_ACCEPT_TRANSPORT=ntlm_message_encryption")
    print("DNS9_WINRM_ACCEPT_PACKAGE_FILES="+str(len(manifest)))
    print("DNS9_WINRM_ACCEPT_FIXTURE_OK=1")
    print("DNS9_WINRM_ACCEPT_TASK_CREATED=0")
    print("DNS9_WINRM_ACCEPT_TOKEN_USED=0")
    print("DNS9_WINRM_ACCEPT_STAGE_CLEANED=1")
    print("DNS9_WINRM_ACCEPT_RECEIPT="+str(receipt_path))
    print("DNS9_WINRM_ACCEPT_OK=1")

if __name__=="__main__":
    main()
