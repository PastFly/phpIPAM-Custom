#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
BRANCH='phase5h6/autonomous-shadow-scheduler'
SOURCE='efa8c8703ab7bf7296106caae0ca180836e153ab'
OBS_REL='tools/phase5h6-shadow-observe.php'
OBS_BLOB='f20c8db95dbc1658f72048e321485eeebe7c8022'
WRAPPER='/opt/phpipam-custom/vmware-fast-shadow/tools/phase5h6-scheduled-cycle.sh'
SERVICE_UNIT='/etc/systemd/system/phpipam-vmware-fast-shadow@.service'
TIMER_UNIT='/etc/systemd/system/phpipam-vmware-fast-shadow@.timer'
WRAPPER_BLOB='13471b8d3d22f7bb97dfed094c9ed637bc04837b'
SERVICE_BLOB='3fd85e1b84c9b094d068281b7323f1c12fd731d3'
TIMER_BLOB='71e689868e79854634401e1b194d55da4712d4c3'
SCHED_TIMER='phpipam-vmware-fast-shadow@1.timer'
SERVING_TIMER='phpipam-vmware-recovery5g5@1.timer'
REQUIRED_NEW_PUBLICATIONS=4
POLL_SECONDS=20
MAX_SECONDS=420
TMP=''
SAMPLES=''

cleanup() {
    rc=$?
    trap - EXIT
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H6_AUTONOMOUS_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$SAMPLES" ] && [ -f "$SAMPLES" ] && printf 'PHASE5H6_AUTONOMOUS_SAMPLES=%s\n' "$SAMPLES"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php python3 runuser systemctl stat date sleep; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ] || { echo 'STOP: repository boundary'; exit 1; }
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }
git fetch --no-tags origin "$BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD

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
check_blob "$SERVICE_UNIT" "$SERVICE_BLOB" 0 0 644
check_blob "$TIMER_UNIT" "$TIMER_BLOB" 0 0 644

verify_timers() {
    local unit active enabled
    for unit in "$SCHED_TIMER" "$SERVING_TIMER"; do
        active="$(systemctl show "$unit" -p ActiveState --value)"
        enabled="$(systemctl show "$unit" -p UnitFileState --value)"
        [ "$active" = active ] && [ "$enabled" = enabled ] || { echo "STOP: timer boundary failed: $unit"; exit 1; }
    done
}
verify_timers

TMP="$(mktemp -d /var/tmp/phpipam-phase5h6-observe.XXXXXXXX)"
chmod 0711 "$TMP"
OBSERVER="$TMP/observe.php"
git show "${SOURCE}:${OBS_REL}" > "$OBSERVER"
chmod 0644 "$OBSERVER"
[ "$(git hash-object "$OBSERVER")" = "$OBS_BLOB" ] || { echo 'STOP: observer blob mismatch'; exit 1; }
php -l "$OBSERVER" >/dev/null
SAMPLES="$(mktemp /var/tmp/phpipam-phase5h6-autonomous-samples.XXXXXXXX.jsonl)"
chmod 0600 "$SAMPLES"

echo 'PHASE5H6_AUTONOMOUS_PREFLIGHT_OK=1'
echo 'PHASE5H6_AUTONOMOUS_NO_RUNNER_INVOCATION=1'
echo 'PHASE5H6_AUTONOMOUS_WEBSITE_READER_CHANGED=0'
echo 'PHASE5H6_AUTONOMOUS_SERVING_5G5_TIMER_CHANGED=0'

observe_once() {
    local log="$TMP/observe.log"
    runuser -u www-data -- php -d memory_limit=256M "$OBSERVER" >"$log" 2>&1
    python3 - "$log" <<'PY'
import json,sys
rows=[]
for line in open(sys.argv[1],encoding='utf-8'):
    if line.startswith('PHASE5H6_OBSERVE='):
        rows.append(json.loads(line.split('=',1)[1]))
if len(rows)!=1:
    raise SystemExit('observe_shape')
print(json.dumps(rows[0],separators=(',',':'),sort_keys=True))
PY
}

