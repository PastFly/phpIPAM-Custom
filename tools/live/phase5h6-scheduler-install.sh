#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
BRANCH='phase5h6/autonomous-shadow-scheduler'
BEHAVIOR='c8b348f3424e24777a186e7ab04be4393a0baf62'
TMP=''

cleanup() {
    rc=$?
    trap - EXIT
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H6_SCHEDULER_LIVE_BLOCK_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git bash python3 systemctl systemd-analyze; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ] || { echo 'STOP: repository boundary'; exit 1; }
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$BRANCH"
git cat-file -e "${BEHAVIOR}^{commit}"
git merge-base --is-ancestor "$BEHAVIOR" FETCH_HEAD
printf 'PHASE5H6_SCHEDULER_BEHAVIOR=%s\n' "$BEHAVIOR"

TMP="$(mktemp -d /var/tmp/phpipam-phase5h6-scheduler.XXXXXXXX)"
chmod 0700 "$TMP"
git clone -q --no-hardlinks "$REPO" "$TMP/source"
git -C "$TMP/source" checkout -q --detach "$BEHAVIOR"
[ -z "$(git -C "$TMP/source" status --porcelain)" ] || { echo 'STOP: temporary source checkout dirty'; exit 1; }

bash -n "$TMP/source/tools/phase5h6-scheduled-cycle.sh"
python3 -m py_compile "$TMP/source/tools/install-phase5h6-scheduler.py" "$TMP/source/tests/phase5h6-scheduler.test.py"
python3 -B "$TMP/source/tests/phase5h6-scheduler.test.py"
[ -z "$(git -C "$TMP/source" status --porcelain)" ] || { echo 'STOP: preflight dirtied source checkout'; exit 1; }
echo 'PHASE5H6_SCHEDULER_LIVE_PREFLIGHT_OK=1'

python3 -B "$TMP/source/tools/install-phase5h6-scheduler.py"
