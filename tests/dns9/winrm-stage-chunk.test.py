#!/usr/bin/env python3
import base64
import hashlib
import importlib.util
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
COMMON=ROOT/"tools/live/dns9_winrm_common.py"

spec=importlib.util.spec_from_file_location("dns9_winrm_common",COMMON)
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

staged=bytearray()
encoded_command_lengths=[]
chunk_env_lengths=[]
chunk_count=0

def fake_run_ps(_protocol,script,env_vars=None):
    global chunk_count
    encoded_command_lengths.append(len(base64.b64encode(script.encode("utf-16le")).decode("ascii")))

    if "DNS9_STAGE_INIT_OK=1" in script:
        ok(env_vars is None,"init has no payload environment")
        staged.clear()
        return 0,"DNS9_STAGE_INIT_OK=1",""

    if "DNS9_STAGE_CHUNK_OK=1" in script:
        ok(isinstance(env_vars,dict),"chunk uses environment")
        ok(set(env_vars)=={"PHPIPAM_DNS9_STAGE_CHUNK"},"chunk environment exact")
        encoded=env_vars["PHPIPAM_DNS9_STAGE_CHUNK"]
        chunk_env_lengths.append(len(encoded))
        ok(len(encoded)<=4096,"chunk environment bounded")
        staged.extend(base64.b64decode(encoded,validate=True))
        chunk_count+=1
        return 0,"DNS9_STAGE_CHUNK_OK=1",""

    if "DNS9_STAGE_SHA256=" in script:
        ok(env_vars is None,"verify has no payload environment")
        digest=hashlib.sha256(bytes(staged)).hexdigest()
        return 0,"DNS9_STAGE_SHA256="+digest,""

    raise AssertionError("unexpected stage PowerShell")

mod.run_ps=fake_run_ps

payload=(bytes(range(256))*256)+b"dns9-stage-tail"
expected=hashlib.sha256(payload).hexdigest()
actual=mod.stage_bytes(object(),r"C:\Windows\Temp\dns9-stage-test\fixture.ps1",payload)

ok(actual==expected,"stage_bytes returns source hash")
ok(bytes(staged)==payload,"chunked staging reconstructs exact bytes")
ok(chunk_count>1,"large payload requires multiple chunks")
ok(max(chunk_env_lengths)<=4096,"all chunk environments bounded")
ok(max(encoded_command_lengths)<8192,"all encoded PowerShell command arguments remain bounded")
ok(len(payload)>32767,"fixture exceeds traditional command-line risk boundary")

print(f"DNS9_WINRM_STAGE_CHUNK_ASSERTIONS={n}")
print("DNS9_WINRM_STAGE_CHUNK_MAX_ENV="+str(max(chunk_env_lengths)))
print("DNS9_WINRM_STAGE_CHUNK_MAX_ENCODED_COMMAND="+str(max(encoded_command_lengths)))
print("DNS9_WINRM_STAGE_CHUNK_COUNT="+str(chunk_count))
print("DNS9_WINRM_STAGE_CHUNK_TESTS_OK=1")
