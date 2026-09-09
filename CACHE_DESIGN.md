# Phase 5F.3.4 — VMware shared snapshot cache

## Goal

Reduce repeated 1–3 second vCenter inventory waits while preserving the existing read-only safety model.

The cache is **not** a source of truth and must never create or modify phpIPAM/vCenter inventory. vCenter stable IDs remain authoritative.

## Scope

Initial cache domains:

1. `core` — topology + datastore projection used by the main workspace;
2. `folders` — authoritative HOST Folder inventory used by Phase 5F.2.5/5F.2.6;
3. `search` — Phase 5F.3.3 enriched VM search facts + Tags/Categories.

Realtime CPU/RAM/Disk samples, VM card details and VM physical network-path evidence remain on-demand and are **not** cached in this phase.

## Storage

Preferred runtime root:

`/var/cache/phpipam-custom/vmware/connector-<id>/`

Files:

- `core-v1.json`
- `folders-v1.json`
- `search-v1.json`
- `refresh.lock`

No connector password, session token, cookie or other secret may be written to cache.

Runtime permissions target:

- directory: `0750`
- snapshot files: `0640`
- lock file: `0640`
- owned by the php-fpm/web runtime group selected during installer preflight.

All writes must use temporary files + atomic rename. Concurrent refreshes must be serialized with `flock()` so multiple browsers/users cannot create a vCenter request stampede.

## Cache metadata

Every snapshot must contain bounded metadata:

- `schema_version`
- `connector_id`
- non-secret connector identity fingerprint (SHA-256 of normalized endpoint + connector id; endpoint itself need not be persisted)
- `created_at`
- `expires_at`
- `hard_expires_at`
- source/result counts
- payload hash
- safety flags: `persistent_inventory_written=false`, `phpipam_objects_modified=false`, `vcenter_objects_modified=false`

A connector endpoint/TLS/config identity mismatch invalidates the snapshot.

## TTL model

Initial proposed values:

| Snapshot | Soft TTL | Hard TTL |
|---|---:|---:|
| core topology/datastores | 60 s | 10 min |
| HOST folders | 5 min | 30 min |
| enriched search | 5 min | 30 min |

`soft TTL` means stale-while-revalidate is allowed. `hard TTL` means the snapshot must not be served without a successful refresh unless the UI explicitly enters a degraded/fail-open state.

## Request behavior

### Fresh snapshot

Return cache immediately. No vCenter request.

### Soft-stale snapshot

Return cached data immediately with cache-age metadata. Trigger one background refresh from the active authenticated VMware page. The refresh request acquires the connector lock; other callers do not duplicate the refresh.

The already-rendered page must not block on the refresh and need not redraw automatically.

### Missing / hard-stale snapshot

Perform one synchronous bounded refresh, write the new snapshot atomically, then return it. This preserves correctness after cold start while subsequent requests become fast.

### Manual Refresh button

May request `force_refresh=1`, bypassing the soft TTL while still honoring the connector lock and all read-only safety limits.

## Periodic refresh under current TLS state

The saved production connector is currently `TLS-INSECURE`. Existing project policy forbids unattended scheduler/systemd/cron refresh while TLS verification is disabled.

Therefore Phase 5F.3.4 must **not** install cron/systemd timers now.

Instead, while an authenticated administrator has the VMware workspace open, the browser may request a cache-refresh endpoint at a bounded interval (proposed 60 s). This is cache maintenance only: it must not repeatedly reload/redraw the whole Explorer. The server globally coalesces refreshes using TTL + `flock`, so multiple open browsers still produce at most one refresh per connector interval.

When internal CA trust is fixed and saved connector TLS becomes VERIFY, a later optional phase may add an unattended systemd timer.

## Performance and load rules

- No per-keystroke vCenter calls.
- No periodic VM detail/performance/network-path polling.
- Core inventory refresh should use the existing bounded topology/datastore collectors.
- Folder refresh should reuse the authoritative stable-ID Folder collector.
- Search refresh should reuse Phase 5F.3.3 bulk `RetrieveProperties` batches and bulk tag association path already proven against 634 VMs.
- Refreshes must be globally coalesced per connector.
- Failures must leave the last valid soft/hard-bounded cache intact; never replace a valid snapshot with a partial/error payload.

## UI

Show a compact cache indicator near Explorer runtime summary, for example:

- `cache: fresh · 18s`
- `cache: refreshing · 74s`
- `cache: stale · 6m`

Do not add a large warning banner for normal cache aging.

The existing Refresh button remains available for an explicit refresh.

## Security / safety invariants

- read-only vCenter calls only;
- no phpIPAM object mutation;
- no vCenter inventory/tag mutation;
- no SQL schema change required;
- no credentials/session tokens/cookies in cache;
- direct/no-proxy internal vCenter path retained where compatibility requires it;
- legacy `RetrieveProperties` retained for PropertyCollector compatibility;
- saved connector TLS mode remains authoritative;
- scheduler remains prohibited while saved connector TLS is INSECURE;
- Phase 5D remains protected fallback.

## Deployment sequence

This phase is currently design-only and intentionally based on the Phase 5F.3.3 compatibility head. It must be restacked on the final accepted Phase 5F.3.3 UI/runtime baseline before production deployment.

Before implementation/deployment:

1. finish and browser-accept Phase 5F.3.2;
2. finish Phase 5F.3.3 enriched-search UI/runtime;
3. restack this cache phase on that exact accepted head;
4. static CI + filesystem permission/unit tests;
5. production read-only/cache-path preflight;
6. exact-head Owner approval;
7. rollback-protected install;
8. browser timing/behavior validation.
