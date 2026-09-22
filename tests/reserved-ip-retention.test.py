#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/reserved-ip-retention-hotfix.py"
n=0

def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

ok(TOOL.is_file(),"hotfix tool exists")
src=TOOL.read_text()

for required in [
    "remove_offline_addresses.php",
    "pingCheck.php",
    "reserved_address_states",
    "strcasecmp",
    "Reserved",
    "updateTag=0",
    "LOWER(type)",
    "reserved_count",
    "ping_exposed_count",
    "backup",
    "os.replace",
]:
    ok(required in src,"required: "+required)

for forbidden in [
    "state != 3",
    "state <> 3",
    "state=3",
    "state = 3",
    "WHERE id=3",
    "WHERE id = 3",
    "rm -f",
]:
    ok(forbidden not in src,"no hardcoded reserved id / unsafe delete: "+forbidden)

ok("expected_ping_replacements = 5" in src,"exact ping guard count")
ok("expected_cleanup_replacements = 1" in src,"exact cleanup guard count")
ok("dry_run" in src,"dry-run supported")
ok("BEGIN" in src and "ROLLBACK" in src and "COMMIT" in src,"DB mutation transactional")
ok("sha256" in src,"source hashes reported")
print(f"RESERVED_IP_RETENTION_ASSERTIONS={n}")
print("RESERVED_IP_RETENTION_TESTS_OK=1")
