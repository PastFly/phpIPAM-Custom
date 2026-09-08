# Phase 5B — VMware vCenter secure persistent configuration

Status: design gate only. No database schema or production change is authorized by this document.

Base master: `a4e0aeda075b2dbf8ecffc85ccf09405ade16c7d` (runtime-verified Phase 5A squash result).

## Goal

Persist one or more VMware vCenter connector configurations so phpIPAM can perform non-interactive read-only inventory synchronization without storing plaintext credentials and without turning VMware into an authoritative IPAM source.

Phase 5B does **not** authorize inventory write-back to vCenter or automatic phpIPAM Device/IP mutation.

## Recommended storage model

Use a dedicated custom table rather than phpIPAM generic settings. The schema should support multiple vCenter instances from the first version.

Proposed logical fields:

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

The password must never be stored in plaintext, phpIPAM settings, Git, Continuity, browser HTML, logs or scheduler arguments.

## Encryption contract

Recommended implementation: AES-256-GCM through PHP OpenSSL.

- random 256-bit master key;
- key stored outside the web root and outside Git, recommended path `/etc/phpipam-custom/integrations.key`;
- file owned by root and readable only by the phpIPAM/PHP runtime account or group;
- recommended mode `0640` or stricter;
- random 96-bit nonce for every encryption;
- authentication tag stored with the ciphertext envelope;
- envelope contains version, key id, nonce, tag and ciphertext;
- decryption fails closed on malformed envelope, wrong key or tag verification failure.

The key must not be stored in the database beside ciphertext. This protects database dumps/backups from directly exposing the vCenter password. It does not claim to protect credentials from a fully compromised phpIPAM host.

Before implementation, live preflight must confirm the PHP OpenSSL extension and identify the actual PHP-FPM/Apache runtime account so permissions are not guessed.

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
- sanitized connector health and last-success timestamp.

After save/test, the password field is cleared. Editing a connector never pre-populates the existing password.

A blank password on ordinary metadata edit means **keep the existing encrypted credential**, not erase it. Credential deletion/replacement must be an explicit action.

## TLS policy

Production target is TLS verification enabled.

The successful Phase 5A runtime test used certificate verification disabled, so Phase 5B should not silently normalize that into the persistent configuration.

Recommended operational path for the internal vCenter CA:

- install the issuing/root CA into the phpIPAM server trust store, or provide an explicitly managed CA bundle outside the web root;
- verify connection with `verify_tls=true`;
- only retain an insecure override if the Owner explicitly chooses it, and render a persistent warning.

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

## Migration and rollback gate

No schema migration is implemented yet.

Before any live migration:

1. inspect current phpIPAM DB version/migration conventions;
2. choose the exact table name and indexes;
3. create a forward migration and a separately reviewed rollback plan;
4. backup schema/data affected by the migration;
5. create the external key file with verified owner/mode;
6. verify encrypt/decrypt round-trip without logging plaintext;
7. run automated migration/reproduction tests;
8. obtain explicit Owner approval for the DB/schema and key-file production mutation.

Rollback must never destroy a non-empty connector table implicitly. Schema rollback after real connector data exists requires an export/backup decision first.

## Proposed next implementation slice

After Owner approval of this contract:

- Phase 5B.1: repository-only storage/encryption classes + migration scripts + regression tests using disposable DB/test fixtures;
- Phase 5B.2: live read-only DB/runtime preflight;
- explicit Owner approval;
- Phase 5B.3: production migration + key creation + Administration save/test UI;
- runtime test with a dedicated vCenter service account and TLS verification enabled.

Only after Phase 5B is runtime verified should Phase 5C scheduled inventory/reconciliation begin.
