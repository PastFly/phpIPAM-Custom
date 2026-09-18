# DNS2 Preview collector — source milestone

Date: 2026-09-18
Base: DNS1 configuration PR68 exact `eae4ec1d81bd7467f187ce37eb5c015105ca5dd1`.
Status: source-only Preview candidate; no Windows or production installation.

## Scope

This increment adds a Windows PowerShell 5.1 Preview collector for Microsoft DNS. It deliberately has no phpIPAM upload, collector pairing, scheduled-task registration, DNS writes, service control, native address-plan writes, or credential persistence.

Inputs are explicit:
- DNS server FQDN;
- a PSCredential object supplied by the caller;
- optional selected zone names;
- bounded operation timeout;
- optional absolute local output file.

No Owner-provided source hostname is hardcoded. Preview uses a Kerberos CIM session with an operation timeout and the DnsServer read cmdlets only. The supported first-release record types are A, AAAA, CNAME and PTR.

## Behavior

Without selected zones, Preview lists zone metadata and returns only an aggregate summary to the pipeline. With selected zones, each requested zone is read independently for A/AAAA/CNAME/PTR and normalized into the schema-1 Preview snapshot shape. Default virtualization instance and default zone scope are recorded explicitly.

A/AAAA values are parsed through System.Net.IPAddress. Owner names and CNAME/PTR targets are normalized case-insensitively with a terminal dot; apex records are mapped to the zone FQDN. TTL is retained in seconds. A valid DNS aging timestamp is converted to Unix UTC seconds; static/invalid timestamp evidence remains null.

Full normalized data is written only when an explicit absolute `-OutputPath` is provided. The helper validates the exact literal path it later writes. Multi-zone output uses `ConvertTo-Json -InputObject` so it remains one JSON document.

## Security/read-only boundaries

The collector source contains no:
- Invoke-RestMethod / Invoke-WebRequest / HTTP client;
- ScheduledTask registration;
- Set/Add/Remove/Clear DnsServer cmdlets;
- service start/stop/restart;
- Invoke-Expression or dynamically created script blocks;
- plaintext SecureString conversion;
- child powershell/cmd process launch;
- Resolve-DnsName or external resolver call.

The domain credential exists as a PSCredential supplied to New-CimSession. Preview does not print, log or serialize that credential.

## Verification

The source contract was created first and observed red because the collector file was absent. A later focused regression was observed red for an output-path variable mismatch, then fixed. A multi-zone serialization regression was also observed red before the ConvertTo-Json fix.

Current exact source blob `fc28d1af47063a4c90fc9b97b428b5360c3c8bab` passed 45 source-boundary assertions in the development session.

`tests/dns2/preview-windows.test.ps1` is an offline Windows PowerShell 5.1 fixture test. It replaces the DnsServer/CIM read commands with local functions and checks Kerberos/timeout propagation, discovery, A/AAAA/CNAME/PTR normalization, static timestamps, multi-zone JSON and session cleanup. Its presence is NOT Windows execution evidence.

The dedicated workflow requires a Windows Server 2022 runner using `shell: powershell` and rejects any major PowerShell version other than 5. Until such steps execute, Windows PowerShell 5.1 compatibility remains pending.

## Next after source acceptance

Run the Preview script on the selected Windows collector host against the configured DNS source only after the collector host/version and a test credential/delegation are available through an approved setup. Start with zone discovery, then one selected forward and reverse zone. Do not add upload/control/persistent scheduling until Preview access and normalization are accepted.
