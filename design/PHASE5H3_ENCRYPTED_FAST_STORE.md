# Phase 5H.3 — encrypted fast store and binding-scoped capability cache

This source milestone continues issue #42. It remains dormant: the active 5G.5 child, timer, receipt, routes, TLS, DB and encrypted production data are unchanged.

## Fast-store isolation

`IntegrationVmwareFastStore` wraps the already tested `IntegrationVmwareInventoryStore` in four pre-provisioned subdomains: `properties`, `metrics`, `capabilities`, and `roster`. Deployment code must create these directories with the reviewed private ownership/mode; normal readers do not create them.

Each domain derives a distinct secretbox input key from the connector master key and also derives a domain-specific binding. Copying ciphertext between domains therefore fails closed. The same encrypted files opened under a changed connector binding return no authorized current value. This preserves the connector credential/binding boundary while allowing fast properties and metrics to publish independently.

A domain publish is atomic under that domain's own worker lock. The properties domain cannot block a metrics publication merely because another domain is being written. `update()` performs read-transform-write under one domain lock for state machines such as capability caching or roster reconciliation.

The store accepts only the explicit Phase5H domain envelopes. Properties/metrics remain non-authoritative for membership; roster carries the two-pass authoritative state; capabilities contain only bounded capability metadata. There is no transport, credential decryption or HTTP route in this layer.

## Capability cache

`IntegrationVmwareCapabilityCache` is a pure bounded policy intended to be persisted only through the binding-scoped encrypted capabilities domain. VM/host entries contain validated `current_supported`, source-derived refresh rate, and explicit aggregate counter statuses. TTL is limited to 60–3600 seconds. Expired or missing entries are due for revalidation; no stale capability is silently treated as current.

Membership pruning is explicit and is intended to run only after the separate two-pass authoritative roster has confirmed membership. The cache never infers deletion from a partial property or metric batch. Connector binding changes invalidate the entire encrypted domain through the store boundary.

## Roster compatibility

Phase5H.2 roster state is now directly publishable as the `roster` fast domain. It still requires two identical complete passes before membership changes become authoritative. Confirmed absence remains a tombstone/overlay state; physical deletion and retention policy are separate future work.

## Next step

Build a shadow worker and versioned provisioning package with separate directories/unit names. It will consume Phase5H.1 batch acquisition, merge Phase5H.2 fast domains, persist through Phase5H.3 stores, and measure behavior beside the active 5G.5 pipeline without feeding website routes. Shadow acceptance must include no-viewer API request count, wall duration, peak memory, oldest field/metric age, binding/denial behavior and actual source-visible change to local-publication delay.

Only after successful shadow measurements should native readers overlay fast domains and the production timer/worker be migrated. The 60–300 second target remains an acceptance target, not a claim from source tests.
