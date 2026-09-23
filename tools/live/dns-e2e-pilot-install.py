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
TOKEN_PATH=Path("/root/phpipam-dns-e2e-pilot-token")
BACKUP_ROOT=Path("/var/backups/phpipam-custom")
DB="phpipam"

SOURCE_FILES={
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsCipher.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCipher.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlRepository.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php",
    ROOT/"layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCollectorMachineService.php",
    ROOT/"layers/dns5/functions/classes/class.IntegrationDnsMachineContextReader.php":
        WEBROOT/"functions/classes/class.IntegrationDnsMachineContextReader.php",
    ROOT/"layers/dns5/functions/classes/class.IntegrationDnsCollectorMachineHttpController.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCollectorMachineHttpController.php",
    ROOT/"layers/dns6/functions/classes/class.IntegrationDnsCredentialDeliveryService.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCredentialDeliveryService.php",
    ROOT/"layers/dns6/functions/classes/class.IntegrationDnsCredentialContextReader.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCredentialContextReader.php",
    ROOT/"layers/dns6/functions/classes/class.IntegrationDnsCredentialHttpController.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCredentialHttpController.php",
    ROOT/"layers/dns5/app/integrations/dns/machine-control.php":
        WEBROOT/"app/integrations/dns/machine-control.php",
    ROOT/"layers/dns6/app/integrations/dns/machine-credential.php":
        WEBROOT/"app/integrations/dns/machine-credential.php",
}

MIGRATIONS=[
    ROOT/"database/dns1/001-configuration.sql",
    ROOT/"database/dns3/001-collector-control.sql",
    ROOT/"database/dns4/001-machine-job-lifecycle.sql",
    ROOT/"database/dns6/001-credential-delivery-marker.sql",
]

DROP_SQL="""
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS custom_dns_control_events;
DROP TABLE IF EXISTS custom_dns_control_jobs;
DROP TABLE IF EXISTS custom_dns_collector_assignments;
DROP TABLE IF EXISTS custom_dns_collectors;
DROP TABLE IF EXISTS custom_dns_config_events;
DROP TABLE IF EXISTS custom_dns_connections;
DROP TABLE IF EXISTS custom_dns_credential_profiles;
DROP TABLE IF EXISTS custom_dns_config_guard;
SET FOREIGN_KEY_CHECKS=1;
"""

def sha256(data: bytes)->str:
    return hashlib.sha256(data).hexdigest()

def sql(sql_text: str)->str:
    p=subprocess.run(
        ["mariadb","--batch","--skip-column-names",DB],
        input=sql_text,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False
    )
    if p.returncode!=0:
        raise RuntimeError("database_command_failed:"+p.stderr.strip())
    return p.stdout

def atomic_create(path: Path,data: bytes,mode: int=0o644):
    if path.exists() or path.is_symlink():
        raise RuntimeError("target_exists:"+str(path))
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp_name=tempfile.mkstemp(prefix=path.name+".",dir=str(path.parent))
    tmp=Path(tmp_name)
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(data); f.flush(); os.fsync(f.fileno())
        os.chmod(tmp,mode)
        os.chown(tmp,0,0)
        os.link(tmp,path)
        tmp.unlink()
    finally:
        if tmp.exists(): tmp.unlink()

