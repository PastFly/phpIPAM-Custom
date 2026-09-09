# Phase 5F.3.3.1 — native phpIPAM/header search

The Owner clarified by an arrow on the screenshot that the requested main search means the top-right global phpIPAM header, not only the VMware workspace inputs. Phase 5F.3.3 MAC search is browser-confirmed; no original installer log/rollback path was provided.

This additive layer extends the native `app/tools/search/index.php` results page. The header, `js/magic.js`, native result queries, permissions, export and search preferences are not rewritten. A VMware block is inserted before native results; no VMware failure may suppress those native results.

## Runtime

- Admin-only panel lists enabled connectors with credentials using metadata-only `list_configs`.
- Existing exact Phase 5F.3.3 index endpoint is reused behind a no-store response wrapper. Session, admin, CSRF, enabled-config and saved-TLS enforcement remain server-side in that endpoint.
- One connector at a time is read asynchronously. Up to five enabled connectors can be searched sequentially as All; with more connectors the user selects one. Errors are isolated per connector.
- In-page memory cache: five minutes. No notes/index data in persistent browser storage, SQL or filesystem. No timer/scheduler or per-keystroke vCenter fan-out.
- Literal case-insensitive search over existing Notes/tags/guest/placement data; also MAC normalization for colon, hyphen, dotted and compact forms. `*` uses ordered literal substring matching, not user regex evaluation.
- All hits are paginated locally, 50 per page. Unavailable tags are explicitly marked as incomplete rather than reported as zero tags.
- VM links contain stable connector ID and VM ID in a URL fragment only. Notes/MAC/query are not added to the destination URL.
- The deeplink initializer is installed BEFORE the existing Phase 5F.1 auto-loader. It sets initial controls without another inventory request, waits for the matching workspace response and reuses the normal guarded VM-card click. Manual navigation cancels pending auto-selection. Missing/disabled connector or missing VM is not guessed.

## Deployment

Base: `056372227e2e00eb3001af52744f2d461cd751ba`, Phase 5F.3.3. New runtime files: four; existing files changed: native search index and VMware index only.

Native index must exactly match phpIPAM 1.8.2 blob `87b8e1b0325eaaf6d0ede366c7fe1afb6590ac71`. Any live customization stops preflight for review; never overwrite drift. Source bytes are read from the pinned checkout commit and compared against the working source before installation. The ancestor Phase 5F.3.3 verifier remains unmodified and is called before/after installation.

Installer stages/lints before live writes, serializes deployment with a file lock, keeps protected backup/receipt, installs additions before activation, and atomically replaces files. All exceptions after activation attempt rollback. Manual rollback validates current hashes first, refusing to overwrite a later/unknown update. It is standalone and does not depend on the temporary repository remaining present.

Production is NOT modified by repository CI or the read-only preflight. A separate exact-head Owner deployment approval is still required.

## Next

Phase 5F.3.4 server snapshot caching remains pending. Restack it on the final accepted native-search baseline; do not claim this layer removes the first inventory/index fetch after a page reload.
