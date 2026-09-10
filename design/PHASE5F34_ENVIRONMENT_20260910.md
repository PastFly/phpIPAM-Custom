# Phase 5F.3.4 — supplied production environment evidence

Received 2026-09-10 from the Owner's complete terminal output. Execution timestamp and standalone Git HEAD line were not included; do not invent them.

The inherited verifier passed through installed Phase 5F.3.3.2: four folder runtime files exact, ancestor sources preserved. This complements the previous browser evidence without reconstructing the missing installation log or full backup path.

```
PHASE5F332_FOLDER_UI_VERIFY_OK
PHASE5F34_BASE_PHASE5F332_OK=1
PHASE5F34_RUNTIME_ABSENT=1
PHP_CLI_CAPABILITIES={"curl":true,"dom":true,"fsync":true,"posix":true,"sapi":"cli","sodium":true,"version":"8.2.30"}
PHP_WEB_SAPI_NOT_PROBED=1
CACHE_FILESYSTEM=ext4
WEB_RUNTIME_CANDIDATES=[{"gid":33,"name":"www-data","uid":33}]
PREFLIGHT_WRITE_OPERATIONS=0
PREFLIGHT_VCENTER_REQUESTS=0
PREFLIGHT_RESULT=CACHE_ENVIRONMENT_READY_FOR_INTEGRATION
CACHE_ACTIVATION_APPROVED=0
CACHE_ENDPOINTS_CONNECTED=0
UNATTENDED_SCHEDULER_INSTALLED=0
```

Prospective cache `/var/cache/phpipam-custom/vmware` absent; nearest existing `/var/cache` root:root 0755. Separate key `/etc/phpipam-custom/vmware-cache.key` absent; parent `/etc/phpipam-custom` root:33 0750. Reported free bytes: 1989115904. No cache/key contents read and no directories or keys created. DB schema remained valid/version 46, one integration row, Rackspace blob c2c9a7424e6caf996633688dea87dfbc594d7eb4 unchanged.

## Integration decisions

Use explicit runtime identity www-data UID/GID 33, not the root CLI invoker. Preserve the existing keyring and directory metadata. New key must be separate, random and local; future activation requires separate exact-head approval and guarded rollback. CLI capabilities are not web-SAPI validation. Cache-aware endpoints must check their actual SAPI dependencies and fall back to authorized direct reads if unsupported, without using an unsafe/old snapshot or running periodic uncached requests.

Shared snapshots must cover core inventory, HOST folders, coherent VM/Datastore folders and the same enriched index for both searches. Guards and enabled-connector checks precede every cache hit. HTTP authentication/authorization denial invalidates all cached domains for the connector. Partial metadata must not overwrite a complete snapshot. Visible authenticated page refresh does not redraw the selected view; no unattended scheduler under saved INSECURE TLS. Real-time charts, VM/host cards and network-path reads remain on-demand.

Environment readiness is not cache activation or a production deployment authorization. Do not ask the Owner to repeat the unchanged environment survey merely to reconfirm these facts.
