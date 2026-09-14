# Provider compatibility corrections — source only, not a live recovery

## Actual evidence

Owner's paused deployment probe (original runtime f7aedad; probe886907) returned
three CPU examples as `aggregate_cpu_unavailable`, all HTTP200,
`QueryAvailablePerfMetric`, `provider_current_supported=true`. Two guest-network
examples returned `guest_nic_id_invalid`, with authentication201/read200/logout204.
24 requests total; no snapshot writes, scheduler activation or website changes.
The saved group remains31CPU failures,2missing metric sections and36guest warnings.
Sampled outcomes must not be generalized to every failed object without a new read.
The published644VM/61host/70datastore inventory and the original paused receipt remain.

## Corrections implemented here

The pinned generator derives two explicitly named classes from exact original
source blobs, without replacing any old layer or changing its source pins:
`IntegrationVmwareInventoryPerformance` and `IntegrationVmwareInventoryGuestNetwork`.
These are future worker dependencies, not classes loaded by the current runtime.

CPU and memory aggregate availability is checked independently after a validated
SOAP response. Missing aggregate instances produce explicit unavailable counter
metadata; device/core values are not summed and missing measurements are never
zero-filled. A supported remaining counter is still queried and returned; its
absent companion is null. A valid no-real-time-provider response is distinct from
invalid provider metadata. Empty successfully returned series remain distinct
from absent capabilities. XML faults, wrong/ambiguous operations, malformed
responses, missing counter metadata, wrong units and transport errors still fail.
No catch-all exception-to-unsupported conversion is added.

The guest API's `nic` field is optional. An absent/null hardware-device reference
no longer rejects valid guest MAC/IP observations: the returned reference is null
and hardware_mapping is unmapped. No synthetic Ethernet ID or guessed association
is introduced. Provided empty/malformed/non-string IDs still fail, as do invalid
MAC/IP values and collection limits. This fixes the documented optional-field
contract. The sampled coarse nic_id_invalid output alone does not reveal whether
each live failing interface omitted the field or supplied another invalid value.

## Sources

- https://dp-downloads-pstg.broadcom.com/api-content/apis/API_VAA_003/7.0U1/html/structures/com/vmware/vcenter/vm/guest/networking/interfaces/svc.info-structure.html
  nic is optional; unset means not backed by a virtual device.
- https://developer.broadcom.com/xapis/virtual-infrastructure-json-api/latest/sdk/vim25/release/PerformanceManager/moId/QueryAvailablePerfMetric/post/
- https://dp-downloads.broadcom.com/api-content/apis/API_VSPA_001/8.0U1/html/vim.PerformanceManager.MetricId.html
  the empty instance is the aggregate, unlike a device/core instance.

## Deployment boundary / next recovery work

This PR does not change the old immutable release, original receipt/manifest,
worker timer, current/pending encrypted data, readiness policy or ordinary pages.
Generating a directory is not deployment. Existing release remains paused.
The corrected variants need a separate reviewed receipt-aware migration that
preserves the old release and rollback receipt and switches worker dependencies
atomically. Never overwrite original hashes merely to pass an integrity check.

Before resuming: integrate both variants and explicit counter reasons into worker
and UI contracts; prefer unattempted/failing section work during bounded first fill;
separate an already completed inventory from an ongoing refresh's cursor; preserve
last-good and current authorization, and avoid waiting for an impossible all-ok
aggregate. Genuine failed, unattempted, denied or invalid sections must NOT become
unsupported. Refresh freshness and sample timestamps still require honest labels.
The interrupted pending generation can expire while paused: preserve publications
and account for expiration in recovery rather than promising zero new requests.
Do not ask for another identical diagnostic or rerun install. No production code,
real provider repair or browser/no-viewer acceptance is claimed by source tests.
