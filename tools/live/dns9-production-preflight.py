#!/usr/bin/env python3
import hashlib
import json
import os
import stat
import subprocess
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
WEBROOT=Path("/var/www/html")
DB="phpipam"

PILOT_CONNECTION_ID="33b6fade9323f15aa7b1f56a8a865a9d"
PILOT_COLLECTOR_ID="f57057d6677e1ca7a032aab8b68796ac"
PILOT_JOB_ID="a4ee3e5987dd15495d7e2419e853d9c8"
PILOT_TOKEN=Path("/root/phpipam-dns-e2e-pilot-token")
KEYRING=Path("/etc/phpipam-custom/dns-credentials.key")

PHP_TARGETS={
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsCipher.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCipher.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php":
        WEBROOT/"functions/classes/class.IntegrationDnsConfigRepository.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsConfigService.php":
        WEBROOT/"functions/classes/class.IntegrationDnsConfigService.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php":
        WEBROOT/"functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsAdminController.php":
        WEBROOT/"functions/classes/class.IntegrationDnsAdminController.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsPhpIpamAdminBridge.php":
        WEBROOT/"functions/classes/class.IntegrationDnsPhpIpamAdminBridge.php",
    ROOT/"layers/dns1/functions/classes/class.IntegrationDnsAdminView.php":
        WEBROOT/"functions/classes/class.IntegrationDnsAdminView.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlRepository.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php",
    ROOT/"layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php":
        WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlService.php",
    ROOT/"layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php":
        WEBROOT/"functions/classes/class.IntegrationDnsAutonomousAdminService.php",
    ROOT/"layers/dns9/functions/classes/class.IntegrationDnsAutonomousScheduler.php":
        WEBROOT/"functions/classes/class.IntegrationDnsAutonomousScheduler.php",
    ROOT/"layers/dns1/app/admin/integrations/dns-config-action.php":
        WEBROOT/"app/admin/integrations/dns-config-action.php",
    ROOT/"layers/dns1/app/admin/integrations/dns-config-panel.php":
        WEBROOT/"app/admin/integrations/dns-config-panel.php",
    ROOT/"layers/dns9/functions/scripts/dns-autonomous-scheduler.php":
        WEBROOT/"functions/scripts/dns-autonomous-scheduler.php",
}

STATIC_TARGETS={
    ROOT/"layers/dns1/app/admin/integrations/dns/config.js":
        WEBROOT/"app/admin/integrations/dns/config.js",
}

SYSTEMD_TARGETS={
    ROOT/"deploy/dns9/phpipam-dns-autonomous.service":
        Path("/etc/systemd/system/phpipam-dns-autonomous.service"),
    ROOT/"deploy/dns9/phpipam-dns-autonomous.timer":
        Path("/etc/systemd/system/phpipam-dns-autonomous.timer"),
}

WINDOWS_PACKAGE=[
    ROOT/"collectors/windows/dns/Install-PhpIpamDnsCollector.ps1",
    ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1",
    ROOT/"collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1",
    ROOT/"collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1",
    ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1",
    ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1",
]

MIGRATION=ROOT/"database/dns9/001-autonomous-enable.sql"
INDEX_APPEND=ROOT/"layers/dns1/app/admin/integrations/index-dns1-append.php"
LIVE_INDEX=WEBROOT/"app/admin/integrations/index.php"
INDEX_MARKER="PHPIPAM_CUSTOM_DNS1_CONFIG_INDEX_APPEND_V1"

