# Phase 5G.1 — independent inventory backend candidate

Base: `b6b1a697f16b22bc8d283ba4b4f59a889037255e`, verified on 2026-09-11.
Issue: #28. Production remains recovered c33 / 5F.3.4; TLS is already VERIFY.

## Scope actually implemented

An independent resumable worker, encrypted durable publication, read model, guarded additive JSON API and repository-side CLI entry point. These components are not installed and do not replace the current pages. The native UI bridge and guarded production deployment are the next integration work; there is no production installer or activated systemd timer in this slice.

The GitHub actions available in this conversation expose reads but not writes. These changes are consequently delivered as a patch, not claimed to be committed, merged or tested by GitHub Actions. The CI workflow is supplied for subsequent publication.

## Storage and lifecycle

`IntegrationVmwareInventoryStore` stores two bounded secretbox envelopes in a pre-provisioned private connector directory. `current.enc` contains the last complete published inventory and its status. `pending.enc` checkpoints an unpublished generation. All fields, including Notes and progress, are encrypted. No raw credentials, HTTP headers or provider error text are persisted. The existing 32-byte cache key is domain-separated for the new encryption format; neither the accepted cache nor its configuration is modified.

Readers only open `current.enc`: absent data reports initialization; age never triggers collection or file creation. An atomic same-directory rename prevents partially serialized data from being observed. File and directory fsync are checked. The writer takes a nonblocking flock; interrupted writes are ignored by readers and cleaned on the next worker lock acquisition. Files are owner-only 0600; directories 0700. Symlinks, hardlinks, oversized files and wrong ownership/modes are rejected.

Default maximum serialized envelope: 32 MiB; constructor ceiling 64 MiB. Space reserve: 256 MiB plus twice the next plaintext size. A complete generation, checkpoint and temporary replacement may coexist, so live memory and disk sizing still need validation. This first format reads/decrypts a whole publication for one query. It is a correctness-first backend, not a measured per-card latency claim; per-object immutable files/indexes are a possible refinement after realistic sizing.

## Collection and completeness

Only the CLI constructs `IntegrationVmwareInventorySource`. It requires strict TLS. It reuses the pinned legacy-compatible REST/SOAP readers for topology, datastore inventory, HOST folders, VM/datastore folders, enriched search/tags, full VM detail and guest networking, friendly guest facts, annotation, physical network-path observations, host summary/hints and datastore details.

A tick processes at most 10 work units by default, with a 45-second **between-unit soft** deadline. The CLI adds a 120-second signal deadline; it is not proof that every native blocking call terminates at exactly that instant. A reviewed future service must supply its own termination/kill bound. Existing collectors have request, response and object limits; the search/HOST-folder readers own their direct transport internally. They are passed `verify_tls=true`; a fully injected aggregate transport budget is not yet implemented for those readers. Real strict-TLS REST/SOAP integration still requires testing; the completed stored web TLS Test does not substitute for it.

A unit checkpoints only after completion. Restart repeats at most the interrupted unit, not the full inventory. Each generation allows at most 1000 VMs, 1000 hosts and 2000 datastores and must finish within four hours. The first full population may take substantial time because existing per-object detail collectors are used; no refresh duration is promised. Batching/pooling of all detail sections is subsequent performance work.

The worker discovers inventory before and after a generation and requires matching ID sets. Search membership must also match the initial VM set. Partial or inconsistent membership does not replace the old complete publication or imply deletion. Membership churn can force a generation restart; sustained churn/starvation must be evaluated on representative fixtures before release. Local atomic publication does not turn independently sampled vCenter properties into an instantaneous remote snapshot. Generation start, publication time and per-object/section collection times are retained.

Mandatory detail failure preserves the previous publication. Optional unavailable data is marked explicitly; dated last-good optional sections are retained with `state=stale`. Known authentication/authorization denial blocks ALL previously published data for that binding. Starting another attempt does not clear the denial; only a new complete generation does. Errors use exponential backoff capped at 15 minutes. Disabled connectors and TLS-off configuration are rejected before reads/collection; config and encrypted credential identity changes invalidate the binding. A connection Test timestamp alone does not invalidate it. No same-value disable/re-enable audit revision is introduced without a schema change.

