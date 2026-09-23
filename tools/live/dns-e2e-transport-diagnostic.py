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

ROOT = Path(__file__).resolve().parents[2]
WEBROOT = Path("/var/www/html")
STATE = Path("/root/phpipam-dns-e2e-transport-diagnostic.json")
SOURCE = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsE2EPilotOnce.ps1"
BASE_URI = "https://ipam.realistbank.ru/"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def require_regular(path: Path) -> bytes:
    st = path.lstat()
    if path.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("source_boundary_invalid")
    return path.read_bytes()


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
        body = tmp.read()
        return code, body, p.stderr.decode("utf-8", "replace").strip()


def publish() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_TRANSPORT_DIAG_ERROR=root_required")
    if STATE.exists() or STATE.is_symlink():
        raise SystemExit("DNS8_TRANSPORT_DIAG_ERROR=state_exists")

    source = require_regular(SOURCE)
    nonce = os.urandom(16).hex()
    name = "dns-e2e-transport-diag-" + nonce
    directory = WEBROOT / name
    directory.mkdir(mode=0o755)

    files = {
        "bootstrap.ps1": source,
        "bootstrap.txt": source,
        "tiny.ps1": b"Write-Output 'dns8-transport-ok'\n",
        "tiny.txt": b"dns8-transport-ok\n",
    }

    try:
        local = {}
        for filename, data in files.items():
            path = directory / filename
            path.write_bytes(data)
            os.chmod(path, 0o644)
            os.chown(path, 0, 0)

            url = BASE_URI + name + "/" + filename
            code, body, err = local_readback(url)
            local[filename] = {
                "http": code,
                "bytes": len(body),
                "exact": body == data,
                "stderr": err,
            }

        state = {
            "stage": "published",
            "created_at": int(time.time()),
            "directory": str(directory),
            "base_uri": BASE_URI + name + "/",
            "source_sha256": sha256(source),
            "local": local,
        }
        STATE.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        os.chmod(STATE, 0o600)
        os.chown(STATE, 0, 0)

        base = state["base_uri"]
        windows = (
            "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;"
            "$wc=New-Object Net.WebClient;"
            "$wc.Proxy=[Net.GlobalProxySelection]::GetEmptyWebProxy();"
            "$names=@('bootstrap.ps1','bootstrap.txt','tiny.ps1','tiny.txt');"
            "foreach($n in $names){try{$b=$wc.DownloadData('" + base + "'+$n);"
            "Write-Output ($n+' OK bytes='+$b.Length)}catch{"
            "Write-Output ($n+' ERROR '+$_.Exception.Message)}}"
        )

        print("DNS8_TRANSPORT_DIAG_PUBLISH_OK=1")
        for filename in files:
            item = local[filename]
            print(
                "DNS8_TRANSPORT_LOCAL "
                + filename
                + " HTTP="
                + str(item["http"])
                + " BYTES="
                + str(item["bytes"])
                + " EXACT="
                + ("1" if item["exact"] else "0")
            )
        print("DNS8_TRANSPORT_WINDOWS_COMMAND=" + windows)
        print("DNS8_TRANSPORT_TOKEN_USED=0")
        print("DNS8_TRANSPORT_JOB_MUTATION=0")
    except Exception:
        if directory.exists():
            shutil.rmtree(directory)
        if STATE.exists():
            STATE.unlink()
        raise


def cleanup() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_TRANSPORT_DIAG_ERROR=root_required")
    if not STATE.exists() or STATE.is_symlink():
        raise SystemExit("DNS8_TRANSPORT_DIAG_ERROR=state_missing")
    st = STATE.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid != 0:
        raise SystemExit("DNS8_TRANSPORT_DIAG_ERROR=state_boundary_invalid")

    state = json.loads(STATE.read_text())
    directory = Path(state["directory"])
    if directory.parent != WEBROOT or not directory.name.startswith("dns-e2e-transport-diag-"):
        raise SystemExit("DNS8_TRANSPORT_DIAG_ERROR=directory_boundary_invalid")

    if directory.exists():
        if directory.is_symlink() or not stat.S_ISDIR(directory.lstat().st_mode):
            raise SystemExit("DNS8_TRANSPORT_DIAG_ERROR=directory_type_invalid")
        shutil.rmtree(directory)
    STATE.unlink()
    print("DNS8_TRANSPORT_DIAG_CLEANUP_OK=1")
    print("DNS8_TRANSPORT_TOKEN_USED=0")
    print("DNS8_TRANSPORT_JOB_MUTATION=0")


def main() -> None:
    ap = argparse.ArgumentParser()
    mode = ap.add_mutually_exclusive_group(required=True)
    mode.add_argument("--publish", action="store_true")
    mode.add_argument("--cleanup", action="store_true")
    args = ap.parse_args()
    if args.publish:
        publish()
    else:
        cleanup()


if __name__ == "__main__":
    main()
