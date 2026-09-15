#!/usr/bin/env bash
set -euo pipefail

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
SOURCE='2de23d8b4a236f8737c9edd9c653d90f4fb6918e'
SOURCE_BRANCH='phase5g/status-popover-utc3'
REL='layers/phase5g3/app/integrations/vmware/phase5g3-status.js'
TARGET='/var/www/html/app/integrations/vmware/phase5g3-status.js'
OLD_BLOB='f69c7659da79604681cc2a5212871dba1f45825a'
NEW_BLOB='52b48d09aa3bd65cfc19cd3007068ca8f8219a94'
TIMER='phpipam-vmware-recovery5g5@1.timer'

TMP=''
BACKUP=''
PATCH_IN_PROGRESS=0
PATCH_OK=0

cleanup() {
    rc=$?
    trap - EXIT
    if [ "$PATCH_IN_PROGRESS" -eq 1 ] && [ "$PATCH_OK" -ne 1 ] && [ -n "$BACKUP" ] && [ -f "$BACKUP/phase5g3-status.js" ]; then
        install -o root -g root -m 0644 "$BACKUP/phase5g3-status.js" "$TARGET" || true
        echo 'PHASE5G6_UTC3_AUTO_ROLLBACK=1'
    fi
    [ -n "$TMP" ] && rm -f -- "$TMP"
    printf '\nPHASE5G6_UTC3_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$BACKUP" ] && printf 'PHASE5G6_UTC3_BACKUP=%s\n' "$BACKUP"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
command -v git >/dev/null
command -v install >/dev/null
command -v stat >/dev/null
command -v systemctl >/dev/null

cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ]
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$SOURCE_BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD
printf 'PHASE5G6_UTC3_SOURCE=%s\n' "$SOURCE"

TMP="$(mktemp /var/tmp/phpipam-phase5g6-utc3.XXXXXXXX.js)"
git show "${SOURCE}:${REL}" > "$TMP"
[ "$(git hash-object "$TMP")" = "$NEW_BLOB" ] || { echo 'STOP: source blob mismatch'; exit 1; }

[ -f "$TARGET" ] && [ ! -L "$TARGET" ] || { echo 'STOP: invalid live JS boundary'; exit 1; }
read -r uid gid mode links < <(stat -c '%u %g %a %h' "$TARGET")
[ "$uid" = 0 ] && [ "$gid" = 0 ] && [ "$mode" = 644 ] && [ "$links" = 1 ] || { echo 'STOP: live JS metadata mismatch'; exit 1; }

[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ] || { echo 'STOP: 5G.5 timer is not active'; exit 1; }
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ] || { echo 'STOP: 5G.5 timer is not enabled'; exit 1; }

CURRENT="$(git hash-object "$TARGET")"
if [ "$CURRENT" = "$OLD_BLOB" ]; then
    STAMP="$(date -u +%Y%m%d-%H%M%S)"
    BACKUP="/var/backups/phpipam-custom/vmware-ui-status-utc3-${STAMP}"
    install -d -o root -g root -m 0700 "$BACKUP"
    install -o root -g root -m 0600 "$TARGET" "$BACKUP/phase5g3-status.js"
    PATCH_IN_PROGRESS=1

    NEW_TARGET="${TARGET}.phase5g6-utc3.$$"
    install -o root -g root -m 0644 "$TMP" "$NEW_TARGET"
    mv -f -- "$NEW_TARGET" "$TARGET"

    [ "$(git hash-object "$TARGET")" = "$NEW_BLOB" ] || { echo 'STOP: live JS verify failed'; exit 1; }
    PATCH_OK=1
    echo 'PHASE5G6_UTC3_PATCH_OK=1'
elif [ "$CURRENT" = "$NEW_BLOB" ]; then
    PATCH_OK=1
    echo 'PHASE5G6_UTC3_ALREADY_PATCHED=1'
else
    printf 'STOP: unexpected live JS blob: %s\n' "$CURRENT"
    exit 1
fi

[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]
systemctl show "$TIMER" --no-pager -p ActiveState -p SubState -p UnitFileState

echo 'PHASE5G6_UTC3_WORKER_CHANGED=0'
echo 'PHASE5G6_UTC3_DATA_CHANGED=0'
echo 'PHASE5G6_UTC3_SYSTEMD_CHANGED=0'
echo 'PHASE5G6_UTC3_INSTALL_OK=1'
