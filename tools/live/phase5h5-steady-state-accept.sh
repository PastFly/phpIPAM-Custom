#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
SHADOW='/opt/phpipam-custom/vmware-fast-shadow'
SHADOW_DATA='/var/lib/phpipam-custom/vmware-fast-shadow'
RUNNER="$SHADOW/tools/phase5h4-shadow-run.php"
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
TIMER='phpipam-vmware-recovery5g5@1.timer'
ACCEPT_BRANCH='phase5h/recurring-shadow-acceptance'
INSPECT_SOURCE='da2e4d10534044023dc3acd073fc37ead50741a8'
INSPECT_REL='tools/phase5h5-shadow-inspect.php'
INSPECT_BLOB='ce31b97df4422fde2cfd64e154ae61ab9d2da592'
RUNNER_BLOB='7ecaa1af168933456069e057bd23de2d2a185dcb'
FAST_BLOB='48279e71f7621e249435d47396c09e765e664340'
PLANNER_BLOB='898d9bf64cfe328dc2243fc938b1c709e234c486'
CYCLE_BLOB='9064e26f6d76f6e09ee7e615f2ae7e5c2506e129'
PERF_BLOB='b35127f8e28e503f558a08327b73cf9c6d41002a'
MAX_CYCLES=7
STEADY_REQUIRED=3
INTERVAL=60
LOCK_WAIT_LIMIT=180

TMP=''
SUMMARY=''
LOCK_HELD=0

cleanup() {
    rc=$?
    trap - EXIT
    if [ "$LOCK_HELD" -eq 1 ]; then flock -u 9 >/dev/null 2>&1 || true; exec 9>&- || true; fi
    [ -n "$TMP" ] && rm -rf -- "$TMP"
    printf '\nPHASE5H5_STEADY_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$SUMMARY" ] && [ -f "$SUMMARY" ] && printf 'PHASE5H5_STEADY_SUMMARY_FILE=%s\n' "$SUMMARY"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php python3 flock runuser timeout systemctl stat date sleep; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ] || { echo 'STOP: repository boundary'; exit 1; }
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$ACCEPT_BRANCH"
git cat-file -e "${INSPECT_SOURCE}^{commit}"
git merge-base --is-ancestor "$INSPECT_SOURCE" FETCH_HEAD

TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-steady.XXXXXXXX)"
chmod 0711 "$TMP"
INSPECT="$TMP/phase5h5-shadow-inspect.php"
git show "${INSPECT_SOURCE}:${INSPECT_REL}" > "$INSPECT"
chmod 0644 "$INSPECT"
[ "$(git hash-object "$INSPECT")" = "$INSPECT_BLOB" ] || { echo 'STOP: inspector source blob mismatch'; exit 1; }
php -l "$INSPECT" >/dev/null

check_blob() {
    local rel="$1" expected="$2"
    local path="$SHADOW/$rel"
    [ -f "$path" ] && [ ! -L "$path" ] || { echo "STOP: invalid shadow file boundary: $rel"; exit 1; }
    read -r uid gid mode links < <(stat -c '%u %g %a %h' "$path")
    [ "$uid" = 0 ] && [ "$gid" = 0 ] && [ "$mode" = 644 ] && [ "$links" = 1 ] || { echo "STOP: shadow file metadata mismatch: $rel"; exit 1; }
    [ "$(git hash-object "$path")" = "$expected" ] || { echo "STOP: shadow source mismatch: $rel"; exit 1; }
}
check_blob 'tools/phase5h4-shadow-run.php' "$RUNNER_BLOB"
check_blob 'layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php' "$FAST_BLOB"
check_blob 'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php' "$PLANNER_BLOB"
check_blob 'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php' "$CYCLE_BLOB"
check_blob 'layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php' "$PERF_BLOB"

for dir in "$SHADOW_DATA" "$SHADOW_DATA/properties" "$SHADOW_DATA/metrics" "$SHADOW_DATA/capabilities" "$SHADOW_DATA/roster"; do
    [ -d "$dir" ] && [ ! -L "$dir" ] || { echo "STOP: invalid shadow data boundary: $dir"; exit 1; }
    [ "$(stat -c '%u:%g:%a' "$dir")" = '33:33:700' ] || { echo "STOP: shadow data metadata mismatch: $dir"; exit 1; }
done

