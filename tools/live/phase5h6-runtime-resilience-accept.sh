#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
BRANCH='phase5h6/autonomous-shadow-scheduler'
OBS_SOURCE='efa8c8703ab7bf7296106caae0ca180836e153ab'
OBS_REL='tools/phase5h6-shadow-observe.php'
OBS_BLOB='f20c8db95dbc1658f72048e321485eeebe7c8022'
WRAPPER='/opt/phpipam-custom/vmware-fast-shadow/tools/phase5h6-scheduled-cycle.sh'
SERVICE_UNIT_FILE='/etc/systemd/system/phpipam-vmware-fast-shadow@.service'
TIMER_UNIT_FILE='/etc/systemd/system/phpipam-vmware-fast-shadow@.timer'
WRAPPER_BLOB='13471b8d3d22f7bb97dfed094c9ed637bc04837b'
SERVICE_BLOB='3fd85e1b84c9b094d068281b7323f1c12fd731d3'
TIMER_BLOB='71e689868e79854634401e1b194d55da4712d4c3'
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
SERVICE='phpipam-vmware-fast-shadow@1.service'
SCHED_TIMER='phpipam-vmware-fast-shadow@1.timer'
SERVING_TIMER='phpipam-vmware-recovery5g5@1.timer'
POLL_SECONDS=10
RECOVERY_LIMIT=240
TMP=''
LOCK_HELD=0
START_PID=''

cleanup() {
    rc=$?
    trap - EXIT
    if [ "$LOCK_HELD" -eq 1 ]; then flock -u 9 >/dev/null 2>&1 || true; exec 9>&- || true; fi
    if [ -n "$START_PID" ]; then wait "$START_PID" >/dev/null 2>&1 || true; fi
    systemctl start "$SCHED_TIMER" >/dev/null 2>&1 || true
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H6_RESILIENCE_BLOCK_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php python3 runuser systemctl journalctl flock stat date sleep; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ] || { echo 'STOP: repository boundary'; exit 1; }
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }
git fetch --no-tags origin "$BRANCH"
git cat-file -e "${OBS_SOURCE}^{commit}"
git merge-base --is-ancestor "$OBS_SOURCE" FETCH_HEAD

check_blob() {
    local path="$1" expected="$2" uid="$3" gid="$4" mode="$5"
    [ -f "$path" ] && [ ! -L "$path" ] || { echo "STOP: invalid installed boundary: $path"; exit 1; }
    read -r auid agid amode links < <(stat -c '%u %g %a %h' "$path")
    [ "$auid" = "$uid" ] && [ "$agid" = "$gid" ] && [ "$amode" = "$mode" ] && [ "$links" = 1 ] || {
        echo "STOP: installed metadata mismatch: $path"; exit 1;
    }
    [ "$(git hash-object "$path")" = "$expected" ] || { echo "STOP: installed blob mismatch: $path"; exit 1; }
}
check_blob "$WRAPPER" "$WRAPPER_BLOB" 0 0 755
check_blob "$SERVICE_UNIT_FILE" "$SERVICE_BLOB" 0 0 644
check_blob "$TIMER_UNIT_FILE" "$TIMER_BLOB" 0 0 644
check_blob "$LOCK" "$(git hash-object "$LOCK")" 33 33 600
[ "$(stat -c '%s' "$LOCK")" = 0 ] || { echo 'STOP: lock size boundary'; exit 1; }

verify_timers() {
    local unit active enabled
    for unit in "$SCHED_TIMER" "$SERVING_TIMER"; do
        active="$(systemctl show "$unit" -p ActiveState --value)"
        enabled="$(systemctl show "$unit" -p UnitFileState --value)"
        [ "$active" = active ] && [ "$enabled" = enabled ] || { echo "STOP: timer boundary failed: $unit"; exit 1; }
    done
}
verify_timers

TMP="$(mktemp -d /var/tmp/phpipam-phase5h6-resilience.XXXXXXXX)"
chmod 0711 "$TMP"
OBSERVER="$TMP/observe.php"
git show "${OBS_SOURCE}:${OBS_REL}" > "$OBSERVER"
chmod 0644 "$OBSERVER"
[ "$(git hash-object "$OBSERVER")" = "$OBS_BLOB" ] || { echo 'STOP: observer blob mismatch'; exit 1; }
php -l "$OBSERVER" >/dev/null

