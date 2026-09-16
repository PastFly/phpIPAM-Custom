#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
ACCEPT_BRANCH='phase5h/recurring-shadow-acceptance'
ACCEPT_SOURCE='f3ae8eb9937f7e71b151aa00228b698614b8b352'
ACCEPT_REL='tools/live/phase5h5-recurring-shadow-accept.sh'
ACCEPT_BLOB='6233e678091a489302a7b8ae2ce139310541a08d'
OLD_FAST='b970742f2d8d33a88c1754ed2e78201b139f747f'
NEW_FAST='366ce1d6a702c91a3556d3a2a969ff9993aad1d4'
OLD_PLANNER='2710ff0f1ebbc463dae02f47543011ef6a9347fe'
NEW_PLANNER='898d9bf64cfe328dc2243fc938b1c709e234c486'
TMP=''

cleanup() {
    rc=$?
    trap - EXIT
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H5_POSTFIX_ACCEPT_WRAPPER_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git python3 bash; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ] || { echo 'STOP: repository boundary'; exit 1; }

git fetch --no-tags origin "$ACCEPT_BRANCH"
git cat-file -e "${ACCEPT_SOURCE}^{commit}"
git merge-base --is-ancestor "$ACCEPT_SOURCE" FETCH_HEAD

TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-postfix-accept.XXXXXXXX)"
chmod 0700 "$TMP"
BASE="$TMP/base.sh"
PATCHED="$TMP/patched.sh"
git show "${ACCEPT_SOURCE}:${ACCEPT_REL}" > "$BASE"
[ "$(git hash-object "$BASE")" = "$ACCEPT_BLOB" ] || { echo 'STOP: recurring acceptance source mismatch'; exit 1; }

BASE="$BASE" PATCHED="$PATCHED" OLD_FAST="$OLD_FAST" NEW_FAST="$NEW_FAST" OLD_PLANNER="$OLD_PLANNER" NEW_PLANNER="$NEW_PLANNER" \
PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import os
from pathlib import Path
src = Path(os.environ['BASE']).read_text()
replacements = [
    (os.environ['OLD_FAST'], os.environ['NEW_FAST']),
    (os.environ['OLD_PLANNER'], os.environ['NEW_PLANNER']),
]
for old, new in replacements:
    if src.count(old) != 1 or new in src:
        raise SystemExit('postfix_acceptance_patch_boundary')
    src = src.replace(old, new)
Path(os.environ['PATCHED']).write_text(src)
PY
chmod 0700 "$PATCHED"
bash -n "$PATCHED"
[ "$(grep -Fc "$NEW_FAST" "$PATCHED")" -eq 1 ]
[ "$(grep -Fc "$NEW_PLANNER" "$PATCHED")" -eq 1 ]
! grep -Fq "$OLD_FAST" "$PATCHED"
! grep -Fq "$OLD_PLANNER" "$PATCHED"

echo 'PHASE5H5_POSTFIX_ACCEPT_WRAPPER_OK=1'
echo 'PHASE5H5_POSTFIX_ACCEPT_EXPECTS_NEW_FAST_DOMAIN=1'
echo 'PHASE5H5_POSTFIX_ACCEPT_EXPECTS_NEW_PLANNER=1'

set +e
bash "$PATCHED"
RC=$?
set -e
exit "$RC"
