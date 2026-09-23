# DNS8 — guarded one-shot E2E pilot

Stacked on DNS7. The production pilot was installed from exact PR76 head `c0a4483af799ee65bbcdb0472e52839c6ffb7ee7`; subsequent source-only commits repaired the DNS7 worker and added bounded execution helpers without changing the installed DNS machine endpoints/schema.

## Purpose

Prove one real phpIPAM -> Windows collector -> Microsoft DNS -> phpIPAM `test_connection` cycle without Scheduled Task installation, autonomous polling, selected-zone ingestion or DNS writes.

The pilot uses `collector_identity`, so no domain password or DNS credential profile is stored in phpIPAM. The Windows operator runs the reviewed one-shot worker interactively under the existing identity that already passed read-only Microsoft DNS Preview checks.

## Installed production boundary

The accepted create-only installer:
1. applied DNS1 -> DNS3 -> DNS4 -> DNS6 schema;
2. installed only machine-control/machine-credential routes and required classes;
3. linted every installed PHP file;
4. created one disabled `collector_identity` connection for `srv-dc01.rbank.local`;
5. created one collector, assignment and pending `test_connection` job;
6. wrote the collector token only to `/root/phpipam-dns-e2e-pilot-token` mode0600;
7. wrote a receipt without the token secret.

Pilot identifiers:
- connection `33b6fade9323f15aa7b1f56a8a865a9d`;
- collector `f57057d6677e1ca7a032aab8b68796ac`;
- job `a4ee3e5987dd15495d7e2419e853d9c8`.

No Scheduled Task, DNS write, selected-zone snapshot or credential profile is part of this pilot.

## Exact runtime artifacts

Repaired worker Git blob:
`92d3a38c7e8415b44f92d35e6fa10e468ce77cbd`, 11080 bytes.

Exact downstream Preview Git blob:
`f96f1f378cc6dd8217399acfda4c98c79ab5c428`.

The repaired worker already passed exact HTTPS download and Windows PowerShell parser acceptance on SRV-DC01 with TLS1.2 and normal certificate validation.

## Bounded one-shot execution helpers

### Linux prepare / re-arm

`tools/live/dns-e2e-one-shot-prepare.py` is specific to the existing pilot identifiers.

It fails closed unless:
- worker and Preview match the exact Git blobs above;
- the root token file is root-owned mode0600 and hashes to the installed collector;
- connection/collector/assignment revisions are still the original pilot values;
- the exact job is still `pending`, attempts=0, unleased and has no credential/result/finish marker.

It then:
- publishes only Bootstrap + exact Worker + exact Preview below one random HTTPS directory;
- creates a one-shot token endpoint backed by a temporary `/run` spool readable only by the Apache account;
- exposes the token only through the random one-shot HTTPS endpoint; the endpoint deletes its temporary token spool and its own PHP source on the first successful read;
- extends only the exact pending job expiry to `now + 300` seconds;
- records a root-only transfer state/receipt without the token secret;
- prints the Windows command, never the collector token.

If preparation fails after the re-arm, it attempts to restore the previous expiry and removes temporary transfer material.

### Windows one-shot bootstrap

`collectors/windows/dns/Invoke-PhpIpamDnsE2EPilotOnce.ps1`:
- requires HTTPS and the same authority for transfer/application URIs;
- explicitly uses TLS1.2 and an empty WebClient proxy;
- does not bypass certificate validation;
- downloads Worker + Preview into a unique Windows TEMP directory;
- verifies both exact Git blob IDs and parses both scripts before token retrieval;
- retrieves the collector token once into memory, validates collector identity and converts it to SecureString;
- runs exactly one worker invocation;
- requires the submitted result to reference the exact pilot job and `test_connection`;
- removes the local TEMP directory in `finally`;
- never prints the token.

The worker remains:
`poll -> one-shot collector_identity credential marker -> exact read-only Preview -> allowlisted result -> submit -> exit`.

### Linux cleanup / acceptance

`tools/live/dns-e2e-one-shot-accept.py` always removes the bounded temporary transfer directory/spool before reporting acceptance.

Acceptance requires:
- exact job state `succeeded`;
- attempts=1;
- no remaining lease;
- `credential_delivered_at` present;
- result code `ok`;
- no result payload for `test_connection`;
- `finished_at` present;
- exactly one `credential_delivered` audit event.

This helper does **not** remove the installed DNS8 pilot schema, routes or persistent root token. Full pilot teardown remains the separate existing receipt-based cleanup operation and needs its own decision.

## Evidence

- Real Windows DNS read-only compatibility accepted.
- Exact DNS2 Preview accepted on `srv-dc01.rbank.local`.
- MariaDB persistence V5 accepted.
- DNS8 production preflight accepted.
- DNS8 create-only pilot install accepted.
- Direct application HTTPS endpoint boundary accepted.
- Corrupt old DNS7 worker detected before token use and repaired.
- Repaired worker exact Windows parser gate accepted.
- Original DNS8 source contract: 29 assertions at the accepted production-preflight head.
- installer blob: `67177edcc2d1ea4ae006867342b7ec84ca15c171`.
- cleanup blob: `32166b185c67031c95bcca36098863d02ce4c788`.

The next runtime gate is the already-authorized single real `test_connection` E2E execution plus immediate transfer cleanup/readback.
