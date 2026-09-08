# Phase 5 — VMware vCenter read-only connector

Status: implementation started on `phase5/vmware-vcenter-readonly`.

## Goal

Add the first real external connector to the Phase 4 Integrations framework without weakening phpIPAM's authority over IPAM data and without introducing write-back to vCenter.

The first executable slice is an **administrator-only transient connection test**. It proves TLS/authentication/API compatibility and read-only inventory access before we design persistent credential storage or scheduled synchronization.

## Phase 5A — transient connection test

This slice intentionally makes **no database schema change** and stores **no endpoint, username, password, session token or inventory payload**.

Administration → Integrations receives a VMware vCenter test form with:

- vCenter base endpoint;
- username;
- password;
- TLS certificate verification toggle, enabled by default;
- explicit `Проверить подключение` action.

Security requirements:

- administrator session required;
- dedicated CSRF token required;
- HTTPS only;
- redirects disabled;
- no URL userinfo, query, fragment or non-root path;
- localhost and literal link-local/loopback targets rejected;
- connection and total request timeouts;
- password is submitted only to the test endpoint and never echoed back;
- password field is cleared in the browser after every test;
- vCenter session token is never returned to the browser;
- no raw vCenter response bodies are displayed;
- no phpIPAM object write and no vCenter infrastructure write-back.

### vCenter API negotiation

The connector tries the modern vSphere Automation API first:

1. `POST /api/session` with HTTP Basic authentication;
2. `GET /api/vcenter/datacenter` using `vmware-api-session-id`;
3. best-effort `DELETE /api/session`.

For older supported vCenter deployments, HTTP 404/405 on the modern session route falls back to:

1. `POST /rest/com/vmware/cis/session`;
2. `GET /rest/vcenter/datacenter`;
3. best-effort session delete.

A successful test reports only non-secret diagnostics: normalized endpoint, API family, visible Datacenter count, TLS verification mode and request timing.

## Phase 5B — persistent configuration contract

Persistent vCenter configuration is deliberately **not implemented in Phase 5A** because it requires an explicit database/schema and secret-management decision.

Recommended contract for the next slice:

- dedicated integration configuration table rather than overloading unrelated phpIPAM settings;
- endpoint, username, connector flags and health metadata stored separately from the secret;
- credential ciphertext stored in the database;
- encryption key stored outside the web root and outside Git, with restrictive filesystem permissions;
- no secret values in logs, audit text, browser HTML, GitHub Actions or Continuity;
- key rotation and credential replacement supported without exposing plaintext;
- scheduled sync reads the credential non-interactively, therefore phpIPAM's interactive Vault unlock session is not sufficient as the only credential source.

Any schema migration or live secret/key creation requires explicit Owner approval and rollback planning.

## Phase 5C — read-only inventory

After persistent configuration is approved and connection test is runtime verified:

- Datacenters;
- Clusters;
- ESXi hosts;
- Virtual Machines;
- VM/host NIC metadata;
- MAC observations;
- guest-observed IP addresses where exposed by vCenter permissions/tools;
- normalized external IDs for stable reconciliation.

phpIPAM remains authoritative. The connector first produces observations/reconciliation state; it does not automatically create, move or delete phpIPAM addresses/devices and does not write to vCenter.

## Test strategy

CI must not require a real vCenter. The connector client supports a test transport so executable regression tests can simulate:

- modern API success;
- legacy API fallback;
- authentication failure;
- insufficient inventory permission;
- TLS flag propagation;
- session cleanup;
- secret non-disclosure;
- endpoint validation.

Clean reproduction layers Phase 5A on top of the already reproducible Phase 4 build.
