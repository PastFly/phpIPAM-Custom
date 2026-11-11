# Microsoft DNS v1 — web-managed connections and Windows collection

Date: 2026-09-18
Revision: 2
Status: Owner confirmed web-managed server, login and password; the detailed implementation/security contract below is proposed for review. No implementation or deployment has occurred.
Source base: `1f6298c3f0896ea6b7af7b6dc8d67cbfc5f9876d` (development master, not an exact production snapshot).
Previous design: `c387fb4150c02a20604466bc1c1a0e08ae1c68f5` in this branch. Revision 2 explicitly replaces the task-identity-only and Windows-only domain-secret assumptions. It does not authorize any runtime credential provisioning.

## 1. Scope and accepted product decisions

The Owner confirmed Windows Server DNS/DHCP and a Windows-collector architecture, then explicitly confirmed that servers, integration usernames and passwords must be configurable through the phpIPAM web interface. Routine server/password changes must not require editing PowerShell code or credentials on Windows.

Initial source hosts supplied by the Owner:
- Microsoft DNS: `srv-dc01.rbank.local`.
- Microsoft DHCP, later release: `srv-dhcp01.rbank.local`.

These are configurable source hosts, not hardcoded runtime constants, verified reachable endpoints, selected zones, or a chosen collector installation host. Do not infer the domain's NetBIOS name, login, Windows version, zone list or read permissions from these names.

DNS is the first release; DHCP follows separately, then AD computer context. Hyper-V is excluded because it is not used. VMware/vSphere development and further disk cleanup remain paused/stopped at the Owner's request. Existing VMware services, deferred PRs, original receipts and backups remain untouched. H7–H10 completion is not a prerequisite for DNS source work.

The first release must complete this journey: configure server and credentials in phpIPAM -> validate access through the assigned Windows collector -> select zones -> explicitly enable collection -> browse records -> open an authorized IP card. Supported record types are A, AAAA, CNAME and PTR, class IN. No Windows DNS changes or automatic native IP/subnet/device changes are permitted. Absence of DNS evidence never marks an IP free.

## 2. Existing integration boundaries

Existing source seams include the Integrations registry and administrative surface under `layers/phase4/` and `layers/phase5/`. These are not deployment targets to copy blindly; the effective later layer and actual live baseline must be resolved before an installer is prepared.

Use separately scoped source under `layers/dns1/`, collector assets under `collectors/windows/dns/`, and dedicated tests. Do not stack DNS on PR62–66 or reuse VMware credentials, key material, locks or recovery receipts. Reuse phpIPAM navigation, escaping, admin/session/CSRF and native IP/subnet permission semantics without weakening existing routes or built-in DNS features.

## 3. Administration and credential profiles

`Administration -> Integrations` exposes independent Microsoft DNS connections. The same connection/profile concepts are intended for the later DHCP release; this design does not implement DHCP early.

Connection fields: display name, provider type, source FQDN, assigned registered collector, credential mode/profile, selected zones, DNS-view to native network-context mapping, collection interval and enabled state. Multiple connections are supported. New connections are disabled. Save does not start collection or automatically import every zone.

A credential profile contains display name, username, an encrypted secret, secret version and lifecycle/audit metadata. Accept UPN or explicitly entered domain-qualified login without deriving a NetBIOS name. DNS and DHCP can use separate profiles; sharing a profile requires explicit assignment and a visible list of affected connections. Changing one shared profile invalidates the relevant versions for all its consumers.

Primary mode is a web-managed profile with username/password. An explicit `collector_identity` mode can use the scheduled-task identity without a stored domain password; it is not mandatory and cannot silently replace the selected profile after an authentication failure. The Windows task identity remains separate from the account used to read DNS.

