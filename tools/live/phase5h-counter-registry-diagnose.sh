#!/usr/bin/env bash
set -euo pipefail

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
SOURCE='9ff2339fcc72ec6e6918b73405f7e8c1b38c4fea'
SOURCE_BRANCH='phase5h/post-stale-mor-diagnostic'
DIAG_REL='tools/phase5h4-counter-registry-diagnose.php'
DIAG_BLOB='14f4118d7d7f1066d6575bca164dbc248fbc1e90'

WORK=''
LOG=''

cleanup() {
    rc=$?
    trap - EXIT
    if [ -n "${WORK:-}" ] && [ -d "$WORK/worktree" ]; then
        git -C "$REPO" worktree remove --force "$WORK/worktree" >/dev/null 2>&1 || true
    fi
    [ -n "${WORK:-}" ] && rm -rf -- "$WORK"
    printf '\nPHASE5H_COUNTERDIAG_BLOCK_EXIT=%s\n' "$rc"
    [ -n "${LOG:-}" ] && printf 'PHASE5H_COUNTERDIAG_LOG=%s\n' "$LOG"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
command -v git >/dev/null
command -v php >/dev/null
command -v runuser >/dev/null
command -v timeout >/dev/null

cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ]
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$SOURCE_BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD

WORK="$(mktemp -d /var/tmp/phpipam-phase5h-counterdiag.XXXXXXXX)"
chmod 0755 "$WORK"
git worktree add --detach "$WORK/worktree" "$SOURCE"
chmod 0755 "$WORK/worktree"

DIAG="$WORK/worktree/$DIAG_REL"
[ "$(git -C "$WORK/worktree" rev-parse HEAD)" = "$SOURCE" ]
[ -f "$DIAG" ] && [ ! -L "$DIAG" ]
[ "$(git -C "$WORK/worktree" hash-object "$DIAG")" = "$DIAG_BLOB" ]
php -l "$DIAG" >/dev/null
runuser -u www-data -- test -r "$DIAG"

LOG="$(mktemp /var/tmp/phpipam-phase5h-counterdiag.XXXXXXXX.log)"
chmod 600 "$LOG"
printf 'PHASE5H_COUNTERDIAG_SOURCE=%s\nPHASE5H_COUNTERDIAG_LOG=%s\n' "$SOURCE" "$LOG" | tee -a "$LOG"

set +e
timeout --signal=TERM --kill-after=5s 180s \
    runuser -u www-data -- php -d memory_limit=256M "$DIAG" \
    2>&1 | tee -i -a "$LOG"
STATUS=("${PIPESTATUS[@]}")
set -e

printf '\nPHASE5H_COUNTERDIAG_COMMAND_EXIT=%s\nPHASE5H_COUNTERDIAG_LOG_EXIT=%s\n' \
    "${STATUS[0]}" "${STATUS[1]}" | tee -a "$LOG"

if [ "${STATUS[0]}" -ne 0 ]; then exit "${STATUS[0]}"; fi
if [ "${STATUS[1]}" -ne 0 ]; then exit "${STATUS[1]}"; fi
