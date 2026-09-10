# Phase 5F.3.4 — shared VMware snapshot integration

Updated 2026-09-10. This document supersedes the foundation-only delivery status; the earlier design remains in Git history.

## Delivery and evidence

Base is the accepted Folder UI `9c40ef6029ebe0d9fb7bbea190fcaca16c67750f`. The Owner supplied working VM/Datastore folder screenshots and the subsequent cache environment survey exact-verified all four installed Folder UI files and ancestors. The original Folder UI INSTALL_OK stdout and complete rollback path were not supplied and are not reconstructed.

The earlier integration was left in unreferenced Git trees. Recovery commit `3dccc7abfa0bcb18253c87b8d323b739c3178f88`, parent `33d4658f67d8a8c14ca474e32e88ba77a9d79f37`, restored that work. Commit `bcd7fedd1d8311aedc766bc0826d130860b13945` published it with integration CI. Its first CI exposed missing generated providers in the test fixture. Commit `11a71ad66c173f448d81f030affa1648add8f120` corrects test generation and runs the root-owned disposable deployment fixture under sudo, without weakening source checks. Exact-head workflow `34492881503` passed PHP 8.2 and 8.4, including browser regressions. This documentation-only successor must also have its exact-head CI checked before delivery.

**Runtime integration, protected endpoints, browser middleware, installer and rollback are implemented. Production activation is NOT approved or performed.** The existing environment survey is accepted; next is the new integration preflight, then separate exact-head deployment approval. Do not ask the Owner to repeat the unchanged environment survey or collector preview.

## Snapshot domains

| Domain | Soft TTL | Hard TTL | Content |
| --- | ---: | ---: | --- |
| core | 60 s | 600 s | Topology and datastore inventory |
| host_folders | 300 s | 1800 s | Existing HOST folder graph |
| object_folders | 300 s | 1800 s | Coherent VM and Datastore folder graphs |
| search | 300 s | 1800 s | Enriched facts and Tags/Categories |

Native-header and workspace search share the search domain. A warm server read avoids another inventory crawl; a cold first read still collects data. Production latency has not been measured. Existing short-lived browser-memory projections remain; no inventory or Notes are stored in localStorage. CPU/RAM/Disk metrics, VM/host cards and physical network paths remain on-demand and are not part of these snapshots.

## Integration boundary

Eleven tracked runtime files plus two deterministically generated providers are installed. `tools/build-phase5f34-providers.py` pins accepted source blobs and extracts the existing HTML renderer and search builder with explicit tested deltas. Generated PHP is linted; there is no eval or source execution during generation. All ancestor collectors and runtime files remain unchanged in the repository.

Additive hooks in `app/integrations/vmware/index.php` and `app/tools/search/index.php` load the cache middleware before their automatic queries. Only the five existing inventory/search POST routes are mapped to cache counterparts; card and metric routes are untouched. Matching is restricted to the same origin and exact application-base paths. Basenames are retained for existing completion/deeplink listeners.

Session, administrator, POST, CSRF and enabled-connector validation run before cache construction or a hit. Saved server configuration is authoritative; clients cannot select upstream credentials, TLS policy, key paths or filesystem paths. Session locks are released before bounded upstream reads. Internal REST and SOAP use the previously accepted direct/no-proxy transport and legacy RetrieveProperties compatibility.

Missing optional facts or tags do not remove authoritative topology VMs. Partial results are identified and do not replace complete snapshots. Provider authentication/authorization denial blocks and invalidates all connector domains rather than serving cached data through a denial. Public errors contain categories, not raw exceptions or upstream bodies.

## Concurrency, freshness and browser behavior

Fresh and soft-stale reads return without invoking the loader. Separate authenticated refresh requests take a nonblocking connector-wide flock and use private temporary writes, flush/fsync and atomic rename. Concurrent cold requests receive a busy response with bounded browser retry, not duplicate loaders. Force refresh has a five-second coalescing floor; errors use thirty-second backoff. Hard-expired server snapshots are not served. Corrupt data is a miss; corrupt control state fails closed. Snapshot payloads are size-bounded.

