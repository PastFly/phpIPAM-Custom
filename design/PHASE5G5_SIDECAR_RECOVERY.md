# Receipt-aware 5G.5 recovery

This package targets only the previously verified paused f7aedad rollout, connector 1,
Debian 12 / Apache mod_php. The parent backup is fixed to the received path
`/var/backups/phpipam-custom/vmware-inventory-20260914-074950-_qwuwnep`.
It is not a new installation over the parent and is not a command to edit its hashes.

## Storage and rollback boundary

The old `/opt/phpipam-custom/inventory`, original receipt, configuration, units and
`/var/lib/phpipam-custom/vmware-inventory` remain unchanged. A new immutable release
uses `/opt/phpipam-custom/inventory-recovery5g5`, a separate private configuration
and `/var/lib/phpipam-custom/vmware-inventory-recovery5g5`. Existing ciphertext is
copied under a shared parent lock, metadata/path/size checked, and hash-verified
before any child collector starts. No credential or snapshot payload is printed.
The old encryption binding is preserved because the application and credentials
are unchanged; private directory names are not part of that binding.

Copying temporarily consumes additional disk space. Provisioning needs the retained
host memory profile, the original free-space reservations, plus one GiB available
beyond the encrypted seed size. Foreign files, symlinks, hardlinks, unexpected units,
changed sources, enabled parent timer or non-paused parent receipt stop the operation.
Known uncommitted .write-* orphans remain in the parent and are not copied.

The child has its own printed `RECOVERY_BACKUP` receipt. Its rollback restores the
pre-cutover website, stops only child units and keeps both encrypted datasets.
The original parent stays paused; restarting its faulty sampler is not rollback.
No database schema, TLS, passwords, keyring or original cache configuration changes.

## Initial work versus normal refresh

Separate child service/timer pairs use fixed `--bootstrap` and `--tick` entrypoints.
They cannot run concurrently through the launcher; both use the same child inventory
lock. Initial mode reuses a complete sufficiently recent publication while first
repairing failed guest sections and sampling missing/failed/expired metric sections.
Already ready metric sections are not repeatedly resampled ahead of missing work.
Normal recurring inventory refresh starts only after the successful website cutover.

The original four-hour publication/collection validity bound is NOT extended. Old
publication timestamps are not rewritten to look fresh. If the retained generation
has aged beyond that bound, actual collection is required. Pending work produced by
the old collector cannot publish old guest errors over repaired data: only the child
copy of a pending generation without the new collector revision is discarded. Its
original ciphertext remains in the parent. A new complete publication still requires
membership consistency, validated object records and domain/search completeness.

Provider variants implement PR38: CPU and RAM aggregate capabilities independent,
null absent counters, and nullable guest hardware NIC association. Real failed
TLS/permission/transport/parser responses do not become unsupported. A validated
provider response with no available aggregate is a successfully collected explicit
absence, not fabricated zero measurements. The actual corrected guest MAC/IP data
is kept even without hardware NIC association.

Readiness retains all original inventory/metric predicates and additionally requires
a valid saved guest-network section per VM. Genuine remaining errors pause rather
than being silently accepted. Original aggregate graph renderer shows available RAM
and the reason for absent CPU; guest-only interfaces show “Без аппаратной привязки”.
Publication completeness and progress of a different refresh are separate fields.
A 900-second lack of progress in the relevant initial stage stops the coordinator
with its child receipt; it does not extend an unproductive four-hour wait forever.

## Cutover, entrypoints and remaining evidence

`python3 -B tools/phase5g5-recover.py recover` prepares and seeds the child, collects
initial data, validates the retained 17 native render contracts, then fully stops
Apache, switches 36 website files and starts Apache and the normal child timer.
ALL Apache virtual hosts are affected by this final stop. Until readiness the
website remains the accepted 5F34 implementation. Browser requests after cutover
use the local-only bridge; force-refresh/missing/stale do not call vCenter.

`resume`, `status`, and `rollback` require the actual CHILD backup argument and the
same recovery script, available in the immutable child release. They never accept
an arbitrary parent receipt as a child. Repeated `recover` over existing child
resources is rejected. Interrupted seeding is retained for review, not auto-resumed.
Interrupted Apache restoration preserves `restoring` for a subsequent safe rollback.

Tests use actual encrypted Store/Sampler, generated source and HTML, and disposable
root-owned seed/cutover/rollback transactions. Host services, DB, and upstream calls
are fixtures. CI is required on PHP 8.2/8.4. Successful source checks are not evidence
of real recovery, initial collection duration, complete optional-panel fidelity,
actual browser latency or no-viewer/restart acceptance; issue28 stays open.
