# Phase 5H.8 — native query-object boundary

## Status

Stacked source-only development on top of Draft PR #62 / Phase5H.7. Branch `phase5h8/native-reader-integration` starts from exact Phase5H.7 head `8873785fd8d976379dff037a259796d9ca2f4437`.

This stage does **not** wire Phase5H into production `native.php`, does not modify `/var/www/html`, does not change systemd or connector/TLS state, and does not authorize a live cutover.

## Problem

`IntegrationVmwareInventoryNative` previously declared a concrete constructor dependency on `IntegrationVmwareInventoryReader` even though the class consumes only the reader's existing `query()` method.

That concrete type prevented the reversible Phase5H.7 overlay from being inserted later without coupling the Phase5G native renderer layer directly to a Phase5H class.

## Boundary

The native adapter now accepts a **query object** rather than one concrete implementation.

Constructor acceptance is deliberately narrow:

- the value must be an object;
- the object must expose a public callable `query()` method;
- everything else fails immediately with `invalid_inventory_query_reader`.

The response path is otherwise unchanged and still calls exactly one `$reader->query($view, $id)` boundary.

This preserves the existing `IntegrationVmwareInventoryReader` object while also permitting another object with the exact same query surface, such as the Phase5H.7 reversible overlay, in a later wiring stage.

## Dependency rules

The native adapter must remain implementation-agnostic. It must not import or name:

- `IntegrationVmwareFastReader`;
- `IntegrationVmwareFastOverlayReader`;
- `IntegrationVmwareFastMetricCompat`;
- `IntegrationVmwareFastStore`;
- Phase5H batch/session/provider classes.

It must also retain the existing local-serving boundary: no PropertyCollector, QueryPerf, cURL/SOAP, credentials, endpoint access, writes, publish/update calls or shell execution.

## Compatibility tests

`tests/phase5h8-native-query-boundary.test.php` proves:

- a legacy-like query object preserves the existing status response shape;
- an overlay-like query object is accepted without the native layer knowing its implementation;
- overlay metadata survives the adapter unchanged;
- `force_refresh` input still cannot turn a local response into collection;
- closure/scalar/object-without-public-query values fail closed;
- reader exceptions such as `mirror_access_denied` remain external to the adapter and can still be mapped by the route layer.

`tests/phase5h8-native-query-boundary.test.py` guards against reintroducing the concrete reader property or any Phase5H/provider/write coupling.

## Serving boundary

No route source is modified in this slice. Production `native.php` still constructs the existing Phase5G `IntegrationVmwareInventoryReader` exactly as before. Therefore this change by itself cannot enable Phase5H serving.

A later wiring slice must remain separately reviewable and disabled-by-default. It must explicitly construct/select the reversible overlay and prove route/renderer parity and Phase5G-only rollback before any production installation is considered.
