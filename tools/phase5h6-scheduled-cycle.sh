#!/usr/bin/env bash
set -euo pipefail
umask 077

INSTANCE="${1-}"
SHADOW='/opt/phpipam-custom/vmware-fast-shadow'
RUNNER="$SHADOW/tools/phase5h4-shadow-run.php"
DATA='/var/lib/phpipam-custom/vmware-fast-shadow'
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
LOCK_WAIT_LIMIT=45
RUN_TIMEOUT=125

[ "$INSTANCE" = '1' ] || { echo 'PHASE5H6_SCHED_ERROR=instance'; exit 64; }
[ "$(id -u)" -eq 33 ] && [ "$(id -g)" -eq 33 ] || { echo 'PHASE5H6_SCHED_ERROR=identity'; exit 1; }
for bin in php flock timeout stat date sleep; do command -v "$bin" >/dev/null || { echo 'PHASE5H6_SCHED_ERROR=capability'; exit 1; }; done

[ -f "$RUNNER" ] && [ ! -L "$RUNNER" ] || { echo 'PHASE5H6_SCHED_ERROR=runner_boundary'; exit 1; }
read -r runner_uid runner_gid runner_mode runner_links < <(stat -c '%u %g %a %h' "$RUNNER")
[ "$runner_uid" = 0 ] && [ "$runner_gid" = 0 ] && [ "$runner_mode" = 644 ] && [ "$runner_links" = 1 ] || {
    echo 'PHASE5H6_SCHED_ERROR=runner_boundary'; exit 1;
}

for dir in "$DATA" "$DATA/properties" "$DATA/metrics" "$DATA/capabilities" "$DATA/roster"; do
    [ -d "$dir" ] && [ ! -L "$dir" ] || { echo 'PHASE5H6_SCHED_ERROR=data_boundary'; exit 1; }
    [ "$(stat -c '%u:%g:%a' "$dir")" = '33:33:700' ] || { echo 'PHASE5H6_SCHED_ERROR=data_boundary'; exit 1; }
done

[ -f "$LOCK" ] && [ ! -L "$LOCK" ] || { echo 'PHASE5H6_SCHED_ERROR=lock_boundary'; exit 1; }
read -r lock_uid lock_gid lock_mode lock_links lock_size < <(stat -c '%u %g %a %h %s' "$LOCK")
[ "$lock_uid" = 33 ] && [ "$lock_gid" = 33 ] && [ "$lock_mode" = 600 ] && [ "$lock_links" = 1 ] && [ "$lock_size" = 0 ] || {
    echo 'PHASE5H6_SCHED_ERROR=lock_boundary'; exit 1;
}

exec 9<>"$LOCK"
start="$(date +%s)"
last_report=-1
echo 'PHASE5H6_SCHED_LOCK_WAIT=1'
while ! flock -x -n 9; do
    now="$(date +%s)"
    elapsed=$((now-start))
    if [ "$elapsed" -ge "$LOCK_WAIT_LIMIT" ]; then
        printf 'PHASE5H6_SCHED_LOCK_BUSY_SKIP=1\nPHASE5H6_SCHED_LOCK_WAIT_SECONDS=%s\n' "$elapsed"
        exec 9>&-
        exit 75
    fi
    if [ "$elapsed" -eq 0 ] || [ $((elapsed/10)) -ne "$last_report" ]; then
        printf 'PHASE5H6_SCHED_LOCK_WAIT_SECONDS=%s\n' "$elapsed"
        last_report=$((elapsed/10))
    fi
    sleep 5
done
now="$(date +%s)"
printf 'PHASE5H6_SCHED_LOCK_ACQUIRED=1\nPHASE5H6_SCHED_LOCK_WAIT_SECONDS=%s\n' "$((now-start))"

set +e
timeout --signal=TERM --kill-after=5s "${RUN_TIMEOUT}s" php -d memory_limit=512M "$RUNNER"
rc=$?
set -e

flock -u 9
exec 9>&-

if [ "$rc" -eq 124 ] || [ "$rc" -eq 137 ]; then
    printf 'PHASE5H6_SCHED_RUN_TIMEOUT=1\nPHASE5H6_SCHED_RUN_EXIT=%s\n' "$rc"
    exit "$rc"
fi
printf 'PHASE5H6_SCHED_RUN_EXIT=%s\n' "$rc"
exit "$rc"
