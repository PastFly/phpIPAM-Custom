#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import shutil
import stat
import subprocess
import tempfile
import time
from pathlib import Path

PING = Path("/var/www/html/functions/scripts/pingCheck.php")
CLEANUP = Path("/var/www/html/functions/scripts/remove_offline_addresses.php")
BACKUP_ROOT = Path("/var/backups/phpipam-custom")
DB = "phpipam"

expected_ping_replacements = 5
expected_cleanup_replacements = 1

PING_ANCHOR = """// loop
foreach ($address_change as $k => $change) {"""
PING_INSERT = """// Reserved is an allocation intent, not a liveness state. Keep its tag stable
// while still allowing lastSeen and hostname refreshes above.
$reserved_address_states = array();
foreach ($Subnets->address_types as $tag_id => $tag) {
    if (isset($tag['type']) && strcasecmp((string) $tag['type'], 'Reserved') === 0) {
        $reserved_address_states[(string) $tag_id] = true;
    }
}

// loop
foreach ($address_change as $k => $change) {"""
PING_GUARD = "$Subnets->address_types[$address_change[$k]['state']]['updateTag'] == 1"
PING_GUARD_PATCHED = PING_GUARD + " && !isset($reserved_address_states[(string) $address_change[$k]['state']])"

CLEANUP_ANCHOR = """and (`ip`.`excludePing` IS NULL or `ip`.`excludePing`!=1 )
			and
			(`ip`.`lastSeen` < '$beforetime'"""
CLEANUP_PATCHED = """and (`ip`.`excludePing` IS NULL or `ip`.`excludePing`!=1 )
			and NOT EXISTS (
				select 1 from `ipTags` as `tag`
				where `tag`.`id` = `ip`.`state` and LOWER(`tag`.`type`) = 'reserved'
			)
			and
			(`ip`.`lastSeen` < '$beforetime'"""

DB_INSPECT_SQL = """
SELECT id,type,updateTag FROM ipTags WHERE LOWER(type)='reserved';
SELECT COUNT(*) AS reserved_count
FROM ipaddresses ip JOIN ipTags tag ON tag.id=ip.state
WHERE LOWER(tag.type)='reserved';
SELECT COUNT(*) AS ping_exposed_count
FROM ipaddresses ip
JOIN ipTags tag ON tag.id=ip.state
JOIN subnets s ON s.id=ip.subnetId
WHERE LOWER(tag.type)='reserved'
  AND s.pingSubnet=1
  AND COALESCE(ip.excludePing,0)<>1;
"""

DB_APPLY_SQL = """
BEGIN;
SELECT id,type,updateTag FROM ipTags WHERE LOWER(type)='reserved' FOR UPDATE;
UPDATE ipTags SET updateTag=0 WHERE LOWER(type)='reserved';
SELECT ROW_COUNT() AS reserved_tag_rows_changed;
SELECT id,type,updateTag FROM ipTags WHERE LOWER(type)='reserved';
COMMIT;
"""

