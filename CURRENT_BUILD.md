# Current build

Current live baseline: phpIPAM 1.8.2 on Linux, application root `/var/www/html`.

## Exact runtime capture

An exact source-surface capture was taken from the live server on 2026-09-07 at 15:21:38 +03.

- archive: `phpipam-custom-runtime-20260907-152138.tar.gz`
- archive SHA256: `b01ab9b650d6ff312b244f1591d2c019f311eb58cf39c2094f5cbdb35c0ef64f`
- captured files: 19
- missing expected files: 0
- PHP CLI: 8.2.30
- all captured PHP files passed `php -l`
- live server is not a Git worktree

The binary archive is evidence only and is not kept in the current branch tip. Exact file hashes and classification are stored in `snapshots/20260907-152138/MANIFEST.tsv`; source is being imported as plain UTF-8.

## Upstream baseline

Official baseline: signed phpipam/phpipam tag `v1.8.2`, commit `6e11367e5f43eb9f50bde7f7b25bf65fc53d844d`.

Current capture classification:

- 12 modified upstream files;
- 1 captured upstream file is unchanged: `app/tools/tools-menu.php`;
- 6 custom new files;
- normalized overlay candidate: 18 files before dead-code cleanup.

The old server hotfix backup directories were deleted after the exact runtime capture. They are no longer required for normalization; pristine source is taken from the official v1.8.2 Git state.

## Runtime-verified behavior

- occupied U ranges are excluded in Add-to-rack, Create Device and Edit Device;
- backend rejects overlap/overflow;
- multi-U and Full Depth are respected;
- native SVG single-rack drag-and-drop works;
- single-rack drag supports Front↔Rear in all directions;
- rack details layout is customized;
- Rack scheme supports same-Location rack-to-rack drag-and-drop;
- cross-rack device data is loaded live from backend using exact visible rack IDs;
- long translated sidebar labels wrap correctly.

## Normalization work now in progress

1. Preserve exact runtime capture metadata.
2. Import the effective runtime source surface to the feature branch as plain UTF-8 and verify each Git blob against the manifest.
3. Remove superseded/dead experimental residue without changing accepted behavior.
4. Produce an 18-file-or-smaller overlay on top of pristine v1.8.2.
5. Build deterministic `install.sh`, `verify.sh`, and rollback workflow.
6. Validate on a disposable v1.8.2 tree before proposing merge to `master`.

Known dead-code candidate: `RACK_V333_DEVICE_ID` plus SVG no-cache headers were introduced for explicit SVG identity but are no longer required after V3.1.4/V3.3.5 switched to occupied-U hit testing.