def remove_created_file(path: Path,expected_sha: str):
    if not path.exists(): return
    if path.is_symlink() or not stat.S_ISREG(path.lstat().st_mode):
        raise RuntimeError("rollback_file_boundary:"+str(path))
    if sha256(path.read_bytes())!=expected_sha:
        raise RuntimeError("rollback_file_drift:"+str(path))
    path.unlink()

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--apply",action="store_true")
    ap.add_argument("--server",default="srv-dc01.rbank.local")
    args=ap.parse_args()
    dry_run=not args.apply

    if os.geteuid()!=0:
        raise SystemExit("DNS_E2E_PILOT_ERROR=root_required")
    if args.server!="srv-dc01.rbank.local":
        raise SystemExit("DNS_E2E_PILOT_ERROR=unexpected_server")

    missing=[str(p) for p in list(SOURCE_FILES)+MIGRATIONS if not p.is_file()]
    if missing:
        raise SystemExit("DNS_E2E_PILOT_ERROR=source_missing:"+",".join(missing))

    table_count=int(sql("""
SELECT COUNT(*) FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'custom_dns_%';
""").strip() or "0")
    print("DNS_E2E_PILOT_EXISTING_TABLES="+str(table_count))
    if table_count!=0:
        raise SystemExit("DNS_E2E_PILOT_ERROR=schema_not_empty")

    existing=[str(dst) for dst in SOURCE_FILES.values() if dst.exists() or dst.is_symlink()]
    print("DNS_E2E_PILOT_EXISTING_TARGET_FILES="+str(len(existing)))
    if existing:
        raise SystemExit("DNS_E2E_PILOT_ERROR=target_exists:"+",".join(existing))
    if TOKEN_PATH.exists() or TOKEN_PATH.is_symlink():
        raise SystemExit("DNS_E2E_PILOT_ERROR=token_path_exists")

    manifest={}
    for src,dst in SOURCE_FILES.items():
        data=src.read_bytes()
        manifest[str(dst)]={"source":str(src.relative_to(ROOT)),"sha256":sha256(data)}
    print("DNS_E2E_PILOT_SOURCE_FILES="+str(len(manifest)))
    print("DNS_E2E_PILOT_MIGRATIONS="+str(len(MIGRATIONS)))

    if dry_run:
        print("DNS_E2E_PILOT_DRY_RUN=1")
        print("DNS_E2E_PILOT_MUTATION=0")
        print("DNS_E2E_PILOT_PREFLIGHT_OK=1")
        return

    stamp=time.strftime("%Y%m%d-%H%M%S",time.gmtime())
    receipt_dir=BACKUP_ROOT/("dns-e2e-pilot-"+stamp)
    receipt_dir.mkdir(parents=True,mode=0o700,exist_ok=False)
    receipt_path=receipt_dir/"receipt.json"

    created_files=[]
    schema_created=False
    token_created=False
    receipt={
        "stage":"starting",
        "server":args.server,
        "files":manifest,
        "token_path":str(TOKEN_PATH),
        "token_secret_recorded":False,
    }
    receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)

    try:
        for migration in MIGRATIONS:
            sql(migration.read_text())
        schema_created=True

        columns=int(sql("""
SELECT COUNT(*) FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='custom_dns_control_jobs'
  AND COLUMN_NAME='credential_delivered_at';
""").strip() or "0")
        if columns!=1:
            raise RuntimeError("schema_marker_invalid")

        for src,dst in SOURCE_FILES.items():
            data=src.read_bytes()
            atomic_create(dst,data,0o644)
            created_files.append((dst,sha256(data)))

        # Parse every installed PHP candidate before seeding the pilot.
        for dst,_digest in created_files:
            if dst.suffix==".php":
                p=subprocess.run(["php","-l",str(dst)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
                if p.returncode!=0:
                    raise RuntimeError("php_lint_failed:"+str(dst)+":"+p.stdout.strip())

        now=int(time.time())
        connection_id=secrets.token_hex(16)
        collector_id=secrets.token_hex(16)
        job_id=secrets.token_hex(16)
        secret=secrets.token_urlsafe(32)
        if len(secret)!=43:
            raise RuntimeError("token_secret_length")
        token=f"dnsctl1.{collector_id}.{secret}"
        digest=hashlib.sha256(("phpipam-custom|dns-control|v1|"+token).encode()).hexdigest()

        seed=f"""
START TRANSACTION;
INSERT INTO custom_dns_connections
(id,provider,name,server,interval_seconds,credential_mode,profile_id,collector_id,enabled,status,revision,binding_epoch,updated_at,updated_by)
VALUES
('{connection_id}','microsoft_dns','DNS E2E Pilot','{args.server}',300,'collector_identity',NULL,NULL,0,'not_configured',1,1,{now},0);

INSERT INTO custom_dns_collectors
(id,name,token_digest,revision,control_epoch,revoked,created_at,created_by,updated_at,updated_by)
VALUES
('{collector_id}','DNS E2E Pilot Collector','{digest}',1,1,0,{now},0,{now},0);

INSERT INTO custom_dns_collector_assignments
(connection_id,collector_id,revision,assigned_at,assigned_by)
VALUES
('{connection_id}','{collector_id}',1,{now},0);

INSERT INTO custom_dns_control_jobs
(id,collector_id,connection_id,assignment_revision,job_type,state,configuration_revision,binding_epoch,credential_version,lease_id,lease_expires_at,attempt_count,credential_delivered_at,result_code,result_payload,finished_at,created_at,expires_at,updated_at,updated_by)
VALUES
('{job_id}','{collector_id}','{connection_id}',1,'test_connection','pending',1,1,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,{now},{now+300},{now},0);
COMMIT;
"""
        sql(seed)

        TOKEN_PATH.write_text(token+"\n")
        os.chmod(TOKEN_PATH,0o600)
        os.chown(TOKEN_PATH,0,0)
        token_created=True

        # Never place the token itself in the receipt.
        receipt.update({
            "stage":"installed",
            "connection_id":connection_id,
            "collector_id":collector_id,
            "job_id":job_id,
            "token_digest":digest,
            "schema_marker":1,
        })
        receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
        os.chmod(receipt_path,0o600)

        print("DNS_E2E_PILOT_CONNECTION_ID="+connection_id)
        print("DNS_E2E_PILOT_COLLECTOR_ID="+collector_id)
        print("DNS_E2E_PILOT_JOB_ID="+job_id)
        print("DNS_E2E_PILOT_TOKEN_PATH="+str(TOKEN_PATH))
        print("DNS_E2E_PILOT_RECEIPT="+str(receipt_path))
        print("DNS_E2E_PILOT_INSTALL_OK=1")
    except Exception:
        rollback_errors=[]
        try:
            if token_created and TOKEN_PATH.exists():
                TOKEN_PATH.unlink()
        except Exception as e:
            rollback_errors.append("token:"+str(e))
        try:
            if schema_created:
                sql(DROP_SQL)
        except Exception as e:
            rollback_errors.append("schema:"+str(e))
        for dst,digest in reversed(created_files):
            try:
                remove_created_file(dst,digest)
            except Exception as e:
                rollback_errors.append("file:"+str(e))
        receipt["stage"]="rollback_after_failure"
        receipt["rollback_errors"]=rollback_errors
        receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
        os.chmod(receipt_path,0o600)
        if rollback_errors:
            raise RuntimeError("rollback_incomplete:"+";".join(rollback_errors))
        raise

if __name__=="__main__":
    main()
