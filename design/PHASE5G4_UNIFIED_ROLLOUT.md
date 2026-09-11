# Phase 5G.4 — guarded unified rollout

Base master: `4214b055b782bdd40a87467ea83d5d640a1882bc`. Issue #28.
Owner authorized continuing the unified deployment task ("Окей, разрешаю."). Source development and reviewed merges are already authorized. This document is implementation scope, not proof of execution on the production server.

## Fixed target and deliberate maintenance boundary

Debian 12, `/var/www/html`, existing enabled strict-TLS connector #1 at `srv-vcenter01.rbank.local`, runtime UID/GID 33, existing accepted c33/5F34 source and cache. All c33 layer/tool/installer bytes remain protected; rejected 5F35 must be rolled back. TLS setup is not repeated. The installer reads only safe connector metadata and reuses the existing encrypted credentials/key without changing them or the schema.

This delivery deliberately supports **Apache with mod_php only**. It checks `apache2ctl -M` before provisioning; an FPM/other-SAPI host stops for profile review rather than pretending that Apache restart clears that PHP process's OPcache. Memory gates: at least 1536 MiB total and 768 MiB available, plus 1 GiB available on the inventory volume. These conservative gates are not an actual host measurement. The worker retains the reviewed systemd bounds and is also run with PHP memory_limit=256M.

A brief complete Apache stop happens ONLY after the initial inventory and metric readiness gates pass, to avoid serving a mixture of old and new endpoints and to discard its mod_php OPcache. This affects all sites served by that Apache instance. There is no zero-downtime claim. Apache is restarted after the transaction; on a failed switch the exact old sources are restored before restarting it. FPM must not be silently handled through this profile.

## One command, staged internally

`python3 -B tools/phase5g4-rollout.py install` runs source/host checks, full accepted-cache verification, syntax checks, backups, private provisioning and an immutable new worker release. It prepares 34 runtime files plus 2 index replacements, encompassing all 20 native/cache routes. Indexes replace the old visible-page cache middleware hook with the new local-only status module. Both original and former cache routes still have local-only replacements. Current native URL/completion contracts are retained.

The fixed release lives at `/opt/phpipam-custom/inventory` with root-owned 0444 files; it is never overwritten if already present. `release.json` verifies all new worker source and accepted live app dependencies before every PHP worker/readiness invocation. The older application bootstrap and legacy collectors are still dependencies, not a claim of a fully self-contained rebuild. Unsafe paths, hardlinks, modified dependencies, foreign existing units/configuration and target drift are rejected.

New configuration: `/etc/phpipam-custom/vmware-inventory.json`, root:33 0640. Private root `/var/lib/phpipam-custom/vmware-inventory` is root:33 0750; connector and telemetry directories are 33:33 0700. Existing c33 cache configuration/key and CA trust are not altered. The systemd timer is installed/enabled to perform the initial fill independently of browsers. The live site's routes remain unchanged while it runs.

The command waits up to four hours, printing only bounded readiness counts/status, not inventory or credentials. It does not promise that the initial fill will complete within that time. On timeout/interruption it pauses the new timer, preserves encrypted progress and original pages, and prints the actual backup receipt. An uncatchable kill/power loss can leave the timer running, but no page is auto-switched without resuming the coordinator. Never rerun `install` to overwrite a partial preparation.

Readiness validates generation timestamps/shape, complete domain and entity sets, nonempty expected infrastructure, ID agreement between topology/search/cards, all initial performance/storage metric sections, and the current access epoch. Explicit unsupported/empty measurements are accepted as unsupported, not fabricated zeros. Transient/unavailable metric attempts do not pass. Optional nonmetric sections may be unavailable and are counted explicitly; they never cause a live fallback.

The staged CLI also exercises 17 retained native response contracts against the saved data without emitting HTML. This is not authenticated HTTP/browser acceptance. After a ready result, the new worker is stopped; readiness is rechecked under the writer lock, then Apache is stopped and all routes/dependencies/status hooks are switched. Native render/read dependencies are verified again. Apache and the independent timer resume. Actual browser/REST/SOAP compatibility, first-fill time and latency are still established only by execution and user testing.

## Resume, status and rollback

The installer prints `ROLLOUT_BACKUP=/var/backups/phpipam-custom/vmware-inventory-<actual-suffix>`. It contains root-only old/new source files and `receipt.json`. The root-owned immutable release keeps the original coordinator, permitting:

```
python3 -B /opt/phpipam-custom/inventory/tools/phase5g4-rollout.py status ACTUAL_BACKUP
python3 -B /opt/phpipam-custom/inventory/tools/phase5g4-rollout.py resume ACTUAL_BACKUP
python3 -B /opt/phpipam-custom/inventory/tools/phase5g4-rollout.py rollback ACTUAL_BACKUP
```

A receipt can be resumed only in prepared/collecting/ready/paused states. A preparing failure leaves pages unchanged and requires review of the exact receipt; it is not force-retried. A crash during switching/restoring requires guarded rollback, not automatic reuse of unknown state. Every old/new hash is verified before restoration. Newer source edits are never overwritten. Private data, immutable release and backups remain retained; the new timer is disabled and the new config is disabled on rollback, while c33/TLS are preserved. An already rolled-back receipt is not used for another install.

## Evidence limitations

Local tests execute pure readiness gates and real temporary filesystem/ownership transactions for readiness rejection, success, pause/resume, restart failure, interrupted switch, newer-source refusal, source metadata drift, hardlinks/symlinks and partial preparation. DB, Apache, systemd and live collection are doubles in those transaction tests. The source-package test builds the actual git-pinned generated files and checks syntax/unit definitions; CI runs PHP 8.2/8.4 and retained core/sampler/native contracts. No production execution is implied by these fixtures, and no physical power-failure or complete web browser test is claimed.

Mechanics references: PHP OPcache runtime configuration documents web-process restart when timestamp validation is off; systemd service documentation distinguishes oneshot startup/stop deadlines from RuntimeMaxSec. These references do not validate this particular host. Do not clear certificates, repeat old TLS surveys, activate rejected5F35, or describe repository code as a measured speedup.