Admin actions: Save, Test connection, Get zones, Enable/Pause, Replace saved password, Revoke collector, and inspect sanitized status. Password handling is explicit: `unchanged` leaves the current encrypted value intact; `replace` requires a new nonempty value; clearing/switching credential mode is a separate validated action. Masked placeholders are never written as passwords. Normal reads expose only `password_set`, profile/version and update metadata, never plaintext, ciphertext or the saved secret in HTML/JSON/export.

Replacing the saved integration password updates the credential used by this integration; it does not change or reset the account's password in Active Directory. No account creation, group membership or permission changes are performed by the UI or collector.

## 4. Windows collector and control flow

Collection still runs on an existing Windows management/task server with Windows PowerShell 5.1 and the required DnsServer module. Its host/version have not been supplied. Installing prerequisites, pairing the collector and registering one task are separately approved one-time setup, not eliminated by web configuration.

The collector initiates verified HTTPS connections to a pinned phpIPAM origin. It polls for assigned configuration and bounded typed work requests, then uploads observations. No inbound listener on Windows or arbitrary remote PowerShell execution service is introduced. Local bootstrap contains origin/trust and machine identity, not DNS server passwords. Production configuration is server-issued; source FQDN, profile and interval are not edited in script text.

Proposed control poll interval: 60 seconds; normal collection interval: 300 seconds, editable in the web UI within 60–3600 seconds. Only one cycle runs at a time; overlapping task starts are skipped. UI test/discovery requests are queued and show pending/running/succeeded/failed/expired or collector-offline states. They run on the next collector poll, not synchronously in the PHP request. Ordinary browsing never triggers collection.

Allow only fixed operations: test_connection, list_zones and collect_selected_zones. Tasks carry IDs and typed parameters bound to the configured source, collector assignment, configuration revision, binding epoch and credential version. No command strings, user-supplied scripts, arbitrary file paths or requester-selected secret IDs. Discovery on a disabled connection requires an explicit, expiring admin request; it does not enable periodic collection. Collector revocation denies all tasks and credential delivery.

Source reads use an explicit CIM session to the configured FQDN, with the selected PSCredential in managed mode, and Get-DnsServerZone/Get-DnsServerResourceRecord through that session. Use typed arguments, not shell interpolation. FQDN/transport/trust must be validated before credentials are used; no arbitrary URL/port override. Start with an explicitly reviewed Kerberos/WSMan policy for the domain environment; no silent downgrade to NTLM, Basic, CredSSP, DCOM or certificate-validation bypass. Actual Windows versions, remote access and least-privilege delegation must pass pilot checks. The existence of a Get-* cmdlet is not proof of read-only authorization. Domain Admins/DnsAdmins is not the default proposal.

Put deadlines on reads, jobs and HTTP requests. A hung collector-owned child can be terminated/reaped without touching DNS services. Do not pass secrets in process arguments, environment variables, command text or temporary scripts. Use the credential object within the collecting process or an ACL-restricted private IPC channel if child isolation is needed. Avoid transcripts/raw exception dumps containing credentials. Keep at most one bounded pending snapshot per dataset and use bounded retry backoff.

Preview remains a separate development/diagnostic mode: selected reads and aggregate results only, no upload, task registration or Windows changes. Temporary test credentials can be supplied through a secure local prompt or the paired control path, never source literals. It is not the final user's recurring configuration workflow.

## 5. Central secret storage and collector authorization

The selected architecture makes phpIPAM a trusted custodian of domain integration secrets. Encrypting at rest does not protect against compromise of a process authorized to decrypt or of the assigned Windows collector. Keep accounts least-privileged, narrowly assigned and separately rotatable; do not describe this as protection from a compromised application.

Store recoverable credentials using authenticated encryption through a reviewed crypto implementation, not hashing-only storage or encoding. Use a separate versioned key outside the database, webroot and source repository, with restricted filesystem ownership/mode and an independently protected backup. Do not reuse VMware keys. The authenticated envelope must bind profile ID, credential version and intended purpose so ciphertext cannot be swapped between profiles. Missing/invalid key material fails closed without overwriting saved ciphertext. Log key/profile identifiers only, never plaintext or ciphertext.

