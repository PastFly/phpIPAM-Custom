# Phase 5H.4 — non-serving shadow cycle before live deployment

This milestone implements a non-serving shadow cycle for issue #42. The active 5G.5 child remains authoritative for the website. No production timer, receipt, route or encrypted dataset is changed by this source work.

## Known-roster shadow input

`IntegrationVmwareShadowPlanner::roster()` extracts an explicit VM/host/datastore roster from a complete already-authorized 5G publication. This is intentionally not new-object discovery and is not authoritative for deletions. It allows the first shadow benchmark to measure fast properties and metrics over the exact currently known object set without changing website behavior.

## Metric planning

The planner first uses a non-expired encrypted capability entry when available. Otherwise it may reuse a validated provider refresh rate and counter metadata from the existing authorized telemetry. Entities without a trustworthy interval/capability become `capability_due` instead of receiving a guessed interval. `IntegrationVmwareShadowCycle` revalidates only a bounded number of those entries per pass. Explicit provider-not-realtime results become unsupported records and are never numeric zero.

The initial shadow fast path focuses on CPU/RAM aggregate metrics. Disk, guest NIC, folders, tags and authoritative discovery remain separate work. This keeps the first live measurement bounded and makes request/latency attribution clear.

## Partial metric publication

BatchPerformance results are normalized into Phase5H fast metric records. A partial response may refresh the series that actually returned while preserving older missing series as stale. This specifically allows fresh RAM to publish even if aggregate CPU is unavailable/missing. A not-returned entity keeps all prior series stale. Source sample timestamps are preserved.

## Implemented cycle and authorization boundary

`IntegrationVmwareShadowCycle::run()` performs one bounded shared SOAP session. Inside that session it reads the property batches, performs bounded capability revalidation, and reads multi-entity metrics. No fast-domain write occurs inside the session. Only after `BatchSession::run()` has completed Logout and its final connector-binding guard does the cycle recheck authorization and atomically publish the independent properties/metrics/capabilities domains.

The cycle returns sanitized operational counters only: roster counts, property-object count, metric-plan count, capability revalidation/due counts, unsupported count, SOAP request/response-byte totals, wall time and PHP peak memory. It explicitly reports `website_served_from_shadow=false`.

Controlled CI runs use a real encrypted FastStore and real DOM SOAP parsing. They verify that a complete small pass populates properties and metrics, and that binding denial prevents any publication into a fresh shadow store.

## One-shot live benchmark package

`tools/install-phase5h4-shadow.py` is create-only. Before provisioning anything it verifies the existing 5G.5 recovery receipt is still `active` and the normal 5G.5 timer is `active/waiting/enabled`. It then copies only the reviewed shadow runner/classes into `/opt/phpipam-custom/vmware-fast-shadow` and provisions `/var/lib/phpipam-custom/vmware-fast-shadow/{properties,metrics,capabilities,roster}` for UID/GID 33. It creates a separate receipt under `/var/backups/phpipam-custom`.

The installer does not write `/var/www/html`, does not create/enable/start/stop systemd units, and does not mutate the connector, database, TLS trust, active 5G.5 release, active inventory or active telemetry. A second install is rejected rather than overwriting the first benchmark state.

`tools/phase5h4-shadow-run.php` is a one-shot CLI executed as `www-data`. It reads the current authorized 5G.5 inventory and telemetry, opens one bounded batch session to vCenter, and writes only the separate fast-shadow encrypted domains. The result is a sanitized `PHASE5H_SHADOW_RESULT` containing wall time, request/response counts, peak memory and aggregate roster/plan/capability counts. It re-reads the active publication afterwards and reports whether that active generation remained unchanged. The website never reads the shadow root during this benchmark.

The package deliberately has no recurring timer yet. First obtain one real pass. If one pass is safely within the target budget and load is acceptable, recurring shadow cadence can be tested separately before any native reader or production collector switch.

## Shadow safety boundary

A live shadow deployment must use a separately provisioned encrypted fast root and must never write the active 5G.5 inventory/telemetry directories, feed native website readers, alter the connector, or change the active timer during measurement. The create-only benchmark package follows this boundary and can remain on disk as measurement evidence without affecting the website.

First live shadow measurement should report only sanitized aggregates: roster counts, planned/due/unsupported counts, SOAP request count, wall duration, peak memory, response bytes and whether the active 5G.5 publication remained unchanged. No object names/IDs, credentials, payloads or session tokens belong in logs.

The purpose is to establish whether the batched fast subset can actually complete within the requested 60–300 second window on the real vCenter before native readers are switched. Passing source tests is not freshness acceptance.
