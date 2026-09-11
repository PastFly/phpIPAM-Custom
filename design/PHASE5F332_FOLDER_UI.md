# Phase 5F.3.3.2 — independent VM/datastore folder UI

Based on installed/header-accepted `55c664fb91bb361b6c691d5aed33d0f4064ca16b` and the unchanged, live-proven ObjectFolders collector blob `a46c03da333a5d1e2b1ab31ffd358a7b2101394d`. The Owner's preview resolved 636 VMs and 70 datastores with no unknown edges. This does not prove the new browser UI.

## Navigation

- `Папки ВМ` is a new tab; the existing HOST infrastructure tab is retained.
- `Хранилища` opens the independent datastore folder tree; `Плоский список` returns to the original view.
- Original HOST tree/list stay mounted under isolated sibling panes. Their collectors, folder state, scope links, global/header search and VM-card loaders are not replaced.
- Empty folders and root-level objects remain visible. Templates are labeled. StoragePod and VirtualApp use their returned types; names never reclassify folders.
- Selecting a folder filters the complete fetched object set by typed parent IDs, not just visible pagination. `С вложенными` controls descendant inclusion. Local pagination and folder/object filters do not request vCenter.
- Collapsed keys only (no names, inventory, Notes, credentials) are persisted per app base, connector and domain. Invalid/full/unavailable storage degrades to working navigation. Temporary search expansion never writes collapse state. Limit: 512 remembered node keys.
- A separate unresolved bucket preserves objects whose parent could not be corroborated. Unresolved containers are listed without inferred placement. Partial data is labeled, never presented as a complete inventory.

## Read model

One bounded graph fetch on demand, cached in page memory for 300 seconds. VM metadata uses the existing enriched index (completed normal-workspace responses are reused); a failure or an object missing from this secondary source does not remove that VM/template from the folder graph. Datastore capacity/type/free space use an optional bounded existing datastore inventory reader with direct/no-proxy transport. IDs join these projections; no parent inference from names.

Saved connector TLS policy is authoritative. Endpoint requires authenticated administrator, POST and existing Explorer CSRF validation before connector/credential access; enabled connector gate is retained. No raw upstream errors or endpoint/credential values are rendered. Responses use no-store headers. No application objects, schema, unattended scheduler, filesystem inventory cache or vCenter inventory changes.

## Deployment

New targets: the proven collector, one authenticated endpoint, one JS file and one scoped CSS file. One hook is appended to the existing VMware index after the accepted enriched-search layer. All ancestor runtime and tool sources are unchanged and checked. Preflight is read-only and runs the complete inherited 5F.3.3.1 verifier. Stage/lint precede writes; common deployment lock, root-only backup, atomic replacements and guarded automatic/manual rollback are included. New exact head requires live preflight and explicit Owner production approval. A green fixture run is not live browser acceptance.

## Checks and acceptance

Tests cover graph fidelity/cycles/duplicate names, descendants/direct-only filtering, empty folders/templates, pagination, text escaping, connector isolation, cache reuse, optional-metadata failure, collapse persistence, unchanged HOST DOM and standard VM-click delegation. Endpoint and disposable installer tests separately exercise access denial, TLS authority, sanitization, permissions, no-write preflight, activation rollback and refusal to overwrite newer files. The existing native-header/deeplink DOM suite remains in CI.

After installation: check `Инфраструктура → Active Directory`, empty VM folder, `Хранилища → NORD/OST`, independent collapsed state after leaving/re-entering, VM card/network/performance, and native header search. Server snapshot cache 5F.3.4 is not part of this update.
