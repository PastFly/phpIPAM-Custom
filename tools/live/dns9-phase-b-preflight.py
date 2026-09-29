#!/usr/bin/env python3
import argparse
import grp
import hashlib
import http.client
import importlib.util
import json
import os
import shutil
import socket
import stat
import subprocess
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
WEBROOT=Path("/var/www/html")
DB="phpipam"
SERVER="srv-dc01.rbank.local"
KEYRING=Path("/etc/phpipam-custom/dns-credentials.key")
SERVICE=Path("/etc/systemd/system/phpipam-dns-autonomous.service")
TIMER=Path("/etc/systemd/system/phpipam-dns-autonomous.timer")
PILOT_JOB_ID="a4ee3e5987dd15495d7e2419e853d9c8"

WINDOWS_PACKAGE=[
    ROOT/"collectors/windows/dns/Install-PhpIpamDnsCollector.ps1",
    ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1",
    ROOT/"collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1",
    ROOT/"collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1",
    ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1",
    ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1",
]

def sha256(data: bytes)->str:
    return hashlib.sha256(data).hexdigest()

def regular(path: Path)->bytes:
    st=path.lstat()
    if path.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("file_boundary_invalid:"+str(path))
    return path.read_bytes()

def sql(text: str)->str:
    p=subprocess.run(
        ["mariadb","--batch","--skip-column-names",DB],
        input=text,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False
    )
    if p.returncode!=0:
        raise RuntimeError("database_command_failed:"+p.stderr.strip())
    return p.stdout

def one(query: str)->str:
    rows=[line for line in sql(query).splitlines() if line.strip()]
    if len(rows)!=1:
        raise RuntimeError("database_row_count_invalid")
    return rows[0]

def constraint_count(name: str)->int:
    return int(one(f"""
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE()
  AND TABLE_NAME='custom_dns_connections'
  AND CONSTRAINT_NAME='{name}'
  AND CONSTRAINT_TYPE='CHECK';
"""))

def tcp_open(host: str,port: int)->bool:
    try:
        with socket.create_connection((host,port),timeout=2.0):
            return True
    except OSError:
        return False

def apt_candidate(package: str)->bool:
    apt=shutil.which("apt-cache")
    if not apt:
        return False
    p=subprocess.run(
        [apt,"policy",package],
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False
    )
    if p.returncode!=0:
        return False
    for line in p.stdout.splitlines():
        line=line.strip()
        if line.startswith("Candidate:"):
            value=line.split(":",1)[1].strip()
            return bool(value and value!="(none)")
    return False

