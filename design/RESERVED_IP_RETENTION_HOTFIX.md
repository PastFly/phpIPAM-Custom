# Reserved IP retention hotfix

## Production evidence

Read-only production diagnostic on 2026-09-22 showed:
- configured offline-removal limit: 2592000 seconds / 30 days;
- settings updateTags=1;
- Reserved tag: id observed as3, locked=Yes, updateTag=1;
-13 Reserved addresses currently exist;
- all13 are exposed to ping automation;
- pingCheck runs every15 minutes;
- remove_offline_addresses runs daily at02:30;
- live cleanup source has no Reserved exclusion;
- live pingCheck source allows any updateTag=1 state to be changed to Used/Offline.

The hotfix does not rely on the observed numeric id3.

## Required invariant

Reserved is allocation intent, not liveness state.

Automated liveness handling may update lastSeen/hostname for a Reserved address, but:
1. ping automation must not change its Reserved tag;
2. offline cleanup must never delete it;
3. manual administrator edits/deletion remain outside this hotfix.

## Source changes

The guarded tool patches only:
- /var/www/html/functions/scripts/pingCheck.php
- /var/www/html/functions/scripts/remove_offline_addresses.php

Reserved identity is resolved semantically from ipTags/address_types type=Reserved. No state id is hardcoded.

Defense in depth also changes the Reserved ipTags row updateTag to0 by semantic type lookup. This protects other updateTag-aware scan paths in addition to the cron pingCheck patch.

The tool is dry-run by default. Apply requires --apply, root, exact source anchors/counts, regular single-link files and a working local MariaDB client. It creates a private backup/receipt before file replacement. File writes are atomic os.replace operations preserving owner/mode. Re-application fails closed.

## Non-goals

- no change to the30-day policy for ordinary non-Reserved addresses;
- no change to ping/discovery cadence;
- no automatic restoration of reservations already deleted in the past;
- no DNS/VMware changes;
- no production execution without separate approval.

## Verification

Source contract:23 assertions green against exact tool blob9bc72fcc1e1103484434eb3bbafa03b076440b7c.

A transform fixture is included to verify five ping tag guards, semantic cleanup exclusion, no hardcoded Reserved id and fail-closed repeat application. CI evidence is accepted only if runner steps actually execute.