BASE="$(observe_once)"
BASE_PUB="$(BASE="$BASE" python3 - <<'PY'
import json,os
r=json.loads(os.environ['BASE'])
p=r.get('metric_published_at')
if not isinstance(p,int): raise SystemExit('base_pub')
print(p)
PY
)"
printf 'PHASE5H6_AUTONOMOUS_BASELINE=%s\n' "$BASE"
START="$(date +%s)"
LAST="$BASE_PUB"
COUNT=0

while :; do
    verify_timers
    NOW="$(date +%s)"
    ELAPSED=$((NOW-START))
    [ "$ELAPSED" -lt "$MAX_SECONDS" ] || break
    sleep "$POLL_SECONDS"
    ROW="$(observe_once)"
    PUB="$(ROW="$ROW" python3 - <<'PY'
import json,os
r=json.loads(os.environ['ROW'])
p=r.get('metric_published_at')
q=r.get('property_published_at')
if not isinstance(p,int) or q!=p: raise SystemExit('publish_pair')
print(p)
PY
)"
    if [ "$PUB" -le "$LAST" ]; then
        printf 'PHASE5H6_AUTONOMOUS_WAIT_SECONDS=%s\n' "$(( $(date +%s)-START ))"
        continue
    fi
    ROW="$ROW" python3 - <<'PY'
import json,os
r=json.loads(os.environ['ROW'])
for key,limit in [
 ('metric_observed_to_visible_max_seconds',300),
 ('metric_collected_to_visible_max_seconds',60),
 ('property_collected_to_visible_max_seconds',60),
]:
    v=r.get(key)
    if not isinstance(v,int) or v>limit: raise SystemExit('freshness_contract:'+key)
if r.get('shadow_read_only_observation') is not True or r.get('website_served_from_shadow') is not False:
    raise SystemExit('observer_contract')
PY
    printf '%s\n' "$ROW" >> "$SAMPLES"
    COUNT=$((COUNT+1))
    LAST="$PUB"
    printf 'PHASE5H6_AUTONOMOUS_PUBLICATION_%s=%s\n' "$COUNT" "$ROW"
    if [ "$COUNT" -ge "$REQUIRED_NEW_PUBLICATIONS" ]; then break; fi
done

verify_timers
SAMPLES="$SAMPLES" REQUIRED="$REQUIRED_NEW_PUBLICATIONS" python3 - <<'PY'
import json,os
rows=[json.loads(x) for x in open(os.environ['SAMPLES'],encoding='utf-8') if x.strip()]
required=int(os.environ['REQUIRED'])
if len(rows)<required:
    print('PHASE5H6_AUTONOMOUS_ACCEPTANCE='+json.dumps({'new_publications':len(rows),'accepted':False},separators=(',',':'),sort_keys=True))
    raise SystemExit(2)
rows=rows[-required:]
pubs=[r['metric_published_at'] for r in rows]
intervals=[b-a for a,b in zip(pubs,pubs[1:])]
if any(v<=0 or v>180 for v in intervals): raise SystemExit('publication_interval')
result={
 'accepted':True,
 'new_publications':len(rows),
 'publication_intervals_seconds':intervals,
 'publication_interval_max_seconds':max(intervals) if intervals else 0,
 'metric_observed_to_visible_max_seconds':max(r['metric_observed_to_visible_max_seconds'] for r in rows),
 'metric_collected_to_visible_max_seconds':max(r['metric_collected_to_visible_max_seconds'] for r in rows),
 'property_collected_to_visible_max_seconds':max(r['property_collected_to_visible_max_seconds'] for r in rows),
 'scheduler_invoked_by_observer':False,
 'website_served_from_shadow':False,
}
print('PHASE5H6_AUTONOMOUS_ACCEPTANCE='+json.dumps(result,separators=(',',':'),sort_keys=True))
PY

echo 'PHASE5H6_AUTONOMOUS_ACCEPT_OK=1'
echo 'PHASE5H6_AUTONOMOUS_SCHEDULER_TIMER_PRESERVED=1'
echo 'PHASE5H6_AUTONOMOUS_SERVING_5G5_TIMER_PRESERVED=1'
