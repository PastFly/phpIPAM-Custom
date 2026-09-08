# Phase 5B — VMware vCenter secure persistent configuration

Status: Owner-approved contract; Phase 5B.1 repository implementation is in progress. No live database schema change, key creation, persistent credential write, or production mutation is authorized by this document.

Base master: `a4e0aeda075b2dbf8ecffc85ccf09405ade16c7d` (runtime-verified Phase 5A squash result).

## Goal

Persist one or more VMware vCenter connector configurations so phpIPAM can perform non-interactive read-only inventory synchronization without storing plaintext credentials and without turning VMware into an authoritative IPAM source.

Phase 5B does **not** authorize inventory write-back to vCenter or automatic phpIPAM Device/IP mutation.

## Recommended storage model

Use a dedicated custom table rather than phpIPAM generic settings. The schema supports multiple vCenter instances from the first version.

Phase 5B.1 repository schema is `custom_integrations` with the following logical fields:

- `id` — connector instance id;
- `provider` — fixed `vmware_vcenter` for this phase;
- `name` — human-readable connector name;
- `enabled` — scheduler eligibility;
- `endpoint` — normalized HTTPS base URL;
- `username` — service account name;
- `verify_tls` — TLS verification flag, default true;
- `secret_envelope` — encrypted credential envelope only;
- `secret_key_id` — key version identifier;
- `created_by`, `updated_by` — phpIPAM user ids;
- `created_at`, `updated_at`;
- `last_test_at`;
- `last_success_at`;
- `last_error_at`;
- `last_error_code` — sanitized category, never raw credentials or response body.

The table has a unique `(provider, endpoint)` index and does not alter phpIPAM `settings.dbversion`.

The password must never be stored in plaintext, phpIPAM settings, Git, Continuity, browser HTML, logs or scheduler arguments.

## Encryption contract

Implementation: AES-256-GCM through PHP OpenSSL.

- random 256-bit master key;
- key stored outside the web root and outside Git, recommended path `/etc/phpipam-custom/integrations.key`;
- file must be a regular file, not a symlink;
- recommended mode `0600` or `0640` (stricter read-only equivalents are accepted by the loader);
- random 96-bit nonce for every encryption;
- 128-bit authentication tag;
- envelope contains version, algorithm, key id, nonce, tag and ciphertext;
- nonce/tag/ciphertext are represented as hexadecimal text rather than base64;
- AAD binds the envelope to the integration/provider context;
- decryption fails closed on malformed envelope, wrong key, changed context or tag verification failure;
- PHP stat cache is explicitly refreshed before permission checks so runtime mode changes are observed immediately.

The key must not be stored in the database beside ciphertext. This protects database dumps/backups from directly exposing the vCenter password. It does not claim to protect credentials from a fully compromised phpIPAM host.

Before live implementation, the read-only preflight must confirm the PHP OpenSSL extension and identify the actual PHP-FPM/Apache runtime account so permissions are not guessed.

## Key rotation

The envelope carries `secret_key_id` from the first implementation.

Rotation flow:

1. create a new key file/version outside Git;
2. mark the new key as current;
3. decrypt each existing secret with its old key;
4. immediately re-encrypt with the new key;
5. update `secret_key_id` transactionally;
6. retain old key only for a bounded rollback window;
7. remove old key after successful validation and backup policy review.

Never print decrypted values during rotation.

The repository layer already exposes explicit rotation detection and re-encryption helpers; live rotation tooling is not implemented or authorized yet.

## Administration UI

`Administration → Integrations → VMware vCenter` should support:

- connector name;
- endpoint;
- username;
- password field used only to create/replace the stored credential;
- TLS verification toggle, default enabled;
- enabled/disabled state;
- `Test connection` using the currently entered password or stored secret;
- `Save configuration`;
- explicit `Replace credential` semantics;
- explicit credential deletion semantics;
- sanitized connector health and last-success timestamp.

After save/test, the password field is cleared. Editing a connector never pre-populates the existing password.

A blank password on ordinary metadata edit means **keep the existing encrypted credential**, not erase it. Credential deletion/replacement must be an explicit action. Deleting the credential disables the connector so a scheduler cannot attempt to run it without authentication material.

The repository configuration store enforces these semantics but no live Administration save/edit UI is installed yet.

## TLS policy

Production target is TLS verification enabled.

The successful Phase 5A runtime test used certificate verification disabled, so Phase 5B does not silently normalize that into the persistent configuration.

