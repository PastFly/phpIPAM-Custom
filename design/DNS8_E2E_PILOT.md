# DNS8 — guarded one-shot E2E pilot installer

Stacked on DNS7 PR74 exact head `c931cc7a0b9e320190d754b9fee47d0f416a2a54`.

## Purpose

Prepare the first real phpIPAM -> Windows collector -> Microsoft DNS -> phpIPAM one-shot pilot without Scheduled Task installation or autonomous polling.

The pilot uses `collector_identity`, so no domain password or DNS credential profile is stored in phpIPAM. The Windows operator runs the already-reviewed one-shot worker interactively under an identity that has read access to Microsoft DNS.

## Production mutation boundary

The installer is dry-run by default. `--apply` is a separate explicit production approval gate.

Apply is create-only and refuses:
- any existing `custom_dns_%` table;
- any existing target DNS machine route/class file;
- an existing pilot token file.

On apply it:
1. applies DNS1 -> DNS3 -> DNS4 -> DNS6 schema in that order;
2. installs only machine-control/machine-credential routes and their required classes;
3. lints every installed PHP file;
4. creates one disabled `collector_identity` connection for `srv-dc01.rbank.local`;
5. creates one collector, assignment and pending `test_connection` job;
6. writes the control token only to `/root/phpipam-dns-e2e-pilot-token` mode0600;
7. writes a receipt without the token secret.

The token is never printed by the installer.

## Pilot scope

Only one `test_connection` job is initially seeded. No list_zones job, selected-zone record collection, snapshot ingestion or persistent scheduler is installed.

The Windows worker remains one-shot:
`poll -> one-shot credential-mode response -> exact read-only Preview -> allowlisted result -> exit`.

For collector_identity, the credential endpoint returns only the mode and marks one-shot delivery; no password is decrypted or returned.

## Rollback

If apply fails before completion, the installer removes only exact files it created, drops only the newly created DNS schema and removes the pilot token file. Rollback refuses file drift.

A separate cleanup tool requires the exact receipt, verifies no non-pilot connection/collector/job/profile data exists and verifies every installed file hash before removing the pilot schema/files/token.

## Evidence

- MariaDB persistence V5 closed on DNS7 exact head.
- Real Windows DNS2 Preview accepted on `srv-dc01.rbank.local`.
- DNS8 source contract:27 assertions green.
- installer blob: `67177edcc2d1ea4ae006867342b7ec84ca15c171`.
- cleanup blob: `32166b185c67031c95bcca36098863d02ce4c788`.

No DNS8 production apply has occurred.
