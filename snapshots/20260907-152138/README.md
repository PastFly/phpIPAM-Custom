# Runtime snapshot 2026-09-07 15:21:38 +03

Source: live phpIPAM Custom server, application root `/var/www/html`.

Capture archive supplied by Owner:

- `phpipam-custom-runtime-20260907-152138.tar.gz`
- SHA256: `b01ab9b650d6ff312b244f1591d2c019f311eb58cf39c2094f5cbdb35c0ef64f`
- captured file count: 19
- missing expected files: 0
- PHP CLI: 8.2.30
- live tree is not a Git worktree
- all captured PHP files passed `php -l`

The binary archive is intentionally not committed. Source material is tracked as plain UTF-8 files/metadata in this repository.

## Comparison boundary

Upstream baseline is the signed phpipam/phpipam `v1.8.2` tag, resolving to commit `6e11367e5f43eb9f50bde7f7b25bf65fc53d844d`.

`MANIFEST.tsv` records for each captured path:

- official upstream v1.8.2 Git blob SHA when the path exists upstream;
- live runtime Git blob SHA;
- live runtime SHA256;
- classification as modified upstream, unchanged upstream, or custom new file.

Results:

- 12 upstream files are modified in the live build;
- 1 upstream file, `app/tools/tools-menu.php`, is byte-identical to v1.8.2 and must not be carried in the normalized overlay;
- 6 files are custom additions;
- total normalized overlay candidate surface before dead-code cleanup: 18 files.

## Deleted server backups

After the first runtime capture, the Owner removed the timestamped `.rack-*` / `.ui-menu-*` backup directories from the server. This does not invalidate the live snapshot. Clean baseline reconstruction no longer depends on those directories: the official signed `v1.8.2` repository state is the source of truth for pristine files.

## Normalization note

The exact live snapshot still contains at least one superseded experimental residue: `RACK_V333_DEVICE_ID` / SVG no-cache support. Current V3.1.4 and V3.3.5 source selection uses occupied-U hit testing instead of SVG `data-device-id`; this residue is a candidate for removal in the normalized overlay after regression checks.