A visible authenticated page schedules eligible domains at sixty-second intervals. Hidden pages stop maintenance requests, and leaving the page stops its timer. Refresh updates the server snapshot and compact status only; it does not redraw the selected list, tree or card. User-triggered reads retain the original handlers and independent folder preferences. An outer request deadline and abort propagation bound cold-lock retries. No cron, systemd timer or unattended scheduler is installed; the saved TLS-INSECURE restriction remains.

## Local storage and permissions

The accepted survey identifies one web runtime, www-data UID/GID 33:33, an ext4 filesystem, about 1.85 GiB free at survey time, and absent proposed cache/key paths. PHP CLI 8.2.30 has required capabilities. The actual web SAPI is not yet proven.

The installer is deliberately scoped to this reviewed identity and paths:

- `/var/cache/phpipam-custom/vmware`: www-data 33:33, mode 0700; connector directories are private and snapshots/locks use 0600.
- `/etc/phpipam-custom/vmware-cache.key`: new separate 32-byte key, root:33, mode 0640.
- `/etc/phpipam-custom/vmware-cache.json`: root:33, mode 0640; binds application path and runtime UID/GID.
- `/var/backups/phpipam-custom/vmware-cache-<timestamp>-<random>`: root-private backups and receipt, outside webroot.

The existing connector keyring is never rewritten. Snapshots use authenticated sodium secretbox encryption, separate purpose fields and a keyed binding of application, connector, endpoint, username, saved TLS, credential and adapter revision. Structured credential/session/header fields are excluded. Free-text Notes may be sensitive; encryption is not a claim to classify or scrub them. Same-UID/root compromise is outside the protection this local file cache provides.

This cache deliberately writes encrypted snapshots to disk. `cache_snapshot_written` is distinct from `inventory_db_written=false`; no business-inventory database write or vCenter mutation is implied. Missing/invalid cache configuration uses authorized live reads, not untrusted old snapshots, and reports disabled status.

## Deployment and rollback

New integration preflight: `bash tools/live-vmware-phase5f34-integration-preflight.sh /var/www/html`.

It checks exact source/ancestor bytes, both canonical hook positions, new-file absence, private parent ownership/modes, absent key/config/cache, and at least 256 MiB free. It does not create/read a cache key, install application files, or request vCenter. Expected marker: `READY_FOR_PHASE5F34_CACHE_OWNER_APPROVAL`. This is not approval itself.

After separate exact-head approval, the installer lints staged files, saves both original indexes and an old/new hash receipt, provisions an initially disabled configuration, places dependencies before hooks, enables the configuration last and verifies. Failure triggers guarded rollback. Verification includes a key/encryption/fsync self-test executed as www-data using CLI; this is not a claim of PHP-FPM/Apache capability parity. The authenticated cache status endpoint reports actual web capabilities for browser acceptance.

Rollback refuses unknown/newer files, disables configuration first, restores old indexes before removing new routes, and retains the private key/encrypted snapshots to avoid deleting resources under existing workers. Retained disabled resources make blind reinstallation fail for review. Do not delete them automatically or bypass that check.

## Verification scope

CI runs encryption/TTL/revocation/concurrent-reader tests, service and generated-provider completeness tests, HTTP guard tests, temporary installation/rollback/drift fixtures and jsdom routing/retry/abort/visibility checks, plus retained Folder UI and native-header/deeplink regressions. The deployment fixture stubs the inherited live-database checker; it is not production DB evidence. Source archives contain committed source only, never test keys or server inventory.

The next live gates are integration preflight, explicit activation approval, actual web-SAPI status, cold/warm timing and browser acceptance of infrastructure, all three folder trees and both searches. No deployment, performance improvement or broad runtime acceptance is claimed before that evidence.

## Primary primitive references

- PHP flock: https://www.php.net/manual/en/function.flock.php
- PHP sodium secretbox: https://www.php.net/manual/en/function.sodium-crypto-secretbox.php
- PHP session lock release: https://www.php.net/manual/en/function.session-write-close.php

These references describe primitives, not independent validation of this implementation.