Admin entry/replacement requires an authenticated admin session, CSRF validation and recent reauthentication for sensitive profile changes or collector reassignment. Both the browser and collector channels require verified HTTPS. Request-body/header diagnostics on secret routes must be redacted; no secrets in URLs, logs, analytics, Git, fixtures or API exports. Human-facing routes provide no show/decrypt-old-password function.

Separate machine permissions:
- snapshot upload credential: random 256-bit source-scoped bearer value, receiver stores only its digest; it authorizes observations/status for that source, not credential retrieval or native address-plan writes;
- collector control credential: a distinct random secret, digest-only at the receiver, bound to one registered collector and its explicit source assignments; it authorizes polling and credential use only for current, unexpired assigned work.

Store bootstrap machine secrets under Windows account/filesystem protection. Credential delivery is on a separate machine-only POST operation: revalidate collector, source assignment, task/configuration revision, epoch and credential version immediately before decryption. The server resolves the profile from the assignment, never trusts a profile ID supplied by the collector. Return only the credential required for that task over HTTPS, with no-store/private cache headers; reject redirects and do not persist domain passwords on Windows by default. Unavailable control service means no new collection using a stale cached password.

A revocation/reassignment invalidates new secret fetches and old-epoch publications. A password replacement creates a new version and invalidates queued or unfinished older-version jobs. Bounded in-flight work is marked obsolete, and its result is rejected. No automatic fallback to the old password. Authentication failures stop automatic retries for that credential version; a new version or explicit rate-limited admin retry is required to avoid account lockout storms.

Delivery deadlines/epochs limit this application's use of a secret; they do not expire or revoke a static domain password already disclosed to a collector. Incident response must rotate the Windows account password and revoke machine access as appropriate. Minimize plaintext lifetime in managed memory without promising guaranteed erasure of every runtime copy.

Record who changed a connection/profile, which collector used which profile version for which job, and whether authorization succeeded. No secret values. Keep compact security audit events separately from high-frequency synchronization diagnostics, bounded to 90 days or 10,000 rows for the pilot with explicit visibility of retention truncation; security policy may require reviewed export/longer retention. No silent unbounded log growth.

## 6. DNS datasets and snapshot contract

Start with an explicit allowlist of server/zone pairs. Cache/root-hints, stub and forwarder-only zones are not authoritative record datasets. Primary, AD-integrated and secondary record datasets are eligible after read capability checks. V1 covers only the explicitly selected default virtualization instance/default zone scope and reports that coverage. Same-named zones on different sources remain distinct; use one reference source per DNS view rather than automatically combining replicas. Replica divergence analysis is deferred.

Query A/AAAA/CNAME/PTR with terminating error handling. Any failed, truncated or timed-out supported-type query makes the zone incomplete. The acceptance unit is source + zone + scope, not all servers together. Each successful upload is one complete supported-type zone snapshot; failed collection publishes sanitized health only, not an empty replacement. Complete empty supported-type results are valid. Omission of a zone does not mean its deletion; dataset removal is an explicit configuration action.

Envelope: schema_version=1, source_id, allowed dataset identity, binding_epoch, configuration revision, run_id UUID, positive dataset sequence no greater than 2^53-1, collector start/end Unix UTC seconds, default instance/scope, supported_types=[A,AAAA,CNAME,PTR], complete=true, record count and records. Credential values never appear in observations. Job/version checks prevent obsolete configuration from publishing as current.

Record: normalized owner FQDN, type, scalar rdata, TTL seconds and nullable source aging timestamp; preserve display spelling separately where needed. Comparison is case-insensitive with one terminal dot, correct apex handling, underscore labels and wildcard evidence. Wildcards are not specific hosts. Parse IPv4/IPv6 rather than matching raw strings. CNAME/PTR targets are names. Local-only CNAME association uses a visited set and at most 16 hops; loops/out-of-coverage remain explicit. Decode conventional in-addr.arpa/ip6.arpa; unsafe classless mappings remain visible but unmapped. No external DNS resolution during validation/rendering. TTL and source aging timestamp are not collector freshness clocks; static records are not stale merely for having no aging timestamp.

