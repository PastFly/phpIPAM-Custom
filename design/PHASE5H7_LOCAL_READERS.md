# Phase 5H.7 — local-only fast-domain readers

## Status

Source-only development stage after merged Phase5H.6 PR61. Production website/native readers still serve Phase5G.5. This stage does not authorize or perform a live reader cutover.

Draft PR #62 is intentionally non-serving. No Phase5H.7 class is loaded by a production HTTP route yet.

## Objective

Allow future native/browser VMware handlers to consume the already synchronized Phase5H fast domains locally, without a foreground vCenter login, PropertyCollector call, QueryPerf call, or any other provider/network work.

The implementation is split into two layers:

1. `IntegrationVmwareFastReader` — strict read-only projection over encrypted `roster`, `properties`, and `metrics` domains.
2. `IntegrationVmwareFastOverlayReader` — reversible compatibility wrapper over the existing Phase5G `query()` contract. The Phase5G record remains the rich source for UI fidelity; only explicitly supported fresh Phase5H VM fields may overlay it.

Neither layer is wired to a browser/native route yet.

## Membership boundary

`properties` and `metrics` have `authoritative_membership=false`. Retained last-good records inside those domains must never establish that an object currently exists.

Only a sufficiently recent two-pass reconciled `roster.authoritative` state may govern membership:

- `present` — the adapter may expose local properties/metrics for that object;
- `absent_confirmed` — retained history is hidden and cannot resurrect deleted membership;
- `unknown` — the adapter fails closed and exposes no object properties/metrics.

The authoritative roster publication is age-bounded. If the roster itself is older than the configured maximum (default 300 seconds), membership becomes `unknown` even if an older authoritative set exists. In particular, a stale old roster cannot hide a VM that may have reappeared since that roster was confirmed.

A partial fast batch therefore never implies deletion.

## Independent publication boundary

Fast domains are independently published. The reader does not claim cross-domain transactional consistency. It returns publication timestamps/ages separately for roster, properties, and metrics and preserves explicit per-field/per-series state.

Fresh values are age-bounded at read time. A stored `fresh` property or metric whose source timestamp exceeds the configured maximum age is projected as `stale`; the last-good value/points remain available with their state and age.

Unsupported/unavailable/null semantics are preserved. Missing or unsupported telemetry is never converted to zero.

## Read-only boundary

`IntegrationVmwareFastReader` may only call `IntegrationVmwareFastStore::read()` for `roster`, `properties`, and `metrics`. It contains no vCenter transport/provider dependency and performs no publish/update/file/system mutation.

Static regression coverage rejects dependencies such as `IntegrationVmwareBatchSession`, `IntegrationVmwareVcenter`, `PropertyCollector`, `QueryPerf`, cURL/SOAP transport, credentials/endpoints, or fast-store writes.

## Reversible Phase5G overlay boundary

`IntegrationVmwareFastOverlayReader` has the same `query()` call shape as `IntegrationVmwareInventoryReader` but receives the existing Phase5G reader through a callable. The base query executes first and remains authoritative for the rich saved card.

When the overlay is disabled, the exact Phase5G result is returned with no additional metadata. This is the source-level rollback path.

For the first compatibility slice, only `view=vm` is overlaid. All other views are exact pass-through until dedicated compatibility tests exist.

With a fresh `present` roster membership, only fresh, type-valid Phase5H VM fields are allowed to overlay the saved Phase5G card:

- `name` -> card/root VM name;
- `runtime.powerState` -> normalized saved power state;
- `runtime.host` -> host external id;
- `config.hardware.numCPU` -> CPU count;
- `config.hardware.memoryMB` -> memory MiB;
- `guest.ipAddress` -> primary IP when it is a valid IPv4/IPv6 address;
- `config.annotation` -> saved annotation section with its local collection timestamp.

Stale fast evidence never overwrites the Phase5G last-good value. Unknown or stale membership falls back to Phase5G. Fresh `absent_confirmed` membership returns the existing `mirror_object_not_found` contract. Malformed fast data degrades to the Phase5G card without leaking provider/source error text.

The wrapper preserves the rich Phase5G context, guest/network/tag fields and zero-vCenter-request/read-only metadata. It does not yet overlay fast metric series; performance compatibility must be derived from the existing retained renderer contract rather than guessed.

## Current source acceptance

Latest local regression results before route wiring:

- `PHASE5H7_FAST_READER_ASSERTIONS=30`
- `PHASE5H7_LOCAL_READER_BOUNDARY_ASSERTIONS=28`
- `PHASE5H7_FAST_OVERLAY_ASSERTIONS=19`
- `PHASE5H7_FAST_OVERLAY_BOUNDARY_ASSERTIONS=27`
- roster absence/unknown/stale fail closed;
- confirmed fresh absence hides retained history;
- age-based fresh-to-stale demotion is explicit;
- unsupported/unavailable/null semantics are retained;
- overlay disabled gives exact Phase5G rollback parity;
- fresh VM fields overlay without losing rich Phase5G context;
- unknown/stale roster falls back while fresh confirmed absence suppresses the old object;
- no foreground vCenter/provider or write dependency is present.

GitHub Actions on exact PR head `5918ebcbb77ef0808f39ea4147dd49954da50aa9` again hit the known repository no-runner condition: both PHP matrix jobs and the general regression job completed with `steps=null`, so no CI step actually executed.

## Next source step

Inspect the exact saved performance-section and renderer contract, then add fast-metric compatibility only when the expected sample/timestamp shape is proven. Keep the wrapper source-only and do not modify production `native.php` until query-contract parity is complete.

Any live installation or serving-path switch remains a separate production-impacting action and requires explicit Owner approval plus rollback.