## Local API contract and authorization

Additive route: `app/integrations/vmware/mirror/read.php`, POST only, existing session/admin/Explorer-CSRF checks before private configuration or files. Parameters: `config_id`, `view`, optional `id`, `search`, `page`, `page_size`.

Views: status, core, host_folders, object_folders, search, vm, host, datastore, vms, hosts, datastores. Entity IDs are typed `vm-N`, `host-N`, `datastore-N`; lists paginate locally (maximum 100). VM replies join locally stored search metadata/tags and retain its distinct timestamp. No shared user-specific HTML or resolved phpIPAM links are stored. The native rendering bridge must resolve IP links with current access and preserve escaping, Guest OS/RAM, Notes, folder state, charts and latest-selection cancellation.

The HTTP route deliberately does not import the worker or a collector. Context checks SELECT only the current connector metadata and encrypted credential envelope; web reads do not decrypt the vCenter password. The worker alone decrypts it after CLI/strict-TLS/current-binding checks. Responses are no-store JSON; known denial returns 403, absent object 404, invalid request 400 and local configuration/storage failure 503. Initial unpublished data returns a nonblocking initialization/progress response. No endpoint can start a scan or a scheduler.

**Existing phpIPAM pages still use the previous live/cache paths.** Connecting all list/card/search and supplementary panels to this local read model together is required before the product requirement can be called complete. Performance graphs are not sampled by this backend and must not silently fall back to live inventory as part of that bridge.

## Future provisioning contract — not a command to activate

`/etc/phpipam-custom/vmware-inventory.json`, owner root, group of the configured runtime UID, mode 0640:

```json
{"schema":1,"enabled":true,"application":"/var/www/html","root":"/var/lib/phpipam-custom/vmware-inventory","uid":33,"gid":33}
```

The connector directory under that root must already exist with runtime UID ownership and 0700 mode. Configuration, directory provisioning, service/timer installation, native-page rewiring and rollback are intentionally not performed by this patch. Do not manually create those files to deploy this incomplete integration. The rejected 5F.3.5 layer must remain rolled back; TLS/root trust must remain intact.

The CLI accepts only `--status|--tick --connector=ID`; `--help` and invalid arguments exit before application bootstrap. It refuses root execution and accepts no passwords, CA overrides, arbitrary URLs or paths on the command line.

## Executed tests and limits

Local PHP 8.4.23: 56 storage/worker/reader assertions passed, including real encryption/tamper rejection, process restart, 100 offline/stale reads with zero provider calls and unchanged file hashes, resume, partial publication rejection, transient last-good and sticky denial, changed credential binding, pagination, limits, locks, unsafe paths and dated optional fallback. Adapter tests use fake network transports to exercise strict TLS, endpoint/method boundaries and denied REST/SOAP results. HTTP tests exercise actual controller denial ordering with a stub phpIPAM session; they are not a successful production HTTP test.

34 inherited source blobs are pinned and verified. Retained topology/datastore/resolver fixtures passed 48/48/33 checks, and accepted snapshot cache/service fixtures passed including their concurrency test. Local syntax and CLI input checks passed. Local PHP does not have curl/DOM; no live adapter, full SOAP integration, native browser regression, production persistence or Actions success is claimed. Supplied Actions workflow targets PHP 8.2 and 8.4 for after publication.

## Next acceptance gate

Publish this candidate into a feature branch and run CI; complete the native UI/local read bridge, worker transport tests, realistic size/duration fixtures and a rollback-protected deployment. No further TLS survey or certificate import is needed. Then perform an explicitly approved runtime rollout and validate with browsers closed, after restart, with vCenter offline and through every existing card/search/folder path. Instrument zero ordinary HTTP inventory requests; report measured latency only after actual browser evidence.

References for primitives (not environment acceptance): PHP sodium_crypto_secretbox manual and PHP fsync manual. Project behavior is determined by the pinned sources and tests, not those external references.
