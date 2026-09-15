#!/usr/bin/env bash
set -euo pipefail

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
SOURCE='385c2eb8b2cc8c74205d3553887435bc0f98f8df'
SOURCE_BRANCH='phase5h/stale-mor-compat'

SHADOW='/opt/phpipam-custom/vmware-fast-shadow'
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
TIMER='phpipam-vmware-recovery5g5@1.timer'
RUNNER="$SHADOW/tools/phase5h4-shadow-run.php"

SESSION_REL='layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php'
INVENTORY_REL='layers/phase5h/functions/classes/class.IntegrationVmwareBatchInventory.php'
SHADOW_REL='layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php'
PERF_REL='layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php'

OLD_SESSION='d6020830c17b6d13c7ee4b4c3f819d0833292253'
OLD_INVENTORY='afc426cd2c7a57998ffde8099410fa34aab59806'
OLD_SHADOW='5a571c42912ed92957d9e01a0893fb10ffa87bbb'
NEW_SESSION='d1241feba9a1e7371c579c4d259ed1f1d983cd9c'
NEW_INVENTORY='5b9cff8bf0c649dd4e285f11d2fb8438eef15aec'
NEW_SHADOW='d07a9362923102d0494fd4b23a92ed0284d8dfb7'
PERF_REQUIRED='5f645c23bbddd807f2189dbea5a05fa1a4f0b9c7'
RUNNER_REQUIRED='7ecaa1af168933456069e057bd23de2d2a185dcb'

TMP=''
BACKUP=''
LOG=''
LOCK_HELD=0
PATCH_IN_PROGRESS=0
PATCH_OK=0

cleanup() {
    rc=$?
    trap - EXIT
    if [ "$LOCK_HELD" -eq 1 ]; then
        flock -u 9 >/dev/null 2>&1 || true
        exec 9>&- || true
    fi
    if [ "$PATCH_IN_PROGRESS" -eq 1 ] && [ "$PATCH_OK" -ne 1 ] && [ -n "$BACKUP" ] && [ -d "$BACKUP" ]; then
        install -o root -g root -m 0644 "$BACKUP/$(basename "$SESSION_REL")" "$SHADOW/$SESSION_REL" || true
        install -o root -g root -m 0644 "$BACKUP/$(basename "$INVENTORY_REL")" "$SHADOW/$INVENTORY_REL" || true
        install -o root -g root -m 0644 "$BACKUP/$(basename "$SHADOW_REL")" "$SHADOW/$SHADOW_REL" || true
        echo 'PHASE5H_STALE_MOR_AUTO_ROLLBACK=1'
    fi
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H_STALE_MOR_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$BACKUP" ] && printf 'PHASE5H_STALE_MOR_BACKUP=%s\n' "$BACKUP"
    [ -n "$LOG" ] && printf 'PHASE5H_STALE_MOR_LOG=%s\n' "$LOG"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
command -v git >/dev/null
command -v php >/dev/null
command -v flock >/dev/null
command -v runuser >/dev/null
command -v timeout >/dev/null

cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ]
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$SOURCE_BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD
printf 'PHASE5H_STALE_MOR_SOURCE=%s\n' "$SOURCE"

TMP="$(mktemp -d /var/tmp/phpipam-phase5h-stale-mor.XXXXXXXX)"
for rel in "$SESSION_REL" "$INVENTORY_REL" "$SHADOW_REL"; do
    git show "${SOURCE}:${rel}" > "$TMP/$(basename "$rel")"
    php -l "$TMP/$(basename "$rel")" >/dev/null
done

[ "$(git hash-object "$TMP/$(basename "$SESSION_REL")")" = "$NEW_SESSION" ]
[ "$(git hash-object "$TMP/$(basename "$INVENTORY_REL")")" = "$NEW_INVENTORY" ]
[ "$(git hash-object "$TMP/$(basename "$SHADOW_REL")")" = "$NEW_SHADOW" ]

for file in "$SHADOW/$SESSION_REL" "$SHADOW/$INVENTORY_REL" "$SHADOW/$SHADOW_REL" "$SHADOW/$PERF_REL" "$RUNNER"; do
    [ -f "$file" ] && [ ! -L "$file" ] || { echo "STOP: invalid shadow file boundary: $file"; exit 1; }
done
[ "$(git hash-object "$SHADOW/$PERF_REL")" = "$PERF_REQUIRED" ] || { echo 'STOP: counter compatibility patch is not installed'; exit 1; }
[ "$(git hash-object "$RUNNER")" = "$RUNNER_REQUIRED" ] || { echo 'STOP: unexpected shadow runner'; exit 1; }

CUR_SESSION="$(git hash-object "$SHADOW/$SESSION_REL")"
CUR_INVENTORY="$(git hash-object "$SHADOW/$INVENTORY_REL")"
CUR_SHADOW="$(git hash-object "$SHADOW/$SHADOW_REL")"

