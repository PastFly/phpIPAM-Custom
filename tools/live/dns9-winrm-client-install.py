#!/usr/bin/env python3
import argparse
import json
import os
import shutil
import subprocess
import time
from pathlib import Path

BACKUP_ROOT=Path("/var/backups/phpipam-custom")
PACKAGES=["python3-winrm","python3-requests-ntlm","python3-xmltodict"]

def run(cmd,check=False,env=None):
    return subprocess.run(
        cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,
        check=check,env=env
    )

def installed_version(package: str):
    p=run(["dpkg-query","-W","-f=${Status}\t${Version}",package])
    if p.returncode!=0:
        return None
    parts=p.stdout.strip().split("\t")
    if len(parts)!=2 or parts[0]!="install ok installed":
        return None
    return parts[1]

def candidate(package: str):
    p=run(["apt-cache","policy",package])
    if p.returncode!=0:
        return None
    for line in p.stdout.splitlines():
        line=line.strip()
        if line.startswith("Candidate:"):
            value=line.split(":",1)[1].strip()
            return None if value=="(none)" else value
    return None

def imports_ok()->bool:
    code=(
        "import inspect,requests_ntlm,xmltodict;"
        "from winrm.protocol import Protocol;"
        "s=inspect.signature(Protocol);"
        "o=inspect.signature(Protocol.open_shell);"
        "assert 'message_encryption' in s.parameters;"
        "assert 'env_vars' in o.parameters"
    )
    return run(["python3","-c",code]).returncode==0

def main()->None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--apply",action="store_true")
    args=ap.parse_args()

    if os.geteuid()!=0:
        raise SystemExit("DNS9_WINRM_CLIENT_ERROR=root_required")
    for command in ("apt-get","apt-cache","dpkg-query","python3"):
        if not shutil.which(command):
            raise SystemExit("DNS9_WINRM_CLIENT_ERROR=command_missing:"+command)

    before={p:installed_version(p) for p in PACKAGES}
    candidates={p:candidate(p) for p in PACKAGES}
    if any(v is None for v in candidates.values()):
        raise SystemExit("DNS9_WINRM_CLIENT_ERROR=apt_candidate_missing")

    simulation=run(["apt-get","-s","install","--no-install-recommends",*PACKAGES])
    if simulation.returncode!=0:
        raise SystemExit("DNS9_WINRM_CLIENT_ERROR=apt_simulation_failed")

    print("DNS9_WINRM_CLIENT_PACKAGES="+",".join(PACKAGES))
    print("DNS9_WINRM_CLIENT_BEFORE="+json.dumps(before,sort_keys=True,separators=(',',':')))
    print("DNS9_WINRM_CLIENT_CANDIDATES="+json.dumps(candidates,sort_keys=True,separators=(',',':')))
    print("DNS9_WINRM_CLIENT_APT_SIMULATION_OK=1")

    if not args.apply:
        print("DNS9_WINRM_CLIENT_DRY_RUN=1")
        print("DNS9_WINRM_CLIENT_PACKAGE_MUTATION=0")
        print("DNS9_WINRM_CLIENT_DB_MUTATION=0")
        print("DNS9_WINRM_CLIENT_SYSTEMD_MUTATION=0")
        print("DNS9_WINRM_CLIENT_WINDOWS_MUTATION=0")
        print("DNS9_WINRM_CLIENT_PREFLIGHT_OK=1")
        return

    stamp=time.strftime("%Y%m%d-%H%M%S",time.gmtime())
    receipt_dir=BACKUP_ROOT/("dns9-winrm-client-install-"+stamp)
    receipt_dir.mkdir(parents=True,mode=0o700,exist_ok=False)
    receipt_path=receipt_dir/"receipt.json"
    receipt={
        "stage":"starting",
        "created_at":int(time.time()),
        "packages":PACKAGES,
        "before":before,
        "candidates":candidates,
    }
    receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
    os.chmod(receipt_path,0o600)

    env=dict(os.environ)
    env["DEBIAN_FRONTEND"]="noninteractive"
    newly_requested=[p for p,v in before.items() if v is None]
    try:
        install=run(
            ["apt-get","install","-y","--no-install-recommends",*PACKAGES],
            env=env
        )
        if install.returncode!=0:
            raise RuntimeError("apt_install_failed")
        after={p:installed_version(p) for p in PACKAGES}
        if any(v is None for v in after.values()) or not imports_ok():
            raise RuntimeError("client_postcheck_failed")

        receipt.update({"stage":"installed","after":after,"newly_requested":newly_requested})
        receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
        os.chmod(receipt_path,0o600)

        print("DNS9_WINRM_CLIENT_AFTER="+json.dumps(after,sort_keys=True,separators=(',',':')))
        print("DNS9_WINRM_CLIENT_IMPORTS_OK=1")
        print("DNS9_WINRM_CLIENT_RECEIPT="+str(receipt_path))
        print("DNS9_WINRM_CLIENT_OK=1")
    except Exception:
        errors=[]
        removable=[p for p in newly_requested if installed_version(p) is not None]
        if removable:
            rb=run(["apt-get","remove","-y",*removable],env=env)
            if rb.returncode!=0:
                errors.append("apt_remove_failed")
        receipt.update({"stage":"rollback_after_failure","rollback_errors":errors})
        receipt_path.write_text(json.dumps(receipt,sort_keys=True,indent=2)+"\n")
        os.chmod(receipt_path,0o600)
        if errors:
            raise RuntimeError("client_rollback_incomplete:"+",".join(errors))
        print("DNS9_WINRM_CLIENT_INTERNAL_ROLLBACK_OK=1")
        raise

if __name__=="__main__":
    main()
