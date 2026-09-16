# Phase 5H.9 — secure disabled-by-default native route candidate

## Status

Stacked source-only candidate on top of Phase5H.8 PR63. Production still serves Phase5G.5. Nothing in this branch is installed live and no config file is created by source development.

The route source now contains the first reviewed local Phase5H selection wiring, but selection remains disabled when `/etc/phpipam-custom/vmware-fast-reader.json` is absent or explicitly disabled. Any live install or enablement remains separately approval-gated.

## Secure configuration boundary

The fixed configuration path is `/etc/phpipam-custom/vmware-fast-reader.json`. There is no request, query-string, environment or CLI override.

`IntegrationVmwareFastReaderConfigLoader` distinguishes missing from invalid state:

- missing path -> `IntegrationVmwareFastReaderConfig::disabled()` and exact Phase5G selection;
- existing path -> read only through the already-reviewed `IntegrationVmwareSnapshotService::readPrivate()` boundary;
- expected profile: root owner, current web group (`www-data` on the reviewed host), mode `0640`, max 4096 bytes;
- malformed JSON or invalid strict config schema -> `fast_reader_configuration_invalid` as a server configuration fault;
- insecure file/profile/path failures from `readPrivate()` remain failures and are not disguised as missing configuration.

The reused `readPrivate()` implementation rejects symlinks in the path chain, requires a regular single-link file with exact owner/group/mode/size, then rechecks inode/device/uid/gid/mode/nlink after open before reading. H9 does not duplicate or weaken those rules.

## Route delta / parity boundary

The H9 controller is based on exact H8 head `b4b3aad50048ccd8ffadd24101b18d32d5d2ac59`.

The only intended behavioral additions are:

1. extend the local runtime class allowlist with the H2/H3/H7/H8/H9 read-side classes;
2. after construction of the existing Phase5G `IntegrationVmwareInventoryReader`, load the fixed server config and wrap the reader through `IntegrationVmwareFastOverlayFactory::selector()`.

Admin authorization, POST-only policy, CSRF validation, request parsing, optional link resolution, native rendering, final connector-binding check, headers and HTTP error mapping remain inherited from the exact H8 controller.

`tests/phase5h9-native-route-parity.test.py` reconstructs the H8 controller by removing only the new class allowlist and two selection lines, then requires byte equality with `git show b4b3aad...:native.php`.

## Disabled semantics

Missing, explicit-disabled or connector-mismatched configuration cannot construct the fast store:

- the loader returns a disabled/scoped config;
- `IntegrationVmwareFastOverlayFactory` creates a selector with `enabled=false`;
- `IntegrationVmwareInventoryQuerySelector` returns the existing Phase5G reader directly and never invokes its overlay factory;
- therefore `IntegrationVmwareInventoryContext::fastStore()` is not called.

Only an existing valid config with `enabled=true` and matching connector id can cross into the lazy fast-store/overlay construction path.

No browser input can enable this path.

## Runtime packaging boundary

The historical `phase5g4-rollout.py` remains unchanged because it contains its own immutable-source guard tied to an earlier baseline.

`tools/build-phase5h9-runtime.py` is a pure package overlay applied after an already-built Phase5G.5 package. It adds only this exact reader allowlist:

- `IntegrationVmwareRosterReconciler`;
- `IntegrationVmwareFastStore`;
- H7 `IntegrationVmwareFastReader`, `IntegrationVmwareFastMetricCompat`, `IntegrationVmwareFastOverlayReader`;
- H8 `IntegrationVmwareInventoryQuerySelector`, `IntegrationVmwareFastReaderConfig`, `IntegrationVmwareFastOverlayFactory`;
- H9 `IntegrationVmwareFastReaderConfigLoader`.

It neither adds provider/transport/session/credential-decryption classes nor mutates system state. Existing package payload/metadata is preserved, collisions fail closed, and the native controller must contain the reviewed selection markers.

## Source acceptance contract

H9 tests cover:

- missing config does not invoke the secure reader and returns disabled config;
- valid config remains connector-scoped and preserves configured age limits;
- invalid/coerced/unknown config fails closed;
- secure-reader/path failures are not downgraded to missing;
- missing/disabled/mismatched route selection performs zero fast-store constructions;
- explicit matching enablement remains lazy and memoized;
- route delta is restricted to the reviewed H9 additions while H8 auth/CSRF/error behavior remains byte-equivalent;
- runtime package extension uses the exact nine-class allowlist and rejects collisions/unwired controllers.

Locally executed before PR creation: PHP 8.4 lint for loader/config/selector/factory integration plus direct disabled/mismatch/enabled selection checks were green. GitHub Actions remains a separate evidence source and is not considered executed unless jobs contain actual steps/logs.

## Production boundary

This PR is not a deployment and does not provision `/etc/phpipam-custom/vmware-fast-reader.json`. Production remains Phase5G.5, Phase5H.6 remains autonomous/non-serving, and Issues #42/#28 stay open.

Before any live action, source review must finish and a separate create-only/rollback-aware install candidate must prove default-disabled behavior against the actual recovery5g5 package. Explicit Owner approval is required before merge to master where governed and before any production installation or enablement.
