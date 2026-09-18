# DNS2 — Windows DNS Preview collector

Base: DNS1 configuration branch `feature/microsoft-dns-config`, exact base head `eae4ec1d81bd7467f187ce37eb5c015105ca5dd1`.

## Scope

This increment adds only a local Preview collector for Windows PowerShell 5.1. It does not register a collector, create a Scheduled Task, upload to phpIPAM, alter DNS, cache credentials, or modify production.

Inputs:
- DNS server FQDN supplied at invocation time;
- PSCredential object supplied by the caller;
- optional explicit zone list;
- operation timeout;
- optional absolute local JSON output path.

Transport:
- PowerShell DnsServer cmdlets over an explicit CIM session;
- Kerberos authentication;
- no CredSSP, Basic fallback, HTTP uploader, arbitrary script execution, or provider writes.

Read operations:
- `Get-DnsServerZone`;
- `Get-DnsServerResourceRecord`;
- A, AAAA, CNAME and PTR only;
- default DNS virtualization instance / default zone scope only.

## Output

Without `-Zone`, Preview returns only a zone inventory summary. With zones, it normalizes records into a bounded JSON-compatible shape:
- canonical lower-case FQDN owner;
- type/class;
- canonical A/AAAA address or trailing-dot CNAME/PTR target;
- TTL seconds;
- optional aging timestamp;
- source server/zone;
- explicit `complete=true` only after all requested read operations succeeded.

The console output is a summary and does not print the credential. Full record data is written only when an explicit absolute `-OutputPath` is supplied.

## Boundaries

The script contains no phpIPAM URL, source hostname default, password string, upload routine, task registration, DNS write cmdlet, service mutation or external process launch.

The first source contract was added before the collector and was red while the collector source was absent. A second red regression exposed that the validated helper path was not the exact path used by Set-Content; this was corrected. A third red regression exposed PowerShell pipeline array expansion before ConvertTo-Json; output now uses `ConvertTo-Json -InputObject` so a multi-zone result remains one JSON document.

## Evidence

Current exact collector blob: `f4dd27fa05308ca4a0596d5f96d1e56d12060d3f`.

Static exact-source contract executed: 45 assertions, green.

This is NOT Windows runtime evidence. The script has not yet been executed with Windows PowerShell 5.1, the DnsServer module, Kerberos, the real DNS source, real delegated permissions, or a real output file on the Owner's Windows environment.

## Next gate

Before control-channel integration:
1. run Preview on a selected Windows host using a non-secret interactive PSCredential;
2. first list zones against the configured DNS source;
3. inspect selected-zone output, record counts and normalization;
4. confirm no DNS modification and no credential persistence;
5. only then design typed async collector registration/control and secret delivery.

No production/phpIPAM/Windows installation is authorized by this source increment.
