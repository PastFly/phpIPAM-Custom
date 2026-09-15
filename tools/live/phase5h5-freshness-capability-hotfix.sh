#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
BEHAVIOR='29ef45598b075ba82897e0491a3cbb78b1b65093'
BRANCH='phase5h/freshness-capability-convergence'
SHADOW='/opt/phpipam-custom/vmware-fast-shadow'
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
TIMER='phpipam-vmware-recovery5g5@1.timer'
FAST_REL='layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php'
PLANNER_REL='layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php'
CYCLE_REL='layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php'
BASE_FAST='b970742f2d8d33a88c1754ed2e78201b139f747f'
V1_FAST='366ce1d6a702c91a3556d3a2a969ff9993aad1d4'
NEW_FAST='48279e71f7621e249435d47396c09e765e664340'
BASE_PLANNER='2710ff0f1ebbc463dae02f47543011ef6a9347fe'
NEW_PLANNER='898d9bf64cfe328dc2243fc938b1c709e234c486'
BASE_CYCLE='d07a9362923102d0494fd4b23a92ed0284d8dfb7'
NEW_CYCLE='9064e26f6d76f6e09ee7e615f2ae7e5c2506e129'
LOCK_WAIT_LIMIT=180

TMP=''
BACKUP=''
LOCK_HELD=0
PATCH_IN_PROGRESS=0
PATCH_OK=0
FAST_CHANGED=0
PLANNER_CHANGED=0
CYCLE_CHANGED=0

cleanup() {
    rc=$?
    trap - EXIT
    if [ "$LOCK_HELD" -eq 1 ]; then flock -u 9 >/dev/null 2>&1 || true; exec 9>&- || true; fi
    if [ "$PATCH_IN_PROGRESS" -eq 1 ] && [ "$PATCH_OK" -ne 1 ] && [ -n "$BACKUP" ] && [ -d "$BACKUP" ]; then
        install -o root -g root -m 0644 "$BACKUP/$(basename "$FAST_REL")" "$SHADOW/$FAST_REL" || true
        install -o root -g root -m 0644 "$BACKUP/$(basename "$PLANNER_REL")" "$SHADOW/$PLANNER_REL" || true
        install -o root -g root -m 0644 "$BACKUP/$(basename "$CYCLE_REL")" "$SHADOW/$CYCLE_REL" || true
        echo 'PHASE5H5_FRESHNESS_V2_AUTO_ROLLBACK=1'
    fi
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H5_FRESHNESS_V2_PATCH_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$BACKUP" ] && printf 'PHASE5H5_FRESHNESS_V2_PATCH_BACKUP=%s\n' "$BACKUP"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php python3 flock systemctl stat install mv date sleep; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ] || { echo 'STOP: repository boundary'; exit 1; }
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$BRANCH"
git cat-file -e "${BEHAVIOR}^{commit}"
git merge-base --is-ancestor "$BEHAVIOR" FETCH_HEAD
printf 'PHASE5H5_FRESHNESS_V2_BEHAVIOR=%s\n' "$BEHAVIOR"

TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-freshness-v2.XXXXXXXX)"
chmod 0700 "$TMP"
git clone -q --no-hardlinks "$REPO" "$TMP/source"
git -C "$TMP/source" checkout -q --detach "$BEHAVIOR"
[ -z "$(git -C "$TMP/source" status --porcelain)" ] || { echo 'STOP: temporary source checkout is dirty'; exit 1; }

for rel in "$FAST_REL" "$PLANNER_REL" "$CYCLE_REL"; do php -l "$TMP/source/$rel" >/dev/null; done
php "$TMP/source/tests/phase5h2-fast-domain.test.php"
php "$TMP/source/tests/phase5h4-shadow-planner.test.php"
php "$TMP/source/tests/phase5h4-shadow-cycle.test.php"
[ -z "$(git -C "$TMP/source" status --porcelain)" ] || { echo 'STOP: tests dirtied temporary source checkout'; exit 1; }
[ "$(git hash-object "$TMP/source/$FAST_REL")" = "$NEW_FAST" ]
[ "$(git hash-object "$TMP/source/$PLANNER_REL")" = "$NEW_PLANNER" ]
[ "$(git hash-object "$TMP/source/$CYCLE_REL")" = "$NEW_CYCLE" ]
echo 'PHASE5H5_FRESHNESS_V2_LOCAL_TESTS_OK=1'

