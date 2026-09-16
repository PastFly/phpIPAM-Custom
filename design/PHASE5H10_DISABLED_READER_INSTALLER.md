# Phase 5H.10 — create-only disabled reader candidate installer

## Status

Source-only staging layer stacked on accepted Phase5H.9 PR64 head `db218eb391b7399a4c51b95a86f18a3c2778d44d`.

This phase does **not** enable the Phase5H reader in production. It prepares a separately isolated candidate tree only. Production continues to serve Phase5G.5 and Phase5H.6 continues to collect autonomously in non-serving mode.

The first explicitly approved staging attempt against old exact PR65 head `eae09e00c9ec14dc752c43f4403be6cae85285c8` stopped fail-closed in live preflight before any H10 persistent transaction began. The discovered source defect was an incorrect `0644` expectation for the active recovery helper; the governing Phase5G rollout stores and verifies release files as root:root `0444`. PR65 was returned to Draft while this guard was corrected and regression-bound to the original rollout invariant.

No retry on a new PR65 head is authorized by the old exact-head approval.

## Candidate boundary

The only intended candidate release is:

`/opt/phpipam-custom/vmware-fast-reader-candidate`

The live reader configuration path remains:

`/etc/phpipam-custom/vmware-fast-reader.json`

H10 never creates or modifies that live config. Instead the candidate tree contains a staged copy at:

`config/vmware-fast-reader.json`

with exact disabled semantics:

- schema `1`;
- `enabled=false`;
- connector id `1`;
- fast root `/var/lib/phpipam-custom/vmware-fast-shadow`;
- property/metric/roster max age `300` seconds.

The bundle manifest states `create_only=true`, `serving_cutover=false`, and all website/systemd/database/connector/TLS/provider mutation flags are false.

## Exact 12-file overlay

The candidate contains an exact 12-file application overlay pinned to accepted H9-stack Git blobs:

1. `IntegrationVmwareInventoryContext`;
2. generic-query `IntegrationVmwareInventoryNative`;
3. H9 native controller;
4. `IntegrationVmwareRosterReconciler`;
5. `IntegrationVmwareFastStore`;
6. H7 `IntegrationVmwareFastReader`;
7. H7 `IntegrationVmwareFastMetricCompat`;
8. H7 `IntegrationVmwareFastOverlayReader`;
9. H8 `IntegrationVmwareInventoryQuerySelector`;
10. H8 `IntegrationVmwareFastReaderConfig`;
11. H8 `IntegrationVmwareFastOverlayFactory`;
12. H9 `IntegrationVmwareFastReaderConfigLoader`.

Every source is verified by exact Git blob before packaging. Source drift fails closed.

### Recovery5G.5 Context transformation

The accepted H8 Context source still contains the generic Phase5G inventory paths. H10 applies exactly two deterministic substitutions when building the candidate:

- `/etc/phpipam-custom/vmware-inventory.json` → `/etc/phpipam-custom/vmware-inventory-recovery5g5.json`;
- `/var/lib/phpipam-custom/vmware-inventory` → `/var/lib/phpipam-custom/vmware-inventory-recovery5g5`.

Both source anchors must occur exactly once. The transformed Context must retain the reviewed `fastStore()` boundary and exact shadow root. H10 never modifies the accepted source file in Git; only candidate bytes are transformed.

## Live preflight boundary

A production execution of the H10 installer is separately approval-gated for each exact head. If approved, preflight requires all of the following before candidate creation:

- effective UID 0;
- exact active recovery script blob `36b19f12e95b2f4884b4f625c755ce3df0db460a`;
- exact active recovery script metadata root:root mode `0444`, matching the immutable release-file contract enforced by `phase5g4-rollout.py::verify_release()`;
- recovery5g5 `status` reports `RECOVERY_STAGE=active` and `CHILD_AND_PARENT_RECEIPTS_VERIFIED=1` against the reviewed child backup;
- serving timer `phpipam-vmware-recovery5g5@1.timer` active/enabled;
- Phase5H scheduler timer `phpipam-vmware-fast-shadow@1.timer` active/enabled;
- exact shared lock regular file UID/GID 33, mode `0600`, size 0;
- Phase5H shadow root and four domains UID/GID 33, mode `0700`;
- live reader config absent;
- candidate release absent.

The installer snapshots SHA256 for these three live application read-path files before candidate creation and requires the same SHA256 after staging:

- `functions/classes/class.IntegrationVmwareInventoryContext.php`;
- `functions/classes/class.IntegrationVmwareInventoryNative.php`;
- `app/integrations/vmware/mirror/native.php`.

H10 intentionally does **not** hardcode a guessed live webroot SHA baseline because the production host has accumulated reviewed hotfixes. The staging acceptance is zero mutation: bytes before and after must be identical. A later cutover phase must obtain and review the exact live replacement baseline before any webroot change.

Both live boundary functions are covered by a dedicated static contract. Their bodies may query recovery status, `systemctl show`, lock/timer metadata and webroot hashes, but may not call candidate write helpers, create/delete paths, mutate systemd, touch the database or invoke provider APIs.

