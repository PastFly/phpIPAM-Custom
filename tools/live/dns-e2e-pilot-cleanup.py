#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import stat
import subprocess
from pathlib import Path

WEBROOT=Path("/var/www/html")
TOKEN_PATH=Path("/root/phpipam-dns-e2e-pilot-token")
DB="phpipam"

TARGET_FILES=[
    WEBROOT/"functions/classes/class.IntegrationDnsCipher.php",
    WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlRepository.php",
    WEBROOT/"functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php",
    WEBROOT/"functions/classes/class.IntegrationDnsCollectorMachineService.php",
    WEBROOT/"functions/classes/class.IntegrationDnsMachineContextReader.php",
    WEBROOT/"functions/classes/class.IntegrationDnsCollectorMachineHttpController.php",
    WEBROOT/"functions/classes/class.IntegrationDnsCredentialDeliveryService.php",
    WEBROOT/"functions/classes/class.IntegrationDnsCredentialContextReader.php",
    WEBROOT/"functions/classes/class.IntegrationDnsCredentialHttpController.php",
    WEBROOT/"app/integrations/dns/machine-control.php",
    WEBROOT/"app/integrations/dns/machine-credential.php",
]

DROP_SQL="""
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE custom_dns_control_events;
DROP TABLE custom_dns_control_jobs;
DROP TABLE custom_dns_collector_assignments;
DROP TABLE custom_dns_collectors;
DROP TABLE custom_dns_config_events;
DROP TABLE custom_dns_connections;
DROP TABLE custom_dns_credential_profiles;
DROP TABLE custom_dns_config_guard;
SET FOREIGN_KEY_CHECKS=1;
"""

def sql(text):
    p=subprocess.run(["mariadb","--batch","--skip-column-names",DB],input=text,text=True,
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False)
    if p.returncode!=0: raise RuntimeError("database_command_failed:"+p.stderr.strip())
    return p.stdout

def sha256(data):
    return hashlib.sha256(data).hexdigest()

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--receipt",required=True)
    ap.add_argument("--apply",action="store_true")
    args=ap.parse_args()
    receipt_path=Path(args.receipt)
    if os.geteuid()!=0: raise SystemExit("DNS_E2E_CLEANUP_ERROR=root_required")
    if not receipt_path.is_file(): raise SystemExit("DNS_E2E_CLEANUP_ERROR=receipt_missing")
    receipt=json.loads(receipt_path.read_text())
    if receipt.get("stage")!="installed": raise SystemExit("DNS_E2E_CLEANUP_ERROR=receipt_stage")
    files=receipt.get("files")
    if not isinstance(files,dict): raise SystemExit("DNS_E2E_CLEANUP_ERROR=receipt_files")

    expected={str(p) for p in TARGET_FILES}
    if set(files)!=expected: raise SystemExit("DNS_E2E_CLEANUP_ERROR=file_set_mismatch")

    ids=[receipt.get("connection_id"),receipt.get("collector_id"),receipt.get("job_id")]
    if any(not isinstance(x,str) or len(x)!=32 for x in ids):
        raise SystemExit("DNS_E2E_CLEANUP_ERROR=id_invalid")

    counts=sql(f"""
SELECT
 (SELECT COUNT(*) FROM custom_dns_connections),
 (SELECT COUNT(*) FROM custom_dns_collectors),
 (SELECT COUNT(*) FROM custom_dns_collector_assignments),
 (SELECT COUNT(*) FROM custom_dns_control_jobs),
 (SELECT COUNT(*) FROM custom_dns_credential_profiles);
""").strip().split("\t")
    print("DNS_E2E_CLEANUP_COUNTS="+",".join(counts))
    if len(counts)!=5 or any(int(x)>1 for x in counts) or int(counts[4])!=0:
        raise SystemExit("DNS_E2E_CLEANUP_ERROR=non_pilot_data_present")

    for path in TARGET_FILES:
        if not path.is_file() or path.is_symlink():
            raise SystemExit("DNS_E2E_CLEANUP_ERROR=file_boundary:"+str(path))
        expected_sha=files[str(path)]["sha256"]
        if sha256(path.read_bytes())!=expected_sha:
            raise SystemExit("DNS_E2E_CLEANUP_ERROR=file_drift:"+str(path))

    if not args.apply:
        print("DNS_E2E_CLEANUP_DRY_RUN=1")
        print("DNS_E2E_CLEANUP_MUTATION=0")
        print("DNS_E2E_CLEANUP_PREFLIGHT_OK=1")
        return

    sql(DROP_SQL)
    for path in TARGET_FILES:
        path.unlink()
    if TOKEN_PATH.exists():
        if TOKEN_PATH.is_symlink() or not stat.S_ISREG(TOKEN_PATH.lstat().st_mode):
            raise RuntimeError("token_boundary")
        TOKEN_PATH.unlink()

    receipt["stage"]="removed"
    receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)
    print("DNS_E2E_CLEANUP_APPLY_OK=1")

if __name__=="__main__":
    main()