check_file() {
    local rel="$1" path="$SHADOW/$rel"
    [ -f "$path" ] && [ ! -L "$path" ] || { echo "STOP: invalid shadow source boundary: $rel"; exit 1; }
    read -r uid gid mode links < <(stat -c '%u %g %a %h' "$path")
    [ "$uid" = 0 ] && [ "$gid" = 0 ] && [ "$mode" = 644 ] && [ "$links" = 1 ] || { echo "STOP: shadow source metadata mismatch: $rel"; exit 1; }
}
check_file "$FAST_REL"; check_file "$PLANNER_REL"; check_file "$CYCLE_REL"

current_fast="$(git hash-object "$SHADOW/$FAST_REL")"
current_planner="$(git hash-object "$SHADOW/$PLANNER_REL")"
current_cycle="$(git hash-object "$SHADOW/$CYCLE_REL")"
printf 'PHASE5H5_FRESHNESS_V2_INSTALLED=%s/%s/%s\n' "$current_fast" "$current_planner" "$current_cycle"

state='invalid'
if [ "$current_fast" = "$BASE_FAST" ] && [ "$current_planner" = "$BASE_PLANNER" ] && [ "$current_cycle" = "$BASE_CYCLE" ]; then
    state='base'
elif [ "$current_fast" = "$V1_FAST" ] && [ "$current_planner" = "$NEW_PLANNER" ] && [ "$current_cycle" = "$BASE_CYCLE" ]; then
    state='v1-live'
elif [ "$current_fast" = "$NEW_FAST" ] && [ "$current_planner" = "$NEW_PLANNER" ] && [ "$current_cycle" = "$NEW_CYCLE" ]; then
    state='v2-final'
fi
[ "$state" != invalid ] || { echo 'STOP: unexpected mixed shadow patch state'; exit 1; }
printf 'PHASE5H5_FRESHNESS_V2_STATE=%s\n' "$state"

[ -f "$LOCK" ] && [ ! -L "$LOCK" ] || { echo 'STOP: production worker lock missing or linked'; exit 1; }
read -r lock_uid lock_gid lock_mode lock_links lock_size < <(stat -c '%u %g %a %h %s' "$LOCK")
[ "$lock_uid" = 33 ] && [ "$lock_gid" = 33 ] && [ "$lock_mode" = 600 ] && [ "$lock_links" = 1 ] && [ "$lock_size" = 0 ] || { echo 'STOP: production lock metadata mismatch'; exit 1; }
[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]

exec 9<>"$LOCK"
start="$(date +%s)"; last_report=-1
echo 'PHASE5H5_FRESHNESS_V2_LOCK_WAIT=1'
while ! flock -x -n 9; do
    now="$(date +%s)"; elapsed=$((now-start))
    [ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ] || { echo 'STOP: production timer not active'; exit 1; }
    if [ "$elapsed" -ge "$LOCK_WAIT_LIMIT" ]; then echo "STOP: timed out waiting for production worker lock after ${elapsed}s"; exit 1; fi
    if [ "$elapsed" -eq 0 ] || [ $((elapsed/10)) -ne "$last_report" ]; then
        printf 'PHASE5H5_FRESHNESS_V2_LOCK_WAIT_SECONDS=%s\n' "$elapsed"; last_report=$((elapsed/10))
    fi
    sleep 5
done
LOCK_HELD=1
now="$(date +%s)"
printf 'PHASE5H5_FRESHNESS_V2_LOCK_ACQUIRED=1\nPHASE5H5_FRESHNESS_V2_LOCK_WAIT_SECONDS=%s\n' "$((now-start))"

