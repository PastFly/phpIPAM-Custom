# Phase 5H.8 — native query-object, lazy selection and authorized fast-store boundary

## Status

Stacked source-only development on top of PR #62 / Phase5H.7. PR #62 is Ready for Review at exact head `869d52ab288b27e7830bdfb7e6ea8068c4aa186b`. Branch `phase5h8/native-reader-integration` remains non-serving and does **not** wire Phase5H into production `native.php`, modify `/var/www/html`, change systemd or connector/TLS state, or authorize a live cutover.

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

It receives the already constructed Phase5G-compatible base query object, a callable overlay factory, and an immutable boolean `enabled` defaulting to `false`. Its own `query()` method has the same call shape as the existing inventory reader.

Selection rules:

- `enabled=false` returns the base query path and **never invokes the overlay factory**;
- `enabled=true` invokes the factory lazily on the first query only;
- the factory receives the exact base query object;
- a valid overlay query object is memoized for that selector instance;
- a factory result without a public callable `query()` fails closed;
- explicit enablement may not silently return the exact base object: that fails with `inventory_overlay_unavailable`;
- factory and reader exceptions are not swallowed, so later route error mapping remains authoritative.

The default-false path is therefore a construction-level rollback boundary, not merely a conditional inside an already-constructed fast reader. A disabled request does not touch a fast store, key, path or Phase5H class through this selector.

## Authorized fast-store bridge

The existing `IntegrationVmwareInventoryContext` already owns the cache master key and current connector binding. Exposing either value through a getter would expand the secret boundary unnecessarily, so Phase5H.8 does **not** add key/binding accessors.

Instead, `IntegrationVmwareInventoryContext::fastStore()` performs a narrow object-construction bridge:

- accepted root is exactly `/var/lib/phpipam-custom/vmware-fast-shadow`;
- an invalid root is rejected before any authorization/database recheck;
- `current()` is re-evaluated immediately before construction, so disabled/changed connector identity fails with `mirror_access_denied`;
- only after that check are the private master key and binding passed directly into `IntegrationVmwareFastStore`;
- the key and binding are never returned to the caller.

`IntegrationVmwareFastStore` itself uses `IntegrationVmwareInventoryStore::directory()` for existing-directory ownership/mode/symlink validation, so constructing a reader store does not provision directories.

The current exact shadow root is intentionally fixed. The autonomous Phase5H.6 scheduler currently supports connector `1` and publishes to this root. A multi-connector fast-domain layout must be designed separately rather than guessed inside the reader cutover.

### Phase5G.5 recovery compatibility

The source was checked against `tools/build-phase5g5-runtime.py`. That builder takes `layers/phase5g/functions/classes/class.IntegrationVmwareInventoryContext.php`, rewrites only the Phase5G inventory config/data paths to the recovery-specific paths, then stages the transformed Context into both the release and app payload. The new `fastStore()` bridge therefore survives that transformation while its fast shadow root stays independent from the Phase5G.5 recovery inventory root.

This is important because production currently serves the Phase5G.5 recovery release, not the untransformed base Phase5G paths.

## Strict reader configuration contract

`IntegrationVmwareFastReaderConfig` is a pure value object. It does not read files, environment variables, request data or database state.

The canonical `disabled()` profile is always available and uses the existing 300-second upper freshness bound. An explicit enabled profile is accepted only through `fromArray()` with the **exact** key set:

- `schema=1` as an integer;
- `enabled` as a real boolean;
- `connector_id` as an integer in `1..2147483647`;
- root exactly `/var/lib/phpipam-custom/vmware-fast-shadow`;
- `max_property_age`, `max_metric_age`, `max_roster_age` as integers in `60..300` seconds.

Unknown keys, missing keys, coerced strings/integers, alternate paths or out-of-range ages fail with `fast_reader_config_invalid`. `enabledFor()` additionally scopes enablement to the exact connector id; an enabled profile for another connector behaves as disabled.

This class deliberately contains **no loader**. A future root-owned loader can be reviewed separately; browser POST values or environment variables therefore cannot currently enable Phase5H serving.

## Lazy overlay factory

`IntegrationVmwareFastOverlayFactory` composes the already-reviewed pieces without provider or credential access:

