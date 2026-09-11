# Phase 5F.3.3.2 — live compatibility evidence and next UI requirements

Recorded: 2026-09-10. Evidence is the Owner-supplied terminal attachment `новый 1.txt`, SHA-256 `26514860caa1aaa54e4d5567cbe2843a5143d0cedfaf174b0690d2714417c517`. No execution timestamp is present; this date records receipt.

## Executed source and result

- PR #27: `phase5f332/vm-datastore-folders`.
- Preview source: `355f8426a6639ba20999adf5a51bba394d3664eb` (terminal HEAD `355f842`).
- Accepted production ancestor: `55c664fb91bb361b6c691d5aed33d0f4064ca16b`, Phase 5F.3.3.1.
- Target runtime used for saved connector configuration: `/var/www/html`.
- Connector: 1; saved TLS INSECURE; direct/no-proxy; legacy `RetrieveProperties`.
- Final marker: `PHASE5F332_PREVIEW_RESULT=READ_ONLY_COMPATIBILITY_OK`.

| Domain | Implicit roots | All Folder nodes, including roots | Non-root folders | Resolved objects | Unresolved objects/containers |
| --- | ---: | ---: | ---: | ---: | ---: |
| VM | 5 | 51 | 46 | 636 | 0 / 0 |
| Datastore | 5 | 9 | 4 | 70 | 0 / 0 |

Reported total: 48 requests, collector duration 760 ms. `partial=false`, `missing_object_count=0`, `unsupported_edge_count=0`. No vApps or datastore clusters were returned in this dataset; their synthetic test coverage is not equivalent to live coverage.

Source installation, persistent inventory, phpIPAM object mutation and vCenter inventory mutation counters are all zero. This verifies collector compatibility only: the new VM/datastore Folder UI has NOT been installed or browser-tested.

## Facts the UI must preserve

- VM and Datastore are independent inventory domains, not projections of the existing HOST Folder tree.
- Membership uses the returned typed stable IDs and parent keys, never name matching.
- VM examples include `Инфраструктура → Active Directory`, `VDI → VDI-CLS`, and `ИБ → PAM → esxi-infosec01`.
- Empty VM folders are present, including `ATC`, `PC`, and `ClonePrepResyncVmFolder`; absence of members must not remove their nodes.
- Under Data Center Prod, Datastore folders are `NetApp-Cluster` (10 objects), `NORD` (23), `OST` (23), and `VDI-NORD-DS` (1), plus one root-level datastore (58 total in that datacenter).
- `NetApp-Cluster` and `VDI-NORD-DS` are explicitly returned as `object_type=Folder`, `kind=folder`. Neither their names nor `group-p` prefixes authorize reclassification as StoragePod.
- Reported counts include implicit domain roots; do not display these technical roots as five extra user folders.

## UI implementation contract — pending

1. Preserve the working HOST/Cluster/ESXi navigation; add a VM Folder view rather than replacing it.
2. Give Datastores their own Folder tree with exact domain-specific membership.
3. Selecting a Folder filters its subtree; show direct and descendant counts without confusing them.
4. Persist collapse preferences using connector ID + domain + typed node key. Never write inventory, Notes, credentials or search index into persistent browser storage.
5. Restore state after AJAX rendering and leaving/re-entering Integrations. Search/filter expansion must not overwrite manual collapse preferences.
6. Preserve native header search, enriched workspace search, exact VM deeplinks, card enrichments, and existing HOST collapse state.
7. Show unknown/racing relationships explicitly; do not hide inventory or fabricate parentage. Retain the existing working view if enrichment fails.
8. Add DOM/access/escaping/deployment regression coverage and a source-pinned, rollback-protected installer. A separate clean UI preflight and exact-head production approval are required before any application deployment.

This document is an evidence-only repository change, not a runtime implementation or a production approval. Server snapshot cache Phase 5F.3.4 remains separate and must later cover the two new domains.

PR evidence comment: `5615040491`.