[ -f "$LOCK" ] && [ ! -L "$LOCK" ] || { echo 'STOP: production worker lock missing or linked'; exit 1; }
read -r lock_uid lock_gid lock_mode lock_links lock_size < <(stat -c '%u %g %a %h %s' "$LOCK")
[ "$lock_uid" = 33 ] && [ "$lock_gid" = 33 ] && [ "$lock_mode" = 600 ] && [ "$lock_links" = 1 ] && [ "$lock_size" = 0 ] || {
    echo 'STOP: production worker lock metadata mismatch'; exit 1;
}

verify_timer() {
    [ "$(systemctl show "$TIMER" -p ActiveState --value)" = active ] || { echo 'STOP: production timer not active'; exit 1; }
    [ "$(systemctl show "$TIMER" -p UnitFileState --value)" = enabled ] || { echo 'STOP: production timer not enabled'; exit 1; }
}

acquire_lock() {
    local cycle="$1" start now elapsed last_report=-1
    exec 9<>"$LOCK"
    start="$(date +%s)"
    printf 'PHASE5H5_STEADY_CYCLE_%s_LOCK_WAIT=1\n' "$cycle"
    while ! flock -x -n 9; do
        verify_timer
        now="$(date +%s)"; elapsed=$((now-start))
        if [ "$elapsed" -ge "$LOCK_WAIT_LIMIT" ]; then
            printf 'PHASE5H5_STEADY_CYCLE_%s_LOCK_TIMEOUT_SECONDS=%s\n' "$cycle" "$elapsed"
            exec 9>&-
            return 1
        fi
        if [ "$elapsed" -eq 0 ] || [ $((elapsed/10)) -ne "$last_report" ]; then
            printf 'PHASE5H5_STEADY_CYCLE_%s_LOCK_WAIT_SECONDS=%s\n' "$cycle" "$elapsed"
            last_report=$((elapsed/10))
        fi
        sleep 5
    done
    LOCK_HELD=1
    now="$(date +%s)"; LOCK_WAIT=$((now-start))
    printf 'PHASE5H5_STEADY_CYCLE_%s_LOCK_ACQUIRED=1\nPHASE5H5_STEADY_CYCLE_%s_LOCK_WAIT_SECONDS=%s\n' "$cycle" "$cycle" "$LOCK_WAIT"
}

verify_timer
echo 'PHASE5H5_STEADY_PREFLIGHT_OK=1'
echo 'PHASE5H5_STEADY_NO_VIEWER=1'
echo 'PHASE5H5_STEADY_EXPECTS_NEW_FAST_DOMAIN=1'
echo 'PHASE5H5_STEADY_EXPECTS_NEW_PLANNER=1'
echo 'PHASE5H5_STEADY_EXPECTS_NEW_SHADOW_CYCLE=1'
echo 'PHASE5H5_STEADY_WEBSITE_CHANGED=0'
echo 'PHASE5H5_STEADY_SYSTEMD_CHANGED=0'

SUMMARY="$(mktemp /var/tmp/phpipam-phase5h5-steady-summary.XXXXXXXX.jsonl)"
chmod 0600 "$SUMMARY"
STEADY_COUNT=0

for cycle in $(seq 1 "$MAX_CYCLES"); do
    verify_timer
    CYCLE_START="$(date +%s)"
    RUN_LOG="$TMP/run-$cycle.log"
    INSPECT_LOG="$TMP/inspect-$cycle.log"
    LOCK_WAIT=0

    acquire_lock "$cycle" || { echo "STOP: cycle $cycle timed out waiting for production worker lock"; exit 1; }
    set +e
    timeout --signal=TERM --kill-after=5s 150s runuser -u www-data -- php -d memory_limit=512M "$RUNNER" >"$RUN_LOG" 2>&1
    RUN_RC=$?
    set -e
    flock -u 9; exec 9>&-; LOCK_HELD=0
    if [ "$RUN_RC" -ne 0 ]; then
        printf 'PHASE5H5_STEADY_CYCLE_%s_RUN_EXIT=%s\n' "$cycle" "$RUN_RC"
        grep -E '^PHASE5H_SHADOW_(ERROR|EXIT)=' "$RUN_LOG" || true
        exit "$RUN_RC"
    fi

    set +e
    runuser -u www-data -- php -d memory_limit=256M "$INSPECT" >"$INSPECT_LOG" 2>&1
    INSPECT_RC=$?
    set -e
    if [ "$INSPECT_RC" -ne 0 ]; then
        printf 'PHASE5H5_STEADY_CYCLE_%s_INSPECT_EXIT=%s\n' "$cycle" "$INSPECT_RC"
        grep -E '^PHASE5H5_SHADOW_INSPECT_(ERROR|EXIT)=' "$INSPECT_LOG" || true
        exit "$INSPECT_RC"
    fi

    CYCLE="$cycle" LOCK_WAIT="$LOCK_WAIT" RUN_LOG="$RUN_LOG" INSPECT_LOG="$INSPECT_LOG" SUMMARY="$SUMMARY" \
    PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os