# Re-read under the shared mutex so the accepted state cannot change between preflight and replacement.
current_fast="$(git hash-object "$SHADOW/$FAST_REL")"
current_planner="$(git hash-object "$SHADOW/$PLANNER_REL")"
current_cycle="$(git hash-object "$SHADOW/$CYCLE_REL")"
if [ "$current_fast" = "$NEW_FAST" ] && [ "$current_planner" = "$NEW_PLANNER" ] && [ "$current_cycle" = "$NEW_CYCLE" ]; then
    PATCH_OK=1
    echo 'PHASE5H5_FRESHNESS_V2_ALREADY_PATCHED=1'
else
    if [ "$current_fast" = "$BASE_FAST" ] && [ "$current_planner" = "$BASE_PLANNER" ] && [ "$current_cycle" = "$BASE_CYCLE" ]; then :
    elif [ "$current_fast" = "$V1_FAST" ] && [ "$current_planner" = "$NEW_PLANNER" ] && [ "$current_cycle" = "$BASE_CYCLE" ]; then :
    else echo 'STOP: shadow patch state changed while waiting for lock'; exit 1; fi

    [ "$current_fast" = "$NEW_FAST" ] || FAST_CHANGED=1
    [ "$current_planner" = "$NEW_PLANNER" ] || PLANNER_CHANGED=1
    [ "$current_cycle" = "$NEW_CYCLE" ] || CYCLE_CHANGED=1

    STAMP="$(date -u +%Y%m%d-%H%M%S)"
    BACKUP="/var/backups/phpipam-custom/vmware-fast-shadow-freshness-v2-${STAMP}"
    install -d -o root -g root -m 0700 "$BACKUP"
    install -o root -g root -m 0600 "$SHADOW/$FAST_REL" "$BACKUP/$(basename "$FAST_REL")"
    install -o root -g root -m 0600 "$SHADOW/$PLANNER_REL" "$BACKUP/$(basename "$PLANNER_REL")"
    install -o root -g root -m 0600 "$SHADOW/$CYCLE_REL" "$BACKUP/$(basename "$CYCLE_REL")"
    PATCH_IN_PROGRESS=1

    fast_new="$SHADOW/$FAST_REL.new.$$"
    planner_new="$SHADOW/$PLANNER_REL.new.$$"
    cycle_new="$SHADOW/$CYCLE_REL.new.$$"
    install -o root -g root -m 0644 "$TMP/source/$FAST_REL" "$fast_new"
    install -o root -g root -m 0644 "$TMP/source/$PLANNER_REL" "$planner_new"
    install -o root -g root -m 0644 "$TMP/source/$CYCLE_REL" "$cycle_new"
    mv -f -- "$fast_new" "$SHADOW/$FAST_REL"
    mv -f -- "$planner_new" "$SHADOW/$PLANNER_REL"
    mv -f -- "$cycle_new" "$SHADOW/$CYCLE_REL"
    for rel in "$FAST_REL" "$PLANNER_REL" "$CYCLE_REL"; do php -l "$SHADOW/$rel" >/dev/null; done
    [ "$(git hash-object "$SHADOW/$FAST_REL")" = "$NEW_FAST" ]
    [ "$(git hash-object "$SHADOW/$PLANNER_REL")" = "$NEW_PLANNER" ]
    [ "$(git hash-object "$SHADOW/$CYCLE_REL")" = "$NEW_CYCLE" ]
    PATCH_OK=1
    echo 'PHASE5H5_FRESHNESS_V2_PATCH_OK=1'
fi

flock -u 9
exec 9>&-
LOCK_HELD=0

[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]
printf 'PHASE5H5_FRESHNESS_V2_FAST_DOMAIN_CHANGED=%s\n' "$FAST_CHANGED"
printf 'PHASE5H5_FRESHNESS_V2_PLANNER_CHANGED=%s\n' "$PLANNER_CHANGED"
printf 'PHASE5H5_FRESHNESS_V2_SHADOW_CYCLE_CHANGED=%s\n' "$CYCLE_CHANGED"
echo 'PHASE5H5_FRESHNESS_V2_SHADOW_DATA_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V2_ACTIVE_DATA_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V2_WEBSITE_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V2_SYSTEMD_CHANGED=0'
