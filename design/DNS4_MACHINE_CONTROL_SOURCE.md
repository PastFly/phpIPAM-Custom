# DNS4 machine poll/result lifecycle — source milestone

Date: 2026-09-18
Base: DNS3 PR70 exact `196cde5bca9e6df57e55367ee9ceed91d74e8a63`.
Status: source-only machine lifecycle; no HTTP route, credential delivery, Windows task or production migration.

## Scope

DNS4 adds machine-side state transitions for already queued `test_connection` and `list_zones` jobs.

- Collector control token is reauthenticated inside the same serialized configuration transaction used for every poll/result transition.
- Queue rows bind assignment revision in addition to configuration revision, binding epoch and credential version.
- Poll revalidates the current collector assignment and current DNS connection versions before leasing work.
- A lease is random, one-shot and bounded to 120 seconds or the job expiry, whichever is earlier.
- A running lease is never automatically reissued. Lease expiry makes the job terminal; another attempt requires a new explicit admin job.
- Results are accepted only for the current collector, current lease and still-current assignment/configuration versions.
- `list_zones` accepts at most 1024 bounded zone descriptors and stores at most 65536 bytes of normalized JSON.
- Machine result error codes are allowlisted; raw exception text is not stored.
- Terminal jobs older than 24 hours are pruned during poll.

There is still no credential decryption or delivery. Poll returns source server plus version metadata only. A profile-backed job therefore cannot yet execute from this layer alone.

## Persistence

The stacked DNS4 migration extends `custom_dns_control_jobs` with assignment, lease and result fields. Deployment preflight must require the jobs table to be empty before applying this source candidate; no migration is executed by runtime code.

`attempt_count` is constrained to 0..1. This intentionally forbids automatic machine retries in this stage.

## Executed local evidence

Reconstructed exact stacked source on PHP 8.4.23:
- DNS3 control regression: 35 assertions;
- DNS3 phpIPAM repository regression: 23 assertions;
- DNS3 runtime boundary: 44 assertions;
- DNS3 persistence boundary: 28 assertions;
- DNS4 machine lifecycle: 31 assertions;
- PHP lint for DNS3/DNS4 classes/tests: green.

The first local regression run exposed a stale reconstructed DNS3 repository that lacked `removeJob`; exact GitHub source already contained the method. The reconstruction was corrected and the complete suite passed. This was not a product-source defect.

## Next

Add a strict machine HTTP controller/host bridge for poll/result without credentials. Only after that should a separately scoped credential-delivery design be considered, with immediate server-side assignment/config/profile revalidation and no collector-selected profile ID.
