# Phase 5H.4 — shadow-cycle planning before live deployment

This milestone prepares a non-serving shadow cycle for issue #42. The active 5G.5 child remains authoritative for the website. No production timer, receipt, route or encrypted dataset is changed by this source work.

## Known-roster shadow input

`IntegrationVmwareShadowPlanner::roster()` extracts an explicit VM/host/datastore roster from a complete already-authorized 5G publication. This is intentionally not new-object discovery and is not authoritative for deletions. It allows the first shadow benchmark to measure fast properties and metrics over the exact currently known object set without changing website behavior.

## Metric planning

The planner first uses a non-expired encrypted capability entry when available. Otherwise it may reuse a validated provider refresh rate and counter metadata from the existing authorized telemetry. Entities without a trustworthy interval/capability become `capability_due` for a later bounded provider/availability check instead of receiving a guessed interval. Explicit provider-not-realtime results become unsupported records and are never numeric zero.

The initial shadow fast path focuses on CPU/RAM aggregate metrics. Disk, guest NIC, folders, tags and authoritative discovery remain separate work. This keeps the first live measurement bounded and makes request/latency attribution clear.

## Partial metric publication

BatchPerformance results are normalized into Phase5H fast metric records. A partial response may refresh the series that actually returned while preserving older missing series as stale. This specifically allows fresh RAM to publish even if aggregate CPU is unavailable/missing. A not-returned entity keeps all prior series stale. Source sample timestamps are preserved.

## Shadow safety boundary

The eventual shadow worker will use a separately provisioned encrypted fast root and separate service/timer names. It must never write the active 5G.5 inventory/telemetry directories, feed native website readers, alter the connector, or change the active timer during measurement. It must be stoppable/removable without rollback of the live website.

First live shadow measurement should report only sanitized aggregates: roster counts, planned/due/unsupported counts, SOAP request count, wall duration, peak memory, property publication age, metric observed-age distribution, errors by allowlisted category and whether the active 5G.5 publication/timer remained unchanged. No object names/IDs, credentials, payloads or session tokens in logs.

The purpose is to establish whether the batched fast subset can actually complete within the requested 60–300 second window on the real vCenter before native readers are switched. Passing source tests is not freshness acceptance.