from pathlib import Path

def one(path, prefix):
    rows=[line[len(prefix):] for line in Path(path).read_text().splitlines() if line.startswith(prefix)]
    if len(rows)!=1: raise SystemExit('steady_output_shape')
    value=json.loads(rows[0])
    if not isinstance(value,dict): raise SystemExit('steady_output_shape')
    return value

run=one(os.environ['RUN_LOG'],'PHASE5H_SHADOW_RESULT=')
inspect=one(os.environ['INSPECT_LOG'],'PHASE5H5_SHADOW_INSPECT=')
if run.get('state')!='shadow_complete' or run.get('website_served_from_shadow') is not False or run.get('active_generation_unchanged') is not True:
    raise SystemExit('steady_shadow_contract')
if run.get('property_publish_state')!='published' or run.get('metric_publish_state')!='published': raise SystemExit('steady_publish_contract')
if inspect.get('website_served_from_shadow') is not False or inspect.get('shadow_read_only_inspection') is not True:
    raise SystemExit('steady_inspect_contract')
p=inspect.get('properties'); m=inspect.get('metrics'); c=inspect.get('capabilities')
if not isinstance(p,dict) or p.get('present') is not True or not isinstance(m,dict) or m.get('present') is not True:
    raise SystemExit('steady_domains_missing')
if p.get('published_at')!=run.get('published_at') or m.get('published_at')!=run.get('published_at'):
    raise SystemExit('steady_publish_timestamp_mismatch')
mo=m.get('fresh_series_observed_to_visible_seconds') or {}
mc=m.get('fresh_series_collected_to_visible_seconds') or {}
pc=p.get('fresh_field_collected_to_visible_seconds') or {}
record={
 'cycle':int(os.environ['CYCLE']),'lock_wait_seconds':int(os.environ['LOCK_WAIT']),
 'published_at':run.get('published_at'),'wall_ms':run.get('wall_ms'),'requests':run.get('requests'),
 'response_bytes':run.get('response_bytes'),'roster_counts':run.get('roster_counts'),
 'metric_plans':run.get('metric_plans'),'capability_revalidated':run.get('capability_revalidated'),
 'capability_still_due':run.get('capability_still_due'),'unsupported':run.get('unsupported'),
 'metric_fresh_observed_count':mo.get('count'),'metric_observed_to_visible_max_seconds':mo.get('max'),
 'metric_collected_to_visible_max_seconds':mc.get('max'),'property_collected_to_visible_max_seconds':pc.get('max'),
 'property_source_completed_to_visible_seconds':p.get('source_completed_to_visible_seconds'),
 'metric_objects_state':m.get('objects'),'metric_series_state':m.get('series'),
 'capability_entries':c.get('entries') if isinstance(c,dict) else None,
 'capability_valid_now':c.get('valid_now') if isinstance(c,dict) else None,
 'capability_expired_now':c.get('expired_now') if isinstance(c,dict) else None,
 'active_generation_unchanged':True,'website_served_from_shadow':False,
}
for key in ['published_at','wall_ms','requests','response_bytes','capability_still_due']:
    if not isinstance(record.get(key),int): raise SystemExit('steady_summary_invalid')
with open(os.environ['SUMMARY'],'a',encoding='utf-8') as f: f.write(json.dumps(record,separators=(',',':'),sort_keys=True)+'\n')
print('PHASE5H5_STEADY_CYCLE_'+str(record['cycle'])+'='+json.dumps(record,separators=(',',':'),sort_keys=True))
PY

    STEADY_COUNT="$(SUMMARY="$SUMMARY" PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os
from pathlib import Path
rows=[json.loads(x) for x in Path(os.environ['SUMMARY']).read_text().splitlines() if x.strip()]
n=0
for row in reversed(rows):
    if row.get('capability_still_due')==0: n+=1
    else: break
