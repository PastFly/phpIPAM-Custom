# Phase 5H.1 — bounded batch acquisition for issue #42

## Implemented, dormant

Three additive CLI-only classes provide a common SOAP session, bounded legacy
RetrieveProperties batches, and multi-entity QueryPerf requests. Nothing imports
them in the deployed 5G.5 worker, web routes, configuration or systemd units yet.
No deployment or data migration is part of this source milestone. The live child
receipt and both encrypted datasets remain unchanged.

A BatchSession is single-use. Within `run`, it retrieves service references once,
authenticates once, executes bounded read operations and closes the same session.
The injected authorization callback is checked before/after requests and before a
result returns. A denial, binding change, failed request or failed cleanup cannot
silently deliver earlier data as an authorized successful result. No automatic
relogin hides loss of a session. The existing direct HTTPS transport is reused;
TLS must be true and inherited proxy settings remain disabled. This reuses the
SOAP authentication session, not a new persistent cURL connection pool.

Default work budget: 128 requests including cleanup, 120 seconds, 1 MiB request,
8 MiB per response and 64 MiB total response bytes. The calling worker must also
retain its process limit. Batches are serial; no hundreds of simultaneous calls.
Session cookies/passwords and arbitrary remote exception text are not emitted by
these classes. Returned property values are private inventory, NOT public logs.

## Property contract

An explicit known roster (maximum 2000 objects) is read in batches of 50 (maximum
100). Supported kinds: VirtualMachine, HostSystem, Datastore. Leaf properties cover
names, VM power/host/CPU-memory configuration/annotation/guest hostname-primary IP
and Tools running status; host connection/maintenance/quick usage; datastore
capacity/free space/accessibility. These are a fast subset, not the complete
existing VM/ESXi panels, folder topology, guest NIC list, tags or hardware details.

Exact requested object identities and field paths are validated. Missing objects
are `unobserved`, missing properties `not_reported`, unavailable values `null`,
and missingSet faults remain explicit; known denial aborts the pass. A partial
reply never proves deletion or authoritative membership. No complete config or
extraConfig, traversal, wildcard properties or unrelated objects are requested.
`collected_at` means receipt time. `source_observed_at` stays null because this
API response does not report an individual property's change timestamp.

## Metrics contract

The caller supplies source-derived real-time interval IDs, not a guessed default.
`provider()` obtains a validated currentSupported/refreshRate for an unknown
provider. `availability()` explicitly distinguishes aggregate from device/core
instances when a gap needs classification. Their safe caching/revalidation across
cycles is NOT implemented here; it must be scoped to connector binding/session
identity in the subsequent worker integration.

Counter definitions for CPU/RAM/disk usage are discovered once per collector's
session from PerformanceManager.perfCounter; numeric IDs are never hardcoded.
QueryPerf accepts up to 25 entity querySpec entries per batch by default, each
containing the requested aggregate counters and at most 60 samples. A batch uses
normal format, never wildcard instances, per-core summation or CSV guessing.
Counter units, requested IDs, entity identities, vector lengths and timestamps
are checked. Negative sentinel values remain null. CPU/RAM percent values use
1/100 percent units; disk values retain kiloBytesPerSecond.

A missing entity/series is `not_returned`/`partial`, NOT proof of unsupported
capability. Available RAM is preserved when CPU is absent. Old sample timestamps
remain old; download time never becomes measurement time. Genuine errors abort
rather than being zero-filled. Safe capability fallback and per-domain atomic
publication will be integrated separately before using this data on the website.

## Verifiable request-count fixture, not a live speed claim

A controlled transport with actual DOM parsing at 645 VMs + 61 ESXi + 70 datastores:
16 property batches for 776 objects; 1 counter-registry read; 29 QueryPerf batches
for 706 VM/host query plans; 1 ServiceContent + 1 Login + 1 Logout = 49 requests.
This fixture assumes supplied valid intervals and requests three aggregate
counters for each VM/host. It excludes roster discovery, initial provider-rate
checks, availability recovery, guest NIC/folder/tag acquisition, and publication.
It is NOT a comparison with the full old inventory sweep, nor a measured 1–5
minute runtime. CI must execute the real XML fixtures on PHP 8.2 and 8.4.

## Next integration and acceptance

Add bounded discovery/reconciliation and a binding-scoped capability cache;
map fast and configuration domains to the current encrypted reader contract;
publish verified domains atomically without waiting for unrelated slow fields.
Retain current access epochs, native authorization/CSRF, old good data on transient
failure, strict TLS and zero browser-triggered upstream reads. Run a separately
approved bounded shadow measurement before changing the current worker/timer or
receipt. Prove source-visible changes reach local views in 60–300 seconds over
several actual no-viewer cycles. Issues #28 and #42 remain open.

## Primary API references (checked 2026-09-15)

- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vmodl.query.PropertyCollector.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.PerformanceManager.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.PerformanceManager.QuerySpec.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.PerformanceManager.MetricId.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.PerformanceManager.CounterInfo.Unit.html

RetrieveProperties is deprecated upstream but deliberately retained for the
already tested server compatibility. WaitForUpdatesEx is not enabled or assumed.
