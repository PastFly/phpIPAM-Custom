# Phase 5G.3 — independent metrics, status and measured synthetic sizing

Base: merged native bridge `35f32c5c8b110aa30ca3f341511a955a38ea6a0b` (PR32).
Issue28 remains open. This is source for a single future guarded rollout, not an activation.
Production remains recovered c33/5F34 with strict TLS; rejected5F35 must not be reinstalled.

## Implemented

A separate telemetry state machine queries the existing CPU/RAM and disk PerformanceManager readers. Only a CLI provider imports these collectors. Normal inventory and native HTTP readers join encrypted per-entity metric shards. No browser request initiates collection, including initial, stale and forced refresh. The saved native SVG renderers remain unchanged. Unsupported counters are not replaced with zero values.

Each section has its own due time, bounded retry and timestamp. Temporary failure keeps its previously sampled data explicitly stale. The latest sample's provider timestamp determines graph freshness, not the time an old series was downloaded. Collection time is retained separately. A missing section remains unavailable and never triggers a live fallback. The fixed history bound is60 samples, matching the previous default; this is not unlimited metric history or a claim of near-real-time refresh for the whole estate.

The telemetry provider enforces one normalized configured /sdk URL, strict TLS, no proxy via the existing direct transport, exactly one allowed SOAP operation and read-only operation names. Existing legacy RetrieveProperties counter discovery is retained. No numeric performance-counter ID is hardcoded. Each section has at most16 requests and a45second aggregate transport budget; request timeouts are clamped to the remaining budget. PHP's between-call/deadline checks do not replace a supervisor's process termination bound. Known HTTP/SOAP denial is sticky even if a retained reader suppresses Logout errors.

Inventory and telemetry use the same connector writer lock, preventing simultaneous upstream collection and conflicting denial publication. A metric denial blocks the inventory, discards unfinished pre-denial collection, and rotates an access epoch. Completing a fresh full inventory preserves that epoch; metric files from before the denial cannot reappear as fresh data. Disabled or changed bindings reject reads and collection. This does not add an independent remote authorization check to every HTTP read.

Telemetry files use a separate key-derivation domain, connector binding and typed vm/host IDs. Their root must be provisioned explicitly as a0700 runtime-owned directory. Each shard is bounded to256KiB, the control file to1MiB, the roster to2000entities. Reads never create paths or locks. Writer cleanup retires only typed metric directories absent from a completed inventory and refuses foreign files, symlinks or hardlinks. The original inventory ciphertext format/key/config are not replaced by this source change.

`tools/vmware-inventory-cycle.php` coordinates up to30 inventory units with a30second between-unit budget, then up to20 metric sections with a25second budget. A120second signal deadline covers the whole invocation; after interruption it does not start the next stage. The dormant service points to this combined CLI and retains the135second start/10second stop limits, www-data identity,512MiB MemoryMax and filesystem sandbox. These are provisional limits, not measured production cycle durations. No unit/config/directory is installed or enabled by this PR.

## Status interface

A new scoped JS module polls ONLY the local status API every30seconds while visible. It displays initial progress, last publication, known error/backoff, missing worker heartbeat and latest metric receipt. Status queries now carry stale/clock/heartbeat semantics even before data exists; previously the early status return omitted them. An untouched initially empty workspace reloads locally once after first publication; later publications do not redraw selected cards. Hidden/unloaded pages stop requests, connector changes cancel old responses, and access denial stops periodic polling. Display values use text nodes, not provider HTML. No browser storage or upstream refresh endpoint is used.

The module is prepared but not hooked into production indexes. The future installer must add its hook together with the native route switch; it must not pretend the existing5F34 page already uses this UI.

## Verification and interpretation

Local checks:52 telemetry/storage/reader assertions, the56 retained core assertions,34 source pins, endpoint denial ordering, adapter tests and existing snapshot concurrency/service tests passed. The actual new metric-provider test uses retained SOAP parsers with controlled transport, including a cleanup denial; it requires DOM and is run in CI, not claimed locally because local PHP lacks DOM/cURL. The JS DOM test likewise runs in CI; local npm dependency retrieval failed. Syntax checks ran locally. Final CI status belongs to the published exact commit receipt, not this pre-publication description.

Synthetic sizing is reproducible through `tests/phase5g3-sizing.test.py`:640 and1000 VM records,64 hosts,70 datastores,4 disks/2 NICs per VM, approximately3.8KiB Notes per VM and separate60-point metric shards. Four independent PHP reader processes perform48 joined reads per case without provider classes or file changes. They are launched under a256MiB PHP limit. Actual local output is retained in `design/PHASE5G3_SYNTHETIC_SIZING.json`.

The640-case envelope is about4MB and readers peak at about31.4MiB PHP allocation;1000-case envelope about6.2MB and43.8MiB. Observed medians are roughly30–71ms on the assistant fixture host. Those values exclude HTML/network/authentication and are NOT production phpIPAM latency. Filesystem-cache state was uncontrolled. PHP allocator peaks and OS process high-water values are different measures, neither is an aggregate simultaneous-site memory measurement. This does not validate every possible32MiB envelope or worst-case nested payload. Real payload size, total first-fill duration, all REST/SOAP readers and concurrency still require controlled runtime acceptance.

## Preservation and remaining rollout work

Only four named undeployed5G.1 files evolve: Context/Reader/Worker and the additive read API. The native controller joins telemetry. The native CI preservation test now permits precisely these modifications and still rejects other ancestor mutation/deletion/rename; a separate exact c33 ancestor check stays strict. Every previously deployed layer/tool/installer byte remains protected. All original core/native/HTTP/browser tests stay enabled.

Remaining: one source-pinned installer with safe private provisioning and immutable worker release, staged initial inventory/metrics collection, validation, all-route/index switch and complete guarded rollback. Do not enable the service manually, run another old TLS survey or request production approval for a partial setup. The real initial-population duration and readiness criterion must be explicit; an installed file or green fixture is not a working ready-before-visit site. Issue28 remains open until no-viewer/restart/outage/denial/all-panel acceptance is demonstrated.

Primary primitive references: Broadcom PerfQuerySpec/PerfProviderSummary (maxSample with provider refreshRate); PHP memory_get_peak_usage and fsync documentation. These references do not establish live compatibility or timings for this environment.