echo 'PHASE5H6_RESILIENCE_PREFLIGHT_OK=1'
echo 'PHASE5H6_RESILIENCE_WEBSITE_CHANGED=0'
echo 'PHASE5H6_RESILIENCE_SERVING_5G5_TIMER_CHANGED=0'
echo 'PHASE5H6_RESILIENCE_RUNTIME_FILES_CHANGED=0'

observe_once() {
    local log="$TMP/observe.log"
    runuser -u www-data -- php -d memory_limit=256M "$OBSERVER" >"$log" 2>&1
    python3 - "$log" <<'PY'
import json,sys
rows=[]
for line in open(sys.argv[1],encoding='utf-8'):
    if line.startswith('PHASE5H6_OBSERVE='):
        rows.append(json.loads(line.split('=',1)[1]))
if len(rows)!=1: raise SystemExit('observe_shape')
r=rows[0]
for key,limit in [
 ('metric_observed_to_visible_max_seconds',300),
 ('metric_collected_to_visible_max_seconds',60),
 ('property_collected_to_visible_max_seconds',60),
]:
    v=r.get(key)
    if not isinstance(v,int) or v>limit: raise SystemExit('freshness_contract:'+key)
if r.get('shadow_read_only_observation') is not True or r.get('website_served_from_shadow') is not False:
    raise SystemExit('observer_contract')
print(json.dumps(r,separators=(',',':'),sort_keys=True))
PY
}

pub_of() {
    ROW="$1" python3 - <<'PY'
import json,os
r=json.loads(os.environ['ROW']); p=r.get('metric_published_at'); q=r.get('property_published_at')
if not isinstance(p,int) or q!=p: raise SystemExit('publish_pair')
print(p)
PY
}

wait_new_publication() {
    local previous="$1" label="$2" start now row pub
    start="$(date +%s)"
    while :; do
        verify_timers
        row="$(observe_once)"
        pub="$(pub_of "$row")"
        if [ "$pub" -gt "$previous" ]; then
            printf 'PHASE5H6_RESILIENCE_%s=%s\n' "$label" "$row"
            printf '%s\n' "$pub"
            return 0
        fi
        now="$(date +%s)"
        if [ $((now-start)) -ge "$RECOVERY_LIMIT" ]; then
            echo "STOP: timed out waiting for $label" >&2
            return 1
        fi
        sleep "$POLL_SECONDS"
    done
}

wait_service_idle() {
    local start now state
    start="$(date +%s)"
    while :; do
        state="$(systemctl show "$SERVICE" -p ActiveState --value)"
        [ "$state" != activating ] && [ "$state" != active ] && return 0
        now="$(date +%s)"
        [ $((now-start)) -lt 60 ] || { echo 'STOP: scheduler service did not become idle'; return 1; }
        sleep 2
    done
}

BASE="$(observe_once)"
BASE_PUB="$(pub_of "$BASE")"
printf 'PHASE5H6_RESILIENCE_BASELINE=%s\n' "$BASE"

# 1. Timer restart must preserve enablement and produce a new autonomous publication.
systemctl restart "$SCHED_TIMER"
verify_timers
RESTART_OUT="$(wait_new_publication "$BASE_PUB" 'AFTER_TIMER_RESTART')"
RESTART_PUB="$(printf '%s\n' "$RESTART_OUT" | tail -n1)"
printf '%s\n' "$RESTART_OUT" | head -n -1 || true
echo 'PHASE5H6_RESILIENCE_TIMER_RESTART_OK=1'