DB_ROLLBACK_MARKER = "ROLLBACK"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def run_db(sql: str) -> str:
    p = subprocess.run(
        ["mariadb", "--batch", "--skip-column-names", DB],
        input=sql,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if p.returncode != 0:
        raise RuntimeError("database_command_failed:" + p.stderr.strip())
    return p.stdout


def read_regular(path: Path):
    st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1:
        raise RuntimeError("file_boundary:" + str(path))
    return path.read_bytes(), st


def patch_sources():
    ping_bytes, ping_st = read_regular(PING)
    cleanup_bytes, cleanup_st = read_regular(CLEANUP)
    ping = ping_bytes.decode("utf-8")
    cleanup = cleanup_bytes.decode("utf-8")

    if "reserved_address_states" in ping or "LOWER(`tag`.`type`) = 'reserved'" in cleanup:
        raise RuntimeError("already_patched")

    if ping.count(PING_ANCHOR) != 1:
        raise RuntimeError("ping_anchor_mismatch")
    if ping.count(PING_GUARD) != expected_ping_replacements:
        raise RuntimeError("ping_guard_count_mismatch")
    if cleanup.count(CLEANUP_ANCHOR) != expected_cleanup_replacements:
        raise RuntimeError("cleanup_anchor_mismatch")

    ping_new = ping.replace(PING_ANCHOR, PING_INSERT, 1)
    ping_new = ping_new.replace(PING_GUARD, PING_GUARD_PATCHED)
    cleanup_new = cleanup.replace(CLEANUP_ANCHOR, CLEANUP_PATCHED, 1)

    if ping_new.count(PING_GUARD_PATCHED) != expected_ping_replacements:
        raise RuntimeError("ping_patch_count_mismatch")
    if cleanup_new.count("LOWER(`tag`.`type`) = 'reserved'") != expected_cleanup_replacements:
        raise RuntimeError("cleanup_patch_count_mismatch")

    return {
        PING: (ping_bytes, ping_new.encode("utf-8"), ping_st),
        CLEANUP: (cleanup_bytes, cleanup_new.encode("utf-8"), cleanup_st),
    }


def atomic_write(path: Path, data: bytes, original_stat):
    fd, tmp_name = tempfile.mkstemp(prefix=path.name + ".", dir=str(path.parent))
    tmp = Path(tmp_name)
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, stat.S_IMODE(original_stat.st_mode))
        os.chown(tmp, original_stat.st_uid, original_stat.st_gid)
        os.replace(tmp, path)
    finally:
        if tmp.exists():
            tmp.unlink()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    dry_run = not args.apply

    if os.geteuid() != 0:
        raise SystemExit("RESERVED_RETENTION_ERROR=root_required")

    changes = patch_sources()
    inspect = run_db(DB_INSPECT_SQL)
    print("RESERVED_RETENTION_DB_INSPECT_BEGIN")
    print(inspect.rstrip())
    print("RESERVED_RETENTION_DB_INSPECT_END")

    reserved_rows = run_db("SELECT id,type,updateTag FROM ipTags WHERE LOWER(type)='reserved';")
    reserved = [line.split("\t") for line in reserved_rows.splitlines() if line.strip()]
    if len(reserved) != 1 or len(reserved[0]) != 3 or reserved[0][1].lower() != "reserved":
        raise RuntimeError("reserved_tag_precondition_failed")
    original_reserved_update_tag = reserved[0][2]
    if original_reserved_update_tag not in ("0", "1"):
        raise RuntimeError("reserved_tag_updateTag_invalid")

    for path, (old, new, _st) in changes.items():
        print(f"RESERVED_RETENTION_FILE={path}")
        print(f"RESERVED_RETENTION_BEFORE_SHA256={sha256(old)}")
        print(f"RESERVED_RETENTION_AFTER_SHA256={sha256(new)}")

    if dry_run:
        print("RESERVED_RETENTION_DRY_RUN=1")
        print("RESERVED_RETENTION_MUTATION=0")
        print("RESERVED_RETENTION_PREFLIGHT_OK=1")
        return

    stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    backup = BACKUP_ROOT / ("reserved-ip-retention-" + stamp)
    backup.mkdir(parents=True, mode=0o700, exist_ok=False)

    receipt = {
        "stage": "backup_created",
        "backup": str(backup),
        "files": {},
        "db_update": "UPDATE ipTags SET updateTag=0 WHERE LOWER(type)='reserved'",
    }

    for path, (old, new, st) in changes.items():
        dst = backup / path.name
        dst.write_bytes(old)
        os.chmod(dst, 0o600)
        receipt["files"][str(path)] = {
            "before_sha256": sha256(old),
            "after_sha256": sha256(new),
            "uid": st.st_uid,
            "gid": st.st_gid,
            "mode": stat.S_IMODE(st.st_mode),
        }

    receipt_path = backup / "receipt.json"
    receipt_path.write_text(json.dumps(receipt, sort_keys=True, indent=2) + "\n")
    os.chmod(receipt_path, 0o600)

    replaced = []
    db_changed = False
    try:
        for path, (_old, new, st) in changes.items():
            atomic_write(path, new, st)
            replaced.append(path)

        # Verify file postconditions before committing the database defense-in-depth change.
        for path, (_old, new, _st) in changes.items():
            current, _ = read_regular(path)
            if sha256(current) != sha256(new):
                raise RuntimeError("file_postcondition_failed:" + str(path))

        db_out = run_db(DB_APPLY_SQL)
        print("RESERVED_RETENTION_DB_APPLY_BEGIN")
        print(db_out.rstrip())
        print("RESERVED_RETENTION_DB_APPLY_END")
        db_changed = True

        verify = run_db("SELECT id,type,updateTag FROM ipTags WHERE LOWER(type)='reserved';")
        rows = [line.split("\t") for line in verify.splitlines() if line.strip()]
        if len(rows) != 1 or rows[0][1].lower() != "reserved" or rows[0][2] != "0":
            raise RuntimeError("reserved_tag_postcondition_failed")

        receipt["stage"] = "applied"
        receipt["db_reserved_updateTag_before"] = int(original_reserved_update_tag)
        receipt["db_reserved_updateTag_after"] = 0
        receipt_path.write_text(json.dumps(receipt, sort_keys=True, indent=2) + "\n")
        os.chmod(receipt_path, 0o600)
        print("RESERVED_RETENTION_BACKUP=" + str(backup))
        print("RESERVED_RETENTION_APPLY_OK=1")
    except Exception:
        db_restore_error = None
        if db_changed:
            try:
                run_db(
                    "UPDATE ipTags SET updateTag=" + original_reserved_update_tag +
                    " WHERE LOWER(type)='reserved';"
                )
            except Exception as restore_error:
                db_restore_error = str(restore_error)

        for path in reversed(replaced):
            old, _new, st = changes[path]
            atomic_write(path, old, st)

        receipt["stage"] = "rollback_after_failure"
        receipt["db_restore_attempted"] = db_changed
        receipt["db_restore_error"] = db_restore_error
        receipt_path.write_text(json.dumps(receipt, sort_keys=True, indent=2) + "\n")
        os.chmod(receipt_path, 0o600)

        if db_restore_error is not None:
            raise RuntimeError("rollback_incomplete:" + db_restore_error)
        raise


if __name__ == "__main__":
    main()
