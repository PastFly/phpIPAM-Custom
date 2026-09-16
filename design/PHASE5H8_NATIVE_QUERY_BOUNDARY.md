# Phase 5H.8 — native query-object and lazy selection boundary

## Status

Stacked source-only development on top of PR #62 / Phase5H.7. PR #62 is Ready for Review at exact head `869d52ab288b27e7830bdfb7e6ea8068c4aa186b`. Branch `phase5h8/native-reader-integration` remains Draft and does **not** wire Phase5H into production `native.php`, modify `/var/www/html`, change systemd or connector/TLS state, or authorize a live cutover.

## Native query-object boundary

`IntegrationVmwareInventoryNative` previously declared a concrete constructor dependency on `IntegrationVmwareInventoryReader` even though it consumes only the reader's `query()` method.

The adapter now accepts a narrow query-object contract:

- the value must be an object;
- the object must expose a public callable `query()` method;
- everything else fails immediately with `invalid_inventory_query_reader`;
- the existing response path still calls exactly one `$reader->query($view, $id)` boundary.

This preserves the current `IntegrationVmwareInventoryReader` while permitting another object with the same query surface without coupling the Phase5G renderer layer to a Phase5H implementation.

## Lazy selection boundary

`IntegrationVmwareInventoryQuerySelector` adds an implementation-agnostic construction boundary before any future route wiring.

It receives:

1. the already constructed Phase5G-compatible base query object;
2. a callable overlay factory;
3. an immutable boolean `enabled`, defaulting to `false`.

Its own public `query()` method has the same call shape as the existing inventory reader, so it can be consumed by `IntegrationVmwareInventoryNative` without any new Native type dependency.

Selection rules:

- `enabled=false` returns the base query path and **never invokes the overlay factory**;
- `enabled=true` invokes the factory lazily on the first query only;
- the factory receives the exact base query object;
- a valid overlay query object is memoized for the remainder of that selector instance;
- a factory result without a public callable `query()` fails closed;
- explicit enablement may not silently return the exact base object: that fails with `inventory_overlay_unavailable`;
- factory and reader exceptions are not swallowed, so later route error mapping remains authoritative.

The default-false path is therefore a construction-level rollback boundary, not merely a conditional inside an already-constructed fast reader. A disabled request does not touch a fast store, key, path or Phase5H class through this selector.

## Dependency rules

Both Native and selector layers remain implementation-agnostic. They must not import or name:

- `IntegrationVmwareFastReader`;
- `IntegrationVmwareFastOverlayReader`;
- `IntegrationVmwareFastMetricCompat`;
- `IntegrationVmwareFastStore`;
- Phase5H batch/session/provider classes.

They also retain the existing local-serving boundary: no PropertyCollector, QueryPerf, cURL/SOAP, `IntegrationSecretCipher`, endpoint/credential reads, filesystem mutation, publish/update calls, environment-driven hidden selection or shell execution.

The selector does not decide *how* a fast reader is constructed. That future construction factory must reuse the existing authorized local binding without exposing the cache key and must receive its enablement from an explicit reviewed configuration boundary.

## Compatibility tests

`tests/phase5h8-native-query-boundary.test.php` proves:

- a legacy-like query object preserves the existing status response shape;
- an overlay-like query object is accepted without the native layer knowing its implementation;
- overlay metadata survives the adapter unchanged;
- `force_refresh` cannot turn a local response into collection;
- closure/scalar/object-without-public-query values fail closed;
- reader exceptions remain external to the adapter and can still be mapped by the route layer.

`tests/phase5h8-native-query-boundary.test.py` guards against reintroducing the concrete reader property or Phase5H/provider/write coupling.

`tests/phase5h8-query-selector.test.php` additionally proves:

- disabled selection preserves Phase5G output while the overlay factory invocation count remains zero;
- enabled selection is lazy and memoized;
- the exact base object is passed into the overlay factory;
- the selector object itself works through `IntegrationVmwareInventoryNative`;
- malformed factory results fail closed;
- an enabled factory cannot silently return the base reader;
- authorization/factory errors remain visible and are not swallowed.

`tests/phase5h8-query-selector-boundary.test.py` statically enforces default-false-before-factory ordering and rejects any Phase5H/provider/secret/filesystem/write/environment coupling.

## Serving boundary

No production route source is modified in this stage. Production `native.php` still constructs the existing Phase5G `IntegrationVmwareInventoryReader` exactly as before. Therefore PR #63 cannot enable Phase5H serving by itself.

A later route-wiring slice must explicitly construct a reviewed overlay factory, select it through an explicit disabled-by-default configuration, and prove HTTP/renderer/error/rollback parity before any live installation is considered. Live reader installation/cutover remains a separate Owner-approval-gated action.
