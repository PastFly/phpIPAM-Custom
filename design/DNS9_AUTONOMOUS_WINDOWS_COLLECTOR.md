# DNS9 — autonomous Windows DNS collector

## Goal

The operator must not open SRV-DC01 and run PowerShell for routine DNS collection.

DNS9 turns the reviewed DNS7 one-shot worker into an install-once Windows collector:
- one-time administrative installation;
- persistent scheduled execution;
- no interactive PowerShell for normal operation;
- phpIPAM remains the control plane;
- one scheduled invocation handles at most one queued job and exits.

The existing DNS7 worker remains the provider boundary. DNS9 does not add DNS writes.

## Windows layout

Default installation root:

`%ProgramData%\phpIPAM\DnsCollector`

Installed files:
- `Invoke-PhpIpamDnsWorkerOnce.ps1`
- `Invoke-PhpIpamDnsPreview.ps1`
- `Invoke-PhpIpamDnsCollectorScheduled.ps1`
- `collector.json`
- `control-token.dpapi`
- transient `status.json`

Scheduled Task:

`phpIPAM Microsoft DNS Collector`

Default poll cadence is once per minute. The task uses `MultipleInstances=IgnoreNew`; the runner also takes a machine-wide mutex so overlapping collector cycles fail closed.

## Token persistence

The collector control token is never written as plaintext.

The installer accepts it as SecureString, converts it only long enough to validate/encrypt it, and writes a Windows DPAPI `LocalMachine` blob to `control-token.dpapi`.

The installation directory has inheritance removed. Access is restricted to:
- LocalSystem;
- local Administrators;
- optionally the configured gMSA/service account.

The runner decrypts the DPAPI blob only in memory, converts it to SecureString for the existing one-shot worker, and clears byte arrays afterward. The token is never printed into task output or status.

## Task identity

Two task-principal modes are supported:

1. `System` — recommended when the phpIPAM connection uses a centrally managed credential profile. The Scheduled Task itself needs no domain password.
2. `ServiceAccount` — for `collector_identity`. The intended account is a gMSA or equivalent passwordless Windows service account with delegated read-only DNS rights.

A normal interactive administrator account is not the intended long-term task identity.

## Runtime

Each scheduled invocation:

1. acquires `Global\phpIPAM-DNS-Collector-v1`;
2. validates local config and HTTPS base URI;
3. decrypts the DPAPI control token in memory;
4. temporarily forces direct .NET HTTP transport;
5. invokes the exact existing one-shot worker;
6. records only redacted status: idle/submitted/error, job ID/type and safe result code;
7. restores proxy state, clears token material, releases the mutex and exits.

There is no permanent polling process and no unbounded retry loop. Task Scheduler provides the recurrence.

## Failure behavior

- overlapping cycle: record `overlap_skipped`, exit;
- missing/corrupt local install: fail;
- invalid token blob: fail;
- worker error: record only an allowlisted safe error code;
- network/server outage: task exits and the next scheduled invocation is independent;
- no token, password or raw exception text is written to status.

## Product direction

Normal operation after installation must not require logging into the DNS server.

The phpIPAM side will own:
- connection configuration;
- credential profile or collector-identity selection;
- collector assignment;
- job scheduling;
- health/status display;
- later selected-zone snapshot ingestion.

The Windows side is only a least-privilege execution agent.

## Current boundary

DNS9 is source-only. It does not install a production Scheduled Task until separately approved and runtime-tested.

The current DNS8 one-shot E2E remains an acceptance gate for the underlying worker/control protocol, but it is not the intended end-user workflow.
