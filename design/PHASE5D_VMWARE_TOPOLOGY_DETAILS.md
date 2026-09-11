# Phase 5D — VMware topology, datastore and VM detail explorer

Status: active design and repository implementation on `phase5d/vmware-topology-details`.

Base stable master: `3a7dab8a3a40092f385b0dd1d1ca598ca0938a48` (runtime-verified Phase 5C squash merge result).

## Goal

Turn the Phase 5C flat read-only inventory/reconciliation surface into a navigable VMware infrastructure explorer while keeping phpIPAM authoritative and preserving the no-write safety boundary.

Primary navigation model:

`Datacenter → Cluster → ESXi Host → VM → VM card`

The Owner clarified that “БД” in the prior discussion meant **VMware datastore**, i.e. where the VM/virtual disks are placed, not a database engine running inside the guest.

## Product experience

The final VMware explorer should not render all 631 VMs as one long table by default. It should provide:

- hierarchical drill-down and breadcrumbs;
- search by VM/Host name and exact known identifiers;
- filters by Datacenter, Cluster, ESXi Host, power state, tag/category and datastore when those fields are available;
- pagination/lazy loading for large collections;
- clickable Datacenter, Cluster, Host and VM entities;
- a dedicated VM detail card/page;
- phpIPAM Device/IP links and advisory reconciliation status;
- explicit source/provenance for observed fields where useful.

## Authoritative topology contract

Relationships must be established only from authoritative vCenter identifiers and supported API filters/details.

Do not infer hierarchy from names, prefixes, folders or naming conventions.

Expected chain:

- Datacenter stable external ID;
- Cluster stable external ID and authoritative Datacenter membership;
- ESXi Host stable external ID and authoritative Cluster/Datacenter membership;
- VM stable external ID and authoritative Host/Cluster/Datacenter placement;
- Datastore stable external ID and authoritative VM/Host/Datacenter association.

If an API path cannot prove a relation, surface it as unknown rather than guessing.

## VM card — target fields

### Identity

- VM display name;
- vCenter VM external ID;
- BIOS/VM UUID and/or instance UUID where the supported API exposes them;
- guest hostname when VMware Tools exposes it.

### Placement

- Datacenter;
- Cluster;
- ESXi Host;
- folder/resource pool where authoritatively available;
- datastore(s) used by the VM;
- per-disk datastore/backing information where the API exposes it safely.

### Compute

- power state;
- vCPU count;
- memory MiB/GiB;
- hardware/version metadata where available.

### Guest

- guest OS;
- VMware Tools status/version where available;
- guest hostname;
- all observed guest NICs;
- MAC addresses;
- IPv4/IPv6 addresses with prefix/origin/state.

### Networking

- vNIC identity/type where exposed;
- backing network / portgroup where exposed;
- MAC and IP observations;
- linked phpIPAM IP Address records and reconciliation state.

### Storage

- datastore name and stable datastore external ID;
- datastore type, capacity/free space when available;
- virtual disks and provisioned/capacity information where available;
- VM ↔ datastore relationship must be authoritative; do not infer solely from a VMDK path string when a stable-ID filter/detail route is available.

### Metadata

Target read-only fields, only after the exact API/property path is evidenced and regression-tested:

- VM Notes / Annotation / Description;
- VMware Tags and Categories;
- Custom Attributes / Custom Fields;
- folder/resource pool metadata.

Do not guess Notes/Description/Tags from VM names.

### phpIPAM linkage

- exact matched phpIPAM Device ID/hostname;
- exact matched phpIPAM IP Address IDs;
- MAC/IP consistency;
- `matched`, `vmware_only`, `phpipam_only`, `ambiguous`, `metadata_mismatch`, `ip_mac_mismatch` states;
- click-through to phpIPAM Device/IP pages where safe and permission-consistent.

The current Phase 5C exact-name Device matcher remains conservative. Phase 5D may enrich matching with additional **evidenced stable signals** (for example exact MAC/IP or explicit UUID metadata) but must not introduce fuzzy/name-guess matching.

