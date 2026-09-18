# Microsoft DNS v1 — Windows collector and local IP context

Date: 2026-09-18
Status: proposed detailed design; Windows-collector direction accepted by Owner; implementation and deployment not yet performed.
Source base: `1f6298c3f0896ea6b7af7b6dc8d67cbfc5f9876d` (development master, not an exact production snapshot).

## 1. Scope and product decision

The Owner confirmed that DNS and DHCP run on Windows Server and accepted the recommended Windows-collector approach. Hyper-V is not used and is excluded from the active roadmap. Further VMware/vSphere development is paused, not declared complete. Existing VMware serving and collection are left alone. H7–H10 reader cutover, receipt reconciliation and open PRs are retained as deferred work, not prerequisites for starting DNS source work.

The first DNS release has one end-to-end outcome: connect a selected DNS source, browse selected zones/records, open an IP in phpIPAM, and inspect DNS evidence and discrepancies. DHCP follows as a separate release; AD computer inventory is later. No DNS/DHCP/AD management console is being built.

Supported first-release record types: A, AAAA, CNAME and PTR, class IN. DNS is read-only. Ingestion writes only the new integration's local observations and operational metadata; it never modifies native IP/subnet/device records or Windows DNS. Absence of DNS evidence never declares an IP free.

## 2. Existing integration boundaries

The source repository already has an Integrations registry and administrative surface: `layers/phase4/app/integrations/registry.php`, the subsequent `layers/phase5/app/integrations/registry.php`, and `layers/phase5/app/admin/integrations/index.php`. They are integration seams, not deployment targets to copy blindly. The effective later layer and exact live baseline must be resolved before any installer is prepared.

DNS implementation will use new, separately scoped source under `layers/dns1/`, collector assets under `collectors/windows/dns/`, and dedicated DNS tests. No code is stacked on PR62–66. The new branch starts from the verified development master. Do not reuse VMware provider credentials, locks, binding keys, or recovery receipts for DNS.

Reuse phpIPAM navigation, escaping, session/admin checks and IP/subnet authorization semantics where appropriate. The machine-ingestion endpoint has separate source-scoped authentication; it must not bypass or weaken browser authentication on any existing route. Built-in DNS-related functionality remains intact.

## 3. Chosen topology

Windows DNS servers -> Windows collector -> HTTPS ingestion -> local DNS store -> authenticated phpIPAM pages.

The collector runs on one existing domain-connected Windows management/task server, preferably not requiring installation on every DNS server. Windows PowerShell 5.1 compatibility is the initial source target, not a claim that every Windows Server build is already tested. The DnsServer module and successful remote read access are prerequisites. Actual Windows versions, source names, zones, account delegation and HTTPS trust are deployment inputs still to be supplied.

Use the scheduled-task identity for Windows DNS access. Do not send or store the domain password on Linux. Delegated least privilege must be tested in the real environment; calling only Get-* commands is not itself an authorization control. Domain Admins/DnsAdmins membership is not the default proposal. Installing RSAT, granting permissions, creating an account and registering a task are separately approved setup actions, not collector side effects.

Alternatives considered: Linux-initiated WinRM adds cross-platform credential/remoting setup; zone transfer does not provide the same structured source metadata and must not be enabled broadly merely for inventory. Neither is the first implementation path.

## 4. Windows collector

Use `Get-DnsServerZone` for selected-zone metadata and `Get-DnsServerResourceRecord` for records on an explicitly configured server. Do not parse GUI output or read raw DNS database files. Query A/AAAA/CNAME/PTR with terminating error handling; a failed or timed-out supported-type query makes that zone collection incomplete.

Start with an explicit allowlist of server/zone pairs. Do not import every discovered zone automatically. Cache/root-hints, stub zones and forwarder-only zones are not authoritative record datasets for this release. Primary, AD-integrated and secondary record datasets are eligible after read capability checks.

V1 collects the explicitly selected default virtualization instance and default zone scope only. Record this coverage in the UI. Nondefault instances/scopes require an explicit later extension; never merge them by zone name or claim default-scope data represents every policy view. Maintain source identity even when several servers host a same-named zone. One configured reference source per DNS view avoids treating replicas as independent address authorities; replica divergence analysis is deferred.

Proposed normal interval: 300 seconds, configurable only in reviewed local settings. One collector cycle at a time; skip overlapping task starts. Put a bounded deadline on source reads and HTTPS requests, including termination/reaping of a hung collector-owned child read, without touching DNS services. Retry transient upload failures with bounded backoff; never build an unbounded historical queue.

