#!/usr/bin/env python3
import hashlib
import json
import os
import pwd
import re
import secrets
import shutil
import stat
import subprocess
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WEBROOT = Path("/var/www/html")
DB = "phpipam"
TOKEN_PATH = Path("/root/phpipam-dns-e2e-pilot-token")
STATE_PATH = Path("/root/phpipam-dns-e2e-transfer-state.json")
BACKUP_ROOT = Path("/var/backups/phpipam-custom")

BASE_URI = "https://ipam.realistbank.ru/"
SERVER = "srv-dc01.rbank.local"
CONNECTION_ID = "33b6fade9323f15aa7b1f56a8a865a9d"
COLLECTOR_ID = "f57057d6677e1ca7a032aab8b68796ac"
JOB_ID = "a4ee3e5987dd15495d7e2419e853d9c8"

WORKER = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1"
PREVIEW = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1"
BOOTSTRAP = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsE2EPilotOnce.ps1"

WORKER_BLOB = "92d3a38c7e8415b44f92d35e6fa10e468ce77cbd"
PREVIEW_BLOB = "f96f1f378cc6dd8217399acfda4c98c79ab5c428"


def git_blob(data: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sql(text: str) -> str:
    p = subprocess.run(
        ["mariadb", "--batch", "--skip-column-names", DB],
        input=text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if p.returncode != 0:
        raise RuntimeError("database_command_failed:" + p.stderr.strip())
    return p.stdout


def one_row(query: str) -> list[str]:
    rows = [line.split("\t") for line in sql(query).splitlines() if line.strip()]
    if len(rows) != 1:
        raise RuntimeError("database_row_count_invalid")
    return rows[0]


def require_regular(path: Path, mode: int | None = None) -> bytes:
    st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or path.is_symlink():
        raise RuntimeError("file_boundary_invalid:" + str(path))
    if mode is not None and stat.S_IMODE(st.st_mode) != mode:
        raise RuntimeError("file_mode_invalid:" + str(path))
    return path.read_bytes()


def php_literal(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=root_required")
    if STATE_PATH.exists() or STATE_PATH.is_symlink():
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=transfer_state_exists")

    worker = require_regular(WORKER)
    preview = require_regular(PREVIEW)
    bootstrap = require_regular(BOOTSTRAP)

    if git_blob(worker) != WORKER_BLOB:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=worker_blob_mismatch")
    if git_blob(preview) != PREVIEW_BLOB:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=preview_blob_mismatch")

    token_bytes = require_regular(TOKEN_PATH, 0o600)
    if TOKEN_PATH.stat().st_uid != 0 or TOKEN_PATH.stat().st_gid != 0:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=token_owner_invalid")
    token = token_bytes.decode("ascii").strip()
    if not re.fullmatch(r"dnsctl1\." + COLLECTOR_ID + r"\.[A-Za-z0-9_-]{43}", token):
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=token_format_invalid")
    token_digest = hashlib.sha256(("phpipam-custom|dns-control|v1|" + token).encode()).hexdigest()

    job = one_row(f"""
SELECT id,collector_id,connection_id,job_type,state,attempt_count,
       IFNULL(lease_id,''),IFNULL(lease_expires_at,0),IFNULL(credential_delivered_at,0),
       IFNULL(result_code,''),IFNULL(result_payload,''),IFNULL(finished_at,0),
       configuration_revision,binding_epoch,IFNULL(credential_version,0),assignment_revision,expires_at
FROM custom_dns_control_jobs WHERE id='{JOB_ID}';
""")
    if job[:6] != [JOB_ID, COLLECTOR_ID, CONNECTION_ID, "test_connection", "pending", "0"]:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=job_boundary_invalid")
    if job[6] != "" or job[7] != "0" or job[8] != "0" or job[9] != "" or job[10] != "" or job[11] != "0":
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=job_already_consumed")
    if job[12:16] != ["1", "1", "0", "1"]:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=job_revision_invalid")
    original_expires = int(job[16])

    connection = one_row(f"""
SELECT id,server,credential_mode,enabled,revision,binding_epoch,IFNULL(profile_id,''),IFNULL(collector_id,'')
FROM custom_dns_connections WHERE id='{CONNECTION_ID}';
""")
    if connection != [CONNECTION_ID, SERVER, "collector_identity", "0", "1", "1", "", ""]:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=connection_boundary_invalid")

    collector = one_row(f"""
SELECT id,token_digest,revision,control_epoch,revoked
FROM custom_dns_collectors WHERE id='{COLLECTOR_ID}';
""")
    if collector != [COLLECTOR_ID, token_digest, "1", "1", "0"]:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=collector_boundary_invalid")

    assignment = one_row(f"""
SELECT connection_id,collector_id,revision
FROM custom_dns_collector_assignments WHERE connection_id='{CONNECTION_ID}';
""")
    if assignment != [CONNECTION_ID, COLLECTOR_ID, "1"]:
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=assignment_boundary_invalid")

    nonce = secrets.token_hex(16)
    transfer_name = "dns-e2e-run-" + nonce
    transfer_dir = WEBROOT / transfer_name
    spool = Path("/run") / ("phpipam-dns-e2e-token-" + nonce)
    if transfer_dir.exists() or transfer_dir.is_symlink() or spool.exists() or spool.is_symlink():
        raise SystemExit("DNS8_E2E_PREPARE_ERROR=transfer_collision")

    stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    receipt_dir = BACKUP_ROOT / ("dns-e2e-transfer-" + stamp + "-" + nonce[:8])
    receipt_dir.mkdir(parents=True, mode=0o700, exist_ok=False)
    receipt_path = receipt_dir / "receipt.json"

    created_transfer = False
    spool_created = False
    rearmed = False
    try:
        transfer_dir.mkdir(mode=0o755)
        created_transfer = True

        published = {
            "Invoke-PhpIpamDnsWorkerOnce.ps1": worker,
            "Invoke-PhpIpamDnsPreview.ps1": preview,
            "Invoke-PhpIpamDnsE2EPilotOnce.ps1": bootstrap,
        }
        files = {}
        for name, data in published.items():
            path = transfer_dir / name
            path.write_bytes(data)
            os.chmod(path, 0o644)
            os.chown(path, 0, 0)
            files[name] = {"sha256": sha256(data), "bytes": len(data)}

        www = pwd.getpwnam("www-data")
        fd = os.open(spool, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            with os.fdopen(fd, "w", encoding="ascii") as handle:
                handle.write(token + "\n")
                handle.flush()
                os.fsync(handle.fileno())
        except Exception:
            try:
                os.close(fd)
            except OSError:
                pass
            raise
        os.chown(spool, www.pw_uid, www.pw_gid)
        os.chmod(spool, 0o400)
        spool_created = True

        token_php = f"""<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=us-ascii');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {{ http_response_code(405); exit; }}
$path={php_literal(str(spool))};
$token=@file_get_contents($path);
if (!is_string($token) || trim($token)==='') {{ http_response_code(410); exit; }}
if (!@unlink($path)) {{ http_response_code(500); exit; }}
@unlink(__FILE__);
echo trim($token)."\\n";
"""
        endpoint = transfer_dir / "token.php"
        endpoint.write_text(token_php, encoding="utf-8")
        os.chmod(endpoint, 0o644)
        os.chown(endpoint, 0, 0)
        lint = subprocess.run(
            ["php", "-l", str(endpoint)],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
        )
        if lint.returncode != 0:
            raise RuntimeError("token_endpoint_php_lint_failed:" + lint.stdout.strip())
        files["token.php"] = {"sha256": sha256(endpoint.read_bytes()), "bytes": endpoint.stat().st_size}

        base = BASE_URI + transfer_name + "/"
        state = {
            "stage": "prepared_before_rearm",
            "created_at": int(time.time()),
            "base_uri": base,
            "transfer_dir": str(transfer_dir),
            "token_spool": str(spool),
            "job_id": JOB_ID,
            "collector_id": COLLECTOR_ID,
            "connection_id": CONNECTION_ID,
            "original_expires_at": original_expires,
            "worker_blob": WORKER_BLOB,
            "preview_blob": PREVIEW_BLOB,
            "files": files,
            "receipt": str(receipt_path),
            "token_secret_recorded": False,
        }
        receipt_path.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        os.chmod(receipt_path, 0o600)
        STATE_PATH.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        os.chmod(STATE_PATH, 0o600)
        os.chown(STATE_PATH, 0, 0)

        now = int(time.time())
        new_expires = now + 300
        out = sql(f"""
START TRANSACTION;
UPDATE custom_dns_control_jobs
SET expires_at={new_expires}, updated_at={now}, updated_by=0
WHERE id='{JOB_ID}'
  AND collector_id='{COLLECTOR_ID}'
  AND connection_id='{CONNECTION_ID}'
  AND job_type='test_connection'
  AND state='pending'
  AND attempt_count=0
  AND lease_id IS NULL
  AND lease_expires_at IS NULL
  AND credential_delivered_at IS NULL
  AND result_code IS NULL
  AND result_payload IS NULL
  AND finished_at IS NULL
  AND configuration_revision=1
  AND binding_epoch=1
  AND credential_version IS NULL
  AND assignment_revision=1;
SELECT ROW_COUNT();
COMMIT;
""").strip().splitlines()
        if out != ["1"]:
            raise RuntimeError("job_rearm_guard_failed")
        rearmed = True

        state["stage"] = "armed"
        state["armed_at"] = now
        state["expires_at"] = new_expires
        receipt_path.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        STATE_PATH.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")

        windows = (
            "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;"
            "$wc=New-Object Net.WebClient;"
            "$wc.Proxy=[Net.GlobalProxySelection]::GetEmptyWebProxy();"
            f"$s=$wc.DownloadString('{base}Invoke-PhpIpamDnsE2EPilotOnce.ps1');"
            f"& ([ScriptBlock]::Create($s)) -TransferBaseUri '{base}' -PhpIpamBaseUri '{BASE_URI}'"
        )
        print("DNS8_E2E_PREPARE_OK=1")
        print("DNS8_E2E_JOB_ID=" + JOB_ID)
        print("DNS8_E2E_EXPIRES_AT=" + str(new_expires))
        print("DNS8_E2E_TRANSFER_RECEIPT=" + str(receipt_path))
        print("DNS8_E2E_WINDOWS_COMMAND=" + windows)
        print("DNS8_E2E_TOKEN_PRINTED=0")
    except Exception:
        if rearmed:
            restore_now = int(time.time())
            try:
                sql(f"""
UPDATE custom_dns_control_jobs
SET expires_at={original_expires}, updated_at={restore_now}, updated_by=0
WHERE id='{JOB_ID}' AND state='pending' AND attempt_count=0 AND lease_id IS NULL
  AND credential_delivered_at IS NULL AND result_code IS NULL AND finished_at IS NULL;
""")
            except Exception:
                pass
        if spool_created and spool.exists():
            spool.unlink()
        if created_transfer and transfer_dir.exists():
            shutil.rmtree(transfer_dir)
        if STATE_PATH.exists():
            STATE_PATH.unlink()
        raise
    finally:
        token = ""
        token_bytes = b""


if __name__ == "__main__":
    main()