# 2. Short real shared-lock contention: the service must wait, then run after release.
wait_service_idle
exec 9<>"$LOCK"
flock -x 9
LOCK_HELD=1
CONTEND_SINCE="$(date +%s)"
set +e
systemctl start "$SERVICE" >/dev/null 2>&1 &
START_PID=$!
set -e
sleep 8
STATE_DURING="$(systemctl show "$SERVICE" -p ActiveState --value)"
[ "$STATE_DURING" = activating ] || { echo "STOP: service was not waiting under contention: $STATE_DURING"; exit 1; }
flock -u 9
exec 9>&-
LOCK_HELD=0
set +e
wait "$START_PID"
START_RC=$?
set -e
START_PID=''
[ "$START_RC" -eq 0 ] || { echo "STOP: service failed after contention rc=$START_RC"; exit 1; }
RESULT="$(systemctl show "$SERVICE" -p Result --value)"
STATUS="$(systemctl show "$SERVICE" -p ExecMainStatus --value)"
[ "$RESULT" = success ] && [ "$STATUS" = 0 ] || { echo "STOP: contention result $RESULT/$STATUS"; exit 1; }
LOCK_MAX="$(journalctl -u "$SERVICE" --since "@${CONTEND_SINCE}" --no-pager -o cat | python3 -c 'import re,sys; v=[int(x) for x in re.findall(r"PHASE5H6_SCHED_LOCK_WAIT_SECONDS=(\d+)",sys.stdin.read())]; print(max(v) if v else -1)')"
[ "$LOCK_MAX" -ge 5 ] || { echo "STOP: contention wait not observed: $LOCK_MAX"; exit 1; }
verify_timers
echo "PHASE5H6_RESILIENCE_CONTENTION_WAIT_SECONDS=$LOCK_MAX"
echo 'PHASE5H6_RESILIENCE_SHARED_LOCK_CONTENTION_OK=1'
CONTEND_ROW="$(observe_once)"
CONTEND_PUB="$(pub_of "$CONTEND_ROW")"
[ "$CONTEND_PUB" -gt "$RESTART_PUB" ] || { echo 'STOP: contention service did not publish'; exit 1; }

# 3. Force one Phase5H-only service failure while it is blocked on the shared lock.
# No provider call occurs before the kill. The still-enabled timer must later recover autonomously.
wait_service_idle
exec 9<>"$LOCK"
flock -x 9
LOCK_HELD=1
FAIL_SINCE="$(date +%s)"
set +e
systemctl start "$SERVICE" >/dev/null 2>&1 &
START_PID=$!
set -e
sleep 4
FAIL_STATE="$(systemctl show "$SERVICE" -p ActiveState --value)"
[ "$FAIL_STATE" = activating ] || { echo "STOP: failure probe service not activating: $FAIL_STATE"; exit 1; }
systemctl kill --kill-who=main --signal=TERM "$SERVICE"
flock -u 9
exec 9>&-
LOCK_HELD=0
set +e
wait "$START_PID"
FAIL_START_RC=$?
set -e
START_PID=''
[ "$FAIL_START_RC" -ne 0 ] || { echo 'STOP: forced failure unexpectedly returned success'; exit 1; }
FAIL_RESULT="$(systemctl show "$SERVICE" -p Result --value)"
[ "$FAIL_RESULT" != success ] || { echo 'STOP: forced failure not recorded'; exit 1; }
verify_timers
echo "PHASE5H6_RESILIENCE_FORCED_FAILURE_RESULT=$FAIL_RESULT"
echo 'PHASE5H6_RESILIENCE_FORCED_FAILURE_OBSERVED=1'

RECOVERY_OUT="$(wait_new_publication "$CONTEND_PUB" 'AFTER_FORCED_FAILURE_RECOVERY')"
RECOVERY_PUB="$(printf '%s\n' "$RECOVERY_OUT" | tail -n1)"
printf '%s\n' "$RECOVERY_OUT" | head -n -1 || true
[ "$RECOVERY_PUB" -gt "$CONTEND_PUB" ] || { echo 'STOP: recovery publication ordering'; exit 1; }
verify_timers
FINAL_RESULT="$(systemctl show "$SERVICE" -p Result --value)"
FINAL_STATUS="$(systemctl show "$SERVICE" -p ExecMainStatus --value)"
[ "$FINAL_RESULT" = success ] && [ "$FINAL_STATUS" = 0 ] || { echo "STOP: scheduler did not recover: $FINAL_RESULT/$FINAL_STATUS"; exit 1; }

echo 'PHASE5H6_RESILIENCE_AUTONOMOUS_RECOVERY_OK=1'
echo 'PHASE5H6_RESILIENCE_SCHEDULER_TIMER_PRESERVED=1'
echo 'PHASE5H6_RESILIENCE_SERVING_5G5_TIMER_PRESERVED=1'
echo 'PHASE5H6_RESILIENCE_ACCEPT_OK=1'
