#!/usr/bin/env python3
import argparse
import grp
import hashlib
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

    for port,name in [(135,"RPC135"),(445,"SMB445"),(5985,"WINRM5985"),(5986,"WINRM5986")]:
        print("DNS9_PHASE_B_"+name+"_OPEN="+("1" if tcp_open(SERVER,port) else "0"))

    commands=["kinit","klist","smbclient","rpcclient","wbinfo","pwsh","python3"]
    for command in commands:
        label=command.upper().replace("-","_")
        print("DNS9_PHASE_B_CMD_"+label+"="+("1" if shutil.which(command) else "0"))

    kerberos_cache=False
    if shutil.which("klist"):
        kerberos_cache=subprocess.run(
            ["klist","-s"],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False
        ).returncode==0
    print("DNS9_PHASE_B_KERBEROS_CACHE="+("1" if kerberos_cache else "0"))

    modules=["winrm","impacket","gssapi","requests_kerberos"]
    for module in modules:
        label=module.upper().replace("_","_")
        print("DNS9_PHASE_B_PY_"+label+"="+("1" if importlib.util.find_spec(module) else "0"))

    print("DNS9_PHASE_B_DB_MUTATION=0")
    print("DNS9_PHASE_B_FILE_MUTATION=0")
    print("DNS9_PHASE_B_SYSTEMD_MUTATION=0")
    print("DNS9_PHASE_B_WINDOWS_MUTATION=0")
    print("DNS9_PHASE_B_PREFLIGHT_OK=1")

if __name__=="__main__":
    main()
