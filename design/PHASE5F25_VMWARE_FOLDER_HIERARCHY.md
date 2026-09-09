# Phase 5F.2.5 — VMware inventory folder hierarchy

## Goal

Render the VMware Explorer infrastructure tree closer to the native vCenter inventory hierarchy by preserving VMware Folder objects instead of flattening every Datacenter directly to Cluster/Host.

Target example:

```text
Datacenter
├─ NORD
│  ├─ esxi-2-09-OS
│  ├─ esxi-2-10-OS
│  └─ ...
├─ OST
│  ├─ ESXi-1-09-DATA
│  └─ ...
└─ NetApp-Cluster
   └─ <cluster/hosts as returned by vCenter>
```

Nested VMware folders must also remain nested when present.

## Authority and identity

Folder names are display labels only. Identity and membership must come from stable vCenter Folder IDs and supported read-only vSphere Automation API filters.

Relevant modern API surfaces:

- `GET /api/vcenter/folder`
  - `type`
  - `datacenters`
  - `parent_folders`
- `GET /api/vcenter/host?folders=<folder-id>`
- `GET /api/vcenter/cluster?folders=<folder-id>`
- later, if VM folders are exposed in Explorer, `GET /api/vcenter/vm?folders=<folder-id>`

Do not infer folder membership from host/VM names such as `NORD`, `OST`, prefixes or DNS naming conventions.

## Initial scope

Phase 5F.2.5 initially targets **HOST-type** VMware folders in the left Infrastructure tree because this matches the Owner-provided vCenter view.

The desired rendering order is:

```text
Datacenter
  Folder (0..N nested levels)
    Cluster
      ESXi Host
    Standalone ESXi Host
```

Objects for which a reliable folder relation cannot be established remain visible in a bounded fallback group; they are never hidden and placement is never guessed.

## Read-only / safety boundary

- no vCenter create/update/delete inventory operations;
- session create/delete only for authentication lifecycle;
- no phpIPAM DB persistence;
- no phpIPAM Device/IP/Rack/Location mutation;
- bounded folder count, relationship request count and response size;
- folder support is additive to the existing Datacenter/Cluster/Host/VM stable-ID topology;
- Phase 5D remains the protected fallback.

## Delivery sequence

1. run a production read-only compatibility preview against the saved connector;
2. confirm real `NORD`, `OST` and other folder IDs/membership from vCenter;
3. implement a bounded `IntegrationVmwareVcenterFolderInventory` collector;
4. extend the in-memory Explorer projection with folder relations;
5. extend tree rendering and breadcrumbs without changing VM-card behavior;
6. add model/UI regressions, source-pinned preflight, verifier and rollback-protected installer;
7. production deployment only after separate explicit Owner approval.

Phase 5F.2.5 is stacked on Phase 5F.2.4 exact head `577180b82f79dcc2dca417de9e9f153753ebde39`. If the accepted Phase 5F.2.4 baseline changes during live validation, this branch must be restacked before deployment.
