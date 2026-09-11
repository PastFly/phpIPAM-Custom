# Phase 5F.3.5 — VM-card shared context, first optimization slice

The Owner reported approximately 2–3 seconds to open a VM card after the accepted c33 cache installation. This is subjective latency, not a benchmark. Existing cards reread the whole topology and datastore inventory before fetching one VM. This slice removes that redundant collection when a shared core snapshot is usable. It does not cache individual VM detail or all enrichment, and does not promise a particular latency.

## Behavior

A source-pinned additive endpoint reproduces the exact installed Phase 5F.2.4 guest-network fail-open card, then substitutes authorized shared-core context for the full infrastructure reads. All original source files are retained. Session/admin/POST/scalar/CSRF/enabled-connector checks precede snapshot access. The PHP session is released after authorization and stored connector resolution, before slow I/O. Saved endpoint/TLS/credentials remain authoritative.

Fresh or soft-stale core is reused, with visible snapshot age for placement/datastore correlation. A VM missing from the snapshot triggers at most one coalesced refresh. Access denial, hard expiry, cold-busy, backoff and incomplete data are not bypassed. Cache-disabled mode is explicitly direct. An existing VM may have moved since the snapshot; its placement is deliberately labelled snapshot context rather than current live host proof.

Individual detail, optional guest interfaces and requested tags are still read on demand. Existing Notes, corrected Guest OS/RAM, network path and graphs stay separate and unmodified. No per-user HTML or individual VM detail is persisted. Cache persistence refers only to the existing encrypted core domain. Timings distinguish core/detail/guest/tags/total in a no-store Server-Timing header; cache state/age/write metadata is exposed without keys, credentials, provider text or inventory in logs.

## Browser compatibility

Existing listeners inspect the original full vm-card-result.php URL. A same-origin/application-base exact-path transport keeps this URL and one normal completion event visible to every listener, while an internal global:false request calls the additive endpoint. Cancellation and latest-request-wins remain active. Only an explicit cold-refresh-busy response is retried, bounded to eight attempts under the outer timeout. No automatic retry after denial and no extra business data in browser storage.

## Deployment and rollback

Production stays on c33 until a separately approved deployment. The reviewed installer installs three runtime files and prefixes two existing cache index hooks with one exact new hook. New dependencies are installed before hooks. Every c33 runtime/tool/installer source is protected byte-for-byte. Shared key/config/TTL/provider files are not changed or re-created. Source/metadata preflight performs no writes, key-content reads or vCenter requests; it intentionally does not run the parent's writing CLI crypto self-test. The installed verifier retains that test.

Backups and receipt stay root-only outside webroot. Automatic/manual rollback refuses foreign/newer contents and restores indexes before removing the three new files. It leaves the installed Phase 5F.3.4 cache enabled and unchanged. Original cache rollback must not be used blindly while a successor is installed.

## Validation scope

Fixtures cover cold/warm/missing/expired/denied/disabled/partial context, real cache engine/service, generated endpoint guards, session release, escaped rendering and optional networking, retained completion listeners and aborts, exact source generation, temporary install/verify/rollback/drift/symlink refusal. Disposable deployment mechanics stub inherited live DB/private verification and are not production proof. Live browser measurements, web-SAPI confirmation and cache hits remain pending.

This is the first slice of issue #28. A separate bounded per-VM snapshot and progressive optional-enrichment rendering remain future work if actual timings justify them. Master consolidation is source management, not production deployment or a byte-for-byte rebuild claim.
