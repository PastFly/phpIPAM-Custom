# DNS2 Windows DNS Preview collector

Stacked source stage on DNS1 configuration. This stage does not install a task, register a collector, upload data or modify DNS.

## Purpose

Provide a local Windows PowerShell 5.1 Preview command for validating the future Microsoft DNS collector contract before phpIPAM control/ingestion is implemented.

Inputs are explicit:
- DNS server FQDN;
- PSCredential supplied by the operator/runtime;
- optional selected zones;
- optional absolute local JSON output path.

No source hostname, username or password is hardcoded.

## Read boundary

The collector imports the Microsoft DnsServer module and opens an explicit Kerberos CIM session. It uses only:
- Get-DnsServerZone;
- Get-DnsServerResourceRecord for A, AAAA, CNAME and PTR.

No DNS writes, service changes, Scheduled Task registration, HTTP upload or arbitrary PowerShell execution exist in this stage.

Microsoft documentation confirms DNS resource-record cmdlets support CimSession, and Get-DnsServerResourceRecord supports the selected RR types.

## Preview output

Without selected zones the script returns zone summary metadata. With zones it returns normalized complete per-zone snapshots for the default virtualization instance and default zone scope.

Console output is summary-only. Full records are written only when an explicit absolute OutputPath is supplied. Multi-zone output is serialized as one JSON document.

A failed type/zone read aborts publication; the script never marks a partial zone complete.

## Verification boundary

Linux source-contract tests prohibit network/upload/task/write primitives and hardcoded production hosts.
A Windows runner parses the source using Windows PowerShell 5.1 syntax only. That is not evidence that the DnsServer module is present or that credentials/permissions work against the Owner's DNS server.

Real Windows acceptance remains pending and must use a suitable Windows host with DnsServer RSAT/module and delegated read permissions. No secret should be put in Git or chat.
