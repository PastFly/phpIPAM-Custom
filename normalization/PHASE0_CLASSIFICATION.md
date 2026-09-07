# Phase 0 normalization classification

Baseline: official signed `phpipam/phpipam` tag `v1.8.2` (`6e11367e5f43eb9f50bde7f7b25bf65fc53d844d`).

Runtime evidence: capture `20260907-152138`, archive SHA256 `b01ab9b650d6ff312b244f1591d2c019f311eb58cf39c2094f5cbdb35c0ef64f`.

## Exact runtime classification

The 19-file capture contains:

- 12 modified upstream files;
- 6 new custom files;
- 1 unchanged upstream file (`app/tools/tools-menu.php`).

The exact file/blob mapping is in `snapshots/20260907-152138/MANIFEST.tsv`.

## Keep in normalized build

### Rack placement correctness

Keep the V2/V2.1 behavior:

- strict free-U calculation for Add-to-rack;
- strict free-U calculation for Create/Edit Device;
- dynamic position recalculation when Size/Full Depth changes;
- backend overlap and overflow rejection;
- multi-U and Full Depth handling;
- self-exclusion while editing an existing device.

Files:

- `functions/classes/class.Rackspace.php` — keep only the `force_no_overlap` extension to `free_u()`;
- `app/admin/racks/edit-rack-devices.php`;
- `app/admin/racks/edit-rack-devices-result.php`;
- `app/admin/racks/edit-rack-devices-free-u.php`;
- `app/admin/devices/edit.php`;
- `app/admin/devices/edit-rack-dropdown.php`;
- `app/admin/devices/edit-result.php`.

### Single-rack native SVG drag-and-drop

Keep current runtime-verified V3.1.4 behavior:

- native phpIPAM SVG remains primary UI;
- explicit Move Mode;
- occupied-U source hit testing;
- parent-page drag layer;
- Front→Front, Front→Rear, Rear→Front, Rear→Rear;
- authoritative backend overlap/overflow checks.

Files:

- `app/tools/racks/print-single-rack.php`;
- `app/tools/racks/rack-v3.1-controller.php`;
- `app/tools/racks/rack-v3-move.php`.

### Rack detail layout

Keep V3.2 layout swap in `app/tools/racks/print-single-rack.php`.

### Same-location cross-rack drag-and-drop

Keep current runtime-verified V3.3.5 behavior:

- exact visible rack IDs from DOM;
- live backend rack/device/content data;
- occupied-U source hit testing;
- parent-page drag layer;
- same-Location restriction;
- server-side validation.

Files:

- `app/tools/racks/print-racks-map.php`;
- `app/tools/racks/rack-v3.3-map-controller.php`;
- `app/tools/racks/rack-v3.3-data.php`;
- `app/tools/racks/rack-v3.3-move.php`.

### Sidebar wrapping

Keep the append-only V1.2 CSS block in both:

- `css/bootstrap/bootstrap-custom.css`;
- `css/bootstrap/bootstrap-custom-dark.css`.

The official v1.8.2 files end immediately before this block, so the normalization can apply it as a deterministic append-only delta.

### Devices menu order

Keep the currently tested runtime order for now:

`Devices → Locations → Racks → Circuits`.

This originated during an early interpretation of the UI request, but the Owner visually accepted the resulting menu. Do not silently revert a working user-visible behavior during Phase 0. It can be reconsidered as a separate UX change later.

File: `app/tools/tools-menu-config.php`.

## Remove from normalized build

### Superseded V3.3.3 explicit SVG identity

Do **not** carry forward:

- `RACK_V333_DEVICE_ID` in `functions/classes/class.Rackspace.php`;
- `data-device-id` injection into SVG anchors;
- `RACK_V333_DEVICE_ID` no-cache headers in `app/tools/racks/draw_rack.php`.

Reason: both V3.1.4 and V3.3.5 now identify source devices from occupied U + live rack data. Explicit SVG-link identity is no longer part of the selection path.

Therefore normalized `draw_rack.php` remains the pristine upstream v1.8.2 file.

## Unchanged upstream

`app/tools/tools-menu.php` is byte-identical to upstream v1.8.2 and is not part of the normalized overlay.

## Normalized overlay size

The clean build therefore consists of:

- 14 exact UTF-8 source files copied from the verified runtime snapshot;
- 1 targeted Rackspace delta (`force_no_overlap` only);
- 2 deterministic CSS append-only deltas;
- no `draw_rack.php` modification;
- no `tools-menu.php` modification;
- no database schema changes.

## Reproducibility gate

Before Phase 0 can be marked `reproducible_verified`, the clean installer must be executed against a pristine phpIPAM 1.8.2 tree and the regression verifier must pass. The live production-like server should not be overwritten merely to prove this gate.
