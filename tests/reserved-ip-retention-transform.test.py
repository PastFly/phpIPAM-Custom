#!/usr/bin/env python3
import importlib.util
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
TOOL=ROOT/"tools/live/reserved-ip-retention-hotfix.py"
spec=importlib.util.spec_from_file_location("retention",TOOL)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

guard=m.PING_GUARD
ping="""<?php
// loop
foreach ($address_change as $k => $change) {
if ("""+guard+""") {}
if ("""+guard+""") {}
if ("""+guard+""") {}
if ("""+guard+""") {}
if ("""+guard+""") {}
"""
cleanup="""<?php
and (`ip`.`excludePing` IS NULL or `ip`.`excludePing`!=1 )
			and
			(`ip`.`lastSeen` < '$beforetime'
"""

with tempfile.TemporaryDirectory() as td:
    root=Path(td)
    p=root/"pingCheck.php"
    c=root/"remove_offline_addresses.php"
    p.write_text(ping)
    c.write_text(cleanup)
    m.PING=p
    m.CLEANUP=c
    changes=m.patch_sources()
    ping_new=changes[p][1].decode()
    cleanup_new=changes[c][1].decode()

    ok(ping_new.count("reserved_address_states")>=6,"semantic reserved state map and five guards")
    ok(ping_new.count(m.PING_GUARD_PATCHED)==5,"all five ping tag mutations guarded")
    ok("strcasecmp((string) $tag['type'], 'Reserved') === 0" in ping_new,"reserved tag found by type")
    ok("LOWER(`tag`.`type`) = 'reserved'" in cleanup_new,"cleanup excludes reserved by type")
    ok("state != 3" not in ping_new+cleanup_new,"no reserved id hardcode")
    ok(p.read_text()==ping and c.read_text()==cleanup,"transform is read-only before apply")

    p.write_text(ping_new)
    c.write_text(cleanup_new)
    try:
        m.patch_sources()
        raise AssertionError("already patched accepted")
    except RuntimeError as e:
        ok(str(e)=="already_patched","repeat application fails closed")

print(f"RESERVED_IP_RETENTION_TRANSFORM_ASSERTIONS={n}")
print("RESERVED_IP_RETENTION_TRANSFORM_TESTS_OK=1")
