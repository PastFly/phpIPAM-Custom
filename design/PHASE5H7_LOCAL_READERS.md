# Phase 5H.7 — local-only fast-domain readers

## Status

Source-only development stage after merged Phase5H.6 PR61. Production website/native readers still serve Phase5G.5. This stage does not authorize or perform a live reader cutover.

## Objective

Allow future native/browser VMware handlers to consume the already synchronized Phase5H fast domains locally, without a foreground vCenter login, PropertyCollector call, QueryPerf call, or any other provider/network work.

The first implementation is `IntegrationVmwareFastReader`: a read-only projection over the encrypted `roster`, `properties`, and `metrics` domains. It is intentionally not wired to any HTTP route yet.

## Membership boundary

`properties` and `metrics` have `authoritative_membership=false`. Retained last-good records inside those domains must never establish that an object currently exists.

Only the two-pass reconciled `roster.authoritative` state governs membership:

- `present` — the adapter may expose local properties/metrics for that object;
- `absent_confirmed` — retained history is hidden and cannot resurrect deleted membership;
- `unknown` — the adapter fails closed and exposes no object properties/metrics.

A partial fast batch therefore never implies deletion.

## Independent publication boundary

Fast domains are independently published. The reader does not claim cross-domain transactional consistency. It returns publication timestamps/ages separately for roster, properties, and metrics and preserves explicit per-field/per-series state.

Fresh values are age-bounded at read time. A stored `fresh` property or metric whose source timestamp exceeds the configured maximum age is projected as `stale`; the last-good value/points remain available with their state and age.

Unsupported/unavailable/null semantics are preserved. Missing or unsupported telemetry is never converted to zero.

## Read-only boundary

The adapter may only call `IntegrationVmwareFastStore::read()` for `roster`, `properties`, and `metrics`. It contains no vCenter transport/provider dependency and performs no publish/update/file/system mutation.

Static regression coverage rejects dependencies such as `IntegrationVmwareBatchSession`, `IntegrationVmwareVcenter`, `PropertyCollector`, `QueryPerf`, cURL/SOAP transport, credentials/endpoints, or fast-store writes.

## Current source acceptance

Initial local regression results:

- `PHASE5H7_FAST_READER_ASSERTIONS=26`
- `PHASE5H7_LOCAL_READER_BOUNDARY_ASSERTIONS=28`
- roster absence/unknown fail closed;
- confirmed absence hides retained history;
- age-based fresh-to-stale demotion is explicit;
- unsupported/unavailable/null semantics are retained;
- no foreground vCenter dependency is present.

## Next source step

Map this projection into one existing native Phase5G reader contract behind an explicit source-selection/rollback boundary, still without changing production. Extend route coverage incrementally after compatibility tests prove UI/API fidelity.

Any live installation or serving-path switch remains a separate production-impacting action and requires explicit Owner approval plus rollback.