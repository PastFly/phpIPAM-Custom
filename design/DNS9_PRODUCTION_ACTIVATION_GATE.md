# DNS9 production activation gate

This proposal concerns only the existing `DNS E2E Pilot` connection on `srv-dc01.rbank.local`. It is not an approval to execute it. DNS collection remains read-only; no DNS or phpIPAM IP-address writes are included.

## Intended steady state

- Connection `33b6fade9323f15aa7b1f56a8a865a9d` uses CollectorIdentity and a 300-second collection interval.
- Windows task `phpIPAM Microsoft DNS Collector` runs as SYSTEM and polls once a minute.
- Linux timer `phpipam-dns-autonomous.timer` is enabled and active; its service schedules read-only zone-list jobs.
- Existing result and queue retention continue to apply. Terminal jobs can be removed after 24 hours; under queue pressure, verified automatic terminal history older than an hour can be pruned while preserving the most recent terminal and successful zone result per source. Manual and unknown-origin rows are not pressure-pruned.

## Preconditions

Immediately before any change, repeat the accepted read-only preflight, including exact installed file and backup hashes, task identity/action/trigger, clock agreement, disabled/inactive timer, disabled pilot connection, zero other enabled connections, no running or unexpected pending jobs and no near-term 24-hour pruning. The only allowed pending jobs are the two previously reviewed expired rows `0f5fbf81fc71164cc7c624fc6101925f` and `edfcd75935083e51c4536a390174057e`, each still matching its exact job, assignment, actor and audit evidence. Any drift stops the operation.

Before enabling either scheduler, take a root-only backup of the full two job rows, their audit events and the pilot connection state, and verify its checksum. Retain existing receipts and guards. Normal polling or scheduling may then mark those two rows `expired`; neither row should be manually reset or deleted. Future 24-hour retention remains applicable.

## Controlled transition

Use the existing shared DNS configuration mutex while rechecking the two jobs and activating the components, so normal phpIPAM configuration or queue writes cannot race the transition. Enable the existing Windows task and Linux timer while holding that mutex, then enable only the pilot connection with its normal revision/binding-epoch increment and audit event, and commit. The Windows task may run immediately because its trigger is active and StartWhenAvailable is set; any such work must wait for the mutex. Do not invoke a manual collector run.

Changing the binding epoch makes the previous 172-zone snapshot ineligible for display until a new result for the current connection state arrives. This is expected; do not relabel an old result as current.

## Verification and stop behavior

Observe the first naturally scheduled job by exact ID, current connection/collector and revisions. Verify one successful attempt, cleared lease, successful result and valid zone payload; confirm both reviewed old rows remain present with their expected normal expired status. Verify the Linux timer remains enabled/active, the scheduler service has run, and the Windows task has run after activation. Compare the recorded result and show the new zone count without logging zone names or credentials.

If any step fails or the outcome is uncertain, prevent future scheduling by stopping/disabling the timer and disabling the Windows task when reachable. Do not terminate an already running collector or scheduler, manually reset jobs, delete guards or blindly repeat the operation. A failure after the connection commit can leave the connection enabled while schedules are disabled; report that state explicitly and inspect it before further changes. If Windows cannot be reached, its task state is unknown and requires read-only reconciliation. Keep all backups and the activation attempt marker.

The operation is accepted only after the first automatic cycle and its authoritative database result are verified. A successful readiness preflight alone is not acceptance or Owner approval.
