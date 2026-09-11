# Planned Phase 5F.3.3 — enriched global VM search

Owner request: extend the main VMware search beyond VM name/external ID to include description/Notes, tags/categories, virtualization/guest facts and MAC addresses.

Current Phase 5D Explorer search is intentionally limited to already-loaded VM `name` and `external_id`. Enriched fields are not present in the topology projection, so they must not be guessed or implemented as hundreds of per-keystroke VM detail requests.

Planned safe design:
- build a transient, bounded search index on demand;
- bulk/batched read-only VirtualMachine properties for description, guest/full-name, hardware version, runtime host and vNIC MAC/backing;
- retrieve tag/category associations through allowlisted read-only tagging actions with bounded request counts;
- direct/no-proxy internal vCenter transport, saved TLS mode authoritative;
- no SQL/schema/inventory persistence, no scheduler;
- browser cache the resulting compact index per connector for a short TTL so debounced typing does not recrawl vCenter;
- search remains display filtering only and is never identity/placement authority.

Implementation follows Phase 5F.3.2 guest-fact source verification so the same authoritative display/search fields are reused.
