#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
SOURCE='e4c53dc9b46bdf4f8fe150bee2f29546adf05da0'
REL='tools/live/phase5h5-steady-state-accept.sh'
SOURCE_BLOB='f35c4bbb9807700e75068b9aa9eb8d90677967ac'
OLD_FAST='48279e71f7621e249435d47396c09e765e664340'
NEW_FAST='5c95cd68601a6175d9127aae980d492316c03dc9'
TMP=''

cleanup() {
    rc=$?
    trap - EXIT
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H5_V4_STEADY_WRAPPER_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git python3 bash; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
git cat-file -e "${SOURCE}^{commit}"
TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-v4-steady.XXXXXXXX)"
chmod 0700 "$TMP"
BASE="$TMP/base.sh"
PATCHED="$TMP/patched.sh"
git show "${SOURCE}:${REL}" > "$BASE"
[ "$(git hash-object "$BASE")" = "$SOURCE_BLOB" ] || { echo 'STOP: steady-state acceptance source mismatch'; exit 1; }

BASE="$BASE" PATCHED="$PATCHED" OLD_FAST="$OLD_FAST" NEW_FAST="$NEW_FAST" \
PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import os
from pathlib import Path
src=Path(os.environ['BASE']).read_text()
old=os.environ['OLD_FAST']; new=os.environ['NEW_FAST']
if src.count(old)!=1 or new in src:
    raise SystemExit('v4_steady_patch_boundary')
src=src.replace(old,new)
Path(os.environ['PATCHED']).write_text(src)
PY
chmod 0700 "$PATCHED"
bash -n "$PATCHED"
[ "$(grep -Fc "$NEW_FAST" "$PATCHED")" -eq 1 ]
! grep -Fq "$OLD_FAST" "$PATCHED"

echo 'PHASE5H5_V4_STEADY_WRAPPER_OK=1'
echo 'PHASE5H5_V4_STEADY_EXPECTS_OMITTED_SERIES_STALE=1'

set +e
bash "$PATCHED"
RC=$?
set -e
exit "$RC"