## Datastore clarification and design

Datastore is a first-class topology entity in Phase 5D.

Target model:

`Datacenter → Cluster/Host → VM → Virtual Disk → Datastore`

and also direct datastore browsing:

`Datacenter → Datastore → VMs`

Use vCenter datastore stable IDs as identities. Exact datastore names are display attributes, not primary identity.

Where the modern vCenter API supports filtering VMs by datastore ID, use that relationship rather than parsing datastore names out of VMDK paths. VMDK backing paths may be shown as metadata but should not be the sole source of the relationship when an ID-based API relation exists.

## Read-only API boundary

Phase 5D remains read-only.

Allowed network operations:

- authentication/session create and best-effort session delete;
- GET-only inventory/detail/filter calls;
- a non-GET endpoint may be introduced only if VMware exposes a semantically read-only operation that has no GET equivalent (for example some tagging APIs), and only after a separate source review, strict endpoint allowlist and regression coverage prove that it cannot mutate vCenter state.

Not allowed:

- VM power/control operations;
- create/reconfigure/migrate/delete VM;
- tag/category mutation;
- datastore mutation;
- phpIPAM Device/IP/Rack/Location mutation;
- automatic reconciliation write-back.

## Scale and request bounds

Production evidence currently shows:

- 5 Datacenters;
- 4 Clusters;
- 61 ESXi Hosts;
- 631 VMs.

Therefore the explorer must avoid unbounded N+1 detail crawling.

Rules:

- list views use bounded collection calls and server-supported filters;
- VM detail calls are primarily on-demand when a VM card is opened;
- guest NIC/IP, disks, datastore, tags and notes are fetched on-demand or in explicitly bounded batches;
- UI must paginate/lazy-load large collections;
- no automatic full 631-VM guest/details crawl in a normal page render.

## Planned implementation slices

### Phase 5D.1 — topology relations

- authoritative Datacenter → Cluster mapping;
- authoritative Cluster → Host mapping;
- authoritative Host → VM mapping;
- preserve stable IDs and unknown relation states;
- repository fake-transport tests first.

### Phase 5D.2 — datastore inventory and relationships

- datastore list with stable ID/name/type/capacity/free space where exposed;
- Datacenter/Host/VM datastore membership through supported ID filters/details;
- VM card shows datastore(s);
- no datastore-name guessing.

### Phase 5D.3 — VM detail model

- on-demand VM details;
- UUID/identity;
- guest OS/hostname/Tools;
- bounded NIC/IP detail reuse from Phase 5C;
- virtual disks and backing metadata;
- network backing metadata where exposed.

### Phase 5D.4 — metadata enrichment

- Notes/Annotation/Description if an evidenced read-only property path exists;
- Tags/Categories through an explicitly reviewed read-only tagging path;
- Custom Attributes when an evidenced path exists.

### Phase 5D.5 — explorer UI

- breadcrumbs;
- Datacenter/Cluster/Host/VM drill-down;
- search/filter/pagination;
- VM card;
- phpIPAM links and reconciliation badges;
- no giant unbounded default VM table.

## TLS and scheduler gate

The live connector is still `TLS INSECURE`.

Manual read-only exploration may continue only with explicit per-request acknowledgement while TLS verification is disabled.

Scheduled inventory/persistence remains prohibited until:

1. the internal vCenter CA is trusted by the phpIPAM host;
2. the saved connector succeeds with TLS verification enabled;
3. persistence/scheduler design is separately reviewed and explicitly Owner-approved.

Phase 5D itself does not require a new production schema or scheduler.

## Approval and deployment rules

- development stays on `phase5d/vmware-topology-details`;
- repository tests/CI first;
- no merge to `master` without explicit Owner approval;
- no live `/var/www/html` deployment without a separate explicit Owner approval after a read-only preflight;
- no secrets in Git, CI, logs, HTML or Continuity;
- source remains plain UTF-8; no manual base64 source encoding.