The first executable mode will be Preview: collect/normalize and show aggregate results or an explicitly requested local file, without upload, task registration, permission changes or server changes. Send mode requires explicit pairing with the receiver.

## 5. Snapshot contract

The acceptance unit is one source + selected zone + scope, not all servers together. Each upload contains one complete supported-type zone snapshot. This avoids a new whole-infrastructure publication barrier.

Envelope fields:
- schema_version = 1; source_id issued by phpIPAM; dataset identity validated against its allowlist;
- binding_epoch issued by the receiver for the active source configuration;
- run_id (UUID), dataset sequence (positive integer, at most 2^53-1), collection start/end Unix UTC seconds;
- zone name, explicit default instance/scope identity, supported_types = [A, AAAA, CNAME, PTR];
- complete = true, declared record count, records array.

Each record contains normalized owner FQDN, type, scalar rdata, TTL seconds and nullable source aging timestamp. Collector observation time is separate from DNS aging timestamp: a static record is not stale merely because it has no aging timestamp. TTL is not an integration freshness clock.

Normalize DNS name comparison case-insensitively with one terminal dot and correct apex handling. Preserve display spelling separately if needed. Support underscore labels as DNS names, not only hostname syntax. Treat wildcard names as wildcard evidence, never as a specific resolved host. IPv4/IPv6 values are parsed and normalized, not matched as arbitrary strings. Do not resolve external names while validating or rendering.

Record identity is (dataset, owner, type, normalized rdata). Multiple A/AAAA answers are valid. CNAME and PTR targets are names, not IP addresses. Resolve CNAME associations only through collected local records, with a visited set and a maximum of 16 hops; cycles and out-of-coverage targets remain explicit. Decode conventional in-addr.arpa/ip6.arpa PTR owners; classless reverse delegation that cannot be mapped safely remains visible but unmapped.

Initial hard limits proposed for the pilot: 4 MiB uncompressed request, 10,000 supported records per zone, 64 selected datasets. Reject the complete upload if any limit is exceeded; never truncate and call it complete. These limits require pilot measurement, not assumptions about production zone size. Chunked upload is not part of v1.

## 6. Authentication, ordering and publication

Use a dedicated random 256-bit bearer credential over verified HTTPS. Store only its digest on the receiver; keep the credential protected by Windows account/filesystem controls. It grants upload/status permission for one configured source and its allowed datasets, not general phpIPAM API access or address-plan writes. No credential in URLs, command arguments, logs, fixtures or Git. Revocation/configuration changes increment binding_epoch and reject old submissions.

POST JSON only on a dedicated machine endpoint. Authenticate before expensive parsing, enforce body/time/rate limits, reject duplicate JSON keys and malformed scalar types, and do not accept browser session cookies as machine authorization. Admin pairing/configuration retains session, admin and CSRF checks. Redirects on the collector upload path are rejected so credentials cannot be forwarded to another origin. TLS certificate validation must never be disabled.

Serialize publication per dataset. A newer sequence may publish; same sequence plus same payload digest is an idempotent acknowledgment, without refreshing the original observation age; same sequence with different bytes or any older sequence is rejected. Persist the collector sequence before sending and retain at most one bounded pending payload per dataset. Loss of sequence state requires explicit re-pairing, not a counter reset accepted by the receiver. Bind ordering to the active epoch; recheck authorization immediately before commit.

Collector clock tolerance is a proposed 300 seconds. Reject future/old submissions outside the allowed interval; retrying an old snapshot must not make it fresh. Use server receipt/publication time for ingest health and retain collector start/end separately. An upload is accepted only after validation and atomic publication, not merely after network receipt.

A failed collection reports only sanitized operational status. It does not publish an empty or partial replacement. A successful complete empty supported-type snapshot is valid. Do not infer that a whole zone was deleted just because it was omitted from a cycle; removing a source/dataset is an explicit configuration action. Only absence in a later complete snapshot changes that dataset's record membership.

## 7. Local storage and resource limits

Use additive DNS-specific tables, not VMware stores or native phpIPAM address tables. Planned logical entities: sources and pairing metadata; datasets and allowlists; zone generations; normalized records; bounded sync status. SQL schema and indexes will be implementation artifacts and require a separately reviewed migration before production use.

