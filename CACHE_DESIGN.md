# Phase 5F.3.4 — shared VMware snapshots

Updated 2026-09-10. This supersedes the initial design in the history of PR #25.

## Current delivery boundary

The isolated cache engine, synthetic tests and a READ-ONLY environment preflight are implemented. **No endpoint, UI hook, key provisioner or production cache installer is included yet.** The next step is endpoint integration after the live environment survey; this is not a deployable cache UI candidate.

Development is restacked onto Phase 5F.3.3.2 `9c40ef6029ebe0d9fb7bbea190fcaca16c67750f`. The Owner has supplied working VM-folder and datastore-folder screenshots and says they work. A new INSTALL_OK log/full rollback path was not supplied for that phase and must not be reconstructed. Its existing exact verifier is run by the new environment preflight.

## Goal and domains

Reuse server data across page visits and administrators without replacing vCenter as the source of truth. Warm reads avoid a new infrastructure crawl; a first cold read still has to collect data. Actual production timings have not yet been measured.

| Domain | Soft TTL | Hard TTL | Content |
| --- | ---: | ---: | --- |
| core | 60 s | 600 s | topology + datastore projection |
| host_folders | 300 s | 1800 s | accepted HOST folder graph |
| object_folders | 300 s | 1800 s | coherent VM + Datastore folder graphs |
| search | 300 s | 1800 s | enriched facts + Tags/Categories |

Both object folder domains are collected together, not by duplicate vCenter calls. Realtime CPU/RAM/Disk, VM details and physical network-path runtime data stay on-demand. Do not turn an active/standby policy into an invented current uplink.

## Storage and identity

Proposed root `/var/cache/phpipam-custom/vmware/`, outside webroot. The eventual provisioner must create application/connector subdirectories for the real web runtime UID, with mode 0700; snapshots and connector locks use 0600. This is stricter than the earlier 0750/0640 proposal. Do not infer the web UID from the root CLI process.

Snapshots use authenticated `sodium_crypto_secretbox` encryption, random per-write nonces and purpose-separated envelope fields. The separate proposed key `/etc/phpipam-custom/vmware-cache.key` is installer-provisioned, root-owned and web-runtime-group readable, outside both cache and webroot. Never reuse, rotate or rewrite the existing connector keyring. Neither the engine nor the preflight creates or prints a key. Preflight reads only path metadata, never existing key contents.

A keyed identity fingerprint binds application root, connector ID, normalized endpoint, username, saved TLS mode, credential and explicit revision. Inputs stay in memory; neither plaintext credentials nor an unkeyed password hash is stored. Changing any identity component invalidates snapshots. A cache key rotation makes old envelopes unreadable.

Providers MUST project allowlisted data before caching. The engine additionally rejects common structured credential/session/endpoint/header fields. Free-text Notes may themselves contain sensitive information: encryption is mandatory, but is not a claim that arbitrary descriptions have been classified or scrubbed. No inventory is stored in localStorage.

## Concurrency, age and failure rules

`get(domain, loader)` returns a fresh or soft-stale snapshot without executing a loader. Stale responses include age and `refresh_required`; there is no magic background process in this function.

The future authenticated browser adapter calls explicit `refresh` in a separate request. One nonblocking connector-wide flock coalesces refreshes across domains, workers and pages. Cold contenders get `snapshot_refresh_busy`, not a duplicate loader; the future client must retry with a bounded delay. Fresh readers are not blocked by a writer. Explicit force refresh remains coalesced for five seconds and cannot bypass error backoff.

Writing uses a private exclusive temporary file in the same directory, complete writes, flush/fsync and rename. Last good snapshots are not overwritten with exceptions, partial payloads or oversized data. A failed provider establishes a 30-second retry backoff. Hard-expired data is never served, including after a slow failed refresh. Successful live reads remain usable if writing fails, marked `uncached` rather than a false cache hit.

A provider observing vCenter authentication/authorization denial MUST raise `IntegrationVmwareSnapshotAccessDenied`: all connector domains are invalidated and blocked until a successful reauthentication after cooldown. Existing user/admin/CSRF/enabled-connector checks must run BEFORE all hits, including fresh ones. Error strings, upstream bodies and session material never enter cache metadata. Optional metadata incompleteness must be handled by the provider without calling an incomplete search index complete.

No promises of persistence across abrupt power loss are made: this is disposable cache, not a durable database. Corrupt/invalid encrypted data is a miss; corrupt authorization/backoff control files fail closed. Cache directory is trusted only for the dedicated runtime UID. Same-UID compromise or root compromise is not a threat this file cache can solve.

## No hidden persistence claims

This feature deliberately writes inventory snapshots to disk when eventually enabled. `cache_snapshot_written` reports that operation. `inventory_db_written=false` means no phpIPAM database inventory mutation, NOT that there were no filesystem writes. Source collector flags describe their original read, not persistence of the wrapping cache. Future UI/API documentation must retain that distinction.

## Active-page refresh contract (not wired yet)

No cron, systemd timer or unattended scheduler is installed. Saved TLS remains INSECURE and the project restriction stays in place. Planned maintenance is request-driven while an authenticated administrator has a visible page open, with a minimum 60-second page interval and global server-side TTL/lock coalescing. Pause when hidden/logged out, do not poll VM metrics, do not rebuild selection/trees on each timer, and show compact snapshot age/error status. A later unattended updater needs a separate TLS/authorization decision.

Existing native-header search must use the same search snapshot, rather than a second cache. HOST, VM and Datastore trees must retain their independent collapse preferences. Preserve stable-ID VM deep links and all existing card enrichments.

## What the next preflight proves

`bash tools/live-vmware-phase5f34-cache-preflight.sh /var/www/html`

It exact-verifies the installed 5F.3.3.2 baseline, surveys CLI PHP capabilities, observes non-root Apache/PHP-FPM process UIDs, checks prospective paths outside webroot, detects symlinks/unsafe parent permissions, reports local filesystem/free space and flags already-existing cache/key paths for review. It does not write cache/config/application files, read key contents, make vCenter requests, or prove web-SAPI extensions match CLI.

`CACHE_ENVIRONMENT_READY_FOR_INTEGRATION` is environmental evidence only, NOT production activation approval. Multiple runtime users, unknown/network filesystem, existing paths, missing extensions or low free space yield review-required, not chmod/delete/workarounds.

Next: integrate scoped providers and cache-age UI, test endpoint guards and actual workload/timing, prepare a rollback-protected provisioner/installer, then obtain separate exact-head production approval. Do not provide an installer for this foundation-only candidate.

## Primary references

- PHP `flock`: https://www.php.net/manual/en/function.flock.php
- PHP authenticated encryption: https://www.php.net/manual/en/function.sodium-crypto-secretbox.php
- PHP session lock release for future endpoint integration: https://www.php.net/manual/en/function.session-write-close.php

References describe primitives, not independent security validation of this implementation. CI tests synthetic fixtures, not the production server.
