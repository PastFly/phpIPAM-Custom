#!/usr/bin/env python3
import argparse
import getpass
import hashlib
import json
import os
import re
import secrets
import stat
import time
from pathlib import Path

from dns9_winrm_common import protocol, regular, run, run_ps, stage_bytes, remove_tree, json_line, ps_quote, ensure_ntlm_md4_runtime

ROOT=Path(__file__).resolve().parents[2]
BACKUP_ROOT=Path("/var/backups/phpipam-custom")
SERVER="srv-dc01.rbank.local"
BASE_URI="https://ipam.realistbank.ru/"
TOKEN_FILE=Path("/root/phpipam-dns-e2e-pilot-token")
TASK_NAME="phpIPAM Microsoft DNS Collector"
INSTALL_ROOT=r"C:\ProgramData\phpIPAM\DnsCollector"

PACKAGE={
    "collectors/windows/dns/Install-PhpIpamDnsCollector.ps1": ROOT/"collectors/windows/dns/Install-PhpIpamDnsCollector.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1",
    "collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1": ROOT/"collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1",
    "collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1": ROOT/"collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1",
}

def sha256(data: bytes)->str:
    return hashlib.sha256(data).hexdigest()

def token()->str:
    st=TOKEN_FILE.lstat()
    if TOKEN_FILE.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("token_boundary")
    if stat.S_IMODE(st.st_mode)!=0o600 or st.st_uid!=0 or st.st_gid!=0 or st.st_nlink!=1:
        raise RuntimeError("token_metadata")
    value=TOKEN_FILE.read_text().strip()
    if not re.fullmatch(r"dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}",value):
        raise RuntimeError("token_shape")
    return value

