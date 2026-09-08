# Phase 5B.3 — Live secure VMware vCenter configuration

Status: implementation branch only. No live production mutation is authorized by this document.

Base master: `3dac8b2d262210898d0a0868c732c51b98a9b719` (Phase 5B.1 squash merge result).

## Goal

Deploy the already-reviewed Phase 5B secure configuration foundation to the live phpIPAM instance in a fail-closed way, then expose Administration UI for persistent vCenter connector configuration without storing plaintext credentials.

## Production mutation boundary

A future Owner-approved installer may perform only the following live changes:

1. create the `custom_integrations` table using the reviewed Phase 5B migration;
2. create `/etc/phpipam-custom/integrations.key` outside the web root;
3. set keyring ownership/permissions for the actual runtime identity `www-data` with restrictive mode;
4. deploy the Phase 5B encryption/config-store classes and Administration save/edit/test endpoints;
5. verify schema, keyring readability, PHP syntax, secret round-trip and live UI behavior.

The installer must not modify Rackspace, phpIPAM `settings.dbversion`, existing phpIPAM tables, Device/IP authoritative data, or vCenter state.

## Keyring policy

- path: `/etc/phpipam-custom/integrations.key`;
- generated locally on the phpIPAM host with `random_bytes(32)` / cryptographically secure randomness;
- JSON keyring with versioned `current_key_id` and hexadecimal 256-bit key material;
- never printed to stdout/stderr;
- never passed in CLI arguments;
- never committed to Git or Continuity;
- regular file only, no symlink;
- target owner/group and mode must be derived from the runtime preflight, not guessed;
- current runtime identity observed by Phase 5B.2: `www-data`;
- intended mode: `0640` or stricter, subject to final installer ownership model.

## Schema safety

- preflight must confirm `custom_integrations` is absent before first migration;
- forward migration creates only `custom_integrations`;
- backup/rollback metadata must be prepared before schema or filesystem mutation;
- rollback may drop the table only while it remains empty;
- once connector data exists, destructive schema rollback must refuse and require explicit export/backup review.

## Administration UI contract

`Administration → Integrations → VMware vCenter` will support multiple connector instances with:

- connector name;
- HTTPS endpoint;
- username;
- password only for create/replace actions;
- TLS verification enabled by default;
- enabled/disabled state;
- connection test using entered password or stored encrypted secret;
- sanitized health and timestamps;
- explicit credential replace/delete operations.

The password field is never pre-populated. Blank password on metadata edit retains the existing credential.

Persistent TLS disable requires an explicit warning/acknowledgement and must remain visibly marked insecure.

## Deployment gates

Before any live mutation:

- exact branch head must be pinned;
- CI must be green on that exact head;
- live read-only preflight must still report Phase 5A exact source, Rackspace sentinel, DB version 46, absent `custom_integrations`, absent/safe keyring path, OpenSSL AES-256-GCM, cURL, system CA bundle and runtime identity;
- production installer and rollback regression must pass in disposable CI;
- explicit Owner approval is required.

## TLS follow-up

The transient vCenter API path is runtime verified, but the successful test used TLS verification disabled. Persistent/scheduled operation should trust the internal vCenter issuing CA on the phpIPAM host and verify the connection with TLS verification enabled before enabling scheduled synchronization.

## Next implementation slice

1. state-specific live installer + verifier + rollback test;
2. Administration persistent connector CRUD/test UI;
3. exact live preflight refresh;
4. Owner approval;
5. production migration/key creation/deployment;
6. runtime verification with a dedicated least-privilege vCenter service account and TLS verification enabled.