Pilot limits remain 4 MiB uncompressed request, 10,000 supported records per zone and 64 selected datasets. Reject oversized complete uploads without truncation. Measure actual zones before deployment; chunking is not part of v1.

## 7. Ingestion, ordering and publication

Dedicated POST JSON ingestion authenticates before expensive parsing and enforces body/time/rate bounds, strict scalar types and duplicate-key rejection. Browser cookies cannot authorize machine requests. Serialize per dataset; publish only after full validation and a final authorization/epoch recheck.

A newer sequence may publish. Same sequence and identical payload digest is an idempotent acknowledgment without refreshing observation age. Same sequence with different bytes or older sequence is rejected. Persist sequence before send. Lost collector sequence state requires explicit re-pairing, not an accepted counter reset. Snapshot retries must not make old observations appear fresh. Proposed clock tolerance is 300 seconds; retain collector times and server receipt/publication times separately.

Last-good snapshots survive collection, validation, transport and database failures. Only absence in a later complete accepted snapshot changes record membership for that dataset. A malicious authorized collector can falsify its assigned observations; source scoping limits the affected data but is not source attestation. No observed data mutates Windows DNS or the native address plan.

## 8. Local storage and resource limits

Use additive integration-specific storage, not VMware stores or native address tables. Logical entities: collectors/control credentials and source upload digests; connections/configuration revisions/assignments; encrypted credential profiles/versions; bounded jobs; datasets/allowlists; complete generations/records; compact sync status and separate bounded security audit metadata. No plaintext secrets in job rows or snapshots. Schema/index changes require separately reviewed production migration.

Retain current plus one previous complete generation per dataset. Validate the new generation before atomically changing the current pointer. Identical normalized content may refresh collection metadata without duplicating record rows, while generation/digest/time updates remain consistent.

Pilot storage budget: 256 MiB accounted snapshot payload, 512 MiB free-space safety floor, at most 1,000 sync-event rows, at most one pending collection job and one pending snapshot per dataset. Expired UI jobs are bounded and removed according to their own retention; do not let polling create unbounded history. Physical table/index growth must be measured: payload accounting is not a filesystem quota. Exhaustion retains last-good data and reports a resource error. Never purge native data, MySQL files or historical recovery backups to make room.

Windows diagnostics: three 5 MiB rotating files with aggregate counts and sanitized error codes. No automatic zone dumps or credential-bearing transcripts. Owner stopped cleanup after journal vacuum freed 533.8M and root free space reached about 2.3G; the observed phpipam.log905MB and database2.3G are not authorization for further deletion.

## 9. Browser and IP-card behavior

Operational navigation: `Integrations -> Microsoft DNS -> source -> zone -> records`. Paginated search by name/IP/type, source/zone coverage, last attempt/success and explicit freshness. Names and addresses are natural links. Configuration belongs in Administration; no browser-triggered collection except explicit queued admin test/discovery actions described above.

Full-zone browsing and source/profile management are admin-only initially. IP-card enrichment checks native IP/subnet visibility plus the allowed DNS source/view. Do not leak across sections/VRFs by address-string matching. Source-to-network-context mapping is administrative; ambiguity produces a scoped chooser or no automatic link.

States: initializing, current, stale, source_error, incomplete, disabled, plus collector/configuration job states. Default stale threshold 900 seconds for the default300-second collection interval; for other intervals derive and display a threshold of three intervals. Error state can coexist with retained records and their true age. Unsupported coverage differs from missing records. Disabled sources cannot issue collection jobs or secret delivery, except an explicit administrative test/discovery task; disabling display retains data without native record changes.