def winrm_auth_probe(host: str)->tuple[int,set[str]]:
    conn=http.client.HTTPConnection(host,5985,timeout=3.0)
    try:
        conn.request(
            "POST","/wsman",body=b"",
            headers={"Content-Type":"application/soap+xml;charset=UTF-8","Content-Length":"0"}
        )
        response=conn.getresponse()
        status=int(response.status)
        values=[]
        for name,value in response.getheaders():
            if name.lower()=="www-authenticate":
                values.append(value)
        response.read(4096)
    finally:
        conn.close()
    auth=set()
    joined=",".join(values).lower()
    for name in ("negotiate","ntlm","kerberos","basic"):
        if name in joined:
            auth.add(name)
    return status,auth

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--receipt",required=True)
    args=ap.parse_args()

    if os.geteuid()!=0:
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=root_required")

    receipt_path=Path(args.receipt)
    if not receipt_path.is_file() or receipt_path.is_symlink():
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=receipt_boundary")
    receipt=json.loads(receipt_path.read_text())
    if receipt.get("stage")!="installed_inactive":
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=receipt_stage")

    if [constraint_count("custom_dns_connection_disabled"),
        constraint_count("custom_dns_connection_enabled"),
        constraint_count("custom_dns_connection_legacy_collector_null")] != [0,1,1]:
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=constraint_state")
    print("DNS9_PHASE_B_FOUNDATION_MIGRATION_OK=1")

    counts=one("""
SELECT CONCAT(
 (SELECT COUNT(*) FROM custom_dns_connections),'|',
 (SELECT COUNT(*) FROM custom_dns_collectors),'|',
 (SELECT COUNT(*) FROM custom_dns_collector_assignments),'|',
 (SELECT COUNT(*) FROM custom_dns_control_jobs),'|',
 (SELECT COUNT(*) FROM custom_dns_credential_profiles),'|',
 (SELECT COUNT(*) FROM custom_dns_connections WHERE enabled<>0)
);
""")
    print("DNS9_PHASE_B_COUNTS="+counts)
    if counts!="1|1|1|1|0|0":
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=control_state")

    job=one(f"""
SELECT CONCAT(
 state,'|',attempt_count,'|',IFNULL(lease_id,''),'|',
 IFNULL(credential_delivered_at,0),'|',IFNULL(result_code,''),'|',
 IFNULL(finished_at,0)
)
FROM custom_dns_control_jobs WHERE id='{PILOT_JOB_ID}';
""")
    print("DNS9_PHASE_B_PILOT_JOB="+job)
    if job!="pending|0||0||0":
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=pilot_job_changed")

    for source,target in [
        (ROOT/"deploy/dns9/phpipam-dns-autonomous.service",SERVICE),
        (ROOT/"deploy/dns9/phpipam-dns-autonomous.timer",TIMER),
    ]:
        if regular(source)!=regular(target):
            raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=systemd_drift")

    timer_enabled=subprocess.run(
        ["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
    ).returncode==0
    timer_active=subprocess.run(
        ["systemctl","is-active","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
    ).returncode==0
    print("DNS9_PHASE_B_TIMER_ENABLED="+("1" if timer_enabled else "0"))
    print("DNS9_PHASE_B_TIMER_ACTIVE="+("1" if timer_active else "0"))
    if timer_enabled or timer_active:
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=timer_already_active")

    www_gid=grp.getgrnam("www-data").gr_gid
    ps=KEYRING.parent.lstat()
    pm=stat.S_IMODE(ps.st_mode)
    parent_ok=(not KEYRING.parent.is_symlink() and stat.S_ISDIR(ps.st_mode) and ps.st_uid==0
               and (pm & 0o002)==0
               and ((ps.st_gid==www_gid and (pm & 0o010)!=0) or (pm & 0o001)!=0))
    if not parent_ok:
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=keyring_parent")
    ks=KEYRING.lstat()
    keyring_ok=(not KEYRING.is_symlink() and stat.S_ISREG(ks.st_mode)
                and stat.S_IMODE(ks.st_mode)==0o640 and ks.st_uid==0
                and ks.st_gid==www_gid and ks.st_nlink==1 and 1<=ks.st_size<=4096)
    if not keyring_ok:
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=keyring")
    print("DNS9_PHASE_B_KEYRING_BOUNDARY_OK=1")

    package={}
    for path in WINDOWS_PACKAGE:
        data=regular(path)
        package[str(path.relative_to(ROOT))]={"sha256":sha256(data),"bytes":len(data)}
    print("DNS9_PHASE_B_WINDOWS_PACKAGE_FILES="+str(len(package)))
    print("DNS9_PHASE_B_WINDOWS_PACKAGE_MANIFEST="+json.dumps(package,sort_keys=True,separators=(',',':')))

    try:
        addrs=sorted({x[4][0] for x in socket.getaddrinfo(SERVER,None,socket.AF_INET)})
    except socket.gaierror:
        addrs=[]
    print("DNS9_PHASE_B_SERVER_RESOLVES="+("1" if addrs else "0"))
    if not addrs:
        raise SystemExit("DNS9_PHASE_B_PREFLIGHT_ERROR=server_resolution")

    ports={}
    for port,name in [(135,"RPC135"),(445,"SMB445"),(5985,"WINRM5985"),(5986,"WINRM5986")]:
        opened=tcp_open(SERVER,port)
        ports[name]=opened
        print("DNS9_PHASE_B_"+name+"_OPEN="+("1" if opened else "0"))

    winrm_status=0
    winrm_auth=set()
    if ports["WINRM5985"]:
        try:
            winrm_status,winrm_auth=winrm_auth_probe(SERVER)
        except (OSError,http.client.HTTPException):
            winrm_status=0
            winrm_auth=set()
    print("DNS9_PHASE_B_WINRM_HTTP_STATUS="+str(winrm_status))
    for auth_name in ("NEGOTIATE","NTLM","KERBEROS","BASIC"):
        print("DNS9_PHASE_B_WINRM_AUTH_"+auth_name+"="+("1" if auth_name.lower() in winrm_auth else "0"))

    commands=["kinit","klist","smbclient","rpcclient","wbinfo","pwsh","python3","pip3","curl","apt-cache"]
    for command in commands:
        label=command.upper().replace("-","_")
        print("DNS9_PHASE_B_CMD_"+label+"="+("1" if shutil.which(command) else "0"))

    kerberos_cache=False
    if shutil.which("klist"):
        kerberos_cache=subprocess.run(
            ["klist","-s"],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
        ).returncode==0
    print("DNS9_PHASE_B_KERBEROS_CACHE="+("1" if kerberos_cache else "0"))

    modules=["winrm","impacket","gssapi","requests_kerberos","requests_ntlm","requests","xmltodict","venv","ensurepip"]
    module_state={}
    for module in modules:
        present=importlib.util.find_spec(module) is not None
        module_state[module]=present
        label=module.upper()
        print("DNS9_PHASE_B_PY_"+label+"="+("1" if present else "0"))

    pip_ready=subprocess.run(
        ["python3","-m","pip","--version"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
    ).returncode==0
    venv_help=subprocess.run(
        ["python3","-m","venv","--help"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
    ).returncode==0
    print("DNS9_PHASE_B_PYTHON_PIP_READY="+("1" if pip_ready else "0"))
    print("DNS9_PHASE_B_PYTHON_VENV_READY="+("1" if venv_help else "0"))

    apt_packages=[
        "python3-winrm","python3-requests-ntlm","python3-requests","python3-xmltodict",
        "python3-venv","python3-pip","krb5-user","python3-kerberos"
    ]
    apt_state={}
    for package in apt_packages:
        available=apt_candidate(package)
        apt_state[package]=available
        label=package.upper().replace("-","_")
        print("DNS9_PHASE_B_APT_"+label+"="+("1" if available else "0"))

    if module_state.get("winrm") and module_state.get("requests_ntlm"):
        client_bootstrap="existing"
    elif apt_state.get("python3-winrm") and apt_state.get("python3-requests-ntlm"):
        client_bootstrap="apt"
    elif pip_ready and venv_help:
        client_bootstrap="venv_pip"
    elif apt_state.get("python3-venv") and apt_state.get("python3-pip"):
        client_bootstrap="apt_then_venv_pip"
    else:
        client_bootstrap="unavailable"
    print("DNS9_PHASE_B_WINRM_CLIENT_BOOTSTRAP="+client_bootstrap)

    ntlm_route=ports.get("WINRM5985",False) and (
        "ntlm" in winrm_auth or "negotiate" in winrm_auth or winrm_status in (401,405)
    )
    print("DNS9_PHASE_B_WINRM_NTLM_ROUTE="+("1" if ntlm_route else "0"))
    if ntlm_route and client_bootstrap!="unavailable":
        print("DNS9_PHASE_B_TRANSPORT_SELECTED=winrm_ntlm_message_encryption")
    else:
        print("DNS9_PHASE_B_TRANSPORT_SELECTED=none")

    print("DNS9_PHASE_B_DB_MUTATION=0")
    print("DNS9_PHASE_B_FILE_MUTATION=0")
    print("DNS9_PHASE_B_SYSTEMD_MUTATION=0")
    print("DNS9_PHASE_B_WINDOWS_MUTATION=0")
    print("DNS9_PHASE_B_PREFLIGHT_OK=1")

if __name__=="__main__":
    main()
