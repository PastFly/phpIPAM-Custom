# Phase 5C — VMware vCenter read-only inventory and reconciliation

Status: repository implementation and live no-persistence inventory preview verified; Administration read-only inventory/reconciliation UI prepared repository-only and not yet deployed.

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

Implemented and repository verified:

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

Implemented and repository verified:

- bounded Host and VM collection in the existing authenticated session;
- modern `/api/vcenter/host` and `/api/vcenter/vm`, with corresponding legacy `/rest` list paths;
- Host stable external id, name, connection state and power state observations;
- VM stable external id, name, power state, CPU count and memory MiB observations;
- state tokens and numeric fields validated and normalized;
- no parent Host/Cluster/Datacenter relationship is guessed when the list response does not supply it;
- result contains no username, password or session token;
- no schema or phpIPAM object writes.

Current list-level hierarchy fields intentionally remain nullable. Parent relationships will be established only from authoritative API filters/details, never inferred from names.

## Phase 5C.3 — VM guest NIC/MAC/IP observations

Implemented and repository verified in `IntegrationVmwareVcenterGuestNetwork`:

- explicit bounded VM request set; duplicate VM IDs are de-duplicated;
- VM external IDs validated before path use;
- modern GET-only `/api/vcenter/vm/{vm}/guest/networking/interfaces` reads;
- per-VM NIC external id and canonical MAC observations;
- validated/canonicalized IPv4 and IPv6 address, prefix length, origin and state observations;
- flattened IP observation rows retain VM/NIC/MAC linkage for advisory reconciliation;
- VMware Tools unavailable (`503`) is represented as sanitized `tools_unavailable` state;
- VM not found (`404`) is represented as sanitized `vm_not_found` state;
- 401/403 and malformed responses fail with sanitized connector categories at the caller boundary;
- legacy guest-network endpoint behavior is deliberately not guessed: a legacy session is rejected as unsupported after clean session cleanup;
- no DNS/WINS/raw guest payload persistence;
- no automatic creation or update of phpIPAM addresses.

The N+1 guest crawl is explicitly bounded; interactive/live preview paths cap guest inspection at 250 VMs.

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

The reconciler performs no database queries and no writes.

## Phase 5C.5 — no-persistence live preview gate

Repository tool: `tools/live-vmware-phase5c-preview.php`.

Runtime verified against the production vCenter through the existing encrypted connector on exact head `4e53260081681a9c713f9f3a750bda38bf4ae53d`.

Observed core inventory:

- API family: `modern`;
- Datacenters: 5;
- Clusters: 4;
- ESXi Hosts: 61;
- VMs: 631.

Observed bounded guest sample (`--guest-limit=5`):

- requested VMs: 5;
- guest interfaces: 5;
- guest IP observations: 5;
- guest statuses: `ok=5`.

The preview declared and preserved:

- `persistent_inventory_written=false`;
- `phpipam_objects_modified=false`;
- `vcenter_objects_modified=false`.

The saved production connector still has TLS verification disabled. This runtime evidence used the deliberate manual `--allow-insecure-tls` override; that override never authorizes scheduled inventory.

## Phase 5C.6 — phpIPAM read projection and Administration inventory UI

Implemented repository-only; not yet deployed to live.

Read adapter: `IntegrationPhpIpamInventorySnapshot`.

It performs bounded SELECT-only projections of fields required for advisory comparison:

- `devices`: id and hostname;
- `ipaddresses`: id, address, linked Device (`switch`) and MAC;
- IP addresses are converted to canonical text and validated;
- MACs are canonicalized when valid;
- malformed source values are skipped/ignored rather than entering reconciliation;
- no INSERT/UPDATE/DELETE or phpIPAM object mutation API is present.

Administration UI foundation:

- enabled saved VMware connectors with credentials only;
- administrator session required;
- dedicated CSRF scope `integration_vmware_vcenter_inventory`;
- core inventory view for Datacenters, Clusters, ESXi Hosts and VMs;
- advisory reconciliation view against the live read-only phpIPAM projection;
- optional guest-network sample bounded to 0/5/25/100/250 VMs;
- TLS-disabled connector requires explicit per-request manual acknowledgement;
- decrypted credential is never rendered and is cleared after use;
- result/error output is sanitized;
- no connector health metadata is persisted by inventory viewing;
- no inventory/reconciliation state is persisted.

Deployment tooling:

- `installer/install-vmware-vcenter-phase5c-ui.sh`;
- `installer/verify-vmware-vcenter-phase5c-ui.sh`;
- `tests/phase5c-ui-deployment-regression.sh` exercises apply → verify → rollback on a disposable runtime tree.

The production installer is fail-closed on exact Phase 5B.3 live source, stages/lints candidates before mutation, creates a rollback backup, adds only the Phase 5C PHP source surface and restores the prior Phase 5B.3 Administration index on rollback. It does not change DB schema, connector rows, credentials, keyring, Rackspace or scheduler configuration.

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

The currently runtime-verified persistent connector succeeds with TLS verification disabled. Repository work and explicitly acknowledged manual read-only inventory may proceed, but production scheduled inventory remains blocked until the internal vCenter CA is trusted by the phpIPAM host and the stored connector succeeds with TLS verification enabled.

## Next implementation / runtime sequence

1. obtain green exact-head CI for the Phase 5C Administration UI installer/verifier/regressions;
2. with separate Owner approval, deploy only the Phase 5C read-only Administration UI source layer to `/var/www/html`;
3. verify the UI in browser first with core inventory, then advisory reconciliation with a small guest sample;
4. resolve internal CA trust and re-test the saved connector with TLS verification enabled;
5. only after TLS is trusted, separately design/approve inventory persistence, health metadata and scheduler behavior;
6. keep all reconciliation advisory until a future explicit Owner decision defines any write workflow.

## Deployment / approval gates

- repository implementation stays on `phase5c/vcenter-readonly-inventory`;
- CI must pass on the exact PR head;
- no merge to `master` without explicit Owner approval;
- no Phase 5C source deployment into `/var/www/html`, new live schema, scheduler, cron/systemd timer or production inventory persistence without a separate explicit Owner approval;
- no secrets in Git, CI, logs, HTML or Continuity.
