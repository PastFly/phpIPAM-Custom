# DNS7 — one-shot Windows collector orchestration

Stacked on DNS6 PR73 exact head `d714fc1a117cfaacee4c32f6ec477035b866a3f9`.

## Purpose

Wire the already-reviewed machine endpoints to the existing read-only Windows DNS Preview collector without introducing scheduling or snapshot ingestion.

One invocation handles at most one leased job:
1. poll `machine-control.php`;
2. if idle, stop;
3. validate exact typed work;
4. fetch the one-shot job-bound credential from `machine-credential.php`;
5. execute only the existing Preview path;
6. submit one allowlisted result to `machine-control.php`;
7. exit.

No polling loop, automatic retry, Scheduled Task, DNS write, snapshot upload or arbitrary script path exists in this layer.

## Authentication and secrets

Collector control token is supplied as `SecureString`. The worker converts it only long enough to build the Bearer header, checks the exact `dnsctl1` token shape, never writes or prints it, and clears its local references on exit.

For `profile` mode, the one-shot response provides username/password only after DNS6 server-side authorization. The worker immediately builds a PSCredential, removes the password property from its local response object and never includes the password in result JSON. PowerShell/.NET string immutability means forensic memory erasure is not claimed.

For `collector_identity` mode no central username/password is returned. DNS7 therefore extends the downstream Preview script so `-Credential` is optional; the CIM session remains explicitly Kerberos and simply omits the Credential argument when current Windows identity is intended.

## Endpoint boundary

Operator supplies one absolute HTTPS phpIPAM base URI. Userinfo, query and fragment are rejected. Machine-control and credential endpoint URIs are derived internally and must keep the same HTTPS authority.

The operator cannot select arbitrary endpoint paths or an arbitrary Preview script.

## Typed work only

Accepted job types remain:
- `test_connection`;
- `list_zones`.

Poll payload must include exact job/lease/server/version fields. Configuration revision, binding epoch, assignment revision and lease expiry must be positive integers; credential version must be null or a positive integer.

`test_connection` executes Preview discovery and submits only `ok/error_code`.

`list_zones` executes Preview discovery into a unique temporary JSON file, validates the complete Preview envelope, transforms only:
- name;
- zone_type;
- ds_integrated;
- reverse_lookup;

and submits at most 1024 zone descriptors. Temporary output is removed in `finally`.

`collect_selected_zones` remains unavailable. DNS record snapshots are not uploaded in DNS7.

## Failure behavior

Preview/provider failures are reduced to the existing allowlisted result codes. There is no automatic retry or sleep loop. Credential/control HTTP authorization or lease failures fail the one-shot invocation and are left for server-side lease expiry semantics.

## Verification

Canonical tests:
- `tests/dns7/orchestration-source.test.py`;
- `tests/dns7/orchestration-windows.test.ps1`.

The source test was introduced red before the worker existed. A focused red regression then required positive numeric poll-context validation before it was implemented.

Current exact source evidence:
- orchestration source boundary: **53 assertions green**;
- worker blob: `2dc20e78d69bdc06e1db8b227e8028f6b3b3dd5b`;
- downstream Preview blob: `f96f1f378cc6dd8217399acfda4c98c79ab5c428`.

The Windows fixture uses mocked HTTPS/DNS provider functions and is not real Windows DNS evidence until it executes under Windows PowerShell 5.1.

## Explicitly absent

- Scheduled Task installation;
- persistent token/credential storage;
- automatic polling/retry;
- snapshot ingestion;
- selected-zone record collection;
- DNS writes;
- phpIPAM production deployment/schema/key provisioning;
- real domain credential/token;
- master merge.

Real Windows Preview and MariaDB evidence remain separate gates before any live pilot.
