#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
ORCH = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsWorkerOnce.ps1"
PREVIEW = ROOT / "collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1"
n = 0

def check(v, label):
    global n
    n += 1
    if not v:
        raise AssertionError(label)

check(ORCH.is_file(), "orchestration source exists")
check(PREVIEW.is_file(), "preview source exists")

orch = ORCH.read_text(encoding="utf-8-sig")
preview = PREVIEW.read_text(encoding="utf-8-sig")
compact = re.sub(r"(?m)^\s*#.*$", "", orch)

for required in [
    "#requires -Version 5.1",
    "System.Security.SecureString",
    "SecureStringToBSTR",
    "ZeroFreeBSTR",
    "Invoke-RestMethod",
    "machine-control.php",
    "machine-credential.php",
    "test_connection",
    "list_zones",
    "ConvertTo-SecureString",
    "System.Management.Automation.PSCredential",
    "Invoke-PhpIpamDnsPreview.ps1",
    "Remove-Item -LiteralPath",
    "Authorization",
    "Bearer ",
    "application/json",
]:
    check(required in orch, "required orchestration contract: " + required)

for forbidden in [
    "Register-ScheduledTask",
    "New-ScheduledTask",
    "Set-DnsServer",
    "Add-DnsServer",
    "Remove-DnsServer",
    "Clear-DnsServer",
    "Set-DnsServerResourceRecord",
    "Add-DnsServerResourceRecord",
    "Remove-DnsServerResourceRecord",
    "collect_selected_zones",
    "Start-Service",
    "Stop-Service",
    "Restart-Service",
    "Invoke-Expression",
    "ScriptBlock]::Create",
    "Start-Process",
    "cmd.exe",
    "powershell.exe",
    "pwsh.exe",
    "srv-dc01.rbank.local",
    "srv-dhcp01.rbank.local",
]:
    check(forbidden not in compact, "orchestration forbids: " + forbidden)

check("while (" not in compact.lower() and "while(" not in compact.lower(), "no poll loop")
check("Start-Sleep" not in compact, "no retry sleep")
check("PreviewScriptPath" not in compact, "preview path is not caller-selected")
check("https" in compact and "Uri" in compact, "HTTPS base URI validated")
check("credential_mode" in compact and "collector_identity" in compact and "profile" in compact,
      "both credential modes handled")
check("job_id" in compact and "lease_id" in compact, "lease-bound credential request")
check("Test-Dns7PositiveInteger" in compact, "polled revisions and lease expiry are validated as positive integers")
check(orch.count("dns_worker_result_submit_failed") == 1, "worker body is not duplicated")
check(len(orch) < 15000, "worker source size remains bounded")
check("'^[0-9a-f]{32}$'" in orch, "job and lease id regex literals remain intact")
check("lease_expires_at) -or" in orch, "lease expiry validation remains in work guard")
check("error_code" in compact and "zones" in compact, "allowlisted result shape")
check("ConvertFrom-Json" in compact, "Preview zone JSON parsed locally")
check("$env:TEMP" in compact, "temporary local output is explicit")
check("finally" in compact and "Remove-Item -LiteralPath" in compact, "temporary output cleanup")
check("Write-Host $token" not in compact and "Write-Output $token" not in compact, "token not printed")
check("Write-Host $password" not in compact and "Write-Output $password" not in compact, "password not printed")

# DNS7 downstream Preview must support collector_identity by allowing credential omission.
check("[Parameter(Mandatory=$false)]" in preview and "[System.Management.Automation.PSCredential]$Credential" in preview,
      "Preview credential may be omitted for collector identity")
check("New-CimSession" in preview and "Credential" in preview, "Preview still supports explicit credential")
check("Authentication = 'Kerberos'" in preview or "-Authentication Kerberos" in preview,
      "Preview remains Kerberos-only")

print(f"DNS7_ORCHESTRATION_SOURCE_ASSERTIONS={n}")
print("DNS7_ORCHESTRATION_SOURCE_TESTS_OK=1")
