# Phase 5C — VMware vCenter read-only inventory and reconciliation

Status: repository-only design and implementation branch.

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

All vCenter API requests are GET-only except session create/delete needed for authentication/session cleanup.

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

## Phase 5C.1 — repository foundation

First slice:

- extend the existing VMware client with read-only inventory methods;
- support modern `/api` first and preserve legacy `/rest` fallback where practical;
- use the existing persistent connector/config-store and encrypted credential path;
- keep response-size and timeout limits;
- preserve redirect blocking and HTTPS-only endpoints;
- normalize inventory into stable internal arrays without persisting raw vCenter payloads;
- add fake-transport regression coverage for request ordering, auth/session cleanup, pagination or filtering behavior where relevant, malformed responses and permission failures;
- add no new production schema in the first slice unless a later repository review demonstrates it is required.

## Identity model

Use vCenter stable IDs as the primary external identity whenever the API supplies them. Names are display attributes only and must not be treated as stable identifiers.

Expected normalized identity fields include:

- provider: `vmware_vcenter`;
- connector id;
- object type;
- external id;
- parent external id where applicable;
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