## First approved staging attempt — safe preflight stop

Owner explicitly approved H10 staging-only execution for exact PR65 head `eae09e00c9ec14dc752c43f4403be6cae85285c8` with no live config/webroot/systemd/DB/connector/TLS/cutover.

Observed server output before the installer call:

- fetched exact head matched `eae09e00...`;
- builder blob matched `cbc4d5ba4d5370f6ee8aee37b08b73aff7652b1e`;
- installer blob matched `34c7e30b71b6079287f326faa25d9a6c3e60cffa`;
- independent external lock/shadow/timer preflight passed (`H10_EXTERNAL_PREFLIGHT_OK=1`).

The installer then emitted:

- `PHASE5H10_INSTALL_ERROR=phase5h10_file_boundary`;
- `PHASE5H10_INSTALL_EXIT=1`;
- `H10_INSTALL_PROCESS_RC=1`;
- `H10_STAGING_ACCEPT=0`.

Source review traced this to `verify_live_boundary()` requiring the active `phase5g5-recover.py` release artifact to be root:root mode `0644`. The governing rollout contract is the opposite: `phase5g4-rollout.py` publishes release files root:root `0444` and `verify_release()` rejects any other mode. The H10 pre/post live boundary was corrected to `0444`; static regression now verifies both H10 checks and the originating rollout invariant.

The failure occurred before `load_builder()`, before audit-backup creation and before candidate-tree creation in `install()`. Therefore this H10 attempt did not begin the persistent staging transaction. No H10 candidate or H10 receipt is claimed from this failed attempt.

## Transaction model

The installer creates a root-owned private audit backup/receipt only after the complete live preflight passes, then creates only the isolated candidate tree.

Candidate file publication uses:

1. a new private temporary inode;
2. full write + `fsync`;
3. final owner/mode verification;
4. an exclusive hard-link publication via `os.link()` so an existing target can never be overwritten;
5. removal of the temporary name;
6. exact device/inode/hash verification;
7. parent-directory `fsync`.

No `rename()`/replace operation is used to overwrite a candidate payload target.

Created directories are registered as transaction-owned immediately after successful `mkdir`, before owner/mode mutation. A pre-existing nested directory is rejected unless it is already present in this transaction's tracked directory list.

## Rollback ownership

Rollback deletes only a file that simultaneously matches:

- exact tracked path;
- exact tracked `st_dev`;
- exact tracked `st_ino`;
- exact tracked SHA256.

A replacement file is therefore foreign even if it has byte-identical content. It is preserved and rollback is marked incomplete rather than deleting an inode the transaction did not create. The replacement-inode test creates both original and replacement concurrently before moving the replacement onto the tracked path, so the identity difference is deterministic rather than filesystem-dependent.

Directories are removed only from the transaction's tracked directory list and only when empty. There is no recursive delete. The audit backup/receipt is retained on successful preparation and on rollback after transaction start.

## Explicit non-actions

H10 staging source has no path that:

- writes `/var/www/html`;
- creates `/etc/phpipam-custom/vmware-fast-reader.json`;
- starts/stops/enables/disables/reloads systemd units;
- writes the database;
- changes connector credentials or TLS state;
- calls vCenter/provider transport;
- changes Phase5G.5 inventory data;
- changes Phase5H shadow data;
- performs serving cutover.

The only `systemctl` use in the production preflight is read-only `systemctl show`.

## Source tests

H10 has four direct suites:

- `phase5h10-disabled-bundle.test.py` — exact 12-file allowlist, exact pins, disabled config, recovery Context transform, no provider/webroot payload;
- `phase5h10-installer-transaction.test.py` — create-only success, preflight rejection, injected partial-failure rollback, enabled-bundle rejection, audit receipt retention, and deterministic replacement-inode preservation;
- `phase5h10-installer-boundary.test.py` — static no-webroot/systemd/DB/TLS/provider mutation boundary, exact immutable recovery-release metadata tied to the Phase5G rollout invariant, exclusive publication, directory tracking, inode-bound rollback, no runtime override channel;
- `phase5h10-live-boundary-static.test.py` — read-only production preflight/postcheck contract, exact root:root `0444` recovery artifact check, and no candidate/webroot/systemd/DB/provider mutation inside the live boundary functions.

The dedicated workflow also reruns H9 native route parity and runtime-package tests so H10 cannot silently weaken the accepted H9 serving candidate contract.

## Approval boundary

Source development, tests, Draft PR creation and review are autonomous project work.

The following remain separate explicit Owner gates:

1. merge/retarget of the stacked H7/H8/H9/H10 PRs where governed;
2. each H10 production staging execution for the exact reviewed PR head;
3. any creation of the live reader config;
4. any copy/replacement into `/var/www/html`;
5. any `enabled=true` configuration or serving cutover.

The old live approval for exact `eae09e00c9ec14dc752c43f4403be6cae85285c8` does not authorize execution of a later hotfix head.

Issues #42 and #28 remain open. H10 staging alone is not served-path acceptance and proves no user-visible freshness improvement.
