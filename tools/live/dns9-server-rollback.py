#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import stat
import subprocess
import tempfile
from pathlib import Path

DB="phpipam"
KEYRING=Path("/etc/phpipam-custom/dns-credentials.key")
SERVICE=Path("/etc/systemd/system/phpipam-dns-autonomous.service")
TIMER=Path("/etc/systemd/system/phpipam-dns-autonomous.timer")
TRACKED_DIRS=[
    Path("/var/www/html/app/admin/integrations/dns"),
]

def sha256(data: bytes)->str:
    return hashlib.sha256(data).hexdigest()

def sql(text: str)->str:
    p=subprocess.run(["mariadb","--batch","--skip-column-names",DB],input=text,text=True,
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False)
    if p.returncode!=0:
        raise RuntimeError("database_command_failed:"+p.stderr.strip())
    return p.stdout

def regular(path: Path)->bytes:
    st=path.lstat()
    if path.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("file_boundary_invalid:"+str(path))
    return path.read_bytes()

def replace(path: Path,data: bytes,mode: int,uid: int,gid: int)->None:
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
    ap.add_argument("--apply",action="store_true")
    args=ap.parse_args()

    if os.geteuid()!=0:
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=root_required")
    receipt_path=Path(args.receipt)
    if not receipt_path.is_file() or receipt_path.is_symlink():
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=receipt_boundary")
    receipt=json.loads(receipt_path.read_text())
    if receipt.get("stage")!="installed_inactive":
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=receipt_stage")

    timer_enabled=subprocess.run(["systemctl","is-enabled","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0
    timer_active=subprocess.run(["systemctl","is-active","phpipam-dns-autonomous.timer"],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode==0
    if timer_enabled or timer_active:
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=timer_active")

    expected_systemd=set(receipt.get("systemd_created") or [])
    if expected_systemd!={str(SERVICE),str(TIMER)}:
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=systemd_receipt")

    files=receipt.get("files")
    created=receipt.get("created_targets")
    created_dirs=receipt.get("created_dirs")
    if not isinstance(files,dict) or not isinstance(created,list) or not isinstance(created_dirs,list):
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=file_receipt")
    if not set(created_dirs).issubset({str(p) for p in TRACKED_DIRS}):
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=tracked_dir_receipt")

    for target in created:
        info=files.get(target)
        path=Path(target)
        if not isinstance(info,dict):
            raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=file_manifest")
        data=regular(path)
        if sha256(data)!=info.get("sha256"):
            raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=file_drift:"+target)

    for path in [SERVICE,TIMER]:
        if not path.is_file() or path.is_symlink():
            raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=systemd_file_boundary:"+str(path))

    if not KEYRING.is_file() or KEYRING.is_symlink():
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=keyring_boundary")
    if sha256(regular(KEYRING))!=receipt.get("keyring_sha256"):
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=keyring_drift")

    index=Path(receipt["index_path"])
    backup=Path(receipt["index_backup"])
    if not index.is_file() or index.is_symlink() or not backup.is_file() or backup.is_symlink():
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=index_boundary")
    if sha256(regular(backup))!=receipt.get("index_before_sha256"):
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=index_backup_drift")

    if [count_constraint("custom_dns_connection_disabled"),
        count_constraint("custom_dns_connection_enabled"),
        count_constraint("custom_dns_connection_legacy_collector_null")] != [0,1,1]:
        raise SystemExit("DNS9_SERVER_ROLLBACK_ERROR=constraint_state")

    print("DNS9_SERVER_ROLLBACK_FILES="+str(len(created)))
    print("DNS9_SERVER_ROLLBACK_TIMER_ACTIVE=0")
    print("DNS9_SERVER_ROLLBACK_PREFLIGHT_OK=1")

    if not args.apply:
        print("DNS9_SERVER_ROLLBACK_DRY_RUN=1")
        print("DNS9_SERVER_ROLLBACK_MUTATION=0")
        return

    for path in [TIMER,SERVICE]:
        path.unlink()

    subprocess.run(["systemctl","daemon-reload"],check=True)

    KEYRING.unlink()
    if receipt.get("keyring_parent_created") is True:
        KEYRING.parent.rmdir()

    replace(index,regular(backup),
            int(receipt["index_before_mode"]),
            int(receipt["index_before_uid"]),
            int(receipt["index_before_gid"]))

    for target in reversed(created):
        Path(target).unlink()

    for directory in reversed(created_dirs):
        path=Path(directory)
        if path.is_symlink() or not path.is_dir():
            raise RuntimeError("tracked_dir_boundary:"+directory)
        path.rmdir()

    sql("""
ALTER TABLE custom_dns_connections
    DROP CONSTRAINT custom_dns_connection_enabled,
    DROP CONSTRAINT custom_dns_connection_legacy_collector_null,
    ADD CONSTRAINT custom_dns_connection_disabled CHECK (enabled = 0 AND collector_id IS NULL);
""")

    if [count_constraint("custom_dns_connection_disabled"),
        count_constraint("custom_dns_connection_enabled"),
        count_constraint("custom_dns_connection_legacy_collector_null")] != [1,0,0]:
        raise RuntimeError("constraint_rollback_failed")

    receipt["stage"]="rolled_back"
    receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)
    print("DNS9_SERVER_ROLLBACK_OK=1")

if __name__=="__main__":
    main()