print(n)
PY
)"
    printf 'PHASE5H5_STEADY_CONSECUTIVE_COUNT=%s\n' "$STEADY_COUNT"
    if [ "$STEADY_COUNT" -ge "$STEADY_REQUIRED" ]; then break; fi

    if [ "$cycle" -lt "$MAX_CYCLES" ]; then
        NEXT=$((CYCLE_START+INTERVAL)); NOW="$(date +%s)"; SLEEP=$((NEXT-NOW))
        if [ "$SLEEP" -gt 0 ]; then
            printf 'PHASE5H5_STEADY_CYCLE_%s_NEXT_IN_SECONDS=%s\n' "$cycle" "$SLEEP"
            sleep "$SLEEP"
        fi
    fi
done

SUMMARY="$SUMMARY" STEADY_REQUIRED="$STEADY_REQUIRED" PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os
from pathlib import Path
rows=[json.loads(x) for x in Path(os.environ['SUMMARY']).read_text().splitlines() if x.strip()]
required=int(os.environ['STEADY_REQUIRED'])
trailing=[]
for row in reversed(rows):
    if row.get('capability_still_due')==0: trailing.append(row)
    else: break
trailing=list(reversed(trailing))
if len(trailing)<required:
    print('PHASE5H5_STEADY_ACCEPTANCE='+json.dumps({'cycles_total':len(rows),'steady_cycles':len(trailing),'target_60_300_acceptance':False},separators=(',',':'),sort_keys=True))
    raise SystemExit(2)
steady=trailing[-required:]
pubs=[r['published_at'] for r in steady]
intervals=[b-a for a,b in zip(pubs,pubs[1:])]
if any(x<=0 for x in intervals): raise SystemExit('steady_publication_order')
metric=[r['metric_observed_to_visible_max_seconds'] for r in steady if isinstance(r.get('metric_observed_to_visible_max_seconds'),int)]
metric_collect=[r['metric_collected_to_visible_max_seconds'] for r in steady if isinstance(r.get('metric_collected_to_visible_max_seconds'),int)]
prop=[r['property_collected_to_visible_max_seconds'] for r in steady if isinstance(r.get('property_collected_to_visible_max_seconds'),int)]
source=[r['property_source_completed_to_visible_seconds'] for r in steady if isinstance(r.get('property_source_completed_to_visible_seconds'),int)]
accept={
 'cycles_total':len(rows),'warmup_cycles':len(rows)-len(trailing),'steady_cycles':required,
 'steady_cycle_numbers':[r['cycle'] for r in steady],
 'capability_still_due_all':[r['capability_still_due'] for r in rows],
 'publication_intervals_seconds':intervals,
 'publication_interval_max_seconds':max(intervals) if intervals else 0,
 'wall_ms_max':max(r['wall_ms'] for r in steady),
 'lock_wait_seconds_max':max(r['lock_wait_seconds'] for r in steady),
 'metric_observed_to_visible_max_seconds':max(metric) if metric else None,
 'metric_collected_to_visible_max_seconds':max(metric_collect) if metric_collect else None,
 'property_collected_to_visible_max_seconds':max(prop) if prop else None,
 'property_source_completed_to_visible_max_seconds':max(source) if source else None,
 'all_active_generation_unchanged':all(r['active_generation_unchanged'] for r in steady),
 'website_served_from_shadow':False,
}
checks=[
 accept['publication_interval_max_seconds']<=300,
 isinstance(accept['metric_observed_to_visible_max_seconds'],int) and accept['metric_observed_to_visible_max_seconds']<=300,
 isinstance(accept['property_collected_to_visible_max_seconds'],int) and accept['property_collected_to_visible_max_seconds']<=60,
 isinstance(accept['property_source_completed_to_visible_max_seconds'],int) and accept['property_source_completed_to_visible_max_seconds']<=60,
 all(r['capability_still_due']==0 for r in steady),
 accept['all_active_generation_unchanged'],
]
accept['target_60_300_acceptance']=all(checks)
print('PHASE5H5_STEADY_ACCEPTANCE='+json.dumps(accept,separators=(',',':'),sort_keys=True))
if not accept['target_60_300_acceptance']: raise SystemExit(2)
PY

verify_timer
systemctl show "$TIMER" --no-pager -p ActiveState -p SubState -p UnitFileState
echo 'PHASE5H5_STEADY_SHADOW_ACCEPT_OK=1'
echo 'PHASE5H5_STEADY_ACTIVE_DATA_PATH_NOT_WRITTEN=1'
echo 'PHASE5H5_STEADY_WEBSITE_CHANGED=0'
echo 'PHASE5H5_STEADY_SYSTEMD_CHANGED=0'
