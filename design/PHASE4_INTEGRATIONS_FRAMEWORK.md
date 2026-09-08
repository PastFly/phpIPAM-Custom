# Phase 4 — Integrations framework

Status: design/implementation foundation.

## Product direction

phpIPAM Custom gets a dedicated top-level **Integrations** product area for external infrastructure sources. Configuration belongs under **Administration → Integrations**. Contextual data from connectors may later appear on Device/IP pages, but phpIPAM remains the authoritative IPAM database.

## Initial connector registry

The first shell exposes these connectors without storing credentials or creating database tables:

- VMware vCenter — first implementation target, read-only first;
- Microsoft Hyper-V — planned;
- Active Directory — planned;
- DNS — planned;
- DHCP — planned.

## Phase 4 foundation scope

1. Add a top-level Integrations route and navigation item.
2. Add a shared connector registry consumed by both user and administration views.
3. Add Administration → Integrations overview.
4. Keep all connector states non-secret and read-only in this foundation.
5. Add deterministic clean-install support and regression checks.
6. Do not add a database schema change in this phase.

## Security and permissions

- every page requires an authenticated phpIPAM session;
- Administration → Integrations additionally requires phpIPAM administrator access;
- no passwords, API tokens, private keys or bind secrets are written to Git or rendered by the foundation shell;
- connector-specific credentials/storage and connector-level authorization are deferred until the first concrete connector contract is implemented;
- external systems are observation sources unless a later design explicitly approves write-back.

## VMware/vCenter next step

Read-only first:

- define connection/configuration storage without secrets in Git;
- validate TLS/endpoint semantics;
- fetch Datacenters, Clusters, ESXi hosts, VMs, NIC/MAC/IP data;
- normalize external object identifiers;
- reconcile VMware-observed addresses/devices with phpIPAM without creating a second authoritative IP database;
- expose connector health, last successful sync and error state.

No production credential flow is included in the initial Integrations shell.
