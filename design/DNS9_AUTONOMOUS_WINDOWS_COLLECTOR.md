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

1. `System` — recommended when the collector runs on the DNS server itself. It supports both a centrally managed credential profile and local `collector_identity`; for the latter the preview uses the local DnsServer provider directly instead of creating a Kerberos CIM hop back to the same server.
2. `ServiceAccount` — optional for deployments that deliberately need a delegated gMSA/passwordless service identity.

A normal interactive administrator account is not the intended long-term task identity. Administrative credentials, when needed for remote installation, are bootstrap-only and are not the collector runtime identity.

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

The Windows side is only an execution agent. The default local collector runs as LocalSystem because it must create a durable Scheduled Task and query the local DNS provider without persisting a domain password; routine DNS operations remain read-only.

## Current boundary

DNS9 is source-only. It does not install a production Scheduled Task until separately approved and runtime-tested.

The current DNS8 one-shot E2E remains an acceptance gate for the underlying worker/control protocol, but it is not the intended end-user workflow.


## phpIPAM-side autonomous scheduling

DNS9 also adds `IntegrationDnsAutonomousScheduler` plus a CLI entrypoint. This is the server-side half of the automation.

For each enabled Microsoft DNS connection it:
- resolves the current collector assignment;
- rejects revoked/missing collectors;
- respects the configured `interval_seconds`;
- refuses to create a duplicate active `list_zones` job;
- queues only read-only `list_zones` jobs;
- binds the job to current configuration/binding/credential/assignment revisions;
- uses a bounded five-minute job expiry;
- records a redacted `autonomous_job_queued` audit event with system actor 0.

The scheduler never opens the credential keyring, never sees the collector token, never calls Microsoft DNS and never writes native phpIPAM address data.

A later activation/UI layer will control when a connection is enabled. The scheduler source is already separated from the Windows agent so each side can be tested and deployed independently.

## Intended operator experience

After initial enrollment/install:
1. configure the DNS source and credentials in phpIPAM;
2. assign/install the collector once;
3. enable the connection in phpIPAM;
4. phpIPAM schedules read-only jobs automatically;
5. Windows Task Scheduler picks them up automatically;
6. status/results return to phpIPAM automatically.

Routine synchronization must not require RDP, an interactive PowerShell prompt, copying commands, or manually handling collector tokens.
