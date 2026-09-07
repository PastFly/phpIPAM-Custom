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

The binary archive is evidence only and is not kept in the current branch tip. Exact file hashes and classification are stored in `snapshots/20260907-152138/MANIFEST.tsv`; durable source is plain UTF-8 plus deterministic deltas against upstream.

## Upstream baseline

Official baseline: signed `phpipam/phpipam` tag `v1.8.2`, commit `6e11367e5f43eb9f50bde7f7b25bf65fc53d844d`.

Official release archive used by the reproduction gate:

- `phpipam-v1.8.2.tgz`
- SHA256: `12745b9cc9f937b219043cc39ee6533b95f9e8ed8b4381ea6f6d96005487bf8d`

Exact capture classification:

- 12 modified upstream files;
- 1 captured upstream file is unchanged: `app/tools/tools-menu.php`;
- 6 custom new files.

## Normalized build

Phase 0 cleanup keeps the accepted behavior and removes obsolete cumulative hotfix residue.

The normalized build consists of:

- 14 exact UTF-8 source files retained from the verified runtime capture;
- a deterministic Rackspace `force_no_overlap` delta against pristine v1.8.2;
- deterministic sidebar wrapping CSS deltas for light and dark themes;
- pristine upstream `app/tools/racks/draw_rack.php`;
- pristine upstream `app/tools/tools-menu.php`;
- no database schema changes.

Superseded V3.3.3 SVG `data-device-id` / no-cache logic is deliberately not carried forward.

## Reproducibility verification

On 2026-09-07 the Owner ran `tests/reproduce-clean-v1.8.2.sh` from exact branch commit `81c0ed1a888113e6b6090d10de2bdb856c29b04e` on the phpIPAM server.

The test did **not** touch `/var/www/html`. It downloaded the official 1.8.2 release into `/tmp`, verified the release SHA256, applied the normalized installer to the disposable pristine tree and ran the verifier.

Verified result:

- official release SHA256: OK;
- pristine v1.8.2 target preconditions: OK;
- candidate PHP lint: OK;
- exact normalized source files: OK (14);
- normalized cleanup invariants: OK;
- PHP lint: OK (15 files);
- behavioral source invariants: OK;
- `VERIFY_OK`;
- `REPRODUCE_OK`.

This establishes a clean static reproduction path from official phpIPAM 1.8.2 to the normalized phpIPAM Custom source state.

A GitHub Actions workflow now repeats the same clean reproduction gate for the Phase 0 PR.

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

## Phase 0 completion gate

Remaining before merge to `master`:

1. GitHub Actions reproduction workflow must pass on the current PR head.
2. PR #1 is marked Ready for review.
3. Merge to `master` only after explicit Owner approval with exact head SHA.
