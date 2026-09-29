#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import secrets
import shutil
import stat
import subprocess
import tempfile
import time
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
WEBROOT=Path("/var/www/html")
DB="phpipam"
BACKUP_ROOT=Path("/var/backups/phpipam-custom")
KEYRING=Path("/etc/phpipam-custom/dns-credentials.key")
LIVE_INDEX=WEBROOT/"app/admin/integrations/index.php"
INDEX_APPEND=ROOT/"layers/dns1/app/admin/integrations/index-dns1-append.php"
INDEX_MARKER="PHPIPAM_CUSTOM_DNS1_CONFIG_INDEX_APPEND_V1"
SERVICE_TARGET=Path("/etc/systemd/system/phpipam-dns-autonomous.service")
TIMER_TARGET=Path("/etc/systemd/system/phpipam-dns-autonomous.timer")
MIGRATION=ROOT/"database/dns9/001-autonomous-enable.sql"

PHP_TARGETS={
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
}
STATIC_TARGETS={
    ROOT/"layers/dns1/app/admin/integrations/dns/config.js": WEBROOT/"app/admin/integrations/dns/config.js",
}
SYSTEMD_SOURCES={
    ROOT/"deploy/dns9/phpipam-dns-autonomous.service": SERVICE_TARGET,
    ROOT/"deploy/dns9/phpipam-dns-autonomous.timer": TIMER_TARGET,
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

def one(query: str)->str:
    rows=[x for x in sql(query).splitlines() if x.strip()]
    if len(rows)!=1:
        raise RuntimeError("database_row_count_invalid")
    return rows[0]

def atomic_create(path: Path,data: bytes,mode: int=0o644,uid: int=0,gid: int=0)->None:
    if path.exists() or path.is_symlink():
        raise RuntimeError("target_exists:"+str(path))
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp_name=tempfile.mkstemp(prefix=path.name+".",dir=str(path.parent))
    tmp=Path(tmp_name)
    try:
        with os.fdopen(fd,"wb") as h:
            h.write(data); h.flush(); os.fsync(h.fileno())
        os.chmod(tmp,mode); os.chown(tmp,uid,gid)
        os.link(tmp,path); tmp.unlink()
    finally:
        if tmp.exists(): tmp.unlink()

def write_atomic_replace(path: Path,data: bytes,mode: int,uid: int,gid: int)->None:
    if path.is_symlink():
        raise RuntimeError("replace_symlink:"+str(path))
    fd,tmp_name=tempfile.mkstemp(prefix=path.name+".",dir=str(path.parent))
    tmp=Path(tmp_name)
    try:
        with os.fdopen(fd,"wb") as h:
            h.write(data); h.flush(); os.fsync(h.fileno())
        os.chmod(tmp,mode); os.chown(tmp,uid,gid)
        os.replace(tmp,path)
    finally:
        if tmp.exists(): tmp.unlink()

def constraint_count(name: str)->int:
    return int(one(f"""
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='custom_dns_connections'
AND CONSTRAINT_NAME='{name}' AND CONSTRAINT_TYPE='CHECK';
"""))

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--apply",action="store_true")
    args=ap.parse_args()
    dry=not args.apply
    if os.geteuid()!=0:
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=root_required")

    required=list(PHP_TARGETS)+list(STATIC_TARGETS)+list(SYSTEMD_SOURCES)+[MIGRATION,INDEX_APPEND]
    missing=[str(p.relative_to(ROOT)) for p in required if not p.is_file()]
    if missing:
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=source_missing:"+",".join(missing))

    legacy=constraint_count("custom_dns_connection_disabled")
    new_enable=constraint_count("custom_dns_connection_enabled")
    new_null=constraint_count("custom_dns_connection_legacy_collector_null")
    if [legacy,new_enable,new_null] != [1,0,0]:
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=constraint_state")

    timer_enabled=subprocess.run(["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0
    timer_active=subprocess.run(["systemctl","is-active","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0
    if timer_enabled or timer_active:
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=timer_already_active")

    if SERVICE_TARGET.exists() or SERVICE_TARGET.is_symlink() or TIMER_TARGET.exists() or TIMER_TARGET.is_symlink():
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=systemd_target_exists")

    if not LIVE_INDEX.is_file() or LIVE_INDEX.is_symlink():
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=integration_index_boundary")
    index_before=regular(LIVE_INDEX)
    index_stat=LIVE_INDEX.lstat()
    index_mode=stat.S_IMODE(index_stat.st_mode)
    index_uid=index_stat.st_uid
    index_gid=index_stat.st_gid
    if index_before.decode("utf-8").count(INDEX_MARKER)!=0:
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=index_marker_state")

    target_manifest={}
    create_targets=[]
    for src,dst in {**PHP_TARGETS,**STATIC_TARGETS}.items():
        data=regular(src)
        target_manifest[str(dst)]={"source":str(src.relative_to(ROOT)),"sha256":sha256(data),"bytes":len(data)}
        if dst.exists() or dst.is_symlink():
            if regular(dst)!=data:
                raise SystemExit("DNS9_SERVER_INSTALL_ERROR=target_drift:"+str(dst))
        else:
            create_targets.append((src,dst))

    if KEYRING.exists() or KEYRING.is_symlink():
        raise SystemExit("DNS9_SERVER_INSTALL_ERROR=keyring_already_exists")

    for src in PHP_TARGETS:
        p=subprocess.run(["php","-l",str(src)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
        if p.returncode!=0:
            raise SystemExit("DNS9_SERVER_INSTALL_ERROR=php_lint:"+str(src.relative_to(ROOT)))

    print("DNS9_SERVER_INSTALL_CREATE_FILES="+str(len(create_targets)))
    print("DNS9_SERVER_INSTALL_EXISTING_EXACT="+str(len(target_manifest)-len(create_targets)))
    print("DNS9_SERVER_INSTALL_INDEX_APPEND=1")
    print("DNS9_SERVER_INSTALL_KEYRING_CREATE=1")
    print("DNS9_SERVER_INSTALL_SYSTEMD_FILES=2")
    print("DNS9_SERVER_INSTALL_TIMER_ENABLE=0")
    print("DNS9_SERVER_INSTALL_TIMER_START=0")

    if dry:
        print("DNS9_SERVER_INSTALL_DRY_RUN=1")
        print("DNS9_SERVER_INSTALL_MUTATION=0")
        print("DNS9_SERVER_INSTALL_PREFLIGHT_OK=1")
        return

    stamp=time.strftime("%Y%m%d-%H%M%S",time.gmtime())
    receipt_dir=BACKUP_ROOT/("dns9-server-install-"+stamp)
    receipt_dir.mkdir(parents=True,mode=0o700,exist_ok=False)
    receipt_path=receipt_dir/"receipt.json"
    index_backup=receipt_dir/"integrations-index.php.before"
    index_backup.write_bytes(index_before)
    os.chmod(index_backup,0o600)

    receipt={
        "stage":"starting",
        "created_at":int(time.time()),
        "files":target_manifest,
        "created_targets":[],
        "index_path":str(LIVE_INDEX),
        "index_backup":str(index_backup),
        "index_before_sha256":sha256(index_before),
        "index_before_mode":index_mode,
        "index_before_uid":index_uid,
        "index_before_gid":index_gid,
        "keyring_path":str(KEYRING),
        "keyring_secret_recorded":False,
        "systemd_targets":[str(SERVICE_TARGET),str(TIMER_TARGET)],
        "timer_enabled":False,
        "timer_started":False,
        "migration_applied":False,
    }
    receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)

    created=[]
    systemd_created=[]
    keyring_created=False
    keyring_parent_created=False
    index_changed=False
    migration_applied=False

    try:
        migration=regular(MIGRATION).decode("utf-8")
        sql(migration)
        migration_applied=True

        if [constraint_count("custom_dns_connection_disabled"),
            constraint_count("custom_dns_connection_enabled"),
            constraint_count("custom_dns_connection_legacy_collector_null")] != [0,1,1]:
            raise RuntimeError("migration_postcheck_failed")

        for src,dst in create_targets:
            data=regular(src)
            atomic_create(dst,data,0o644,0,0)
            created.append(dst)

        append=regular(INDEX_APPEND)
        merged=index_before
        if not merged.endswith(b"\n"):
            merged+=b"\n"
        merged+=b"\n"+append
        write_atomic_replace(LIVE_INDEX,merged,index_mode,index_uid,index_gid)
        index_changed=True
        if regular(LIVE_INDEX).decode("utf-8").count(INDEX_MARKER)!=1:
            raise RuntimeError("index_postcheck_failed")

        key_id="dns9-"+time.strftime("%Y%m%d",time.gmtime())
        key_hex=secrets.token_hex(32)
        key_doc={"version":1,"active":key_id,"keys":{key_id:key_hex}}
        key_bytes=(json.dumps(key_doc,sort_keys=True,separators=(',',':'))+"\n").encode()
        keyring_parent_created=False
        if not KEYRING.parent.exists():
            KEYRING.parent.mkdir(parents=True,mode=0o750)
            os.chown(KEYRING.parent,0,0)
            keyring_parent_created=True
        elif KEYRING.parent.is_symlink() or not stat.S_ISDIR(KEYRING.parent.lstat().st_mode):
            raise RuntimeError("keyring_parent_boundary")
        atomic_create(KEYRING,key_bytes,0o640,0,33)
        keyring_created=True
        key_hex=""
        key_bytes=b""

        for src,dst in SYSTEMD_SOURCES.items():
            atomic_create(dst,regular(src),0o644,0,0)
            systemd_created.append(dst)

        daemon=subprocess.run(["systemctl","daemon-reload"],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False)
        if daemon.returncode!=0:
            raise RuntimeError("systemd_daemon_reload_failed:"+daemon.stderr.strip())

        if subprocess.run(["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0:
            raise RuntimeError("timer_unexpectedly_enabled")
        if subprocess.run(["systemctl","is-active","phpipam-dns-autonomous.timer"],
            stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0:
            raise RuntimeError("timer_unexpectedly_active")

        php_ok=0
        for dst in PHP_TARGETS.values():
            p=subprocess.run(["php","-l",str(dst)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
            if p.returncode!=0:
                raise RuntimeError("installed_php_lint_failed:"+str(dst)+":"+p.stdout.strip())
            php_ok+=1

        receipt.update({
            "stage":"installed_inactive",
            "created_targets":[str(p) for p in created],
            "systemd_created":[str(p) for p in systemd_created],
            "migration_applied":True,
            "keyring_created":True,
            "keyring_parent_created":keyring_parent_created,
            "keyring_sha256":sha256(regular(KEYRING)),
            "index_after_sha256":sha256(regular(LIVE_INDEX)),
            "php_linted":php_ok,
        })
        receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
        os.chmod(receipt_path,0o600)

        print("DNS9_SERVER_INSTALL_RECEIPT="+str(receipt_path))
        print("DNS9_SERVER_INSTALL_MIGRATION_OK=1")
        print("DNS9_SERVER_INSTALL_FILES_OK=1")
        print("DNS9_SERVER_INSTALL_INDEX_OK=1")
        print("DNS9_SERVER_INSTALL_KEYRING_OK=1")
        print("DNS9_SERVER_INSTALL_SYSTEMD_FILES_OK=1")
        print("DNS9_SERVER_INSTALL_TIMER_ENABLE=0")
        print("DNS9_SERVER_INSTALL_TIMER_START=0")
        print("DNS9_SERVER_INSTALL_OK=1")
    except Exception:
        errors=[]
        try:
            subprocess.run(["systemctl","disable","--now","phpipam-dns-autonomous.timer"],
                stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False)
        except Exception as e:
            errors.append("timer:"+str(e))
        for dst in reversed(systemd_created):
            try:
                if dst.exists() and not dst.is_symlink(): dst.unlink()
            except Exception as e:
                errors.append("systemd_file:"+str(e))
        try:
            subprocess.run(["systemctl","daemon-reload"],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False)
        except Exception as e:
            errors.append("daemon_reload:"+str(e))
        if keyring_created:
            try:
                if KEYRING.exists() and not KEYRING.is_symlink(): KEYRING.unlink()
            except Exception as e:
                errors.append("keyring:"+str(e))
        if keyring_parent_created:
            try:
                KEYRING.parent.rmdir()
            except Exception as e:
                errors.append("keyring_parent:"+str(e))
        if index_changed:
            try:
                write_atomic_replace(LIVE_INDEX,index_before,index_mode,index_uid,index_gid)
            except Exception as e:
                errors.append("index:"+str(e))
        for dst in reversed(created):
            try:
                if dst.exists() and not dst.is_symlink(): dst.unlink()
            except Exception as e:
                errors.append("file:"+str(e))
        if migration_applied:
            try:
                sql("""
ALTER TABLE custom_dns_connections
    DROP CONSTRAINT custom_dns_connection_enabled,
    DROP CONSTRAINT custom_dns_connection_legacy_collector_null,
    ADD CONSTRAINT custom_dns_connection_disabled CHECK (enabled = 0 AND collector_id IS NULL);
""")
            except Exception as e:
                errors.append("migration:"+str(e))
        receipt["stage"]="rollback_after_failure"
        receipt["rollback_errors"]=errors
        receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
        os.chmod(receipt_path,0o600)
        if errors:
            raise RuntimeError("rollback_incomplete:"+";".join(errors))
        raise

if __name__=="__main__":
    main()