Recommended operational path for the internal vCenter CA:

- install the issuing/root CA into the phpIPAM server trust store, or provide an explicitly managed CA bundle outside the web root;
- verify connection with `verify_tls=true`;
- only retain an insecure override if the Owner explicitly chooses it, and render a persistent warning.

The repository store refuses to persist `verify_tls=false` unless the caller supplies an explicit insecure-TLS acknowledgement.

## Service account

Use a dedicated least-privilege vCenter service account for scheduled synchronization rather than a personal administrator account.

Required privileges should be limited to read-only inventory required for Datacenters, Clusters, ESXi hosts, VMs, NIC/MAC/IP observations and related metadata. No vCenter mutation privilege is required.

## Scheduled sync boundary

A later Phase 5C worker may read the encrypted credential non-interactively using the external key file.

Rules:

- no password in CLI arguments;
- no password in environment variables when avoidable;
- no session token in logs;
- one connector failure does not abort all connectors;
- connection timeout and response size limits retained from Phase 5A;
- vCenter session is closed best-effort;
- synchronization initially writes only connector observation/cache data, not authoritative phpIPAM Device/IP state.

## Audit requirements

Audit events may record:

- connector created/updated/enabled/disabled;
- endpoint or username changed;
- TLS policy changed;
- credential created/replaced/deleted;
- connection test succeeded/failed using sanitized error category;
- sync succeeded/failed with counts/timing.

Audit must never contain password, decrypted credential, Authorization header, session token, encryption key, raw API response body or secret envelope.

The repository store only persists an allowlisted error category. Arbitrary exception/error text is reduced to `unknown` rather than written to connector state.

## Migration and rollback gate

Phase 5B.1 now contains repository-only forward and rollback SQL. These scripts have **not** been executed against live phpIPAM.

Forward migration:

- creates `custom_integrations` only;
- uses InnoDB / utf8mb4;
- does not bump phpIPAM `settings.dbversion`;
- defaults `verify_tls=1` and `enabled=0`;
- supports multiple providers/connectors while Phase 5B code currently permits only `vmware_vcenter`.

Rollback is deliberately fail closed:

- absent table: harmless;
- empty custom table: may be dropped;
- non-empty table: raises SQLSTATE `45000` and refuses destructive rollback;
- a retry first removes any stale helper procedure left by a previous guarded failure.

Before any live migration:

1. run `tools/live-vmware-phase5b-preflight.sh` read-only;
2. verify current Phase 5A live hashes/markers and Rackspace sentinel;
3. verify OpenSSL AES-256-GCM and cURL support;
4. identify actual web runtime user/group;
5. read-only query DB version and confirm `custom_integrations` is absent;
6. confirm keyring path state without reading key contents;
7. review internal CA/trust-store readiness;
8. backup schema/data affected by the migration;
9. create the external key file with verified owner/mode;
10. verify encrypt/decrypt round-trip without logging plaintext;
11. obtain explicit Owner approval for the DB/schema and key-file production mutation.

Rollback must never destroy a non-empty connector table implicitly. Schema rollback after real connector data exists requires an export/backup decision first.

## Phase 5B.1 repository verification

Automated coverage includes:

- AES-GCM round-trip;
- plaintext absence from envelope;
- AAD/context mismatch rejection;
- tamper/tag failure;
- key-id validation and rotation;
- keyring permission enforcement including stat-cache refresh;
- metadata create/update while blank password retains the credential;
- explicit credential replacement/deletion;
- duplicate endpoint rejection;
- cannot enable without a credential;
- explicit insecure-TLS acknowledgement;
- sanitized failure categories;
- secret-bearing DB reads bypass phpIPAM HTML escaping using `no_html_escape`;
- disposable MariaDB forward migration;
- schema defaults/indexes;
- non-empty rollback refusal;
- empty-table rollback and safe retry.

Existing rack, Integrations and Phase 5A reproduction gates remain mandatory.

## Proposed next implementation slice

- Phase 5B.1: repository-only storage/encryption classes + migration scripts + regression tests — implemented and CI-gated;
- Phase 5B.2: live read-only DB/runtime preflight — tool prepared, Owner execution next;
- explicit Owner approval after preflight;
- Phase 5B.3: production migration + key creation + Administration save/test UI;
- runtime test with a dedicated vCenter service account and TLS verification enabled.

Only after Phase 5B is runtime verified should Phase 5C scheduled inventory/reconciliation begin.
