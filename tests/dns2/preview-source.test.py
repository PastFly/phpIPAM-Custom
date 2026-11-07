#!/usr/bin/env python3
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[2]
PS=ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsPreview.ps1"
n=0
def check(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

check(PS.is_file(),"preview collector source exists")
src=PS.read_text(encoding="utf-8-sig")
compact=re.sub(r'(?m)^\s*#.*$','',src)

for required in [
    "[CmdletBinding()]","System.Management.Automation.PSCredential","Get-DnsServerZone",
    "Get-DnsServerResourceRecord","New-CimSession",
    "Remove-CimSession","Import-Module DnsServer","-RRType",
    "A","AAAA","CNAME","PTR","OutputPath","ConvertTo-Json"
]:
    check(required in compact,"required source contract: "+required)

for forbidden in [
    "Invoke-RestMethod","Invoke-WebRequest","System.Net.Http",
    "Register-ScheduledTask","New-ScheduledTask","Set-DnsServer",
    "Add-DnsServer","Remove-DnsServer","Clear-DnsServer","Set-DnsServerResourceRecord",
    "Add-DnsServerResourceRecord","Remove-DnsServerResourceRecord",
    "Start-Service","Stop-Service","Restart-Service","Invoke-Expression",
    "ScriptBlock]::Create","ConvertTo-SecureString -AsPlainText",
    "Start-Process","cmd.exe","powershell.exe","pwsh.exe"
]:
    check(forbidden not in compact,"preview forbids: "+forbidden)

check("srv-dc01.rbank.local" not in compact,"source host is not hardcoded")
check(re.search(r"Authentication\s*=\s*'Kerberos'",compact) is not None and "New-CimSession @sessionArgs" in compact,"remote session enforces Kerberos")
check("srv-dhcp01.rbank.local" not in compact,"dhcp host is not hardcoded")
check("param(" in compact and "Server" in compact and "Credential" in compact,"server and credential are inputs")
check("ValidatePattern" in compact,"server input constrained as FQDN")
check("Get-Item -LiteralPath $LiteralPath -Force" in compact and "dns_preview_output_exists" in compact,"existing output path is refused")
check("Set-Content -LiteralPath $LiteralPath" in compact,"validated helper path is the written path")
check("Resolve-DnsName" not in compact,"no external name resolution")
check("ConvertTo-Json -InputObject $Value" in compact,"multi-zone output stays one JSON document")
check("complete" in compact and "supported_types" in compact,"normalized preview metadata present")
print(f"DNS2_PREVIEW_SOURCE_ASSERTIONS={n}")
print("DNS2_PREVIEW_SOURCE_TESTS_OK=1")
