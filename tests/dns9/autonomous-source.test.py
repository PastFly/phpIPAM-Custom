#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
INSTALL=ROOT/"collectors/windows/dns/Install-PhpIpamDnsCollector.ps1"
RUNNER=ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1"
STATUS=ROOT/"collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1"
UNINSTALL=ROOT/"collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1"
DESIGN=ROOT/"design/DNS9_AUTONOMOUS_WINDOWS_COLLECTOR.md"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for path in (INSTALL,RUNNER,STATUS,UNINSTALL,DESIGN):
    ok(path.is_file(),path.name+" exists")

install=INSTALL.read_text()
runner=RUNNER.read_text()
status=STATUS.read_text()
uninstall=UNINSTALL.read_text()
design=DESIGN.read_text()

for required in [
    "New-ScheduledTaskAction",
    "New-ScheduledTaskTrigger",
    "Register-ScheduledTask",
    "MultipleInstances IgnoreNew",
    "DataProtectionScope]::LocalMachine",
    "control-token.dpapi",
    "SecureString",
    "ServiceAccount",
    "SYSTEM",
    "DnsCredentialMode",
    "CollectorIdentity",
    "dns9_collector_identity_requires_service_account",
    "*S-1-5-18:(OI)(CI)F",
    "*S-1-5-32-544:(OI)(CI)F",
    "DNS9_AUTONOMOUS_TOKEN_PRINTED=0",
]:
    ok(required in install,"installer boundary: "+required)

ok("-Password" not in install,"installer does not accept scheduled-task password")
ok("Write-Output $plain" not in install,"installer does not print token plaintext")
ok("Set-DnsServer" not in install and "Add-DnsServer" not in install and "Remove-DnsServer" not in install,"installer has no DNS write cmdlets")

for required in [
    "Global\\phpIPAM-DNS-Collector-v1",
    "WaitOne(0",
    "ProtectedData]::Unprotect",
    "DataProtectionScope]::LocalMachine",
    "Invoke-PhpIpamDnsWorkerOnce.ps1",
    "DefaultWebProxy=[Net.GlobalProxySelection]::GetEmptyWebProxy()",
    "DefaultWebProxy=$oldProxy",
    "status.json",
    "overlap_skipped",
]:
    ok(required in runner,"runner boundary: "+required)

ok("ScheduledTask" not in runner,"runner does not mutate task definition")
ok("Write-Output $plain" not in runner,"runner does not print token plaintext")
ok("Set-DnsServer" not in runner and "Add-DnsServer" not in runner and "Remove-DnsServer" not in runner,"runner has no DNS write cmdlets")
ok("while(" not in runner.lower() and "while (" not in runner.lower(),"runner has no permanent polling loop")
ok("Start-Sleep" not in runner,"runner has no retry sleep loop")

for required in [
    "Get-ScheduledTask",
    "Get-ScheduledTaskInfo",
    "status.json",
]:
    ok(required in status,"status boundary: "+required)

ok("control-token.dpapi" not in status,"status helper never reads token blob")

for required in [
    "SupportsShouldProcess",
    "Unregister-ScheduledTask",
    "Remove-Item",
    "DNS9_AUTONOMOUS_UNINSTALL_OK=1",
]:
    ok(required in uninstall,"uninstall boundary: "+required)

for required in [
    "install-once Windows collector",
    "must not require logging into the DNS server",
    "gMSA",
    "centrally managed credential profile",
]:
    ok(required in design,"design: "+required)

print(f"DNS9_AUTONOMOUS_SOURCE_ASSERTIONS={n}")
print("DNS9_AUTONOMOUS_SOURCE_TESTS_OK=1")
