# Runtime snapshot 2026-09-07 15:21:38 +03

Captured from `/var/www/html` using `tools/capture-current-runtime.sh`.

Evidence:
- upstream runtime reported: phpIPAM 1.8.2
- PHP CLI: 8.2.30
- capture root: `/var/www/html`
- archive SHA256: `b01ab9b650d6ff312b244f1591d2c019f311eb58cf39c2094f5cbdb35c0ef64f`
- expected customized files missing: none
- PHP lint: all captured PHP files passed
- live tree is not a Git worktree

The binary archive itself is committed in this directory. It intentionally excludes config.php, DB data, uploads, logs, sessions and credentials.

Status: exact runtime source capture evidence; comparison against pre-custom/pristine 1.8.2 is still pending.
