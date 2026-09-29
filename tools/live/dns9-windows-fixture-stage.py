#!/usr/bin/env python3
import argparse
import base64
import json
import os
import secrets
import shutil
import stat
import subprocess
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
WEBROOT=Path("/var/www/html")
STATE=Path("/root/phpipam-dns9-windows-fixture-state.json")
BASE_URI="https://ipam.realistbank.ru/"

FILES={
    "tests/dns9/autonomous-windows.test.ps1": ROOT/"tests/dns9/autonomous-windows.test.ps1",
    "collectors/windows/dns/Install-PhpIpamDnsCollector.ps1": ROOT/"collectors/windows/dns/Install-PhpIpamDnsCollector.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1",
    "collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1": ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1",
    "collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1": ROOT/"collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1",
    "collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1": ROOT/"collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1",
}

def regular(path: Path)->bytes:
    st=path.lstat()
    if path.is_symlink() or not stat.S_ISREG(st.st_mode):
        raise RuntimeError("source_boundary_invalid:"+str(path))
    return path.read_bytes()

def readback(url: str)->tuple[int,bytes,str]:
    with tempfile.NamedTemporaryFile() as tmp:
        p=subprocess.run([
            "curl","--noproxy","*",
            "--resolve","ipam.realistbank.ru:443:127.0.0.1",
            "--silent","--show-error",
            "--output",tmp.name,
            "--write-out","%{http_code}",
            url,
        ],stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=False)
        try:
            code=int(p.stdout.decode("ascii").strip() or "0")
        except ValueError:
            code=0
        tmp.seek(0)
        return code,tmp.read(),p.stderr.decode("utf-8","replace").strip()

def publish()->None:
    if os.geteuid()!=0:
        raise SystemExit("DNS9_FIXTURE_ERROR=root_required")
    if STATE.exists() or STATE.is_symlink():
        raise SystemExit("DNS9_FIXTURE_ERROR=state_exists")

    source={name:regular(path) for name,path in FILES.items()}
    nonce=secrets.token_hex(16)
    directory=WEBROOT/("dns9-windows-fixture-"+nonce)
    directory.mkdir(mode=0o755)

    try:
        report={}
        for name,data in source.items():
            target=directory/name
            target.parent.mkdir(parents=True,exist_ok=True)
            target.write_bytes(data)
            os.chmod(target,0o644)
            os.chown(target,0,0)
            url=BASE_URI+directory.name+"/"+name
            code,body,err=readback(url)
            if code!=200 or body!=data:
                raise RuntimeError("fixture_readback_failed:"+name+":"+str(code)+":"+err)
            report[name]={"http":code,"bytes":len(body),"exact":True}

        base=BASE_URI+directory.name+"/"
        state={"directory":str(directory),"base_uri":base,"files":report}
        STATE.write_text(json.dumps(state,sort_keys=True,indent=2)+"\n")
        os.chmod(STATE,0o600)
        os.chown(STATE,0,0)

        windows=(
            "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12;"
            "$wc=New-Object Net.WebClient;"
            "$wc.Proxy=[Net.GlobalProxySelection]::GetEmptyWebProxy();"
            "$root=Join-Path $env:TEMP ('phpipam-dns9-fixture-'+[Guid]::NewGuid().ToString('N'));"
            "New-Item -ItemType Directory -Path $root|Out-Null;"
            "$files=@("
            "'tests/dns9/autonomous-windows.test.ps1',"
            "'collectors/windows/dns/Install-PhpIpamDnsCollector.ps1',"
            "'collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1',"
            "'collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1',"
            "'collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1',"
            "'collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1',"
            "'collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1'"
            ");"
            "try{foreach($f in $files){$dst=Join-Path $root ($f -replace '/',[IO.Path]::DirectorySeparatorChar);"
            "$parent=Split-Path -Parent $dst;New-Item -ItemType Directory -Path $parent -Force|Out-Null;"
            "$wc.DownloadFile('"+base+"'+$f,$dst)};"
            "& (Join-Path $root 'tests\\dns9\\autonomous-windows.test.ps1')"
            "}finally{Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue;$wc.Dispose()}"
        )
        encoded=base64.b64encode(windows.encode("utf-16le")).decode("ascii")

        print("DNS9_WINDOWS_FIXTURE_STAGE_OK=1")
        for name,item in report.items():
            print("DNS9_WINDOWS_FIXTURE_LOCAL "+name+" HTTP=200 BYTES="+str(item["bytes"])+" EXACT=1")
        print("DNS9_WINDOWS_FIXTURE_ENCODED_COMMAND=powershell.exe -NoProfile -EncodedCommand "+encoded)
        print("DNS9_WINDOWS_FIXTURE_TOKEN_USED=0")
        print("DNS9_WINDOWS_FIXTURE_DB_MUTATION=0")
        print("DNS9_WINDOWS_FIXTURE_TASK_MUTATION=0")
    except Exception:
        if directory.exists():
            shutil.rmtree(directory)
        if STATE.exists():
            STATE.unlink()
        raise

def cleanup()->None:
    if os.geteuid()!=0:
        raise SystemExit("DNS9_FIXTURE_ERROR=root_required")
    if not STATE.exists() or STATE.is_symlink():
        raise SystemExit("DNS9_FIXTURE_ERROR=state_missing")
    st=STATE.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0:
        raise SystemExit("DNS9_FIXTURE_ERROR=state_boundary_invalid")
    state=json.loads(STATE.read_text())
    directory=Path(state["directory"])
    if directory.parent!=WEBROOT or not directory.name.startswith("dns9-windows-fixture-"):
        raise SystemExit("DNS9_FIXTURE_ERROR=directory_boundary_invalid")
    if directory.exists():
        if directory.is_symlink() or not stat.S_ISDIR(directory.lstat().st_mode):
            raise SystemExit("DNS9_FIXTURE_ERROR=directory_type_invalid")
        shutil.rmtree(directory)
    STATE.unlink()
    print("DNS9_WINDOWS_FIXTURE_CLEANUP_OK=1")
    print("DNS9_WINDOWS_FIXTURE_TOKEN_USED=0")
    print("DNS9_WINDOWS_FIXTURE_DB_MUTATION=0")
    print("DNS9_WINDOWS_FIXTURE_TASK_MUTATION=0")

def main()->None:
    ap=argparse.ArgumentParser()
    g=ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--publish",action="store_true")
    g.add_argument("--cleanup",action="store_true")
    args=ap.parse_args()
    if args.publish:
        publish()
    else:
        cleanup()

if __name__=="__main__":
    main()
