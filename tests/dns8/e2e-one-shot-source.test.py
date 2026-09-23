#!/usr/bin/env python3
import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
PREPARE=ROOT/"tools/live/dns-e2e-one-shot-prepare.py"
ACCEPT=ROOT/"tools/live/dns-e2e-one-shot-accept.py"
BOOTSTRAP=ROOT/"collectors/windows/dns/Invoke-PhpIpamDnsE2EPilotOnce.ps1"

n=0
def ok(v,label):
    global n
    n+=1
    if not v:
        raise AssertionError(label)

for path in (PREPARE,ACCEPT,BOOTSTRAP):
    ok(path.is_file(),f"{path.name} exists")

prepare=PREPARE.read_text()
accept=ACCEPT.read_text()
bootstrap=BOOTSTRAP.read_text()

ast.parse(prepare)
ast.parse(accept)
ok(True,"python syntax")

for required in [
    "a4ee3e5987dd15495d7e2419e853d9c8",
    "f57057d6677e1ca7a032aab8b68796ac",
    "33b6fade9323f15aa7b1f56a8a865a9d",
    "92d3a38c7e8415b44f92d35e6fa10e468ce77cbd",
    "f96f1f378cc6dd8217399acfda4c98c79ab5c428",
    "state='pending'",
    "attempt_count=0",
    "credential_delivered_at IS NULL",
    "expires_at={new_expires}",
    "new_expires = now + 300",
    "token.php",
    "DNS8_E2E_WINDOWS_COMMAND=",
    "DNS8_E2E_WINDOWS_INSTRUCTION=RUN_ENCODED_COMMAND_NOT_PARSER_GATE",
    "DNS8_E2E_WINDOWS_ENCODED_COMMAND=powershell.exe -NoProfile -EncodedCommand ",
    "utf-16le",
    "DNS8_E2E_TOKEN_PRINTED=0",
]:
    ok(required in prepare,"prepare boundary: "+required)

ok("print(token)" not in prepare,"prepare does not print token")
ok("TOKEN_VALUE" not in prepare,"prepare has no token output marker")
ok("ScheduledTask" not in prepare,"prepare has no ScheduledTask primitive")
ok("dnsserver" not in prepare.lower(),"prepare has no DNS provider mutation")

for required in [
    "DownloadData($workerUri)",
    "DownloadData($previewUri)",
    "DownloadString($tokenUri)",
    "ConvertTo-SecureString",
    "$ExpectedWorkerBlob",
    "$ExpectedPreviewBlob",
    "$ExpectedJobId",
    "TransportPreflightOnly",
    "DNS8_E2E_TRANSPORT_PREFLIGHT_OK=1",
    "DNS8_E2E_TRANSPORT_TOKEN_USED=0",
    "[System.Net.WebRequest]::DefaultWebProxy=[Net.GlobalProxySelection]::GetEmptyWebProxy()",
    "[System.Net.WebRequest]::DefaultWebProxy=$oldDefaultProxy",
    "DNS8_E2E_WINDOWS_TOKEN_PRINTED=0",
]:
    ok(required in bootstrap,"bootstrap boundary: "+required)

ok("ServerCertificateValidationCallback" not in bootstrap,"certificate validation not bypassed")
ok("ScheduledTask" not in bootstrap,"bootstrap has no ScheduledTask primitive")
ok(
    "Set-DnsServer" not in bootstrap
    and "Add-DnsServer" not in bootstrap
    and "Remove-DnsServer" not in bootstrap,
    "bootstrap has no DNS write cmdlet",
)
ok("Write-Output $tokenText" not in bootstrap,"bootstrap does not print token")

for required in [
    "DNS8_E2E_TRANSFER_CLEANED=1",
    "DNS8_E2E_JOB_STATE=",
    "DNS8_E2E_CREDENTIAL_DELIVERED=",
    "DNS8_E2E_CREDENTIAL_AUDIT_COUNT=",
    "DNS8_E2E_ACCEPT=",
    "audit_count == 1",
    "raise SystemExit(2)",
]:
    ok(required in accept,"acceptance boundary: "+required)

ok("DROP TABLE" not in accept,"acceptance does not remove pilot schema")
ok("TOKEN_PATH" not in accept,"acceptance does not remove persistent pilot token")
print(f"DNS8_E2E_ONE_SHOT_SOURCE_ASSERTIONS={n}")
print("DNS8_E2E_ONE_SHOT_SOURCE_TESTS_OK=1")
