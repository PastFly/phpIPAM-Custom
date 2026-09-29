#!/usr/bin/env python3
import atexit
import base64
import hashlib
import json
import os
import re
import sys
import tempfile
from pathlib import Path

OPENSSL_LEGACY_CONFIG="""openssl_conf = openssl_init

[openssl_init]
providers = provider_sect

[provider_sect]
default = default_sect
legacy = legacy_sect

[default_sect]
activate = 1

[legacy_sect]
activate = 1
"""

def _cleanup_legacy_config()->None:
    path=os.environ.get("DNS9_OPENSSL_LEGACY_CONFIG")
    if not path:
        return
    try:
        st=os.lstat(path)
        if os.path.isfile(path) and not os.path.islink(path) and st.st_uid==os.geteuid():
            os.unlink(path)
    except FileNotFoundError:
        pass
    except OSError:
        pass

if os.environ.get("DNS9_OPENSSL_LEGACY_CONFIG"):
    atexit.register(_cleanup_legacy_config)

def _md4_ready()->bool:
    try:
        hashlib.new("md4",b"dns9")
        return True
    except (ValueError,TypeError):
        return False

def ensure_ntlm_md4_runtime()->str:
    if _md4_ready():
        return "legacy_process" if os.environ.get("DNS9_OPENSSL_LEGACY_REEXEC")=="1" else "native"
    if os.environ.get("DNS9_OPENSSL_LEGACY_REEXEC")=="1":
        raise RuntimeError("ntlm_md4_unavailable_after_legacy_provider")
    if os.geteuid()!=0:
        raise RuntimeError("ntlm_md4_legacy_provider_requires_root")
    fd,path=tempfile.mkstemp(
        prefix="phpipam-dns9-openssl-",
        suffix=".cnf",
        dir="/run",
        text=True
    )
    try:
        os.fchmod(fd,0o600)
        os.write(fd,OPENSSL_LEGACY_CONFIG.encode("ascii"))
    finally:
        os.close(fd)
    env=dict(os.environ)
    env["OPENSSL_CONF"]=path
    env["DNS9_OPENSSL_LEGACY_CONFIG"]=path
    env["DNS9_OPENSSL_LEGACY_REEXEC"]="1"
    os.execve(sys.executable,[sys.executable,*sys.argv],env)
    raise RuntimeError("ntlm_md4_reexec_failed")

def sha256(data: bytes)->str:
    return hashlib.sha256(data).hexdigest()

def regular(path: Path)->bytes:
    st=path.lstat()
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("file_boundary_invalid:"+str(path))
    return path.read_bytes()

def ps_quote(value: str)->str:
    return "'" + value.replace("'","''") + "'"

def load_protocol():
    try:
        from winrm.protocol import Protocol
    except Exception as exc:
        raise RuntimeError("pywinrm_unavailable") from exc
    return Protocol

def protocol(server: str, username: str, password: str):
    ensure_ntlm_md4_runtime()
    if not re.fullmatch(r"(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?",server):
        raise RuntimeError("server_invalid")
    if not username or len(username)>256 or any(x in username for x in "\r\n\0"):
        raise RuntimeError("username_invalid")
    Protocol=load_protocol()
    for key in ("NO_PROXY","no_proxy"):
        current=os.environ.get(key,"")
        values=[x.strip() for x in current.split(",") if x.strip()]
        if server not in values:
            values.append(server)
        os.environ[key]=",".join(values)
    return Protocol(
        endpoint=f"http://{server}:5985/wsman",
        transport="ntlm",
        username=username,
        password=password,
        message_encryption="always",
        operation_timeout_sec=20,
        read_timeout_sec=30,
    )

def _decode(data: bytes)->str:
    return data.decode("utf-8","replace").replace("\x00","")

def run(protocol_obj, executable: str, arguments=(), env_vars: dict[str,str]|None=None):
    shell_id=None
    command_id=None
    try:
        shell_id=protocol_obj.open_shell(codepage=65001,env_vars=env_vars)
        command_id=protocol_obj.run_command(
            shell_id,executable,arguments,
            console_mode_stdin=True,skip_cmd_shell=True
        )
        out,err,rc=protocol_obj.get_command_output(shell_id,command_id)
        return int(rc),_decode(out),_decode(err)
    finally:
        if shell_id is not None and command_id is not None:
            try: protocol_obj.cleanup_command(shell_id,command_id)
            except Exception: pass
        if shell_id is not None:
            try: protocol_obj.close_shell(shell_id)
            except Exception: pass

def run_ps(protocol_obj, script: str, env_vars: dict[str,str]|None=None):
    encoded=base64.b64encode(script.encode("utf-16le")).decode("ascii")
    return run(protocol_obj,"powershell.exe",[
        "-NoProfile","-NonInteractive","-ExecutionPolicy","Bypass",
        "-EncodedCommand",encoded
    ],env_vars=env_vars)

def stage_bytes(protocol_obj, remote_path: str, data: bytes)->str:
    b64=base64.b64encode(data).decode("ascii")
    expected=sha256(data)
    script=f"""
$ErrorActionPreference='Stop'
$path={ps_quote(remote_path)}
$parent=Split-Path -Parent $path
if(-not (Test-Path -LiteralPath $parent -PathType Container)){{
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}}
[IO.File]::WriteAllBytes($path,[Convert]::FromBase64String({ps_quote(b64)}))
$actual=(Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
if($actual -cne {ps_quote(expected)}){{throw 'dns9_stage_hash_mismatch'}}
Write-Output ('DNS9_STAGE_SHA256='+$actual)
"""
    rc,out,err=run_ps(protocol_obj,script)
    if rc!=0 or ("DNS9_STAGE_SHA256="+expected) not in out:
        raise RuntimeError("remote_stage_failed:"+remote_path+":"+err[:512])
    return expected

def remove_tree(protocol_obj, remote_path: str)->None:
    script=f"""
$ErrorActionPreference='Stop'
$path={ps_quote(remote_path)}
if(Test-Path -LiteralPath $path){{
    Remove-Item -LiteralPath $path -Recurse -Force -ErrorAction Stop
}}
Write-Output 'DNS9_REMOTE_STAGE_CLEAN=1'
"""
    rc,out,err=run_ps(protocol_obj,script)
    if rc!=0 or "DNS9_REMOTE_STAGE_CLEAN=1" not in out:
        raise RuntimeError("remote_stage_cleanup_failed:"+err[:512])

def json_line(output: str, prefix: str):
    for line in output.splitlines():
        line=line.strip()
        if line.startswith(prefix):
            return json.loads(line[len(prefix):])
    raise RuntimeError("remote_json_marker_missing:"+prefix)
