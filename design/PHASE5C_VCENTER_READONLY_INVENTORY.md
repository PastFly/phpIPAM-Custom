# Phase 5C — VMware vCenter read-only inventory and reconciliation

Status: repository implementation in progress; no Phase 5C source has been deployed to live.

Base stable master: `e029493d37c133121949cf2678a9d36c7576d757` (Phase 5B.3 squash merge result).

## Goal

Extend the runtime-verified secure VMware connector from connection testing into bounded read-only inventory collection and advisory reconciliation against phpIPAM state.

phpIPAM remains authoritative. VMware is an observation source only.

## Hard safety boundary

Phase 5C must not:

- write to vCenter;
- create, move, delete or modify phpIPAM Devices, IP addresses, subnets, racks or Locations automatically;
- expose stored passwords, decrypted credentials, session tokens or raw vCenter response bodies;
- weaken TLS policy or silently disable certificate verification;
- enable scheduled synchronization while the production connector remains `INSECURE`;
- introduce cross-Location rack behavior or touch Rackspace.

All vCenter inventory requests are GET-only except session create/delete needed for authentication/session cleanup.

## Phase 5C.1 — Datacenter and Cluster inventory

Implemented repository-only:

- `IntegrationVmwareVcenterInventory` client;
- modern `/api` session first and legacy `/rest` fallback;
- bounded GET-only Datacenter and Cluster list collection;
- stable vCenter IDs used as external identity;
- Cluster HA/DRS observations;
- existing Phase 5A HTTPS endpoint validation reused;
- redirects disabled, response-size and collection limits retained;
- best-effort session cleanup on success and failure;
- no raw response, credential or token persistence;
- fake-transport regression coverage for modern/legacy paths, malformed JSON, permissions, collection bounds, TLS propagation and secret suppression.

## Phase 5C.2 — ESXi Host and VM inventory

Implemented repository-only:

- bounded Host and VM collection in the existing authenticated session;
- modern `/api/vcenter/host` and `/api/vcenter/vm`, with the corresponding legacy `/rest` list paths;
- Host stable external id, name, connection state and power state observations;
- VM stable external id, name, power state, CPU count and memory MiB observations;
- state tokens and numeric fields validated and normalized;
- no parent Host/Cluster/Datacenter relationship is guessed when the list response does not supply it;
- result contains no username, password or session token;
- no schema or phpIPAM object writes.

Current list-level hierarchy fields intentionally remain nullable. Parent relationships will be established only from authoritative API filters/details, never inferred from names.

## Phase 5C.3 — VM guest NIC/MAC/IP observations

Implemented repository-only in `IntegrationVmwareVcenterGuestNetwork`:

- explicit bounded VM request set; duplicate VM IDs are de-duplicated;
- VM external IDs validated before path use;
- modern GET-only `/api/vcenter/vm/{vm}/guest/networking/interfaces` reads;
- per-VM NIC external id and canonical MAC observations;
- validated/canonicalized IPv4 and IPv6 address, prefix length, origin and state observations;
- flattened IP observation rows retain VM/NIC/MAC linkage for later advisory reconciliation;
- VMware Tools unavailable (`503`) is represented as sanitized `tools_unavailable` state;
- VM not found (`404`) is represented as sanitized `vm_not_found` state;
- 401/403 and malformed responses fail with sanitized connector categories at the caller boundary;
- legacy guest-network endpoint behavior is deliberately not guessed: a legacy session is rejected as unsupported after clean session cleanup;
- no DNS/WINS/raw guest payload persistence;
- no automatic creation or update of phpIPAM addresses.

The N+1 guest crawl is explicitly bounded; the live preview tool further caps manual guest inspection at 250 VMs.

## Phase 5C.4 — advisory VMware/phpIPAM reconciliation

Implemented repository-only in `IntegrationVmwareVcenterReconciler` as a pure in-memory comparator.

Current conservative matching contract:

- VM to phpIPAM Device: exact hostname/name match, case-insensitive only;
- no fuzzy matching, suffix stripping or FQDN guessing;
- duplicate Device-name candidates are `ambiguous`;
- VMware VM without a unique Device match is `vmware_only`;
- untouched phpIPAM Devices are returned as `phpipam_only` observations;
- guest IP to phpIPAM address: exact canonical IPv4/IPv6 match;
- duplicate phpIPAM address candidates are `ambiguous`;
- mismatching observed/stored MAC is `ip_mac_mismatch`;
- a matched VM whose observed IP belongs to another linked phpIPAM Device is `metadata_mismatch`;
- phpIPAM addresses absent from the VMware observation set are returned separately.

The reconciler performs no database queries and no writes. A future adapter will supply normalized phpIPAM Device/address snapshots to this pure comparison layer.

## Phase 5C.5 — no-persistence live preview gate

Repository tool: `tools/live-vmware-phase5c-preview.php`.

Purpose: prove the inventory client against the existing encrypted persistent connector before any schema, scheduled job or inventory persistence is designed.

Safety contract:

- CLI-only;
- loads an existing enabled connector through the Phase 5B encrypted config store;
- performs no health metadata write and no inventory persistence;
- summary-only output: API family and object counts, never username/password/token/raw API bodies;
- password memory is explicitly cleared after use where runtime support permits;
- default behavior refuses a connector with TLS verification disabled;
- a manual preview of the current known-INSECURE connector requires the explicit `--allow-insecure-tls` flag;
- optional guest inspection is bounded by `--guest-limit=N`, maximum 250;
- scheduled sync remains prohibited regardless of that manual override.

No live preview has been executed yet. Running it will make read-only API calls using the stored production credential and therefore remains a deliberate runtime verification action, not a CI action.

## Identity model

Use vCenter stable IDs as the primary external identity whenever the API supplies them. Names are display attributes only and must not be treated as stable identifiers.

Expected normalized identity fields include:

- provider: `vmware_vcenter`;
- connector id at the caller/persistence boundary;
- object type;
- external id;
- parent external id where supplied authoritatively;
- display name;
- relevant observed metadata.

## TLS gate

The currently runtime-verified persistent connector succeeded with TLS verification disabled. Repository work and an explicitly acknowledged manual read-only preview may proceed, but production scheduled inventory remains blocked until the internal vCenter CA is trusted by the phpIPAM host and the stored connector succeeds with TLS verification enabled.

## Next implementation / runtime sequence

1. exact-head CI for all Phase 5C.1–5C.5 repository gates;
2. read-only live preview using the existing stored connector, first core inventory counts only;
3. optionally repeat preview with a small guest VM limit to validate Tools/MAC/IP observations;
4. resolve internal CA trust and re-test the saved connector with TLS verification enabled;
5. only then design inventory persistence/health schema and scheduler as separate Owner-approved phases;
6. build the phpIPAM read adapter and advisory reconciliation UI before considering any automated action.

## Deployment / approval gates

- repository implementation stays on `phase5c/vcenter-readonly-inventory`;
- CI must pass on the exact PR head;
- no merge to `master` without explicit Owner approval;
- no Phase 5C source deployment into `/var/www/html`, new live schema, scheduler, cron/systemd timer or production inventory persistence without a separate explicit Owner approval;
- no secrets in Git, CI, logs, HTML or Continuity.