Discrepancies are review suggestions: differing IPAM/DNS names, an observed address absent from the selected authorized scope, or nonreciprocal A/AAAA-PTR evidence. Missing reverse coverage is unknown, not an error. Multiple names/addresses are not inherently errors. No automatic rename/create/delete/free-address action.

## 10. Acceptance and rollout

Tests must cover web credential create/replace/unchanged/clear semantics; no saved-secret readback; encrypted-envelope tampering/profile swaps/wrong keys; admin/session/CSRF/reauthentication; foreign collector/source/profile requests; upload-token rejection at control routes; host/config revision changes; obsolete jobs and publications; shared-profile rotation; offline collector; rejected redirects/TLS failures; redacted logs and authentication retry bounds. Verify read permissions under the actual selected Windows account rather than equating cmdlet naming with least privilege.

Retain tests for normalization, apex/nested/wildcard/underscore names, IPv4/IPv6, CNAME loops, partial/empty zones, independently failing sources, duplicate/reordered/revoked uploads, hostile JSON/XSS, network-context/IP permissions, quotas, failed commits and concurrency. Actual Windows PowerShell5.1 execution is required; Linux/static or PowerShell7 tests are not equivalent evidence.

Pilot: one chosen collector, DNS source `srv-dc01.rbank.local`, one explicitly selected forward/reverse pair. Configure server/login/password in the web UI, test through the collector, select zones and explicitly enable. Observe at least three autonomous cycles, a failed zone with last-good preservation, web password replacement without script edits, revocation and zone-search-to-authorized-IP navigation. Verify that editing the stored password does not change AD. Measure times/payload/storage/frontend latency before expansion. DHCP source `srv-dhcp01.rbank.local` remains a later pilot.

Deploy an optional disabled-by-default DNS module from its own reviewed package. Never rerun VMware recovery. Capture exact live replacement baselines including hotfixes and provide rollback. Source merge, SQL migration, key/pairing provisioning, Windows task/account setup and production activation retain applicable explicit approval gates. No such operation is authorized or performed by this documentation revision.

## 11. Implementation order after detailed design review

1. Connection/profile/configuration contracts and fake-collector tests, pure DNS normalization, and local Preview collector. No production writes or real credentials.
2. Web administration with encrypted profile storage, collector pairing/control tasks and asynchronous test/discovery, all disabled by default in source/test environments.
3. Windows execution and scoped receiver/storage; ensure the chosen per-connection credentials, web configuration revisions and all negative/security cases work together.
4. DNS browse/IP-card journey and the bounded pilot before DHCP implementation.

Known pilot inputs: Windows Server platform; DNS source `srv-dc01.rbank.local`; later DHCP source `srv-dhcp01.rbank.local`; central username/password management accepted. Still needed before live pilot, not before source development: collector host/version, source Windows versions, selected zones, actual service account/delegation, network-context mapping, HTTPS origin/trust and measured zone sizes. Do not ask again whether web credential management is wanted or request secret values in chat.

## References

Primary documentation checked 2026-09-18. These establish interfaces/principles, not actual production permissions or Windows5.1 test evidence.
- Microsoft Get-DnsServerZone: https://learn.microsoft.com/en-us/powershell/module/dnsserver/get-dnsserverzone?view=windowsserver2025-ps
- Microsoft Get-DnsServerResourceRecord, including CimSession and DNS scope: https://learn.microsoft.com/en-us/powershell/module/dnsserver/get-dnsserverresourcerecord?view=windowsserver2025-ps
- Microsoft New-CimSession, Credential and Authentication: https://learn.microsoft.com/en-us/powershell/module/cimcmdlets/new-cimsession?view=powershell-7.5
- Microsoft Get-DhcpServerv4Lease, future DHCP interface: https://learn.microsoft.com/en-us/powershell/module/dhcpserver/get-dhcpserverv4lease?view=windowsserver2025-ps
- OWASP Secrets Management Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html
