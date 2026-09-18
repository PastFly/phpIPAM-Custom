# Microsoft DNS: configuration and credential profiles

Approved direction: design/MICROSOFT_DNS_V1.md revision 2 (PR67 cb83531ac5a122b978378e2568a5407b7e551eba).
Source base: development master 1f6298c3f0896ea6b7af7b6dc8d67cbfc5f9876d; not an exact production snapshot.

## This source increment

Implement connection/profile administration, not only Preview collection. No production or Windows action.

1. Write executable PHP contract tests for create/update/delete, redaction, invalid input, stale edits, shared-profile confirmation and all-or-nothing credential rotation. Run them red before implementing.
2. Add a separate DNS cipher backed by sodium authenticated encryption. Bind ciphertext to profile identity, username and secret version; validate a separate private keyring. Test tampering, wrong context/key, nonce uniqueness and private-file boundaries.
3. Add transactional configuration service plus repository contract. Connections are saved disabled. Profile rotation/revocation invalidates all consumers atomically. No stored-secret read/export or external collection action in this API.
4. Add a PDO MySQL/MariaDB repository and additive schema candidate. Use an InnoDB configuration mutex, optimistic revisions, parameterized SQL and bounded audit events. DDL never runs inside a browser request. Without a local PDO database driver, database integration remains explicitly unverified and must run in CI before deployment.
5. Add a transport-independent admin controller and Russian form component. Host authentication/CSRF and service construction are injected after checks; no route or navigation wiring is activated. Tests exercise forbidden method/session/admin/CSRF inputs, password actions and escaped HTML. Missing collector functions are shown unavailable, never fake-success.
6. Run PHP functional/security suites, syntax checks, HTML/JS checks and source-boundary checks. Commit one isolated source PR; record exact scope/results/limitations in Continuity. No master merge, SQL execution, key provisioning, VMware changes or live deployment.

## Explicit next integration work

Connect the component to the effective current phpIPAM admin surface and Database_PDO lifecycle with exact source/route regression coverage; execute MariaDB transaction/concurrency tests. Then implement collector pairing/control/async checks and real Windows PowerShell 5.1 tests. Source hostnames supplied by Owner are configuration values, never defaults baked into runtime code. Do not require these pilot details to implement the domain layer.
