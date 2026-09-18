# DNS6 — one-shot job-bound credential delivery

Stacked on DNS5 PR72 exact head `67bd10af70adb7e4b6d77741b1e11eca5c9d3dc2`.

## Purpose

Deliver credentials only for an already-running leased collector job. The collector supplies only:
- Bearer `dnsctl1` token;
- job ID;
- lease ID.

It cannot supply a connection ID, profile ID, username, server or secret version.

## One-shot server-side authorization

Inside the existing serialized DNS control transaction the service:
1. authenticates the collector token;
2. loads the exact job and verifies it belongs to that collector;
3. requires `state=running`;
4. verifies lease ID and lease/job expiry;
5. rejects a second delivery when `credential_delivered_at` is already set;
6. resolves the connection ID from the job;
7. revalidates current collector assignment and assignment revision;
8. resolves current DNS1 connection/profile context on the server;
9. revalidates configuration revision, binding epoch and credential version;
10. only then invokes the decryptor.

For profile mode, profile ID, username, envelope and secret version come only from the current server-side connection/profile rows. The profile ID/envelope never appear in the machine HTTP request or response.

For collector-identity mode no centrally stored username/password is returned.

After successful resolution, `credential_delivered_at` is persisted and a redacted `credential_delivered` control event is written. A second request for the same job/lease is rejected. This intentionally prefers fail-closed behavior over automatic secret retransmission.

## HTTP boundary

Dedicated source-only endpoint:
- POST only;
- phpIPAM trusted HTTPS check;
- `application/json` only;
- exact Bearer token shape;
- 4096-byte body limit before parsing;
- duplicate JSON keys rejected;
- exact input shape `job_id + lease_id`;
- no user session/CSRF;
- `Cache-Control: no-store`, `Pragma: no-cache`;
- allowlisted/sanitized errors.

The DNS keyring is loaded lazily by the decryptor only after collector/job/lease/assignment/config/version checks reach the credential-resolution step.

## Persistence delta

DNS6 adds one nullable field to the existing DNS control-job schema:

`credential_delivered_at BIGINT UNSIGNED NULL`

Candidate migration: `database/dns6/001-credential-delivery-marker.sql`.

The inherited DNS3 queue initializes this field to null and the inherited phpIPAM control repository persists/normalizes it. No new tables are introduced. Production DDL remains separately approval-gated.

## Secret handling

A successful profile-mode response necessarily carries the plaintext integration password over authenticated HTTPS. It is not written to jobs or audit rows. The controller serializes immediately and best-effort zeroes its plaintext array copy; the route best-effort zeroes the serialized response after echoing. No claim of forensic memory erasure is made.

## Explicitly absent

- collector-selected profile/connection;
- generic credential list/export API;
- DNS provider execution;
- PowerShell execution;
- Scheduled Task installation;
- snapshot ingestion/upload;
- production schema/key deployment;
- real collector token or real domain credential.

## Verification contract

Canonical tests:
- `tests/dns6/credential-context.test.php`;
- `tests/dns6/credential-delivery.test.php`;
- `tests/dns6/credential-http.test.php`;
- `tests/dns6/credential-source.test.py`.

Tests were introduced before the HTTP/source implementation. The lazy-keyring rule was explicitly observed red before route hardening.

Runtime/CI success must be claimed only from an exact committed head with actual executed steps.

## Next

After source acceptance, extend the Windows collector in a separate layer to:
1. poll its typed job;
2. fetch credentials once for that active lease;
3. execute only the already-reviewed Preview read operations;
4. submit the allowlisted result;
5. zero transient plaintext best-effort.

Do not combine snapshot ingestion, persistent scheduling or live deployment into DNS6.
