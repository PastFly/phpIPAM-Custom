#!/usr/bin/env python3
import json
import os
import shutil
import stat
import subprocess
from pathlib import Path

DB = "phpipam"
STATE_PATH = Path("/root/phpipam-dns-e2e-transfer-state.json")
WEBROOT = Path("/var/www/html")
RUN_ROOT = Path("/run")
JOB_ID = "a4ee3e5987dd15495d7e2419e853d9c8"
COLLECTOR_ID = "f57057d6677e1ca7a032aab8b68796ac"
CONNECTION_ID = "33b6fade9323f15aa7b1f56a8a865a9d"


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


def main() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=root_required")
    if not STATE_PATH.exists() or STATE_PATH.is_symlink():
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=transfer_state_missing")

    st = STATE_PATH.lstat()
    if not stat.S_ISREG(st.st_mode) or stat.S_IMODE(st.st_mode) != 0o600 or st.st_uid != 0:
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=transfer_state_boundary_invalid")

    state = json.loads(STATE_PATH.read_text())
    if state.get("stage") != "armed" or state.get("job_id") != JOB_ID:
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=transfer_state_invalid")

    transfer_dir = Path(state["transfer_dir"])
    token_spool = Path(state["token_spool"])
    receipt_path = Path(state["receipt"])

    if transfer_dir.parent != WEBROOT or not transfer_dir.name.startswith("dns-e2e-run-"):
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=transfer_dir_outside_boundary")
    if token_spool.parent != RUN_ROOT or not token_spool.name.startswith("phpipam-dns-e2e-token-"):
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=token_spool_outside_boundary")

    job = one_row(f"""
SELECT id,collector_id,connection_id,job_type,state,attempt_count,
       IFNULL(lease_id,''),IFNULL(lease_expires_at,0),IFNULL(credential_delivered_at,0),
       IFNULL(result_code,''),IFNULL(result_payload,''),IFNULL(finished_at,0)
FROM custom_dns_control_jobs WHERE id='{JOB_ID}';
""")
    if job[:4] != [JOB_ID, COLLECTOR_ID, CONNECTION_ID, "test_connection"]:
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=job_identity_invalid")

    audit = one_row(f"""
SELECT COUNT(*),COALESCE(MIN(action),''),COALESCE(MAX(action),'')
FROM custom_dns_control_events
WHERE object_id='{JOB_ID}' AND action='credential_delivered';
""")
    audit_count = int(audit[0])

    cleanup_errors = []
    try:
        if token_spool.exists():
            if token_spool.is_symlink() or not stat.S_ISREG(token_spool.lstat().st_mode):
                cleanup_errors.append("token_spool_boundary")
            else:
                token_spool.unlink()
        if transfer_dir.exists():
            if transfer_dir.is_symlink() or not stat.S_ISDIR(transfer_dir.lstat().st_mode):
                cleanup_errors.append("transfer_dir_boundary")
            else:
                shutil.rmtree(transfer_dir)
    except Exception as exc:
        cleanup_errors.append("cleanup:" + str(exc))

    if cleanup_errors:
        raise SystemExit("DNS8_E2E_ACCEPT_ERROR=" + ";".join(cleanup_errors))

    state["stage"] = "transfer_cleaned"
    state["final_job_state"] = job[4]
    state["final_attempt_count"] = int(job[5])
    state["final_credential_delivered_at"] = int(job[8])
    state["final_result_code"] = job[9]
    state["final_finished_at"] = int(job[11])
    state["credential_delivery_audit_count"] = audit_count
    receipt_path.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
    os.chmod(receipt_path, 0o600)
    STATE_PATH.unlink()

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

    print("DNS8_E2E_TRANSFER_CLEANED=1")
    print("DNS8_E2E_JOB_STATE=" + job[4])
    print("DNS8_E2E_JOB_ATTEMPTS=" + job[5])
    print("DNS8_E2E_CREDENTIAL_DELIVERED=" + ("1" if int(job[8]) > 0 else "0"))
    print("DNS8_E2E_RESULT_CODE=" + (job[9] or "none"))
    print("DNS8_E2E_FINISHED=" + ("1" if int(job[11]) > 0 else "0"))
    print("DNS8_E2E_CREDENTIAL_AUDIT_COUNT=" + str(audit_count))
    print("DNS8_E2E_ACCEPT=" + ("1" if accepted else "0"))
    print("DNS8_E2E_TOKEN_PRINTED=0")


if __name__ == "__main__":
    main()
