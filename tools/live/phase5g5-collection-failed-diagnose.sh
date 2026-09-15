#!/usr/bin/env bash
set -euo pipefail

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
SOURCE='a98d0e4f6dd3526bd084af5156e26ab971968c6f'
SOURCE_BRANCH='phase5g/collection-failed-diagnostic'
REL='tools/phase5g5-collection-failed-diagnose.php'
DIAG_BLOB='ce8959fdd7aabffdebdd84ea3d2ba5ac0933f115'
TIMER='phpipam-vmware-recovery5g5@1.timer'

WORK=''
LOG=''
cleanup() {
    rc=$?
    trap - EXIT
    if [ -n "$WORK" ] && [ -d "$WORK/worktree" ]; then
        git -C "$REPO" worktree remove --force "$WORK/worktree" >/dev/null 2>&1 || true
    fi
    [ -n "$WORK" ] && rm -rf -- "$WORK"
    printf '\nPHASE5G5_FAILDIAG_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$LOG" ] && printf 'PHASE5G5_FAILDIAG_LOG=%s\n' "$LOG"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ]
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ] || { echo 'STOP: 5G.5 timer is not active'; exit 1; }
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ] || { echo 'STOP: 5G.5 timer is not enabled'; exit 1; }

git fetch --no-tags origin "$SOURCE_BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD

WORK="$(mktemp -d /var/tmp/phpipam-phase5g5-faildiag.XXXXXXXX)"
chmod 0755 "$WORK"
git worktree add --detach "$WORK/worktree" "$SOURCE"
chmod 0755 "$WORK/worktree"
DIAG="$WORK/worktree/$REL"
[ "$(git -C "$WORK/worktree" rev-parse HEAD)" = "$SOURCE" ]
[ -f "$DIAG" ] && [ ! -L "$DIAG" ]
[ "$(git -C "$WORK/worktree" hash-object "$DIAG")" = "$DIAG_BLOB" ]
runuser -u www-data -- test -r "$DIAG"

LOG="$(mktemp /var/tmp/phpipam-phase5g5-faildiag.XXXXXXXX.log)"
chmod 600 "$LOG"
printf 'PHASE5G5_FAILDIAG_SOURCE=%s\nPHASE5G5_FAILDIAG_LOG=%s\n' "$SOURCE" "$LOG" | tee -a "$LOG"

set +e
timeout --signal=TERM --kill-after=5s 720s \
    runuser -u www-data -- php -d memory_limit=512M "$DIAG" 2>&1 | tee -i -a "$LOG"
STATUS=("${PIPESTATUS[@]}")
set -e

printf '\nPHASE5G5_FAILDIAG_COMMAND_EXIT=%s\nPHASE5G5_FAILDIAG_LOG_EXIT=%s\n' \
    "${STATUS[0]}" "${STATUS[1]}" | tee -a "$LOG"
systemctl show "$TIMER" --no-pager -p ActiveState -p SubState -p UnitFileState | tee -a "$LOG"
[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]

if [ "${STATUS[0]}" -ne 0 ]; then exit "${STATUS[0]}"; fi
if [ "${STATUS[1]}" -ne 0 ]; then exit "${STATUS[1]}"; fi
