#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
BEHAVIOR='9a628539680cbe3dd36a9474c6bac17f36c92856'
BRANCH='phase5h/freshness-capability-convergence'
SHADOW='/opt/phpipam-custom/vmware-fast-shadow'
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
TIMER='phpipam-vmware-recovery5g5@1.timer'
FAST_REL='layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php'
PLANNER_REL='layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php'
CYCLE_REL='layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php'
OLD_FAST='3d6b29d233bca8ccb1edc7a5bcc3fb2710c42ed6'
NEW_FAST='5c95cd68601a6175d9127aae980d492316c03dc9'
EXPECTED_PLANNER='898d9bf64cfe328dc2243fc938b1c709e234c486'
EXPECTED_CYCLE='9064e26f6d76f6e09ee7e615f2ae7e5c2506e129'
LOCK_WAIT_LIMIT=180

TMP=''
BACKUP=''
LOCK_HELD=0
PATCH_IN_PROGRESS=0
PATCH_OK=0

cleanup() {
    rc=$?
    trap - EXIT
    if [ "$LOCK_HELD" -eq 1 ]; then flock -u 9 >/dev/null 2>&1 || true; exec 9>&- || true; fi
    if [ "$PATCH_IN_PROGRESS" -eq 1 ] && [ "$PATCH_OK" -ne 1 ] && [ -n "$BACKUP" ] && [ -f "$BACKUP/$(basename "$FAST_REL")" ]; then
        install -o root -g root -m 0644 "$BACKUP/$(basename "$FAST_REL")" "$SHADOW/$FAST_REL" || true
        echo 'PHASE5H5_FRESHNESS_V4_AUTO_ROLLBACK=1'
    fi
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H5_FRESHNESS_V4_PATCH_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$BACKUP" ] && printf 'PHASE5H5_FRESHNESS_V4_PATCH_BACKUP=%s\n' "$BACKUP"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php flock systemctl stat install mv date sleep; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ] || { echo 'STOP: repository boundary'; exit 1; }
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$BRANCH"
git cat-file -e "${BEHAVIOR}^{commit}"
git merge-base --is-ancestor "$BEHAVIOR" FETCH_HEAD
printf 'PHASE5H5_FRESHNESS_V4_BEHAVIOR=%s\n' "$BEHAVIOR"

TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-freshness-v4.XXXXXXXX)"
chmod 0700 "$TMP"
git clone -q --no-hardlinks "$REPO" "$TMP/source"
git -C "$TMP/source" checkout -q --detach "$BEHAVIOR"
[ -z "$(git -C "$TMP/source" status --porcelain)" ] || { echo 'STOP: temporary source checkout is dirty'; exit 1; }
php -l "$TMP/source/$FAST_REL" >/dev/null
php "$TMP/source/tests/phase5h2-fast-domain.test.php"
[ -z "$(git -C "$TMP/source" status --porcelain)" ] || { echo 'STOP: tests dirtied temporary source checkout'; exit 1; }
[ "$(git hash-object "$TMP/source/$FAST_REL")" = "$NEW_FAST" ] || { echo 'STOP: new FastDomain blob mismatch'; exit 1; }
echo 'PHASE5H5_FRESHNESS_V4_LOCAL_TESTS_OK=1'

check_file() {
    local rel="$1" expected="$2"
    local path="$SHADOW/$rel"
    local current
    [ -f "$path" ] && [ ! -L "$path" ] || { echo "STOP: invalid shadow source boundary: $rel"; exit 1; }
    read -r uid gid mode links < <(stat -c '%u %g %a %h' "$path")
    [ "$uid" = 0 ] && [ "$gid" = 0 ] && [ "$mode" = 644 ] && [ "$links" = 1 ] || { echo "STOP: shadow source metadata mismatch: $rel"; exit 1; }
    current="$(git hash-object "$path")"
    [ "$current" = "$expected" ] || { echo "STOP: unexpected installed shadow blob: $rel $current"; exit 1; }
}
check_file "$PLANNER_REL" "$EXPECTED_PLANNER"
check_file "$CYCLE_REL" "$EXPECTED_CYCLE"

