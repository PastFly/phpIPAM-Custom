# Current build

Current upstream baseline: official phpIPAM 1.8.2, signed tag `v1.8.2`, commit `6e11367e5f43eb9f50bde7f7b25bf65fc53d844d`.

Current stable development `master`: `d5a14986f32df4796fd41e963fd0d7bc359afeff` (Phase 1 squash result).

Active feature branch: `phase4/integrations-framework`.

## Exact runtime evidence

The accepted rack/UI runtime source surface was captured from `/var/www/html` on 2026-09-07 at 15:21:38 +03.

- capture ID: `20260907-152138`;
- archive SHA256: `b01ab9b650d6ff312b244f1591d2c019f311eb58cf39c2094f5cbdb35c0ef64f`;
- captured files: 19/19 expected;
- missing expected files: 0;
- PHP CLI on capture host: 8.2.30;
- all captured PHP files passed `php -l`;
- the live application tree is not a Git worktree.

The binary archive is evidence only and is not durable source in the repository. Durable source is plain UTF-8 plus deterministic deltas and hashes.

## Normalized rack build — merged

Phase 0 established a reproducible clean transformation from official phpIPAM 1.8.2 to the accepted rack/UI build. Phase 1 then added regression coverage and one backend correctness fix.

Current merged behavior includes:

- strict occupied-U filtering in Add-to-rack, Create Device and Edit Device;
- backend overlap/overflow rejection;
- multi-U and Full Depth placement semantics;
- native SVG single-rack drag-and-drop;
- Front↔Rear moves in all directions;
- accepted single-rack details/layout refinement;
- same-Location rack-to-rack drag-and-drop on Rack scheme;
- live rack/device data for cross-rack drag using exact visible rack IDs;
- long translated sidebar label wrapping;
- authoritative Full Depth `rackContents` overlap protection on both physical sides.

Phase 1 regression gate contains 121 assertions, all passing:

- `rack-free-u-regression.php`: 14;
- `rack-backend-regression.php`: 22;
- `rack-endpoint-security-regression.php`: 45;
- `rack-dnd-source-regression.php`: 40.

Cross-Location rack drag/drop is intentionally not planned; manual Location relocation remains the supported workflow.

## Phase 4 — Integrations framework

Phase 4 introduces a separate Integrations product area without changing the database schema and without introducing credential storage yet.

Current feature-branch foundation provides:

- top-level `Integrations` route and navigation entry;
- support for both dynamic and Static phpIPAM menu modes;
- shared metadata-only connector registry;
- `Administration → Integrations` overview guarded by administrator access;
- connector cards for VMware vCenter, Hyper-V, Active Directory, DNS and DHCP;
- VMware vCenter marked as the first implementation target;
- every connector currently read-only;
- no endpoint, username, password, token, private key or bind-secret fields;
- no configuration form, POST handler or object write in the foundation shell.

Exact custom source files:

- `src/app/integrations/registry.php`;
- `src/app/integrations/index.php`;
- `src/app/admin/integrations/index.php`.

Deployment/reproduction artifacts:

- `installer/install-integrations-framework-v1.sh` — fail-closed installer layered on the normalized Phase 1 tree, with backup and rollback;
- `installer/verify-integrations-framework-v1.sh` — route/auth/registry/static verification;
- `tests/integrations-framework-regression.php` — 31 regression assertions;
- `tests/reproduce-clean-v1.8.2.sh` — now reproduces both the normalized rack build and the Integrations framework from official phpIPAM 1.8.2.

## Phase 4 verification

GitHub Actions run `34156334497` succeeded on exact feature head `d3a94936504a0de14f6d47aff334e2a0275b9e21`.

Observed gates:

- official phpIPAM 1.8.2 release SHA256: OK;
- normalized Phase 0/1 installation: OK;
- Phase 4 source blobs/preconditions: OK;
- candidate PHP lint: OK;
- base `VERIFY_OK`;
- `INTEGRATIONS_VERIFY_OK`;
- rack regressions: 121 passed, 0 failed;
- Integrations regressions: 31 passed, 0 failed;
- total regression assertions: 152 passed, 0 failed;
- final clean `REPRODUCE_OK`.

Therefore the Phase 4 foundation is `repository_verified` and reproducible on a clean official 1.8.2 test tree. It is **not yet `runtime_verified` on the live `/var/www/html` server**.

## Next gate

Before any live deployment:

1. perform a read-only live preflight against the current `/var/www/html` tree;
2. determine whether the live tree already contains the Phase 1 Rackspace Full Depth correction or still matches the earlier runtime capture;
3. prepare a backup/rollback-safe incremental live updater for the exact detected state;
4. install only after explicit Owner approval for the production-impacting update;
5. Owner verifies the new Integrations navigation and Administration view in the browser.

After runtime verification, proceed to the VMware/vCenter read-only connector contract and secure configuration storage design.
