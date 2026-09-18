# DNS3 collector registration and typed control queue — source milestone

Date: 2026-09-18
Base: DNS2 Preview PR69 exact `0e3211f81c44927f01fe794b536c911a1236cbf1`.
Status: Draft source-only control/persistence layer; no machine HTTP endpoint, credential delivery, Windows task or production install.

## Scope

This layer defines:
- collector registration with one-time high-entropy control token and digest-only storage;
- explicit revisioned connection-to-collector assignment;
- a bounded admin queue for exactly `test_connection` and `list_zones`;
- phpIPAM `Database_PDO` persistence and a separate additive schema candidate.

`collect_selected_zones` is intentionally not enabled here.

Registration returns `dnsctl1.<collector-id>.<secret>` once. Stored collector state contains only a context-prefixed SHA-256 digest. Public collector/job projections exclude digest/token. No endpoint exists for snapshot upload or credential retrieval in this layer.

Jobs bind configuration revision, binding epoch and credential version. They contain neither profile ID nor domain password. Collector revocation increments collector revision/control epoch, removes assignments and obsoletes pending/running work in one transaction.

A duplicate active job of the same type/connection is rejected for both `pending` and `running` states until it expires. This prevents parallel authentication/discovery work from amplifying account-lockout risk.

## Persistence

`IntegrationDnsCollectorControlPhpIpamRepository` uses only the existing phpIPAM database wrapper methods:
- `beginTransaction`
- `commit`
- `rollBack`
- `getObjectsQuery`
- `runQuery`

It reuses DNS1 `custom_dns_config_guard` with `FOR UPDATE`; DNS3 does not create a second mutex.

Candidate schema `database/dns3/001-collector-control.sql` adds four InnoDB tables for collectors, assignments, jobs and bounded control audit. It requires the DNS1 configuration schema first. It stores no raw control token, password, profile ID or credential envelope. Job type constraints permit only `test_connection` and `list_zones`.

Runtime code never performs DDL. The schema candidate is not installed anywhere by this PR.

## Boundaries

Control runtime has no:
- `IntegrationDnsCipher` dependency or secret decryption/delivery;
- DNS provider cmdlets or PowerShell execution;
- machine HTTP route;
- snapshot upload;
- Windows task management;
- hardcoded Owner source hosts.

Limits: 64 collectors, 256 assignments through the existing connection bound, 512 stored jobs, and 10,000 bounded control audit events.

The source-stage job limit counts retained jobs. A future poll/result retention layer must expire/prune terminal jobs before live use; this PR does not pretend that retention lifecycle already exists.

## Verification actually executed

PHP 8.4.23 local/reconstructed exact-source contracts:
- collector-control functional suite: **35 assertions**;
- phpIPAM Database_PDO persistence adapter: **23 assertions**;
- token-aware runtime boundary: **44 assertions**;
- persistence/schema boundary: **28 assertions**;
- PHP lint on current DNS3 PHP sources: passed.

Test-first/debugging evidence:
- initial control contract was red while source was absent;
- raw-comment boundary failed on the comment-only word `decryption`; root cause was test scanning, fixed with PHP tokenization excluding comments/docblocks;
- duplicate-running-job regression was observed red, then fixed by treating both pending and running as active;
- Database_PDO persistence contract was observed red while its adapter was absent.

Current service blob: `44d26691ada01400d7fcb1bd1a6fab08e4fcab21`.
Current persistence adapter blob: `2835af731ef794eb143b1ad99c53d55fab936cf7`.

## MariaDB status

`tests/dns3/mysql.test.php` is opt-in and accepts only an empty localhost database named `phpipam_dns3_test`. It applies DNS1 + DNS3 candidate schema and tests:
- digest-only collector token persistence;
- assignment/job version persistence;
- duplicate running-job rejection;
- shared DNS configuration mutex contention through a second connection;
- trigger-injected rollback of collector revoke;
- revoke fanout;
- bounded 10,000-row control audit retention.

Local environment has no PDO MySQL driver, so this suite has **not** executed successfully and no MariaDB pass is claimed. Dedicated CI provisions MariaDB 10.11, but exact-head execution must be inspected before crediting it.

## Concurrency/revalidation caveat before credential delivery

The current service resolves connection context before entering the DNS3 persistence transaction for assignment/job queueing. Jobs capture revision/binding/credential version, but future machine polling and especially credential delivery must revalidate assignment plus current connection/profile versions immediately before lease/secret use. Do not add secret delivery by merely trusting queued job fields.

## Next

1. Obtain real MariaDB persistence evidence.
2. Add machine poll/result state transitions with collector-token authentication, lease/deadline/state/version checks, no secrets.
3. Add bounded terminal-job expiry/cleanup.
4. Only then design a separately scoped credential-delivery route with immediate server-side assignment/config/profile revalidation and no collector-selected profile ID.

No production/schema/key/task provisioning, Windows/DNS action or master merge is included.