[ -f "$LOCK" ] && [ ! -L "$LOCK" ] || { echo 'STOP: production worker lock missing or linked'; exit 1; }
read -r lock_uid lock_gid lock_mode lock_links lock_size < <(stat -c '%u %g %a %h %s' "$LOCK")
[ "$lock_uid" = 33 ] && [ "$lock_gid" = 33 ] && [ "$lock_mode" = 600 ] && [ "$lock_links" = 1 ] && [ "$lock_size" = 0 ] || { echo 'STOP: production lock metadata mismatch'; exit 1; }
[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]

current_fast="$(git hash-object "$SHADOW/$FAST_REL")"
printf 'PHASE5H5_FRESHNESS_V4_INSTALLED_FAST=%s\n' "$current_fast"
if [ "$current_fast" = "$NEW_FAST" ]; then
    PATCH_OK=1
    echo 'PHASE5H5_FRESHNESS_V4_ALREADY_PATCHED=1'
elif [ "$current_fast" = "$OLD_FAST" ]; then
    exec 9<>"$LOCK"
    start="$(date +%s)"; last_report=-1
    echo 'PHASE5H5_FRESHNESS_V4_LOCK_WAIT=1'
    while ! flock -x -n 9; do
        now="$(date +%s)"; elapsed=$((now-start))
        [ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ] || { echo 'STOP: production timer not active'; exit 1; }
        if [ "$elapsed" -ge "$LOCK_WAIT_LIMIT" ]; then echo "STOP: timed out waiting for production worker lock after ${elapsed}s"; exit 1; fi
        if [ "$elapsed" -eq 0 ] || [ $((elapsed/10)) -ne "$last_report" ]; then
            printf 'PHASE5H5_FRESHNESS_V4_LOCK_WAIT_SECONDS=%s\n' "$elapsed"; last_report=$((elapsed/10))
        fi
        sleep 5
    done
    LOCK_HELD=1
    now="$(date +%s)"
    printf 'PHASE5H5_FRESHNESS_V4_LOCK_ACQUIRED=1\nPHASE5H5_FRESHNESS_V4_LOCK_WAIT_SECONDS=%s\n' "$((now-start))"

    current_fast="$(git hash-object "$SHADOW/$FAST_REL")"
    [ "$current_fast" = "$OLD_FAST" ] || { echo 'STOP: FastDomain changed while waiting for lock'; exit 1; }
    check_file "$PLANNER_REL" "$EXPECTED_PLANNER"
    check_file "$CYCLE_REL" "$EXPECTED_CYCLE"

    STAMP="$(date -u +%Y%m%d-%H%M%S)"
    BACKUP="/var/backups/phpipam-custom/vmware-fast-shadow-freshness-v4-${STAMP}"
    install -d -o root -g root -m 0700 "$BACKUP"
    install -o root -g root -m 0600 "$SHADOW/$FAST_REL" "$BACKUP/$(basename "$FAST_REL")"
    PATCH_IN_PROGRESS=1

    fast_new="$SHADOW/$FAST_REL.new.$$"
    install -o root -g root -m 0644 "$TMP/source/$FAST_REL" "$fast_new"
    mv -f -- "$fast_new" "$SHADOW/$FAST_REL"
    php -l "$SHADOW/$FAST_REL" >/dev/null
    [ "$(git hash-object "$SHADOW/$FAST_REL")" = "$NEW_FAST" ]
    PATCH_OK=1
    echo 'PHASE5H5_FRESHNESS_V4_PATCH_OK=1'

    flock -u 9
    exec 9>&-
    LOCK_HELD=0
else
    echo "STOP: unexpected FastDomain live blob: $current_fast"
    exit 1
fi

[ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ]
[ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ]
echo 'PHASE5H5_FRESHNESS_V4_FAST_DOMAIN_CHANGED=1'
echo 'PHASE5H5_FRESHNESS_V4_PLANNER_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V4_SHADOW_CYCLE_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V4_SHADOW_DATA_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V4_ACTIVE_DATA_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V4_WEBSITE_CHANGED=0'
echo 'PHASE5H5_FRESHNESS_V4_SYSTEMD_CHANGED=0'