EXPECTED_TABLES=[
    "custom_dns_collector_assignments",
    "custom_dns_collectors",
    "custom_dns_config_events",
    "custom_dns_config_guard",
    "custom_dns_connections",
    "custom_dns_control_events",
    "custom_dns_control_jobs",
    "custom_dns_credential_profiles",
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

def one_row(query: str)->list[str]:
    rows=[line.split("\t") for line in sql(query).splitlines() if line.strip()]
    if len(rows)!=1:
        raise RuntimeError("database_row_count_invalid")
    return rows[0]

def main()->None:
    if os.geteuid()!=0:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=root_required")

    required=list(PHP_TARGETS)+list(STATIC_TARGETS)+list(SYSTEMD_TARGETS)+WINDOWS_PACKAGE+[MIGRATION,INDEX_APPEND]
    missing=[str(p.relative_to(ROOT)) for p in required if not p.is_file()]
    if missing:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=source_missing:"+",".join(missing))

    migration=regular(MIGRATION).decode("utf-8")
    if "DROP CONSTRAINT custom_dns_connection_disabled" not in migration or "custom_dns_connection_enabled" not in migration:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=migration_contract_invalid")
    if "UPDATE " in migration or "DELETE " in migration or "DROP TABLE" in migration:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=migration_destructive")

    tables=sorted([line.strip() for line in sql("""
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'custom_dns_%'
ORDER BY TABLE_NAME;
""").splitlines() if line.strip()])
    print("DNS9_PREFLIGHT_TABLES="+",".join(tables))
    if tables!=EXPECTED_TABLES:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=unexpected_dns_tables")

    legacy=int(one_row("""
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE()
  AND TABLE_NAME='custom_dns_connections'
  AND CONSTRAINT_NAME='custom_dns_connection_disabled'
  AND CONSTRAINT_TYPE='CHECK';
""")[0])
    enabled_new=int(one_row("""
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE()
  AND TABLE_NAME='custom_dns_connections'
  AND CONSTRAINT_NAME='custom_dns_connection_enabled'
  AND CONSTRAINT_TYPE='CHECK';
""")[0])
    legacy_null_new=int(one_row("""
SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE()
  AND TABLE_NAME='custom_dns_connections'
  AND CONSTRAINT_NAME='custom_dns_connection_legacy_collector_null'
  AND CONSTRAINT_TYPE='CHECK';
""")[0])
    print("DNS9_PREFLIGHT_LEGACY_CONSTRAINT="+str(legacy))
    print("DNS9_PREFLIGHT_NEW_ENABLE_CONSTRAINT="+str(enabled_new))
    print("DNS9_PREFLIGHT_NEW_NULL_CONSTRAINT="+str(legacy_null_new))
    if [legacy,enabled_new,legacy_null_new] != [1,0,0]:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=constraint_state_unexpected")

    counts=one_row("""
SELECT
 (SELECT COUNT(*) FROM custom_dns_connections),
 (SELECT COUNT(*) FROM custom_dns_collectors),
 (SELECT COUNT(*) FROM custom_dns_collector_assignments),
 (SELECT COUNT(*) FROM custom_dns_control_jobs),
 (SELECT COUNT(*) FROM custom_dns_credential_profiles),
 (SELECT COUNT(*) FROM custom_dns_connections WHERE enabled<>0);
""")
    print("DNS9_PREFLIGHT_COUNTS="+",".join(counts))
    if counts!=["1","1","1","1","0","0"]:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=pilot_counts_unexpected")

    connection=one_row(f"""
SELECT id,server,credential_mode,enabled,revision,binding_epoch,status
FROM custom_dns_connections WHERE id='{PILOT_CONNECTION_ID}';
""")
    if connection[:6] != [PILOT_CONNECTION_ID,"srv-dc01.rbank.local","collector_identity","0","1","1"]:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=pilot_connection_drift")
    print("DNS9_PREFLIGHT_CONNECTION_OK=1")

    collector=one_row(f"""
SELECT id,revision,control_epoch,revoked
FROM custom_dns_collectors WHERE id='{PILOT_COLLECTOR_ID}';
""")
    if collector != [PILOT_COLLECTOR_ID,"1","1","0"]:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=pilot_collector_drift")
    print("DNS9_PREFLIGHT_COLLECTOR_OK=1")

    assignment=one_row(f"""
SELECT connection_id,collector_id,revision
FROM custom_dns_collector_assignments WHERE connection_id='{PILOT_CONNECTION_ID}';
""")
    if assignment != [PILOT_CONNECTION_ID,PILOT_COLLECTOR_ID,"1"]:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=pilot_assignment_drift")
    print("DNS9_PREFLIGHT_ASSIGNMENT_OK=1")

    job=one_row(f"""
SELECT id,state,attempt_count,IFNULL(lease_id,''),IFNULL(lease_expires_at,0),
       IFNULL(credential_delivered_at,0),IFNULL(result_code,''),IFNULL(result_payload,''),
       IFNULL(finished_at,0)
FROM custom_dns_control_jobs WHERE id='{PILOT_JOB_ID}';
""")
    if job != [PILOT_JOB_ID,"pending","0","","0","0","","","0"]:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=pilot_job_consumed_or_drifted")
    print("DNS9_PREFLIGHT_PILOT_JOB_UNCONSUMED=1")

    if not PILOT_TOKEN.exists() or PILOT_TOKEN.is_symlink():
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=pilot_token_missing")
    token_stat=PILOT_TOKEN.stat()
    if not stat.S_ISREG(token_stat.st_mode) or stat.S_IMODE(token_stat.st_mode)!=0o600 or token_stat.st_uid!=0 or token_stat.st_gid!=0:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=pilot_token_boundary")
    print("DNS9_PREFLIGHT_PILOT_TOKEN_BOUNDARY_OK=1")

    php_linted=0
    existing_exact=0
    absent=0
    drift=[]
    manifest={}
    for src,dst in {**PHP_TARGETS,**STATIC_TARGETS}.items():
        data=regular(src)
        manifest[str(dst)]={"source":str(src.relative_to(ROOT)),"sha256":sha256(data),"bytes":len(data)}
        if src.suffix==".php":
            p=subprocess.run(["php","-l",str(src)],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
            if p.returncode!=0:
                raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=php_lint:"+str(src.relative_to(ROOT)))
            php_linted+=1
        if dst.exists() or dst.is_symlink():
            try:
                live=regular(dst)
            except Exception:
                drift.append(str(dst))
                continue
            if live==data:
                existing_exact+=1
            else:
                drift.append(str(dst))
        else:
            absent+=1

    print("DNS9_PREFLIGHT_PHP_LINTED="+str(php_linted))
    print("DNS9_PREFLIGHT_TARGET_EXISTING_EXACT="+str(existing_exact))
    print("DNS9_PREFLIGHT_TARGET_ABSENT="+str(absent))
    print("DNS9_PREFLIGHT_TARGET_DRIFT="+str(len(drift)))
    if drift:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=target_drift:"+",".join(drift))

    if not LIVE_INDEX.is_file() or LIVE_INDEX.is_symlink():
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=integration_index_boundary")
    index_text=LIVE_INDEX.read_text(errors="strict")
    marker_count=index_text.count(INDEX_MARKER)
    print("DNS9_PREFLIGHT_INDEX_MARKER_COUNT="+str(marker_count))
    if marker_count>1:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=integration_index_marker_duplicate")

    systemd_existing=[]
    for src,dst in SYSTEMD_TARGETS.items():
        regular(src)
        if dst.exists() or dst.is_symlink():
            systemd_existing.append(str(dst))
    print("DNS9_PREFLIGHT_SYSTEMD_EXISTING="+str(len(systemd_existing)))
    if systemd_existing:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=systemd_target_exists:"+",".join(systemd_existing))

    systemctl=subprocess.run(
        ["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,check=False
    )
    timer_enabled=systemctl.returncode==0
    print("DNS9_PREFLIGHT_TIMER_ENABLED="+("1" if timer_enabled else "0"))
    if timer_enabled:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=timer_already_enabled")

    if KEYRING.exists() or KEYRING.is_symlink():
        try:
            ks=KEYRING.lstat()
            keyring_ready=(not KEYRING.is_symlink() and stat.S_ISREG(ks.st_mode)
                and stat.S_IMODE(ks.st_mode)==0o640 and ks.st_uid==0 and ks.st_gid==33
                and ks.st_nlink==1 and 1<=ks.st_size<=4096)
        except Exception:
            keyring_ready=False
        print("DNS9_PREFLIGHT_KEYRING_PRESENT=1")
        print("DNS9_PREFLIGHT_KEYRING_METADATA_OK="+("1" if keyring_ready else "0"))
        if not keyring_ready:
            raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=keyring_boundary")
    else:
        print("DNS9_PREFLIGHT_KEYRING_PRESENT=0")
        print("DNS9_PREFLIGHT_KEYRING_METADATA_OK=0")

    sodium=subprocess.run(
        ["php","-r","exit(function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')?0:1);"],
        check=False
    ).returncode==0
    print("DNS9_PREFLIGHT_SODIUM_READY="+("1" if sodium else "0"))
    if not sodium:
        raise SystemExit("DNS9_PRODUCTION_PREFLIGHT_ERROR=sodium_missing")

    windows_manifest={}
    for path in WINDOWS_PACKAGE:
        data=regular(path)
        windows_manifest[str(path.relative_to(ROOT))]={"sha256":sha256(data),"bytes":len(data)}
    print("DNS9_PREFLIGHT_WINDOWS_PACKAGE_FILES="+str(len(windows_manifest)))
    print("DNS9_PREFLIGHT_WINDOWS_PACKAGE_MANIFEST="+json.dumps(windows_manifest,sort_keys=True,separators=(',',':')))

    print("DNS9_PREFLIGHT_PHP_TARGETS="+str(len(PHP_TARGETS)+len(STATIC_TARGETS)))
    print("DNS9_PREFLIGHT_SYSTEMD_TARGETS="+str(len(SYSTEMD_TARGETS)))
    print("DNS9_PREFLIGHT_PROFILE_MODE_READY="+("1" if KEYRING.exists() else "0"))
    print("DNS9_PREFLIGHT_COLLECTOR_IDENTITY_MODE_SOURCE_READY=1")
    print("DNS9_PREFLIGHT_DB_MUTATION=0")
    print("DNS9_PREFLIGHT_FILE_MUTATION=0")
    print("DNS9_PREFLIGHT_SYSTEMD_MUTATION=0")
    print("DNS9_PREFLIGHT_WINDOWS_TASK_MUTATION=0")
    print("DNS9_PRODUCTION_PREFLIGHT_OK=1")

if __name__=="__main__":
    main()
