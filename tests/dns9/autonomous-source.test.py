#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
INSTALL=ROOT/"collectors/windows/dns/Install-PhpIpamDnsCollector.ps1"
RUNNER=ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1"
STATUS=ROOT/"collectors/windows/dns/Get-PhpIpamDnsCollectorStatus.ps1"
UNINSTALL=ROOT/"collectors/windows/dns/Uninstall-PhpIpamDnsCollector.ps1"
DESIGN=ROOT/"design/DNS9_AUTONOMOUS_WINDOWS_COLLECTOR.md"
ENABLE_SQL=ROOT/"database/dns9/001-autonomous-enable.sql"
ADMIN=ROOT/"layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php"
CONTROLLER=ROOT/"layers/dns1/functions/classes/class.IntegrationDnsAdminController.php"
VIEW=ROOT/"layers/dns1/functions/classes/class.IntegrationDnsAdminView.php"
UI=ROOT/"layers/dns1/app/admin/integrations/dns/config.js"
SYSTEMD_SERVICE=ROOT/"deploy/dns9/phpipam-dns-autonomous.service"
SYSTEMD_TIMER=ROOT/"deploy/dns9/phpipam-dns-autonomous.timer"
WINDOWS_FIXTURE=ROOT/"tests/dns9/autonomous-windows.test.ps1"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for path in (INSTALL,RUNNER,STATUS,UNINSTALL,DESIGN,ENABLE_SQL,ADMIN,CONTROLLER,VIEW,UI,SYSTEMD_SERVICE,SYSTEMD_TIMER,WINDOWS_FIXTURE):
    ok(path.is_file(),path.name+" exists")

install=INSTALL.read_text()
runner=RUNNER.read_text()
status=STATUS.read_text()
uninstall=UNINSTALL.read_text()
design=DESIGN.read_text()
enable_sql=ENABLE_SQL.read_text()
admin=ADMIN.read_text()
controller=CONTROLLER.read_text()
view=VIEW.read_text()
ui=UI.read_text()
systemd_service=SYSTEMD_SERVICE.read_text()
systemd_timer=SYSTEMD_TIMER.read_text()
windows_fixture=WINDOWS_FIXTURE.read_text()

for required in [
    "New-ScheduledTaskAction",
    "New-ScheduledTaskTrigger",
    "Register-ScheduledTask",
    "MultipleInstances IgnoreNew",
    "Add-Type -AssemblyName System.Security -ErrorAction Stop",
    "System.Security.Cryptography.ProtectedData]::Protect",
    "System.Security.Cryptography.DataProtectionScope]::LocalMachine",
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
    "Add-Type -AssemblyName System.Security -ErrorAction Stop",
    "System.Security.Cryptography.ProtectedData]::Unprotect",
    "System.Security.Cryptography.DataProtectionScope]::LocalMachine",
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

for required in [
    "DROP CONSTRAINT custom_dns_connection_disabled",
    "custom_dns_connection_enabled CHECK (enabled IN (0,1))",
    "custom_dns_connection_legacy_collector_null CHECK (collector_id IS NULL)",
]:
    ok(required in enable_sql,"enable migration: "+required)
ok("UPDATE " not in enable_sql and "DELETE " not in enable_sql,"migration has no data rewrite/delete")
ok("DROP TABLE" not in enable_sql,"migration has no table drop")

for required in [
    "setCollectionEnabled",
    "queueControlJob",
    "registerCollector",
    "saveCollectorAssignment",
    "revokeCollector",
    "collector_control_available",
    "connection_runtime",
    "assignment_unavailable",
    "collector_unavailable",
]:
    ok(required in admin,"admin composition: "+required)

for required in [
    "register_collector",
    "save_assignment",
    "revoke_collector",
    "queue_test_connection",
    "queue_list_zones",
    "set_collection_enabled",
]:
    ok(required in controller,"controller action: "+required)
    ok(required in ui,"UI action: "+required)

for required in [
    "Автоматический сбор:",
    "Проверить доступ",
    "Получить зоны",
    "Включить сбор",
    "Отключить сбор",
    "Windows-сборщики",
    "Назначить сборщик",
    "Control token сборщика — показывается один раз.",
]:
    ok(required in view,"view automation: "+required)

ok("DNS write" not in ui,"UI contains no DNS-write action")

ok("data-dns-enrollment-token" in view,"view has one-time enrollment token target")
ok("tokenBox.textContent = token" in ui,"UI renders one-time token only after registration response")
ok("console.log" not in ui,"UI never logs enrollment token")

for required in [
    "Type=oneshot",
    "User=www-data",
    "ExecStart=/usr/bin/php /var/www/html/functions/scripts/dns-autonomous-scheduler.php",
    "NoNewPrivileges=true",
    "ProtectSystem=full",
]:
    ok(required in systemd_service,"systemd service: "+required)
for required in [
    "OnUnitActiveSec=60s",
    "Unit=phpipam-dns-autonomous.service",
    "WantedBy=timers.target",
]:
    ok(required in systemd_timer,"systemd timer interval: "+required)
ok("curl " not in systemd_service and "wget " not in systemd_service,"server scheduler does not shell-fetch code")

ok("[Security.Cryptography.ProtectedData]" not in install,"installer has no short DPAPI type")
ok("[Security.Cryptography.ProtectedData]" not in runner,"runner has no short DPAPI type")
ok("[Security.Cryptography.ProtectedData]" not in windows_fixture,"fixture has no short DPAPI type")
for required in [
    "Add-Type -AssemblyName System.Security -ErrorAction Stop",
    "System.Security.Cryptography.ProtectedData]::Protect",
    "System.Security.Cryptography.ProtectedData]::Unprotect",
    "System.Security.Cryptography.DataProtectionScope]::LocalMachine",
]:
    ok(required in windows_fixture,"Windows fixture DPAPI compatibility: "+required)

print(f"DNS9_AUTONOMOUS_SOURCE_ASSERTIONS={n}")
print("DNS9_AUTONOMOUS_SOURCE_TESTS_OK=1")
