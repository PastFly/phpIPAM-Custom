# Phase 2 — Cross-Location rack move design

Status: design gate; no production behavior is changed by this document.

Baseline: `master` after Phase 1, commit `d5a14986f32df4796fd41e963fd0d7bc359afeff`.

## Goal

Extend the existing V3.3.5 rack-to-rack move so a device can be moved between racks that belong to different phpIPAM Locations while preserving the existing rack placement invariants and making Location changes explicit and auditable.

## Current behavior

The existing cross-rack endpoint deliberately rejects a move when source and target racks belong to different Locations. It re-reads the device from the database, derives the source rack from current device state, validates target rack, Front/Rear capability, overflow and overlap, and then updates `devices.rack` + `devices.rack_start`.

The current device edit path stores `devices.location` independently from the rack fields. Therefore a cross-Location rack move must define what happens to `devices.location`; silently changing only the rack would create inconsistent physical metadata.

## Proposed authoritative semantics

### Recommended rule

When a device is moved to a rack in another Location:

- target rack Location becomes the resulting `devices.location`;
- `devices.rack`, `devices.rack_start` and `devices.location` are written together in one device update;
- `rack_size` and `rack_deep` remain authoritative from current stored device state and are never accepted from the browser;
- the user must confirm source Location/rack/U and target Location/rack/U before the write;
- if Locations are disabled globally, cross-Location mode does not exist and current behavior remains unchanged.

This keeps rack placement and Device Location consistent after every successful move.

### Permission rule

A cross-Location move should require:

- Devices: RW;
- Racks: RW;
- Locations: RW when Locations are enabled and the target Location differs from the current rack Location.

Same-Location rack moves retain the existing permission behavior.

A user without Location write permission must not be allowed to indirectly modify `devices.location` by moving a device across Locations.

## Server-authoritative validation

The endpoint must re-read immediately before write:

1. device by id;
2. current source rack from `device.rack`;
3. target rack by id;
4. current source/target Location ids;
5. device `rack_size` and `rack_deep`;
6. target rack `size` and `hasBack`.

The backend then validates:

- authenticated session;
- maintenance mode;
- Devices/Racks/Locations permissions as applicable;
- CSRF token;
- device still belongs to a valid source rack;
- target rack exists;
- target Location exists when Locations are enabled;
- target Rear capability;
- overflow;
- overlap including Full Depth devices and Full Depth rackContents;
- request context is not stale.

Client-provided `rack_size`, `rack_deep`, source rack, source Location or current Device Location must never be authoritative.

## Consistency rule for pre-existing drift

It is possible for historical data to contain a device whose `devices.location` does not match its current rack Location.

Recommended handling:

- do not use `devices.location` as the source-of-truth for rack authorization;
- derive physical source Location from the current source rack;
- show the existing Device Location mismatch in the confirmation as a warning;
- on successful cross-Location move, normalize `devices.location` to the target rack Location in the same write.

This repairs drift without weakening stale-rack validation.

## UX options

### Option A — persistent picked-device state across Location tabs (recommended)

1. User enables rack move mode.
2. User picks/drags a device.
3. A compact floating card remains active: device, size, source Location/rack/U.
4. The selected device id is persisted briefly in `sessionStorage`; no sensitive data is stored.
5. User switches to another Location tab.
6. New page re-fetches authoritative device data by id and restores the picked state only if it is still valid.
7. User drops onto a target rack/U.
8. Confirmation shows the full move before save.

Benefits:

- preserves the existing Rack scheme UI;
- does not render all Locations at once;
- scales to many racks/Locations;
- continues to use native SVG racks;
- stale state is naturally rejected/reloaded from backend.

### Option B — destination dialog/panel

After picking a device, open Location -> Rack -> side -> U selectors backed by live availability data.

This is simpler technically but is less direct than rack drag/drop and duplicates some rack UI.

### Option C — All Locations rack canvas

Render racks from every Location in one page and drag directly between them.

Not recommended as the default because large installations will make the view heavy and difficult to navigate.

## Recommended UX

Use Option A. Keep the current Location tabs and native rack SVGs. Persist only the picked device id plus a short timestamp in `sessionStorage`; after a tab switch, re-fetch the device and rack state from the server before enabling a drop.

The floating picked-device card should provide `Cancel` and should expire automatically after a short interval or any authoritative mismatch.

## Confirmation

Before a cross-Location write, show at minimum:

- device hostname;
- device size and Full Depth state;
- source Location;
- source rack;
- source side and U range;
- target Location;
- target rack;
- target side and U range;
- explicit note that Device Location will change to the target Location.

Same-Location moves can keep the current lighter confirmation.

## Atomic update

For a confirmed cross-Location move, the device update payload should be limited to:

```text
id
rack
target rack_start
location
```

No other Device fields should be rewritten by this endpoint.

## CSRF scope

The current V3.3 token is scoped to a single Location. Cross-Location selection spans source and target tabs, so Phase 2 should use a dedicated move scope that is not derived from client-provided target Location alone.

Recommended scope: device-specific cross-Location move token generated from authoritative selected device state, with the target Location validated independently on submit.

Do not reuse a token design that makes the target Location authoritative merely because it appears in the token name.

## Auditability

Minimum Phase 2 behavior should return source and target Location/rack/U in the JSON response and preserve enough structured information for later move-history logging.

A dedicated persistent rack move history table is useful but is not required for the first Phase 2 implementation; adding DB schema is intentionally deferred unless separately approved.

## Regression requirements

Existing 121 Phase 1 assertions remain mandatory.

Add tests for:

- same-Location move does not unexpectedly change Location;
- cross-Location move requires Locations RW;
- target Location derived from target rack, not POST;
- source Location derived from current source rack, not POST;
- `devices.location` updates with target rack Location;
- rack/rack_start/location written together;
- stale picked device after tab switch rejected;
- stale source rack rejected;
- target rack moved to another Location between fetch and submit rejected;
- Front/Rear and Full Depth invariants across Locations;
- crafted POST cannot override size/depth/source Location;
- sessionStorage state contains no credentials/CSRF token and is revalidated server-side.

## Explicit owner decision required before implementation

Before changing move behavior, confirm this semantic choice:

**Cross-Location rack move automatically changes `devices.location` to the target rack Location, with explicit confirmation and Locations RW permission.**

Recommended answer: yes.
