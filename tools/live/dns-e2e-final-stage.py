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
STATE = Path("/root/phpipam-dns-e2e-final-stage.json")
BACKUP_ROOT = Path("/var/backups/phpipam-custom")
BASE_URI = "https://ipam.realistbank.ru/"

BOOTSTRAP = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsE2EPilotOnce.ps1"
WORKER = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1"
PREVIEW = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1"

WORKER_BLOB = "92d3a38c7e8415b44f92d35e6fa10e468ce77cbd"
PREVIEW_BLOB = "f96f1f378cc6dd8217399acfda4c98c79ab5c428"


def git_blob(data: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def require_regular(path: Path) -> bytes:
    st = path.lstat()
    if path.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("source_boundary_invalid:" + str(path))
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
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=root_required")
    if STATE.exists() or STATE.is_symlink():
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=state_exists")

    bootstrap = require_regular(BOOTSTRAP)
    worker = require_regular(WORKER)
    preview = require_regular(PREVIEW)

    if git_blob(worker) != WORKER_BLOB:
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=worker_blob_mismatch")
    if git_blob(preview) != PREVIEW_BLOB:
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=preview_blob_mismatch")

    nonce = os.urandom(16).hex()
    name = "dns-e2e-final-" + nonce
    directory = WEBROOT / name
    if directory.exists() or directory.is_symlink():
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=collision")

    stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
    receipt_dir = BACKUP_ROOT / ("dns-e2e-final-stage-" + stamp + "-" + nonce[:8])
    receipt_dir.mkdir(parents=True, mode=0o700, exist_ok=False)
    receipt = receipt_dir / "receipt.json"

    directory.mkdir(mode=0o755)
    published = {
        "Invoke-PhpIpamDnsE2EPilotOnce.ps1": bootstrap,
        "Invoke-PhpIpamDnsWorkerOnce.ps1": worker,
        "Invoke-PhpIpamDnsPreview.ps1": preview,
    }

    try:
        files = {}
        for filename, data in published.items():
            path = directory / filename
            path.write_bytes(data)
            os.chmod(path, 0o644)
            os.chown(path, 0, 0)

            url = BASE_URI + name + "/" + filename
            code, body, err = local_readback(url)
            if code != 200:
                raise RuntimeError("local_http_invalid:" + filename + ":" + str(code) + ":" + err)
            if body != data:
                raise RuntimeError("local_readback_mismatch:" + filename)

            files[filename] = {
                "sha256": sha256(data),
                "bytes": len(data),
                "http": code,
                "exact": True,
            }

        base = BASE_URI + name + "/"
        state = {
            "stage": "transport_preflight_ready",
            "created_at": int(time.time()),
            "directory": str(directory),
            "base_uri": base,
            "receipt": str(receipt),
            "files": files,
            "worker_blob": WORKER_BLOB,
            "preview_blob": PREVIEW_BLOB,
            "token_present": False,
            "job_mutation": False,
        }
        receipt.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        os.chmod(receipt, 0o600)
        STATE.write_text(json.dumps(state, sort_keys=True, indent=2) + "\n")
        os.chmod(STATE, 0o600)
        os.chown(STATE, 0, 0)

        windows = (
            "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;"
            "$wc=New-Object Net.WebClient;"
            "$wc.Proxy=[Net.GlobalProxySelection]::GetEmptyWebProxy();"
            "$bb=$wc.DownloadData('" + base + "Invoke-PhpIpamDnsE2EPilotOnce.ps1');"
            "$s=[Text.Encoding]::UTF8.GetString($bb);"
            "& ([ScriptBlock]::Create($s)) "
            "-TransferBaseUri '" + base + "' "
            "-PhpIpamBaseUri '" + BASE_URI + "' "
            "-TransportPreflightOnly"
        )

        print("DNS8_FINAL_STAGE_PUBLISH_OK=1")
        print("DNS8_FINAL_STAGE_BASE_URI=" + base)
        print("DNS8_FINAL_STAGE_RECEIPT=" + str(receipt))
        for filename in published:
            item = files[filename]
            print(
                "DNS8_FINAL_STAGE_LOCAL "
                + filename
                + " HTTP="
                + str(item["http"])
                + " BYTES="
                + str(item["bytes"])
                + " EXACT=1"
            )
        print("DNS8_FINAL_STAGE_WINDOWS_PREFLIGHT_COMMAND=" + windows)
        print("DNS8_FINAL_STAGE_TOKEN_PRESENT=0")
        print("DNS8_FINAL_STAGE_JOB_MUTATION=0")
    except Exception:
        if directory.exists():
            shutil.rmtree(directory)
        if STATE.exists():
            STATE.unlink()
        raise


def verify() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=root_required")
    if not STATE.exists() or STATE.is_symlink():
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=state_missing")
    st = STATE.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or stat.S_IMODE(st.st_mode) != 0o600:
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=state_boundary_invalid")

    state = json.loads(STATE.read_text())
    if state.get("stage") != "transport_preflight_ready":
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=unexpected_stage")

    directory = Path(state["directory"])
    if directory.parent != WEBROOT or not directory.name.startswith("dns-e2e-final-"):
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=directory_boundary_invalid")

    expected = {
        "Invoke-PhpIpamDnsE2EPilotOnce.ps1": require_regular(BOOTSTRAP),
        "Invoke-PhpIpamDnsWorkerOnce.ps1": require_regular(WORKER),
        "Invoke-PhpIpamDnsPreview.ps1": require_regular(PREVIEW),
    }

    for filename, data in expected.items():
        path = directory / filename
        current = require_regular(path)
        if current != data:
            raise SystemExit("DNS8_FINAL_STAGE_ERROR=file_drift:" + filename)

        url = state["base_uri"] + filename
        code, body, err = local_readback(url)
        if code != 200 or body != data:
            raise SystemExit("DNS8_FINAL_STAGE_ERROR=https_drift:" + filename + ":" + str(code) + ":" + err)

    print("DNS8_FINAL_STAGE_VERIFY_OK=1")
    print("DNS8_FINAL_STAGE_BASE_URI=" + state["base_uri"])
    print("DNS8_FINAL_STAGE_TOKEN_PRESENT=0")
    print("DNS8_FINAL_STAGE_JOB_MUTATION=0")
    print("DNS8_FINAL_STAGE_READY_FOR_ARM=1")


def cleanup() -> None:
    if os.geteuid() != 0:
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=root_required")
    if not STATE.exists() or STATE.is_symlink():
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=state_missing")

    state = json.loads(STATE.read_text())
    directory = Path(state["directory"])
    if directory.parent != WEBROOT or not directory.name.startswith("dns-e2e-final-"):
        raise SystemExit("DNS8_FINAL_STAGE_ERROR=directory_boundary_invalid")

    if directory.exists():
        if directory.is_symlink() or not stat.S_ISDIR(directory.lstat().st_mode):
            raise SystemExit("DNS8_FINAL_STAGE_ERROR=directory_type_invalid")
        shutil.rmtree(directory)
    STATE.unlink()

    print("DNS8_FINAL_STAGE_CLEANUP_OK=1")
    print("DNS8_FINAL_STAGE_TOKEN_PRESENT=0")
    print("DNS8_FINAL_STAGE_JOB_MUTATION=0")


def main() -> None:
    ap = argparse.ArgumentParser()
    mode = ap.add_mutually_exclusive_group(required=True)
    mode.add_argument("--publish", action="store_true")
    mode.add_argument("--verify", action="store_true")
    mode.add_argument("--cleanup", action="store_true")
    args = ap.parse_args()

    if args.publish:
        publish()
    elif args.verify:
        verify()
    else:
        cleanup()


if __name__ == "__main__":
    main()