1. it receives `IntegrationVmwareInventoryContext`, the strict config object and the current connector id;
2. it creates `IntegrationVmwareInventoryQuerySelector` using `config->enabledFor(connectorId)`;
3. only inside the selector's lazy factory closure does it call `Context::fastStore()`;
4. it constructs `IntegrationVmwareFastReader` with the configured 60..300 second age bounds;
5. it constructs the reversible `IntegrationVmwareFastOverlayReader` with the base query callable and `[$context, 'current']` authorization guard.

Because the fast-store call lives inside the selector closure, a disabled profile or connector mismatch performs **zero** fast-store constructions and does not touch Phase5H data. Once enabled, the selector memoizes the constructed overlay for that request object while the overlay itself continues to recheck authorization on reads.

The factory does not load configuration and does not import a provider/session/transport/secret-cipher path. It cannot itself activate the feature; it only consumes an already-reviewed config value.

## Dependency rules

Native and selector layers remain implementation-agnostic. They must not import or name Phase5H reader/store/provider classes.

The `fastStore()` bridge is the only deliberate Phase5H store type reference in the Phase5G Context. Its method body may not read endpoint/username/password/secret-envelope values, decrypt credentials, perform transport/provider calls, write/publish/update data, use environment-driven hidden selection, or expose the private key/binding.

The H8 config/factory layer may name the reviewed H7 reader/overlay classes but may not perform PropertyCollector, QueryPerf, cURL/SOAP, `IntegrationSecretCipher`, credential/database reads, filesystem mutation, shell execution or hidden environment/request-based selection.

## Compatibility tests and executed evidence

`tests/phase5h8-native-query-boundary.test.php` / `.py` cover the generic Native query-object boundary. Previously locally executed evidence:

- `PHASE5H8_NATIVE_QUERY_ASSERTIONS=14`;
- `PHASE5H8_NATIVE_QUERY_BOUNDARY_ASSERTIONS=34`;
- PHP lint green.

`tests/phase5h8-query-selector.test.php` proves disabled factory isolation, lazy/memoized enabled selection, exact base identity passed to the factory, Native compatibility, explicit no-silent-base behavior, and error propagation. Standalone local execution passed 16 functional assertions plus 49 core static assertions; the repository integration test is retained for CI/source review.

`tests/phase5h8-fast-context.test.php` exercises the Context bridge through an isolated reflection/stub fixture without reading real secrets or `/var/www/html`. Local execution passed:

- `PHASE5H8_FAST_CONTEXT_ASSERTIONS=12`;
- 38 core static boundary assertions;
- PHP lint green.

It verifies exact root, direct key/binding handoff to the store constructor, authorization recheck, changed credential binding denial, disabled connector denial, and no construction on rejected paths/bindings.

`tests/phase5h8-fast-overlay-factory.test.php` covers canonical disabled behavior, connector mismatch isolation, lazy construction, exact root, configured age propagation, authorization callback wiring and strict config validation. The current exact source was locally executed with PHP 8.4 and passed:

- `PHASE5H8_FAST_OVERLAY_FACTORY_ASSERTIONS=31`;
- PHP lint green for config, selector, factory and the functional test.

`tests/phase5h8-fast-overlay-factory-boundary.test.py` contains 88 assertions total. The config/factory core (83 assertions) was locally executed against the current exact source; five additional assertions pin the still-unwired `native.php` route and are verified by repository source readback/CI when a runner is available.

GitHub Actions continues to show the known repository infrastructure condition where jobs finish with `steps=null`/`logs_url=null`; such runs are not counted as executed source tests.

## Serving boundary

No production route source is modified in this stage. Production `native.php` still constructs the existing Phase5G `IntegrationVmwareInventoryReader` exactly as before. Therefore PR #63 cannot enable Phase5H serving by itself.

The construction stack is now complete enough to separate the next concern cleanly. The next source stage should be a **separate disabled-by-default route/config-loader candidate**, not further expansion of this PR. It must load only a root-owned reviewed config, construct the selector/factory behind the existing admin/CSRF/local-reader boundary, and prove HTTP/renderer/error/rollback parity before any live installation.

Live reader installation/cutover remains a separate Owner-approval-gated action.