Each dataset retains current plus one previous complete generation. Transactional publication validates the full new generation before changing the current pointer; failure leaves the last-good generation visible. Identical normalized record content can refresh collection metadata without duplicating the record generation. Generation, digest and observed-time updates must remain consistent.

Initial budget: 256 MiB of accounted stored snapshot payload across the module, a 512 MiB free-space safety floor, and at most 1,000 compact sync-event rows. Physical table/index growth must be measured during the pilot: payload accounting alone is not a filesystem quota. Exhaustion fails closed with last-good data retained. No purging native phpIPAM data, MySQL files or historical recovery backups to make room.

Proposed Windows diagnostic logging budget: three 5 MiB rotating files, aggregate counts and sanitized error codes only. No automatic full-zone dumps. The existing 905 MiB phpipam.log and 2.3 GiB phpIPAM database observed during cleanup are not authorization to delete anything further. Owner stopped cleanup after journal vacuum freed 533.8 MiB and root free space reached about 2.3 GiB.

## 8. Browser and IP-card behavior

Use `Integrations -> Microsoft DNS -> source -> zone -> records`. Provide paginated search/filtering by name, address and type, explicit source/zone coverage and last-success/last-attempt times. Click names/addresses to navigate naturally. No browser-triggered collection and no external name resolution on request.

Initial full-zone browsing and source management are admin-only, following the current integration boundary. The IP-card enrichment must check native IP/subnet visibility plus allowed DNS source/view before returning data. Never leak records from another section/VRF by matching only an address string. Source-to-network-context mapping is administrative; ambiguous matches show a scoped chooser or no automatic link.

States: initializing, current, stale, source_error, incomplete, disabled. Proposed stale threshold: 900 seconds since the last valid collection. A source error may coexist with retained records and their age. Display unsupported coverage separately from missing records.

Discrepancies are review suggestions: differing IPAM/DNS name, observed DNS address absent from the selected authorized address scope, and nonreciprocal A/AAAA-PTR evidence. Missing reverse coverage means unknown, not an error. Multiple names or addresses are not errors by themselves. No automatic rename, creation, deletion or 'free address' transition.

## 9. Acceptance and rollout

Source acceptance covers normalization, apex/nested/wildcard/underscore names, IPv4/IPv6, CNAME cycles, partial/empty snapshots, independently failing zones, duplicate/reordered requests, revoked keys/epochs, out-of-scope datasets, hostile JSON/XSS, exact IP permission checks, quotas, failed database commits and concurrency. Windows PowerShell 5.1 tests must execute on Windows; static parsing or PowerShell 7 on Linux is not equivalent evidence.

Pilot acceptance uses one chosen Windows collector and one forward plus one reverse zone. Verify Preview first, then explicit pairing and an approved receiver deployment. Observe at least three autonomous cycles, an intentionally failed zone read with last-good preservation, credential revocation, and an actual user journey from zone search to an authorized IP card. Measure collection time, payload, storage and frontend latency before expanding.

Deploy DNS as its own optional, disabled-by-default module. Never rerun VMware recovery to install DNS. The deployment package must capture exact live files it changes and refuse unknown drift. Source merges, schema changes, Windows task installation, credential provisioning and production activation retain their applicable explicit approvals. Disabling DNS stops ingestion/display without damaging native records or VMware state; retain observation data until a separate retention/removal decision.

## 10. Implementation order after design review

1. Pure schema/normalization fixtures and Windows Preview collector, no receiver writes.
2. Source-scoped receiver, additive storage and negative/security tests.
3. DNS browsing and permission-aware IP-card block; complete the pilot journey before adding DHCP.

Required before a live pilot, but not before source development: collector Windows hostname/version, DNS source hostnames/versions, selected forward/reverse zones, task identity/delegation, DNS view to phpIPAM network-scope mapping, HTTPS origin/certificate trust, and measured zone counts. No credentials should be supplied in chat or committed to Git.

## References

Microsoft documentation checked 2026-09-18; capabilities are references, not proof of this environment's permissions or compatibility.
- Get-DnsServerZone: https://learn.microsoft.com/en-us/powershell/module/dnsserver/get-dnsserverzone?view=windowsserver2025-ps
- Get-DnsServerResourceRecord (including source server, zone scope and virtualization instance): https://learn.microsoft.com/en-us/powershell/module/dnsserver/get-dnsserverresourcerecord?view=windowsserver2025-ps
