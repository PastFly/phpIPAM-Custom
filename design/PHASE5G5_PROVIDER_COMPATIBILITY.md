# Provider compatibility corrections — source only, not a live recovery

## Actual evidence

Owner's paused deployment probe (original runtime f7aedad; probe886907) returned
three CPU examples as aggregate_cpu_unavailable, all HTTP200,
QueryAvailablePerfMetric, provider_current_supported=true. Two guest-network
examples returned guest_nic_id_invalid, authentication201/read200/logout204.
24 requests total; no snapshot writes, scheduler activation or website changes.
The saved group remains31CPU failures,2missing metric sections and36guest warnings.
Sampled outcomes must not be generalized to every failed object without a new read.
The published644VM/61host/70datastore inventory and original paused receipt remain.

## One canonical implementation

The interrupted continuation had already created draft PR38 at922ff5a7. A later
continuation created overlapping PR39 at8531cba. These histories are retained as
parents of the consolidated work on PR38; PR39 is superseded, not a second runtime.
The canonical build-phase5g5-collectors.py entry now delegates to exactly one pinned
generator build-phase5g5-provider-compat.py. Both fixture suites exercise its same
output. Original collector tests remain unchanged.

The generator derives IntegrationVmwareInventoryPerformance and
IntegrationVmwareInventoryGuestNetwork from two exact legacy blobs. No old layer,
source pin, running release or class is patched in place. Strict TLS is mandatory.
CPU and memory aggregate availability is checked independently after a validated
SOAP response. Missing aggregate instances produce explicit per-counter reasons;
device/core values are not summed and missing measurements are never zero-filled.
A supported remaining counter is retained; its absent companion is null. Negative
provider sentinels are null, not negative utilization. A valid no-realtime-provider
response is distinct from invalid capability metadata. Empty successful series
are distinct from absent capabilities. XML faults, wrong/ambiguous operations,
malformed responses, missing required counter metadata, wrong units and transport
errors still fail. No catch-all exception-to-unsupported conversion is added.

The guest API's nic field is optional. An absent/null hardware-device reference
no longer rejects valid guest MAC/IP observations: nic_external_id is null and
hardware_association is unassociated. No synthetic Ethernet ID or guessed mapping
is introduced. Provided empty/malformed/non-string IDs still fail, as do invalid
MAC/IP values and collection limits. This fixes the documented optional-field
contract. Coarse live nic_id_invalid output alone does not distinguish an omitted
field from a supplied malformed value on every failing interface.

## Primary references

- https://dp-downloads-pstg.broadcom.com/api-content/apis/API_VAA_003/7.0U1/html/structures/com/vmware/vcenter/vm/guest/networking/interfaces/svc.info-structure.html
  nic is optional; unset means not backed by a virtual device.
- https://developer.broadcom.com/xapis/virtual-infrastructure-json-api/latest/sdk/vim25/release/PerformanceManager/moId/QueryAvailablePerfMetric/post/
- https://dp-downloads.broadcom.com/api-content/apis/API_VSPA_001/8.0U1/html/vim.PerformanceManager.MetricId.html
  the empty instance is the aggregate, unlike a device/core instance.

## Runtime boundary and next recovery work

These distinct classes are NOT loaded by the current paused worker. This source
change does not alter the original release/receipt/manifest, timer, current/pending
encrypted data, readiness policy or ordinary pages. Generating files is not deploy.
Integrate the variants and explicit counter reasons into worker/UI contracts in a
separate reviewed receipt-aware migration. Preserve old release and rollback
receipt; never edit original hashes merely to pass an integrity check.

Before resume, prioritize unattempted/failing sections during bounded first fill,
separate published inventory from an ongoing refresh cursor, retain dated last-good
and current authorization, and avoid waiting for an impossible all-ok aggregate.
Genuine failed/unattempted/denied/invalid sections must NOT become unsupported.
An interrupted pending generation can expire while paused: preserve publications
and account for expiration rather than promise zero new requests. No new identical
probe, blind install, real provider repair or browser acceptance is claimed here.