if [ "$CUR_SESSION" = "$OLD_SESSION" ] && [ "$CUR_INVENTORY" = "$OLD_INVENTORY" ] && [ "$CUR_SHADOW" = "$OLD_SHADOW" ]; then
    STAMP="$(date -u +%Y%m%d-%H%M%S)"
    BACKUP="/var/backups/phpipam-custom/vmware-fast-shadow-stale-mor-${STAMP}"
    install -d -o root -g root -m 0700 "$BACKUP"
    install -o root -g root -m 0600 "$SHADOW/$SESSION_REL" "$BACKUP/$(basename "$SESSION_REL")"
    install -o root -g root -m 0600 "$SHADOW/$INVENTORY_REL" "$BACKUP/$(basename "$INVENTORY_REL")"
    install -o root -g root -m 0600 "$SHADOW/$SHADOW_REL" "$BACKUP/$(basename "$SHADOW_REL")"
    PATCH_IN_PROGRESS=1

    install -o root -g root -m 0644 "$TMP/$(basename "$SESSION_REL")" "$SHADOW/$SESSION_REL.new.$$"
    install -o root -g root -m 0644 "$TMP/$(basename "$INVENTORY_REL")" "$SHADOW/$INVENTORY_REL.new.$$"
    install -o root -g root -m 0644 "$TMP/$(basename "$SHADOW_REL")" "$SHADOW/$SHADOW_REL.new.$$"
    mv -f -- "$SHADOW/$SESSION_REL.new.$$" "$SHADOW/$SESSION_REL"
    mv -f -- "$SHADOW/$INVENTORY_REL.new.$$" "$SHADOW/$INVENTORY_REL"
    mv -f -- "$SHADOW/$SHADOW_REL.new.$$" "$SHADOW/$SHADOW_REL"

    [ "$(git hash-object "$SHADOW/$SESSION_REL")" = "$NEW_SESSION" ]
    [ "$(git hash-object "$SHADOW/$INVENTORY_REL")" = "$NEW_INVENTORY" ]
    [ "$(git hash-object "$SHADOW/$SHADOW_REL")" = "$NEW_SHADOW" ]
    php -l "$SHADOW/$SESSION_REL" >/dev/null
    php -l "$SHADOW/$INVENTORY_REL" >/dev/null
    php -l "$SHADOW/$SHADOW_REL" >/dev/null
    PATCH_OK=1
    echo 'PHASE5H_STALE_MOR_PATCH_OK=1'
elif [ "$CUR_SESSION" = "$NEW_SESSION" ] && [ "$CUR_INVENTORY" = "$NEW_INVENTORY" ] && [ "$CUR_SHADOW" = "$NEW_SHADOW" ]; then
    PATCH_OK=1
    echo 'PHASE5H_STALE_MOR_ALREADY_PATCHED=1'
else
    echo "STOP: mixed/unexpected shadow patch state: $CUR_SESSION $CUR_INVENTORY $CUR_SHADOW"
    exit 1
fi

[ -f "$LOCK" ] && [ ! -L "$LOCK" ] || { echo 'STOP: active worker lock missing or linked'; exit 1; }
read -r lock_uid lock_gid lock_mode lock_links lock_size < <(stat -c '%u %g %a %h %s' "$LOCK")
[ "$lock_uid" = 33 ] && [ "$lock_gid" = 33 ] && [ "$lock_mode" = 600 ] && [ "$lock_links" = 1 ] && [ "$lock_size" = 0 ] || {
    echo 'STOP: active worker lock metadata mismatch'; exit 1;
}

[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]

exec 9<>"$LOCK"
WAIT_START="$(date +%s)"
echo 'PHASE5H_STALE_MOR_LOCK_WAIT=1'
flock -x -w 600 9 || { echo 'STOP: timed out waiting for production worker lock'; exit 1; }
LOCK_HELD=1
WAIT_END="$(date +%s)"
printf 'PHASE5H_STALE_MOR_LOCK_ACQUIRED=1\nPHASE5H_STALE_MOR_LOCK_WAIT_SECONDS=%s\n' "$((WAIT_END-WAIT_START))"

LOG="$(mktemp /var/tmp/phpipam-phase5h-stale-mor-rebench.XXXXXXXX.log)"
chmod 600 "$LOG"
printf 'PHASE5H_STALE_MOR_REBENCH_SOURCE=%s\nPHASE5H_STALE_MOR_REBENCH_LOG=%s\n' "$SOURCE" "$LOG" | tee -a "$LOG"

set +e
timeout --signal=TERM --kill-after=5s 150s \
    runuser -u www-data -- php -d memory_limit=512M "$RUNNER" \
    2>&1 | tee -i -a "$LOG"
STATUS=("${PIPESTATUS[@]}")
set -e

flock -u 9
exec 9>&-
LOCK_HELD=0

printf '\nPHASE5H_STALE_MOR_REBENCH_COMMAND_EXIT=%s\nPHASE5H_STALE_MOR_REBENCH_LOG_EXIT=%s\n' \
    "${STATUS[0]}" "${STATUS[1]}" | tee -a "$LOG"
systemctl show "$TIMER" --no-pager -p ActiveState -p SubState -p UnitFileState | tee -a "$LOG"

[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]

if [ "${STATUS[0]}" -ne 0 ]; then exit "${STATUS[0]}"; fi
if [ "${STATUS[1]}" -ne 0 ]; then exit "${STATUS[1]}"; fi
