#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
BRANCH='phase5h/recurring-shadow-acceptance'
SOURCE='d6dfa0dac9de3c5e05838b9c0bd7142e9f724a5a'
DIAG_REL='tools/phase5h5-residual-diagnose.php'
DIAG_BLOB='c72b330cec15fec542eb2997c706b645e0e61180'
SHADOW='/opt/phpipam-custom/vmware-fast-shadow'
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
TIMER='phpipam-vmware-recovery5g5@1.timer'
LOCK_WAIT_LIMIT=180

TMP=''
LOG=''
LOCK_HELD=0
cleanup() {
    rc=$?
    trap - EXIT
    if [ "$LOCK_HELD" -eq 1 ]; then flock -u 9 >/dev/null 2>&1 || true; exec 9>&- || true; fi
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H5_RESIDUAL_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$LOG" ] && printf 'PHASE5H5_RESIDUAL_LOG=%s\n' "$LOG"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php flock runuser timeout systemctl stat; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ]
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD

TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-residual.XXXXXXXX)"
chmod 0711 "$TMP"
DIAG="$TMP/phase5h5-residual-diagnose.php"
git show "${SOURCE}:${DIAG_REL}" > "$DIAG"
chmod 0644 "$DIAG"
[ "$(git hash-object "$DIAG")" = "$DIAG_BLOB" ] || { echo 'STOP: diagnostic source blob mismatch'; exit 1; }
php -l "$DIAG" >/dev/null

check_blob() {
    local rel="$1" expected="$2"
    local path="$SHADOW/$rel"
    [ -f "$path" ] && [ ! -L "$path" ] || { echo "STOP: invalid shadow file boundary: $rel"; exit 1; }
    read -r uid gid mode links < <(stat -c '%u %g %a %h' "$path")
    [ "$uid" = 0 ] && [ "$gid" = 0 ] && [ "$mode" = 644 ] && [ "$links" = 1 ] || { echo "STOP: shadow file metadata mismatch: $rel"; exit 1; }
    [ "$(git hash-object "$path")" = "$expected" ] || { echo "STOP: shadow source mismatch: $rel"; exit 1; }
}
check_blob 'layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php' 'd1241feba9a1e7371c579c4d259ed1f1d983cd9c'
check_blob 'layers/phase5h/functions/classes/class.IntegrationVmwareBatchInventory.php' '5b9cff8bf0c649dd4e285f11d2fb8438eef15aec'
check_blob 'layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php' 'b35127f8e28e503f558a08327b73cf9c6d41002a'
check_blob 'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php' '380de222f7d437406ea8aa14807b09bbafa581b1'
check_blob 'layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php' 'bde978b7d8e2085e9e16b7a7f00b1e7a46096a27'
check_blob 'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php' '2710ff0f1ebbc463dae02f47543011ef6a9347fe'

[ -f "$LOCK" ] && [ ! -L "$LOCK" ] || { echo 'STOP: production worker lock missing or linked'; exit 1; }
read -r lock_uid lock_gid lock_mode lock_links lock_size < <(stat -c '%u %g %a %h %s' "$LOCK")
[ "$lock_uid" = 33 ] && [ "$lock_gid" = 33 ] && [ "$lock_mode" = 600 ] && [ "$lock_links" = 1 ] && [ "$lock_size" = 0 ] || {
    echo 'STOP: production worker lock metadata mismatch'; exit 1;
}
verify_timer() {
    [ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ] || { echo 'STOP: production timer not active'; exit 1; }
    [ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ] || { echo 'STOP: production timer not enabled'; exit 1; }
}
verify_timer

echo 'PHASE5H5_RESIDUAL_PREFLIGHT_OK=1'
echo 'PHASE5H5_RESIDUAL_READ_ONLY=1'
echo 'PHASE5H5_RESIDUAL_WEBSITE_CHANGED=0'
echo 'PHASE5H5_RESIDUAL_SYSTEMD_CHANGED=0'

exec 9<>"$LOCK"
START="$(date +%s)"; LAST=-1
echo 'PHASE5H5_RESIDUAL_LOCK_WAIT=1'
while ! flock -x -n 9; do
    verify_timer
    NOW="$(date +%s)"; ELAPSED=$((NOW-START))
    if [ "$ELAPSED" -ge "$LOCK_WAIT_LIMIT" ]; then echo "PHASE5H5_RESIDUAL_LOCK_TIMEOUT_SECONDS=$ELAPSED"; exit 1; fi
    if [ "$ELAPSED" -eq 0 ] || [ $((ELAPSED/10)) -ne "$LAST" ]; then
        echo "PHASE5H5_RESIDUAL_LOCK_WAIT_SECONDS=$ELAPSED"; LAST=$((ELAPSED/10))
    fi
    sleep 5
done
LOCK_HELD=1
NOW="$(date +%s)"
echo 'PHASE5H5_RESIDUAL_LOCK_ACQUIRED=1'
echo "PHASE5H5_RESIDUAL_LOCK_WAIT_SECONDS=$((NOW-START))"

LOG="$(mktemp /var/tmp/phpipam-phase5h5-residual.XXXXXXXX.log)"
chmod 0600 "$LOG"
set +e
timeout --signal=TERM --kill-after=5s 150s runuser -u www-data -- php -d memory_limit=512M "$DIAG" 2>&1 | tee -i "$LOG"
STATUS=("${PIPESTATUS[@]}")
set -e

flock -u 9
exec 9>&-
LOCK_HELD=0
verify_timer

printf 'PHASE5H5_RESIDUAL_COMMAND_EXIT=%s\nPHASE5H5_RESIDUAL_LOG_EXIT=%s\n' "${STATUS[0]}" "${STATUS[1]}"
echo 'PHASE5H5_RESIDUAL_SHADOW_WRITTEN=0'
echo 'PHASE5H5_RESIDUAL_WEBSITE_CHANGED=0'
echo 'PHASE5H5_RESIDUAL_SYSTEMD_CHANGED=0'
[ "${STATUS[0]}" -eq 0 ] || exit "${STATUS[0]}"
[ "${STATUS[1]}" -eq 0 ] || exit "${STATUS[1]}"
