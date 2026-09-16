# Phase 5H.6 — autonomous non-serving shadow scheduler

## Goal

Run the already live-accepted Phase5H shadow collector autonomously without browser activity while production continues serving Phase5G.5. This stage does **not** switch website/native readers to Phase5H.

## Runtime model

- Service template: `phpipam-vmware-fast-shadow@.service`
- Timer template: `phpipam-vmware-fast-shadow@.timer`
- Enabled instance: connector `1` only
- Service identity: `www-data:www-data` (UID/GID 33)
- Installed wrapper: `/opt/phpipam-custom/vmware-fast-shadow/tools/phase5h6-scheduled-cycle.sh`
- Shadow runner: `/opt/phpipam-custom/vmware-fast-shadow/tools/phase5h4-shadow-run.php`
- Shadow data: `/var/lib/phpipam-custom/vmware-fast-shadow`
- Shared upstream mutex: `/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock`

The service itself is unprivileged. Root is used only by the create-only installer to place the wrapper/unit files, run one sandboxed pre-enable cycle, daemon-reload and enable the Phase5H timer.

## Cadence and serialization

The timer uses `OnUnitActiveSec=60s`, `RandomizedDelaySec=3s`, `AccuracySec=1s`, and `Persistent=true`. The wrapper waits up to 45 seconds for the exact serving Phase5G.5 worker mutex. A lock timeout is exit 75, configured as a successful transient skip (`SuccessExitStatus=75`) so the autonomous timer continues rather than competing with Phase5G.5.

The runner is bounded by a 125-second external timeout and the service has `TimeoutStartSec=180s`. The timer/service model plus `flock` prevents concurrent upstream Phase5H work.

## Security / write boundary

The service uses systemd hardening including `NoNewPrivileges`, `PrivateTmp`, `PrivateDevices`, `ProtectSystem=strict`, kernel/control-group protections and restricted address families.

Write access is explicitly limited to:

- `/var/lib/phpipam-custom/vmware-fast-shadow`
- the exact shared worker lock file

The website, active Phase5G.5 release, `/etc/phpipam-custom`, and other protected filesystem paths are read-only under the service sandbox. Connector secrets are never supplied through argv or environment; the existing runner obtains its runtime through the established connector/binding path.

## Installer transaction

`tools/install-phase5h6-scheduler.py` is create-only. Before changing systemd it verifies:

- serving Phase5G.5 timer remains active/enabled;
- worker lock ownership/mode/shape;
- shadow data ownership/modes;
- exact live-accepted Phase5H.5 runtime blobs from merged PR60;
- source wrapper syntax and non-serving boundary.

It refuses pre-existing scheduler targets. It creates a receipt under `/var/backups/phpipam-custom`, installs root-owned wrapper/unit files, verifies installed hashes, runs `systemd-analyze verify`, daemon-reloads, and performs one real service start under the final systemd sandbox before enabling the timer.

Any failure removes only files created by this transaction, disables the Phase5H timer if enablement may have started, and daemon-reloads. It never stops/disables/modifies the Phase5G.5 timer.

## Acceptance required before reader integration

1. Multiple autonomous no-viewer publications observed without directly invoking the runner.
2. Publication cadence and source-visible freshness remain within the accepted target.
3. Serving Phase5G.5 timer remains active/enabled throughout.
4. Timer restart and service failure recovery are demonstrated.
5. Shared-lock contention is demonstrated without stopping Phase5G.5.
6. Website/native readers remain on Phase5G.5 during this entire stage.

Only after these conditions pass should a Phase5H native-reader/cutover design be introduced.