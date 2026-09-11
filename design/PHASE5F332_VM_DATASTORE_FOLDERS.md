# Phase 5F.3.3.2 — VM and datastore inventory folders

Base: accepted native-header-search candidate `55c664fb91bb361b6c691d5aed33d0f4064ca16b`.

## Scope

The Owner requested the existing folder UX in **VMs** and **Datastores** too. Do not reuse HOST-folder placement as VM-folder placement. These are separate vCenter inventory domains.

The first slice implements a bounded, fixture-tested read-only collector and CLI compatibility preview. No UI activation or production installer is included in this slice. The next UI slice follows production compatibility evidence rather than guessing folder response shapes.

## Source of truth

- Datacenter.vmFolder and Datacenter.datastoreFolder are explicit, separate domain roots.
- Folder.childEntity and ManagedEntity.parent corroborate typed membership.
- StoragePod is a datastore cluster, not a normal folder. Its childEntity contains Datastores.
- VirtualApp is a separate container; parentFolder/parentVApp and vm/resourcePool describe its inventory placement. VM.parentVApp takes precedence over VM.parent when present.
- Root objects are visually implicit by their reference, NOT because their name equals `vm` or `datastore`.
- Names, datastore VMDK paths, VM network placement and ESXi host folders are never used to infer inventory-folder identity.

Primary API references:
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.Datacenter.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.Folder.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.StoragePod.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.VirtualApp.html
- https://developer.broadcom.com/xapis/vsphere-web-services-api/latest/vim.VirtualMachine.html

## Read/safety model

Explicit-object batches of at most 20, at most 4096 discovered objects and 256 SOAP calls including a reserved logout, depth 32, 4 MiB response cap. A 60-second collection budget limits new requests; cleanup has a separate 5-second limit. Requests use the existing direct/no-proxy transport, saved connector TLS mode and legacy RetrieveProperties. The only method allowlist is RetrieveServiceContent / Login / RetrieveProperties / Logout; no ContainerView, filter creation, changes, scheduled task or write-back.

One session discovers the datacenter folder tree, then VM and datastore domain trees. Only minimal names, parent/child references and VM template flags are read. Errors are categories/fingerprints, not raw XML, endpoint, credentials or cookies. DTD/entity input is rejected. Missing objects, unsupported children, cycles, parent races and unresolvable containers are reported as partial; no fabricated parent is returned. Scope reflects objects visible to the configured vCenter account, not an assertion of account-independent completeness.

## UI contract for the next slice (not installed by preview)

- Keep current Host/Cluster navigation and add a VM-folder mode, rather than replacing Host folders.
- Datastores use their own Datacenter -> Folder -> DatastoreCluster -> Datastore tree.
- VM inventory uses Datacenter -> Folder/vApp -> VM, retaining templates explicitly.
- Folder selection includes descendants; empty folders and objects directly under an implicit root remain visible.
- Persist expansion using browser-local keys partitioned by connector + domain + typed Folder ID; only UI preferences, never inventory/Notes/credentials.
- Keep global/header search broad; a selected Folder must not silently hide global matches.
- On missing/racing folder data, preserve the working ungrouped list and show an unavailable/partial hint instead of hiding objects.
- No server cache in this slice. Phase 5F.3.4 remains pending and should include these domains when implemented.

## Compatibility gate

Run `php tools/live-vmware-phase5f332-object-folders-preview.php /var/www/html 1` from the exact candidate. Review separate VM/datastore roots, folder/node names, parent IDs, StoragePods/vApps and resolved/unresolved counts. `READ_ONLY_COMPATIBILITY_PARTIAL` is deliberately not promoted to a full pass. This writes neither application source nor inventory.
