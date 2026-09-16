# Phase 5H.7 — local-only fast-domain readers

## Status

Source-only development stage after merged Phase5H.6 PR61. Production website/native readers still serve Phase5G.5. This stage does not authorize or perform a live reader cutover.

Draft PR #62 is intentionally non-serving. No Phase5H.7 class is loaded by a production HTTP route yet.

## Objective

Allow future native/browser VMware handlers to consume the already synchronized Phase5H fast domains locally, without a foreground vCenter login, PropertyCollector call, QueryPerf call, or any other provider/network work.

The implementation is split into three layers:

1. `IntegrationVmwareFastReader` — strict read-only projection over encrypted `roster`, `properties`, and `metrics` domains.
2. `IntegrationVmwareFastMetricCompat` — pure compatibility projector from normalized Phase5H metric series to the retained Phase5G `performance` / `storage_performance` renderer section shapes.
3. `IntegrationVmwareFastOverlayReader` — reversible compatibility wrapper over the existing Phase5G `query()` contract. The Phase5G record remains the rich fallback for UI fidelity; only explicitly supported fresh Phase5H VM fields/metric sections may overlay it.

None of these layers is wired to a browser/native route yet.

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

`IntegrationVmwareFastMetricCompat` is a pure array projection. It receives only the already projected fast object and has no store, provider, transport, credential, clock, file or write dependency.

Static regression coverage rejects dependencies such as `IntegrationVmwareBatchSession`, `IntegrationVmwareVcenter`, `PropertyCollector`, `QueryPerf`, cURL/SOAP transport, credentials/endpoints, or fast-store writes.

## Renderer-compatible metric boundary

The retained Phase5G renderers consume two independent local sections:

- `performance` — CPU and RAM samples;
- `storage_performance` — aggregate disk throughput samples.

Phase5H stores the equivalent normalized series as `cpu_percent`, `memory_percent`, and `disk_kib_per_second`. `IntegrationVmwareFastMetricCompat` maps only **fresh** fast evidence into the retained section shapes.

CPU/RAM is deliberately all-or-nothing:

- both series must be fresh and valid;
- both series must have the same sample count and exactly matching `observed_at` timestamps;
- percent values must be finite and within 0..100 when present;
- malformed, stale, unsupported, partial or misaligned evidence does not replace the Phase5G saved `performance` section.

Disk is independent from CPU/RAM:

- a fresh valid disk series may overlay even when CPU/RAM falls back;
- null disk points are omitted exactly as in the retained storage collector and are never converted to zero;
- fewer than two usable disk samples yields the retained `supported=false` / `reason=insufficient_samples` shape.

Timestamp strings are derived directly from `observed_at` in UTC. A provider refresh-rate hint is inferred only when all adjacent sample timestamps are exactly uniform and the interval is within the known real-time provider bound of 1..300 seconds. Irregular or slower series keep `refresh_rate=null` and `interval_seconds=null`; the projector never invents provider cadence.

The projected section remains local-only and reports `verify_tls=true` because Phase5H shadow collection itself is gated on the strict-TLS runtime boundary; no TLS decision is made by the projector.

## Reversible Phase5G overlay boundary

`IntegrationVmwareFastOverlayReader` has the same `query()` call shape as `IntegrationVmwareInventoryReader` but receives the existing Phase5G reader through a callable. The base query executes first and remains the rich saved fallback.

When the overlay is disabled, the exact Phase5G result is returned with no additional metadata. This is the source-level rollback path.

For this compatibility slice, only `view=vm` is overlaid. All other views are exact pass-through until dedicated compatibility tests exist.

With a fresh `present` roster membership, only fresh, type-valid Phase5H VM fields are allowed to overlay the saved Phase5G card:

- `name` -> card/root VM name;
- `runtime.powerState` -> normalized saved power state;
- `runtime.host` -> host external id;
- `config.hardware.numCPU` -> CPU count;
- `config.hardware.memoryMB` -> memory MiB;
- `guest.ipAddress` -> primary IP when it is a valid IPv4/IPv6 address;
- `config.annotation` -> saved annotation section with its local collection timestamp;
- fresh compatible CPU/RAM -> `sections.performance`;
- fresh compatible disk series -> `sections.storage_performance`.

Stale fast evidence never overwrites the Phase5G last-good value/graph. Unknown or stale membership falls back to Phase5G. Fresh `absent_confirmed` membership returns the existing `mirror_object_not_found` contract. Malformed fast data degrades to the Phase5G card without leaking provider/source error text.

The wrapper preserves the rich Phase5G context, guest/network/tag fields and zero-vCenter-request/read-only metadata.

## Current source acceptance

Latest source-level regression contract after metric compatibility:

- `PHASE5H7_FAST_READER_ASSERTIONS=30`
- `PHASE5H7_LOCAL_READER_BOUNDARY_ASSERTIONS=28`
- `PHASE5H7_FAST_METRIC_COMPAT_ASSERTIONS=31`
- `PHASE5H7_FAST_METRIC_COMPAT_BOUNDARY_ASSERTIONS=41`
- `PHASE5H7_FAST_OVERLAY_ASSERTIONS=28`
- `PHASE5H7_FAST_OVERLAY_BOUNDARY_ASSERTIONS=30`
- PHP lint coverage is present for all three classes and their PHP contract tests;
- exact Phase5G rollback parity when the overlay is disabled;
- stale-roster absence falls back instead of hiding a possibly reappeared object;
- confirmed fresh absence hides retained history;
- age-based fresh-to-stale demotion is explicit;
- unsupported/unavailable/null semantics are retained;
- fresh CPU/RAM requires exact timestamp alignment;
- fresh disk can overlay independently;
- null disk telemetry is never zero-filled;
- irregular or >300 second sample cadence is never advertised as a real-time provider interval;
- no foreground vCenter/provider or write dependency is present.

GitHub Actions continues to show the repository runner/infrastructure condition where matrix/general jobs complete with `steps=null`; those runs execute no test step and are therefore not treated as source acceptance evidence.

## Next source step

Keep PR #62 as the self-contained source-only compatibility layer. The next change should be a separate, minimal native-reader integration slice that resolves the current concrete `IntegrationVmwareInventoryNative(IntegrationVmwareInventoryReader ...)` constructor boundary without enabling production serving by default. That slice must preserve an explicit Phase5G-only rollback path and receive route/renderer parity tests before any live installation is considered.

Any live installation or serving-path switch remains a separate production-impacting action and requires explicit Owner approval plus rollback.
