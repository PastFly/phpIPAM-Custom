# Phase 5C — VMware vCenter read-only inventory and reconciliation

Status: repository-only implementation branch.

Base stable master: `e029493d37c133121949cf2678a9d36c7576d757` (Phase 5B.3 squash merge result).

## Goal

Extend the runtime-verified secure VMware connector from connection testing into bounded read-only inventory collection and reconciliation against phpIPAM state.

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

## Inventory order

Implement incrementally:

1. Datacenters;
2. Clusters;
3. ESXi hosts;
4. Virtual machines;
5. VM NIC/MAC observations;
6. guest-observed IP addresses when exposed by vCenter;
7. stable external identity normalization;
8. reconciliation views against phpIPAM Device/IP state;
9. connector health, last inventory timestamp and sanitized error categories.

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

- bounded `GET /vcenter/host` and `GET /vcenter/vm` collection in the existing authenticated session;
- modern `/api` and legacy `/rest` list paths;
- Host stable external id, name, connection state and power state observations;
- VM stable external id, name, power state, CPU count and memory MiB observations;
- state tokens normalized without relying on display names as identity;
- numeric VM fields validated and bounded;
- no parent Host/Cluster/Datacenter relationship is guessed when the list response does not supply it;
- result contains no username, password or session token;
- no schema or phpIPAM object writes.

Current list-level hierarchy fields intentionally remain nullable. Parent relationships will be established only from authoritative API filters/details in a later slice, never inferred from names.

## Phase 5C.3 — VM network observations

Next repository slice.

The modern vSphere API exposes guest network interfaces per VM through a GET-only guest networking interface endpoint. The response can provide guest NIC identity, MAC address and guest-observed IP/prefix/state/origin data. This data is suitable for advisory IPAM reconciliation but must be treated as an observation, not authoritative configuration.

Design constraints:

- per-VM requests must be explicitly bounded to prevent an unbounded N+1 crawl;
- VM IDs must be validated and path-encoded before use;
- VMware Tools unavailable responses must be represented as a sanitized observation state rather than leaking raw API bodies;
- MAC addresses must be canonicalized before matching;
- IPv4/IPv6 addresses and prefix lengths must be validated before entering normalized observations;
- no DNS/WINS/raw guest payload persistence in the first slice;
- no automatic creation or update of phpIPAM addresses;
- legacy compatibility must be evidence-driven and must not be guessed if the endpoint is unavailable on an older API family.

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

## Reconciliation policy

Initial reconciliation is advisory only:

- matched;
- VMware-only observation;
- phpIPAM-only object;
- metadata mismatch;
- IP/MAC mismatch;
- ambiguous match requiring manual review.

No reconciliation result performs an automatic write.

## TLS gate

The currently runtime-verified persistent connector succeeded with TLS verification disabled. Repository work may proceed, but production scheduled inventory remains blocked until the internal vCenter CA is trusted by the phpIPAM host and the stored connector succeeds with TLS verification enabled.

## Deployment / approval gates

- repository implementation stays on `phase5c/vcenter-readonly-inventory`;
- CI must pass on the exact PR head;
- no merge to `master` without explicit Owner approval;
- no new live schema, scheduler, cron/systemd timer or production inventory persistence without a separate explicit Owner approval;
- no secrets in Git, CI, logs, HTML or Continuity.