def acceptance(path: Path,server: str,manifest: dict)->dict:
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("acceptance_receipt_boundary")
    st=path.lstat()
    if st.st_uid!=0 or stat.S_IMODE(st.st_mode)!=0o600:
        raise RuntimeError("acceptance_receipt_metadata")
    doc=json.loads(path.read_text())
    if doc.get("stage")!="accepted" or doc.get("server")!=server.lower():
        raise RuntimeError("acceptance_receipt_state")
    if doc.get("transport")!="winrm_ntlm_message_encryption" or doc.get("windows_fixture_ok") is not True:
        raise RuntimeError("acceptance_receipt_transport")
    if doc.get("production_collector_present_before") is not False:
        raise RuntimeError("acceptance_receipt_install_state")
    if doc.get("package")!=manifest:
        raise RuntimeError("acceptance_receipt_package_drift")
    return doc

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--acceptance-receipt",required=True)
    ap.add_argument("--username")
    ap.add_argument("--server",default=SERVER)
    ap.add_argument("--apply",action="store_true")
    args=ap.parse_args()

    if os.geteuid()!=0:
        raise SystemExit("DNS9_WINRM_BOOTSTRAP_ERROR=root_required")

    md4_mode=ensure_ntlm_md4_runtime()
    print("DNS9_WINRM_BOOTSTRAP_MD4_PROVIDER="+md4_mode)

    source={name:regular(path) for name,path in PACKAGE.items()}
    manifest={name:{"sha256":sha256(data),"bytes":len(data)} for name,data in source.items()}
    acceptance(Path(args.acceptance_receipt),args.server,manifest)
    print("DNS9_WINRM_BOOTSTRAP_ACCEPTANCE_RECEIPT_OK=1")

    print("DNS9_WINRM_BOOTSTRAP_SERVER="+args.server.lower())
    print("DNS9_WINRM_BOOTSTRAP_PACKAGE_FILES="+str(len(manifest)))
    print("DNS9_WINRM_BOOTSTRAP_TRANSPORT=ntlm_message_encryption")
    print("DNS9_WINRM_BOOTSTRAP_PRINCIPAL=SYSTEM")
    print("DNS9_WINRM_BOOTSTRAP_CREDENTIAL_MODE=CollectorIdentity")
    print("DNS9_WINRM_BOOTSTRAP_TASK_ENABLE=0")
    print("DNS9_WINRM_BOOTSTRAP_START_NOW=0")

    if not args.apply:
        print("DNS9_WINRM_BOOTSTRAP_TOKEN_READ=0")
        print("DNS9_WINRM_BOOTSTRAP_DRY_RUN=1")
        print("DNS9_WINRM_BOOTSTRAP_WINDOWS_MUTATION=0")
        print("DNS9_WINRM_BOOTSTRAP_PREFLIGHT_OK=1")
        return

    if not args.username:
        raise SystemExit("DNS9_WINRM_BOOTSTRAP_ERROR=username_required")
    password=getpass.getpass("WinRM bootstrap password: ")
    if not password:
        raise SystemExit("DNS9_WINRM_BOOTSTRAP_ERROR=password_empty")

    p=protocol(args.server,args.username,password)
    password=""
    control_token=None
    nonce=secrets.token_hex(8)
    stage=rf"C:\Windows\Temp\phpipam-dns9-bootstrap-{nonce}"
    install_invoked=False
    installed=False
    stage_cleaned=False

    try:
        precheck=f"""
$ErrorActionPreference='Stop'
$identity=[Security.Principal.WindowsIdentity]::GetCurrent()
$principal=New-Object Security.Principal.WindowsPrincipal($identity)
$fqdn=[System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).HostName.ToLowerInvariant()
$admin=$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
$task=[bool](Get-ScheduledTask -TaskName '{TASK_NAME}' -ErrorAction SilentlyContinue)
$root=[bool](Test-Path -LiteralPath '{INSTALL_ROOT}')
[PSCustomObject][ordered]@{{fqdn=$fqdn;admin=[bool]$admin;task_exists=$task;install_root_exists=$root}} |
  ConvertTo-Json -Compress | ForEach-Object {{Write-Output ('DNS9_WINRM_PRECHECK_JSON='+$_)}}
"""
        rc,out,err=run_ps(p,precheck)
        if rc!=0:
            raise RuntimeError("winrm_precheck_failed:"+err[:512])
        info=json_line(out,"DNS9_WINRM_PRECHECK_JSON=")
        if info.get("fqdn","").lower()!=args.server.lower():
            raise RuntimeError("winrm_target_mismatch")
        if info.get("admin") is not True:
            raise RuntimeError("winrm_not_administrator")
        if info.get("task_exists") or info.get("install_root_exists"):
            raise RuntimeError("winrm_collector_already_present")

        for name,data in source.items():
            stage_bytes(p,stage+chr(92)+Path(name).name,data)

        bootstrap=r"""param([Parameter(Mandatory=$true)][string]$Stage)
Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$tokenLine=$null
$secure=$null
try {
    $tokenLine=[string]$env:PHPIPAM_DNS9_CONTROL_TOKEN
$env:PHPIPAM_DNS9_CONTROL_TOKEN=$null
    if(-not [regex]::IsMatch($tokenLine,'^dnsctl1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$')){throw 'dns9_bootstrap_token_invalid'}
    $secure=ConvertTo-SecureString -String $tokenLine -AsPlainText -Force
    & (Join-Path $Stage 'Install-PhpIpamDnsCollector.ps1') `
        -PhpIpamBaseUri 'https://ipam.realistbank.ru/' `
        -ControlToken $secure `
        -PrincipalMode System `
        -DnsCredentialMode CollectorIdentity
}
finally {
    $tokenLine=$null
    $secure=$null
}
"""
        bootstrap_path=stage+r"\bootstrap.ps1"
        stage_bytes(p,bootstrap_path,bootstrap.encode("utf-8"))

        control_token=token()
        print("DNS9_WINRM_BOOTSTRAP_TOKEN_BOUNDARY_OK=1")

        install_invoked=True
        rc,out,err=run(
            p,"powershell.exe",
            ["-NoProfile","-NonInteractive","-ExecutionPolicy","Bypass",
             "-File",bootstrap_path,"-Stage",stage],
            env_vars={"PHPIPAM_DNS9_CONTROL_TOKEN":control_token}
        )
        control_token=None
        if rc!=0:
            raise RuntimeError("remote_install_failed:"+err[:512])
        installed=True
        for marker in [
            "DNS9_AUTONOMOUS_INSTALL_OK=1",
            "DNS9_AUTONOMOUS_PRINCIPAL_MODE=System",
            "DNS9_AUTONOMOUS_DNS_CREDENTIAL_MODE=CollectorIdentity",
            "DNS9_AUTONOMOUS_TASK_ENABLED=0",
            "DNS9_AUTONOMOUS_START_NOW=0",
            "DNS9_AUTONOMOUS_TOKEN_PRINTED=0",
        ]:
            if marker not in out:
                raise RuntimeError("remote_install_marker_missing:"+marker)

        expected_runtime={
            "Invoke-PhpIpamDnsWorkerOnce.ps1":manifest["collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1"]["sha256"],
            "Invoke-PhpIpamDnsPreview.ps1":manifest["collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1"]["sha256"],
            "Invoke-PhpIpamDnsCollectorScheduled.ps1":manifest["collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1"]["sha256"],
        }
        verifier=f"""
$ErrorActionPreference='Stop'
$task=Get-ScheduledTask -TaskName '{TASK_NAME}' -ErrorAction Stop
$root='{INSTALL_ROOT}'
$hashes=[ordered]@{{}}
foreach($name in @('Invoke-PhpIpamDnsWorkerOnce.ps1','Invoke-PhpIpamDnsPreview.ps1','Invoke-PhpIpamDnsCollectorScheduled.ps1')){{
  $hashes[$name]=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $root $name)).Hash.ToLowerInvariant()
}}
$tokenPath=Join-Path $root 'control-token.dpapi'
$configPath=Join-Path $root 'collector.json'
[PSCustomObject][ordered]@{{
  task_state=[string]$task.State
  task_enabled=([string]$task.State -ne 'Disabled')
  token_present=[bool](Test-Path -LiteralPath $tokenPath -PathType Leaf)
  token_bytes=[int64](Get-Item -LiteralPath $tokenPath).Length
  config_present=[bool](Test-Path -LiteralPath $configPath -PathType Leaf)
  hashes=$hashes
}} | ConvertTo-Json -Compress -Depth 4 | ForEach-Object {{Write-Output ('DNS9_WINRM_VERIFY_JSON='+$_)}}
"""
        rc,out,err=run_ps(p,verifier)
        if rc!=0:
            raise RuntimeError("remote_verify_failed:"+err[:512])
        verify=json_line(out,"DNS9_WINRM_VERIFY_JSON=")
        if verify.get("task_enabled") is not False or verify.get("task_state")!="Disabled":
            raise RuntimeError("remote_task_not_disabled")
        if verify.get("token_present") is not True or int(verify.get("token_bytes",0))<32:
            raise RuntimeError("remote_token_blob_invalid")
        if verify.get("config_present") is not True or verify.get("hashes")!=expected_runtime:
            raise RuntimeError("remote_runtime_hash_mismatch")

        remove_tree(p,stage)
        stage_cleaned=True
    except Exception:
        control_token=None
        if install_invoked:
            try:
                uninstall_path=stage+chr(92)+"Uninstall-PhpIpamDnsCollector.ps1"
                rollback=f"""
$ErrorActionPreference='Stop'
$script={ps_quote(uninstall_path)}
if(Test-Path -LiteralPath $script -PathType Leaf){{& $script -Confirm:$false}}
$task=[bool](Get-ScheduledTask -TaskName '{TASK_NAME}' -ErrorAction SilentlyContinue)
$root=[bool](Test-Path -LiteralPath '{INSTALL_ROOT}')
if($task -or $root){{throw 'dns9_remote_rollback_incomplete'}}
Write-Output 'DNS9_WINRM_REMOTE_ROLLBACK_OK=1'
"""
                run_ps(p,rollback)
            except Exception:
                pass
        try:
            remove_tree(p,stage)
        except Exception:
            pass
        raise

    stamp=time.strftime("%Y%m%d-%H%M%S",time.gmtime())
    receipt_dir=BACKUP_ROOT/("dns9-winrm-bootstrap-"+stamp)
    receipt_dir.mkdir(parents=True,mode=0o700,exist_ok=False)
    receipt_path=receipt_dir/"receipt.json"
    receipt={
        "stage":"installed_disabled",
        "created_at":int(time.time()),
        "server":args.server.lower(),
        "transport":"winrm_ntlm_message_encryption",
        "bootstrap_identity_recorded":False,
        "control_token_recorded":False,
        "package":manifest,
        "principal_mode":"System",
        "dns_credential_mode":"CollectorIdentity",
        "task_enabled":False,
        "task_started":False,
        "remote_stage_cleaned":stage_cleaned,
    }
    receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)

    print("DNS9_WINRM_BOOTSTRAP_TASK_INSTALLED=1")
    print("DNS9_WINRM_BOOTSTRAP_TASK_ENABLED=0")
    print("DNS9_WINRM_BOOTSTRAP_TASK_STARTED=0")
    print("DNS9_WINRM_BOOTSTRAP_TOKEN_PRINTED=0")
    print("DNS9_WINRM_BOOTSTRAP_STAGE_CLEANED=1")
    print("DNS9_WINRM_BOOTSTRAP_RECEIPT="+str(receipt_path))
    print("DNS9_WINRM_BOOTSTRAP_OK=1")

if __name__=="__main__":
    main()
