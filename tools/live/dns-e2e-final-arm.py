#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WEBROOT = Path("/var/www/html")
DB = "phpipam"
FINAL_STATE = Path("/root/phpipam-dns-e2e-final-stage.json")
TOKEN_PATH = Path("/root/phpipam-dns-e2e-pilot-token")
BASE_URI = "https://ipam.realistbank.ru/"

BOOTSTRAP = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsE2EPilotOnce.ps1"
WORKER = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1"
PREVIEW = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1"

SERVER = "srv-dc01.rbank.local"
CONNECTION_ID = "33b6fade9323f15aa7b1f56a8a865a9d"
COLLECTOR_ID = "f57057d6677e1ca7a032aab8b68796ac"
JOB_ID = "a4ee3e5987dd15495d7e2419e853d9c8"
WORKER_BLOB = "92d3a38c7e8415b44f92d35e6fa10e468ce77cbd"
PREVIEW_BLOB = "f96f1f378cc6dd8217399acfda4c98c79ab5c428"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def git_blob(data: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()


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
    if path.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("file_boundary_invalid:" + str(path))
    if mode is not None and stat.S_IMODE(st.st_mode) != mode:
        raise RuntimeError("file_mode_invalid:" + str(path))
    return path.read_bytes()


def php_literal(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def local_readback(url: str) -> tuple[int, bytes, str]:
    with tempfile.NamedTemporaryFile() as tmp:
        p = subprocess.run(
            [
                "curl", "--noproxy", "*",
                "--resolve", "ipam.realistbank.ru:443:127.0.0.1",
                "--silent", "--show-error",
                "--output", tmp.name,
                "--write-out", "%{http_code}",
                url,
            ],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        try:
            code = int(p.stdout.decode("ascii").strip() or "0")
        except ValueError:
            code = 0
        tmp.seek(0)
        return code, tmp.read(), p.stderr.decode("utf-8", "replace").strip()


def load_state() -> tuple[dict, Path]:
    if not FINAL_STATE.exists() or FINAL_STATE.is_symlink():
        raise RuntimeError("final_state_missing")
    st = FINAL_STATE.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or stat.S_IMODE(st.st_mode) != 0o600:
        raise RuntimeError("final_state_boundary_invalid")
    state = json.loads(FINAL_STATE.read_text())
    directory = Path(state["directory"])
    if directory.parent != WEBROOT or not directory.name.startswith("dns-e2e-final-"):
        raise RuntimeError("final_directory_boundary_invalid")
    return state, directory


def verify_stage(state: dict, directory: Path) -> None:
    expected = {
        "Invoke-PhpIpamDnsE2EPilotOnce.ps1": require_regular(BOOTSTRAP),
        "Invoke-PhpIpamDnsWorkerOnce.ps1": require_regular(WORKER),
        "Invoke-PhpIpamDnsPreview.ps1": require_regular(PREVIEW),
    }
    if git_blob(expected["Invoke-PhpIpamDnsWorkerOnce.ps1"]) != WORKER_BLOB:
        raise RuntimeError("worker_source_blob_mismatch")
    if git_blob(expected["Invoke-PhpIpamDnsPreview.ps1"]) != PREVIEW_BLOB:
        raise RuntimeError("preview_source_blob_mismatch")

    for filename, data in expected.items():
        current = require_regular(directory / filename)
        if current != data:
            raise RuntimeError("stage_file_drift:" + filename)
        code, body, err = local_readback(state["base_uri"] + filename)
        if code != 200 or body != data:
            raise RuntimeError("stage_https_drift:" + filename + ":" + str(code) + ":" + err)


def verify_pilot(token: str) -> int:
    digest = hashlib.sha256(("phpipam-custom|dns-control|v1|" + token).encode()).hexdigest()

    job = one_row(f"""
SELECT id,collector_id,connection_id,job_type,state,attempt_count,
       IFNULL(lease_id,''),IFNULL(lease_expires_at,0),IFNULL(credential_delivered_at,0),
       IFNULL(result_code,''),IFNULL(result_payload,''),IFNULL(finished_at,0),
       configuration_revision,binding_epoch,IFNULL(credential_version,0),assignment_revision,expires_at
FROM custom_dns_control_jobs WHERE id='{JOB_ID}';
""")
    if job[:6] != [JOB_ID, COLLECTOR_ID, CONNECTION_ID, "test_connection", "pending", "0"]:
        raise RuntimeError("job_boundary_invalid")
    if job[6] != "" or job[7] != "0" or job[8] != "0" or job[9] != "" or job[10] != "" or job[11] != "0":
        raise RuntimeError("job_already_consumed")
    if job[12:16] != ["1", "1", "0", "1"]:
        raise RuntimeError("job_revision_invalid")

    connection = one_row(f"""
SELECT id,server,credential_mode,enabled,revision,binding_epoch,IFNULL(profile_id,''),IFNULL(collector_id,'')
FROM custom_dns_connections WHERE id='{CONNECTION_ID}';
""")
    if connection != [CONNECTION_ID, SERVER, "collector_identity", "0", "1", "1", "", ""]:
        raise RuntimeError("connection_boundary_invalid")

    collector = one_row(f"""
SELECT id,token_digest,revision,control_epoch,revoked
FROM custom_dns_collectors WHERE id='{COLLECTOR_ID}';
""")
    if collector != [COLLECTOR_ID, digest, "1", "1", "0"]:
        raise RuntimeError("collector_boundary_invalid")

    assignment = one_row(f"""
SELECT connection_id,collector_id,revision
FROM custom_dns_collector_assignments WHERE connection_id='{CONNECTION_ID}';
""")
    if assignment != [CONNECTION_ID, COLLECTOR_ID, "1"]:
        raise RuntimeError("assignment_boundary_invalid")

    return int(job[16])


def arm() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_FINAL_ARM_ERROR=root_required")

    state, directory = load_state()
    if state.get("stage") != "transport_preflight_ready":
        raise SystemExit("DNS8_FINAL_ARM_ERROR=stage_not_preflight_ready")

    verify_stage(state, directory)

    token_bytes = require_regular(TOKEN_PATH, 0o600)
    token_stat = TOKEN_PATH.stat()
    if token_stat.st_uid != 0 or token_stat.st_gid != 0:
        raise SystemExit("DNS8_FINAL_ARM_ERROR=token_owner_invalid")
    token = token_bytes.decode("ascii").strip()
    if not re.fullmatch(r"dnsctl1\." + COLLECTOR_ID + r"\.[A-Za-z0-9_-]{43}", token):
        raise SystemExit("DNS8_FINAL_ARM_ERROR=token_format_invalid")

    original_expires = verify_pilot(token)

    endpoint = directory / "token.php"
    spool = Path("/run") / ("phpipam-dns-e2e-final-token-" + directory.name.removeprefix("dns-e2e-final-"))
    if endpoint.exists() or endpoint.is_symlink() or spool.exists() or spool.is_symlink():
        raise SystemExit("DNS8_FINAL_ARM_ERROR=token_transfer_collision")

    www = pwd.getpwnam("www-data")
    endpoint_created = False
    spool_created = False
    rearmed = False

    try:
        fd = os.open(spool, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w", encoding="ascii") as handle:
            handle.write(token + "\n")
            handle.flush()
            os.fsync(handle.fileno())
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
        endpoint.write_text(token_php, encoding="utf-8")
        os.chmod(endpoint, 0o644)
        os.chown(endpoint, 0, 0)
        endpoint_created = True

        lint = subprocess.run(
            ["php", "-l", str(endpoint)],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            check=False,
        )
        if lint.returncode != 0:
            raise RuntimeError("token_endpoint_php_lint_failed:" + lint.stdout.strip())

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

        state["stage"] = "armed_for_real_e2e"
        state["armed_at"] = now
        state["expires_at"] = new_expires
        state["original_expires_at"] = original_expires
        state["token_spool"] = str(spool)
        state["token_endpoint"] = str(endpoint)
        state["token_secret_recorded"] = False
        FINAL_STATE.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        os.chmod(FINAL_STATE, 0o600)

        receipt = Path(state["receipt"])
        receipt.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        os.chmod(receipt, 0o600)

        base = state["base_uri"]
        windows = (
            "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;"
            "$wc=New-Object Net.WebClient;"
            "$wc.Proxy=[Net.GlobalProxySelection]::GetEmptyWebProxy();"
            "$bb=$wc.DownloadData('" + base + "Invoke-PhpIpamDnsE2EPilotOnce.ps1');"
            "$s=[Text.Encoding]::UTF8.GetString($bb);"
            "& ([ScriptBlock]::Create($s)) "
            "-TransferBaseUri '" + base + "' "
            "-PhpIpamBaseUri '" + BASE_URI + "'"
        )

        print("DNS8_FINAL_ARM_OK=1")
        print("DNS8_FINAL_ARM_JOB_ID=" + JOB_ID)
        print("DNS8_FINAL_ARM_EXPIRES_AT=" + str(new_expires))
        print("DNS8_FINAL_ARM_SAME_STAGE=1")
        print("DNS8_FINAL_ARM_WINDOWS_COMMAND=" + windows)
        print("DNS8_FINAL_ARM_TOKEN_PRINTED=0")
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
        if endpoint_created and endpoint.exists():
            endpoint.unlink()
        if spool_created and spool.exists():
            spool.unlink()
        raise
    finally:
        token = ""
        token_bytes = b""


def accept() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_FINAL_ACCEPT_ERROR=root_required")
    state, directory = load_state()
    if state.get("stage") != "armed_for_real_e2e":
        raise SystemExit("DNS8_FINAL_ACCEPT_ERROR=stage_not_armed")

    job = one_row(f"""
SELECT id,collector_id,connection_id,job_type,state,attempt_count,
       IFNULL(lease_id,''),IFNULL(lease_expires_at,0),IFNULL(credential_delivered_at,0),
       IFNULL(result_code,''),IFNULL(result_payload,''),IFNULL(finished_at,0)
FROM custom_dns_control_jobs WHERE id='{JOB_ID}';
""")
    if job[:4] != [JOB_ID, COLLECTOR_ID, CONNECTION_ID, "test_connection"]:
        raise SystemExit("DNS8_FINAL_ACCEPT_ERROR=job_identity_invalid")

    audit = one_row(f"""
SELECT COUNT(*) FROM custom_dns_control_events
WHERE object_id='{JOB_ID}' AND action='credential_delivered';
""")
    audit_count = int(audit[0])

    endpoint = Path(state["token_endpoint"])
    spool = Path(state["token_spool"])
    cleanup_errors = []

    try:
        if spool.exists():
            if spool.is_symlink() or not stat.S_ISREG(spool.lstat().st_mode):
                cleanup_errors.append("spool_boundary")
            else:
                spool.unlink()
        if endpoint.exists():
            if endpoint.is_symlink() or not stat.S_ISREG(endpoint.lstat().st_mode):
                cleanup_errors.append("endpoint_boundary")
            else:
                endpoint.unlink()
    except Exception as exc:
        cleanup_errors.append("token_cleanup:" + str(exc))

    if cleanup_errors:
        raise SystemExit("DNS8_FINAL_ACCEPT_ERROR=" + ";".join(cleanup_errors))

    accepted = (
        job[4] == "succeeded"
        and job[5] == "1"
        and job[6] == ""
        and job[7] == "0"
        and int(job[8]) > 0
        and job[9] == "ok"
        and job[10] == ""
        and int(job[11]) > 0
        and audit_count == 1
    )

    state["stage"] = "real_e2e_complete" if accepted else "real_e2e_failed"
    state["final_job_state"] = job[4]
    state["final_attempt_count"] = int(job[5])
    state["final_credential_delivered_at"] = int(job[8])
    state["final_result_code"] = job[9]
    state["final_finished_at"] = int(job[11])
    state["credential_delivery_audit_count"] = audit_count

    receipt = Path(state["receipt"])
    receipt.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
    os.chmod(receipt, 0o600)

    if directory.exists():
        shutil.rmtree(directory)
    FINAL_STATE.unlink()

    print("DNS8_FINAL_ACCEPT_TRANSFER_CLEANED=1")
    print("DNS8_FINAL_ACCEPT_JOB_STATE=" + job[4])
    print("DNS8_FINAL_ACCEPT_JOB_ATTEMPTS=" + job[5])
    print("DNS8_FINAL_ACCEPT_CREDENTIAL_DELIVERED=" + ("1" if int(job[8]) > 0 else "0"))
    print("DNS8_FINAL_ACCEPT_RESULT_CODE=" + (job[9] or "none"))
    print("DNS8_FINAL_ACCEPT_FINISHED=" + ("1" if int(job[11]) > 0 else "0"))
    print("DNS8_FINAL_ACCEPT_CREDENTIAL_AUDIT_COUNT=" + str(audit_count))
    print("DNS8_FINAL_ACCEPT=" + ("1" if accepted else "0"))
    print("DNS8_FINAL_ACCEPT_TOKEN_PRINTED=0")

    if not accepted:
        raise SystemExit(2)


def main() -> None:
    ap = argparse.ArgumentParser()
    mode = ap.add_mutually_exclusive_group(required=True)
    mode.add_argument("--arm", action="store_true")
    mode.add_argument("--accept", action="store_true")
    args = ap.parse_args()
    if args.arm:
        arm()
    else:
        accept()


if __name__ == "__main__":
    main()
