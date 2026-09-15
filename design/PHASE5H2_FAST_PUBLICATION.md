# Phase 5H.2 — independent fast-domain publication

This source milestone continues issue #42. It does **not** modify the active 5G.5 worker, timer, receipt, encrypted child dataset, website routes, TLS or credentials.

## Problem

5G.5 publishes the main inventory only after a complete object sweep and membership recheck. A five-minute timer therefore does not imply five-minute data freshness. Phase 5H.1 reduces upstream request count for a selected fast subset, but a safe publication policy is still required before those results can overlay the complete snapshot.

## Fast-domain rules

`IntegrationVmwareFastDomain` is a pure merge policy with no transport or persistence side effects. It produces independently dated property and metric domains that can later be encrypted in their own `IntegrationVmwareInventoryStore` directory.

A batch is explicitly non-authoritative for membership. An object omitted from one batch, or returned as `unobserved`, is never interpreted as deletion. Existing local data remains available but is marked stale with the latest attempt result. Deletion requires a separate authoritative roster reconciliation step.

For scalar properties:

- `available` replaces the prior value and marks it fresh;
- `unavailable` is an explicit provider result and replaces the value with null;
- `not_reported` and `property_fault` preserve a previous value as stale when one exists, otherwise remain missing;
- per-field collected time is preserved; `source_observed_at` remains null until the upstream API provides a real observation/change time.

For metrics:

- only `ok`, `unsupported`, `partial`, and `not_returned` normalized states are accepted;
- `partial` and `not_returned` preserve previous series as stale rather than erasing them;
- source sample timestamps are retained per point and are the preferred age basis;
- unsupported results are explicit and never converted to numeric zero;
- CPU, RAM and disk series are bounded and independently representable.

The helper `age()` prefers the source observation timestamp and uses collection time only when no source timestamp exists. Worker heartbeat or timer time is never data age.

## Next integration

The next step is a binding-scoped encrypted fast store and bounded authoritative roster reconciliation. The existing BatchInventory/BatchPerformance output will be normalized and published independently from full reconciliation. Native readers will overlay only identity-compatible fresh fields onto the complete 5G.5 snapshot while keeping all permission/access-epoch checks and zero foreground vCenter calls.

A shadow worker must run beside 5G.5 before production switch. It must measure source-visible change to local-publication latency, API request count, oldest supported field age, memory and failure behavior with no browser open. Partial results must never imply deletion. Known access denial or connector binding changes fail closed.

Target acceptance remains approximately 60 seconds for dynamic fields and 60–300 seconds for supported configuration/guest data under healthy upstream conditions. These are targets, not achieved performance claims.
