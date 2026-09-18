# DNS2 — Microsoft DNS read-only Preview collector

Stacked source stage over DNS1 PR68. No deployment or scheduled task is included.

## Purpose

Provide one manually invoked Windows PowerShell 5.1 Preview path before collector pairing/control exists. It reads Microsoft DNS through the DnsServer module and an explicit Kerberos CIM session, normalizes only A/AAAA/CNAME/PTR from the default virtualization instance/default scope, and returns aggregate status. Full normalized data is written only when an operator supplies a new absolute local `-OutputPath`.

## Boundaries

- Runtime server is always an input; no rbank host is hardcoded.
- Source credential is a `PSCredential`; plaintext password is not a script argument or persisted by this source.
- Kerberos is explicit. No CredSSP/Basic fallback is implemented.
- Only `Get-DnsServerZone` and `Get-DnsServerResourceRecord` perform DNS provider reads.
- No DNS write cmdlets, service control, HTTP upload, collector registration, scheduled task, phpIPAM API call or local secret cache.
- Existing output paths are refused; Preview never overwrites a local file.
- Omitting `-Zone` lists zones only. Supplying one or more zones reads those zones; all four supported RR types must finish successfully or the script fails rather than publishing a partial zone as complete.
- This source is not yet Windows-runtime evidence. Actual PowerShell 5.1, DnsServer-module, Kerberos delegation and permission behavior require a later Windows pilot.

## Operator-shaped example

The actual source server and credentials are supplied interactively/configurably, not embedded in the script:

```powershell
$Credential = Get-Credential
.\Invoke-PhpIpamDnsPreview.ps1 -Server '<dns-fqdn>' -Credential $Credential
.\Invoke-PhpIpamDnsPreview.ps1 -Server '<dns-fqdn>' -Credential $Credential -Zone '<zone>' -OutputPath 'C:\Temp\dns-preview.json'
```

No production credential should be committed to Git or sent in chat.
