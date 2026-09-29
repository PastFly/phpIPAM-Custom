#!/usr/bin/env python3
import argparse
import hashlib
import grp
import json
import os
import stat
import subprocess
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
WEBROOT=Path("/var/www/html")
DB="phpipam"
KEYRING=Path("/etc/phpipam-custom/dns-credentials.key")
SERVICE=Path("/etc/systemd/system/phpipam-dns-autonomous.service")
TIMER=Path("/etc/systemd/system/phpipam-dns-autonomous.timer")
INDEX=WEBROOT/"app/admin/integrations/index.php"
INDEX_MARKER="PHPIPAM_CUSTOM_DNS1_CONFIG_INDEX_APPEND_V1"

TARGETS={
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsCipher.php": WEBROOT/"functions/classes/class.IntegrationDnsCipher.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php": WEBROOT/"functions/classes/class.IntegrationDnsConfigRepository.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsConfigService.php": WEBROOT/"functions/classes/class.IntegrationDnsConfigService.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php": WEBROOT/"functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsAdminController.php": WEBROOT/"functions/classes/class.IntegrationDnsAdminController.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsPhpIpamAdminBridge.php": WEBROOT/"functions/classes/class.IntegrationDnsPhpIpamAdminBridge.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsAdminView.php": WEBROOT/"functions/classes/class.IntegrationDnsAdminView.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php": WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlRepository.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php": WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php": WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlService.php",
    ROOT/"layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php": WEBROOT/"functions/classes/class.IntegrationDnsAutonomousAdminService.php",
    ROOT/"layers/dns9/functions/classes/class.IntegrationDnsAutonomousScheduler.php": WEBROOT/"functions/classes/class.IntegrationDnsAutonomousScheduler.php",
    ROOT/"layers/dns1/app/admin/integrations/dns-config-action.php": WEBROOT/"app/admin/integrations/dns-config-action.php",
    ROOT/"layers/dns1/app/admin/integrations/dns-config-panel.php": WEBROOT/"app/admin/integrations/dns-config-panel.php",
    ROOT/"layers/dns9/functions/scripts/dns-autonomous-scheduler.php": WEBROOT/"functions/scripts/dns-autonomous-scheduler.php",
    ROOT/"layers/dns1/app/admin/integrations/dns/config.js": WEBROOT/"app/admin/integrations/dns/config.js",
}

def sha256(data: bytes)->str:
    return hashlib.sha256(data).hexdigest()

def regular(path: Path)->bytes:
    st=path.lstat()
    if path.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("file_boundary_invalid:"+str(path))
    return path.read_bytes()

def sql(text: str)->str:
    p=subprocess.run(["mariadb","--batch","--skip-column-names",DB],input=text,text=True,
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False)
    if p.returncode!=0:
        raise RuntimeError("database_command_failed:"+p.stderr.strip())
    return p.stdout

def count_constraint(name: str)->int:
    out=sql(f"""
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='custom_dns_connections'
AND CONSTRAINT_NAME='{name}' AND CONSTRAINT_TYPE='CHECK';
""").strip()
    return int(out or "0")

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--receipt",required=True)
    args=ap.parse_args()
    if os.geteuid()!=0:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=root_required")

    receipt_path=Path(args.receipt)
    if not receipt_path.is_file() or receipt_path.is_symlink():
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=receipt_boundary")
    receipt=json.loads(receipt_path.read_text())
    if receipt.get("stage")!="installed_inactive":
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=receipt_stage")

    if [count_constraint("custom_dns_connection_disabled"),
        count_constraint("custom_dns_connection_enabled"),
        count_constraint("custom_dns_connection_legacy_collector_null")] != [0,1,1]:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=constraint_state")
    print("DNS9_SERVER_ACCEPT_MIGRATION_OK=1")

    exact=0
    php_linted=0
    for src,dst in TARGETS.items():
        source=regular(src)
        live=regular(dst)
        if source!=live:
            raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=file_drift:"+str(dst))
        exact+=1
        if dst.suffix==".php":
            p=subprocess.run(["php","-l",str(dst)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,check=False)
            if p.returncode!=0:
                raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=php_lint:"+str(dst))
            php_linted+=1
    print("DNS9_SERVER_ACCEPT_FILES_EXACT="+str(exact))
    print("DNS9_SERVER_ACCEPT_PHP_LINTED="+str(php_linted))

    index=regular(INDEX).decode("utf-8")
    if index.count(INDEX_MARKER)!=1:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=index_marker")
    print("DNS9_SERVER_ACCEPT_INDEX_OK=1")

    www_gid=grp.getgrnam("www-data").gr_gid
    st=KEYRING.lstat()
    keyring_ok=(not KEYRING.is_symlink() and stat.S_ISREG(st.st_mode)
        and stat.S_IMODE(st.st_mode)==0o640 and st.st_uid==0 and st.st_gid==www_gid
        and st.st_nlink==1 and 1<=st.st_size<=4096)
    if not keyring_ok:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=keyring_metadata")
    ring=json.loads(regular(KEYRING).decode("utf-8"))
    if not isinstance(ring,dict) or set(ring)!={"version","active","keys"} or ring["version"]!=1:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=keyring_shape")
    if not isinstance(ring["active"],str) or not isinstance(ring["keys"],dict) or ring["active"] not in ring["keys"]:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=keyring_shape")
    if len(ring["keys"])!=1 or not all(isinstance(v,str) and len(v)==64 for v in ring["keys"].values()):
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=keyring_shape")
    print("DNS9_SERVER_ACCEPT_KEYRING_OK=1")

    service_source=regular(ROOT/"deploy/dns9/phpipam-dns-autonomous.service")
    timer_source=regular(ROOT/"deploy/dns9/phpipam-dns-autonomous.timer")
    if regular(SERVICE)!=service_source or regular(TIMER)!=timer_source:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=systemd_drift")

    verify=subprocess.run(["systemd-analyze","verify",str(SERVICE),str(TIMER)],
        stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,check=False)
    if verify.returncode!=0:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=systemd_verify:"+verify.stdout.strip())
    print("DNS9_SERVER_ACCEPT_SYSTEMD_FILES_OK=1")

    timer_enabled=subprocess.run(["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0
    timer_active=subprocess.run(["systemctl","is-active","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0
    if timer_enabled or timer_active:
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=timer_unexpectedly_active")
    print("DNS9_SERVER_ACCEPT_TIMER_ENABLED=0")
    print("DNS9_SERVER_ACCEPT_TIMER_ACTIVE=0")

    if sha256(regular(KEYRING))!=receipt.get("keyring_sha256"):
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=keyring_receipt_drift")
    if sha256(regular(INDEX))!=receipt.get("index_after_sha256"):
        raise SystemExit("DNS9_SERVER_ACCEPT_ERROR=index_receipt_drift")

    print("DNS9_SERVER_ACCEPT_DB_MUTATION=0")
    print("DNS9_SERVER_ACCEPT_TIMER_MUTATION=0")
    print("DNS9_SERVER_ACCEPT_WINDOWS_TASK_MUTATION=0")
    print("DNS9_SERVER_ACCEPT_OK=1")

if __name__=="__main__":
    main()
