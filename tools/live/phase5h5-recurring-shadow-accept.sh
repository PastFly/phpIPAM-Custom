#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
SOURCE='da2e4d10534044023dc3acd073fc37ead50741a8'
BRANCH='phase5h/recurring-shadow-acceptance'
SHADOW='/opt/phpipam-custom/vmware-fast-shadow'
SHADOW_DATA='/var/lib/phpipam-custom/vmware-fast-shadow'
RUNNER="$SHADOW/tools/phase5h4-shadow-run.php"
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
TIMER='phpipam-vmware-recovery5g5@1.timer'
INSPECT_REL='tools/phase5h5-shadow-inspect.php'
INSPECT_BLOB='ce31b97df4422fde2cfd64e154ae61ab9d2da592'
CYCLES=3
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
    printf '\nPHASE5H5_RECURRING_BLOCK_EXIT=%s\n' "$rc"
    [ -n "$SUMMARY" ] && [ -f "$SUMMARY" ] && printf 'PHASE5H5_RECURRING_SUMMARY_FILE=%s\n' "$SUMMARY"
    exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php python3 flock runuser timeout systemctl stat; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
[ "$(git rev-parse --show-toplevel)" = "$REPO" ]
[ "$(git rev-parse HEAD)" = "$PROTECTED" ] || { echo 'STOP: protected checkout HEAD changed'; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo 'STOP: protected checkout has local changes'; exit 1; }

git fetch --no-tags origin "$BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD

TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-recurring.XXXXXXXX)"
# The inspector is deliberately executed as www-data. 0711 permits path traversal
# without directory listing; umask 077 keeps run logs and any other temp files root-only.
chmod 0711 "$TMP"
INSPECT="$TMP/phase5h5-shadow-inspect.php"
git show "${SOURCE}:${INSPECT_REL}" > "$INSPECT"
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
check_blob 'tools/phase5h4-shadow-run.php' '7ecaa1af168933456069e057bd23de2d2a185dcb'
check_blob 'layers/phase5h/functions/classes/class.IntegrationVmwareBatchSession.php' 'd1241feba9a1e7371c579c4d259ed1f1d983cd9c'
check_blob 'layers/phase5h/functions/classes/class.IntegrationVmwareBatchInventory.php' '5b9cff8bf0c649dd4e285f11d2fb8438eef15aec'
check_blob 'layers/phase5h/functions/classes/class.IntegrationVmwareBatchPerformance.php' 'b35127f8e28e503f558a08327b73cf9c6d41002a'
check_blob 'layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php' 'b970742f2d8d33a88c1754ed2e78201b139f747f'
check_blob 'layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php' '380de222f7d437406ea8aa14807b09bbafa581b1'
check_blob 'layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php' 'bde978b7d8e2085e9e16b7a7f00b1e7a46096a27'
check_blob 'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php' '2710ff0f1ebbc463dae02f47543011ef6a9347fe'
check_blob 'layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php' 'd07a9362923102d0494fd4b23a92ed0284d8dfb7'

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

acquire_production_lock() {
    local cycle="$1" start now elapsed last_report=-1
    exec 9<>"$LOCK"
    start="$(date +%s)"
    printf 'PHASE5H5_CYCLE_%s_LOCK_WAIT=1\n' "$cycle"
    while ! flock -x -n 9; do
        verify_timer
        now="$(date +%s)"
        elapsed=$((now-start))
        if [ "$elapsed" -ge "$LOCK_WAIT_LIMIT" ]; then
            printf 'PHASE5H5_CYCLE_%s_LOCK_TIMEOUT_SECONDS=%s\n' "$cycle" "$elapsed"
            exec 9>&-
            return 1
        fi
        if [ "$elapsed" -eq 0 ] || [ $((elapsed/10)) -ne "$last_report" ]; then
            printf 'PHASE5H5_CYCLE_%s_LOCK_WAIT_SECONDS=%s\n' "$cycle" "$elapsed"
            last_report=$((elapsed/10))
        fi
        sleep 5
    done
    LOCK_HELD=1
    now="$(date +%s)"
    LOCK_WAIT=$((now-start))
    printf 'PHASE5H5_CYCLE_%s_LOCK_ACQUIRED=1\nPHASE5H5_CYCLE_%s_LOCK_WAIT_SECONDS=%s\n' "$cycle" "$cycle" "$LOCK_WAIT"
}

verify_timer

echo 'PHASE5H5_RECURRING_PREFLIGHT_OK=1'
echo 'PHASE5H5_RECURRING_NO_VIEWER=1'
echo 'PHASE5H5_RECURRING_WEBSITE_SERVING_CHANGED=0'
echo 'PHASE5H5_RECURRING_SYSTEMD_CHANGED=0'

SUMMARY="$(mktemp /var/tmp/phpipam-phase5h5-recurring-summary.XXXXXXXX.jsonl)"
chmod 0600 "$SUMMARY"

for cycle in $(seq 1 "$CYCLES"); do
    verify_timer
    CYCLE_START="$(date +%s)"
    RUN_LOG="$TMP/run-$cycle.log"
    INSPECT_LOG="$TMP/inspect-$cycle.log"
    LOCK_WAIT=0

    acquire_production_lock "$cycle" || { echo "STOP: cycle $cycle timed out waiting for production worker lock"; exit 1; }

    set +e
    timeout --signal=TERM --kill-after=5s 150s runuser -u www-data -- php -d memory_limit=512M "$RUNNER" >"$RUN_LOG" 2>&1
    RUN_RC=$?
    set -e

    flock -u 9
    exec 9>&-
    LOCK_HELD=0

    if [ "$RUN_RC" -ne 0 ]; then
        printf 'PHASE5H5_CYCLE_%s_RUN_EXIT=%s\n' "$cycle" "$RUN_RC"
        grep -E '^PHASE5H_SHADOW_(ERROR|EXIT)=' "$RUN_LOG" || true
        exit "$RUN_RC"
    fi

    set +e
    runuser -u www-data -- php -d memory_limit=256M "$INSPECT" >"$INSPECT_LOG" 2>&1
    INSPECT_RC=$?
    set -e
    if [ "$INSPECT_RC" -ne 0 ]; then
        printf 'PHASE5H5_CYCLE_%s_INSPECT_EXIT=%s\n' "$cycle" "$INSPECT_RC"
        grep -E '^PHASE5H5_SHADOW_INSPECT_(ERROR|EXIT)=' "$INSPECT_LOG" || true
        exit "$INSPECT_RC"
    fi

    CYCLE="$cycle" LOCK_WAIT="$LOCK_WAIT" RUN_LOG="$RUN_LOG" INSPECT_LOG="$INSPECT_LOG" SUMMARY="$SUMMARY" \
    PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os
from pathlib import Path

def prefixed(path, prefix):
    rows=[line[len(prefix):] for line in Path(path).read_text().splitlines() if line.startswith(prefix)]
    if len(rows)!=1: raise SystemExit('acceptance_output_shape')
    value=json.loads(rows[0])
    if not isinstance(value,dict): raise SystemExit('acceptance_output_shape')
    return value

run=prefixed(os.environ['RUN_LOG'],'PHASE5H_SHADOW_RESULT=')
inspect=prefixed(os.environ['INSPECT_LOG'],'PHASE5H5_SHADOW_INSPECT=')
cycle=int(os.environ['CYCLE']); lock_wait=int(os.environ['LOCK_WAIT'])
if run.get('state')!='shadow_complete' or run.get('website_served_from_shadow') is not False or run.get('active_generation_unchanged') is not True:
    raise SystemExit('shadow_cycle_contract')
if run.get('property_publish_state')!='published' or run.get('metric_publish_state')!='published': raise SystemExit('shadow_publish_contract')
if inspect.get('website_served_from_shadow') is not False or inspect.get('shadow_read_only_inspection') is not True:
    raise SystemExit('shadow_inspect_contract')
p=inspect.get('properties'); m=inspect.get('metrics'); c=inspect.get('capabilities')
if not isinstance(p,dict) or p.get('present') is not True or not isinstance(m,dict) or m.get('present') is not True:
    raise SystemExit('shadow_domains_missing')
if p.get('published_at')!=run.get('published_at') or m.get('published_at')!=run.get('published_at'):
    raise SystemExit('shadow_publish_timestamp_mismatch')
metric_obs=m.get('fresh_series_observed_to_visible_seconds') or {}
metric_collect=m.get('fresh_series_collected_to_visible_seconds') or {}
prop_collect=p.get('fresh_field_collected_to_visible_seconds') or {}
record={
  'cycle':cycle,'lock_wait_seconds':lock_wait,'started_at':run.get('started_at'),'published_at':run.get('published_at'),
  'wall_ms':run.get('wall_ms'),'requests':run.get('requests'),'response_bytes':run.get('response_bytes'),
  'roster_counts':run.get('roster_counts'),'property_objects':run.get('property_objects'),'metric_plans':run.get('metric_plans'),
  'capability_revalidated':run.get('capability_revalidated'),'capability_still_due':run.get('capability_still_due'),
  'unsupported':run.get('unsupported'),'active_generation_unchanged':True,'website_served_from_shadow':False,
  'property_source_window_seconds':p.get('source_window_seconds'),
  'property_source_completed_to_visible_seconds':p.get('source_completed_to_visible_seconds'),
  'property_fresh_field_visible_max_seconds':prop_collect.get('max'),
  'property_objects_state':p.get('objects'),'metric_objects_state':m.get('objects'),'metric_series_state':m.get('series'),
  'metric_fresh_observed_count':metric_obs.get('count'),'metric_observed_to_visible_max_seconds':metric_obs.get('max'),
  'metric_observed_to_visible_avg_seconds':metric_obs.get('avg'),
  'metric_collected_to_visible_max_seconds':metric_collect.get('max'),
  'capability_entries':c.get('entries') if isinstance(c,dict) else None,
  'capability_valid_now':c.get('valid_now') if isinstance(c,dict) else None,
  'capability_expired_now':c.get('expired_now') if isinstance(c,dict) else None,
}
for key in ['published_at','wall_ms','requests','response_bytes','capability_still_due']:
    if not isinstance(record.get(key),int): raise SystemExit('shadow_summary_invalid')
with open(os.environ['SUMMARY'],'a',encoding='utf-8') as out: out.write(json.dumps(record,separators=(',',':'),sort_keys=True)+'\n')
print('PHASE5H5_RECURRING_CYCLE_'+str(cycle)+'='+json.dumps(record,separators=(',',':'),sort_keys=True))
PY

    verify_timer
    if [ "$cycle" -lt "$CYCLES" ]; then
        NEXT=$((CYCLE_START+INTERVAL))
        NOW="$(date +%s)"
        SLEEP=$((NEXT-NOW))
        if [ "$SLEEP" -gt 0 ]; then
            printf 'PHASE5H5_CYCLE_%s_NEXT_IN_SECONDS=%s\n' "$cycle" "$SLEEP"
            sleep "$SLEEP"
        fi
    fi
done

SUMMARY="$SUMMARY" PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os
from pathlib import Path
rows=[json.loads(line) for line in Path(os.environ['SUMMARY']).read_text().splitlines() if line.strip()]
if len(rows)!=3: raise SystemExit('recurring_cycle_count')
pubs=[r['published_at'] for r in rows]
intervals=[b-a for a,b in zip(pubs,pubs[1:])]
if any(v<=0 for v in intervals): raise SystemExit('recurring_publication_order')
metric_lags=[r['metric_observed_to_visible_max_seconds'] for r in rows if isinstance(r.get('metric_observed_to_visible_max_seconds'),int)]
property_lags=[r['property_fresh_field_visible_max_seconds'] for r in rows if isinstance(r.get('property_fresh_field_visible_max_seconds'),int)]
source_publish=[r['property_source_completed_to_visible_seconds'] for r in rows if isinstance(r.get('property_source_completed_to_visible_seconds'),int)]
due=[r['capability_still_due'] for r in rows]
accept={
 'cycles':len(rows),'publication_intervals_seconds':intervals,'publication_interval_max_seconds':max(intervals),
 'wall_ms_max':max(r['wall_ms'] for r in rows),'lock_wait_seconds_max':max(r['lock_wait_seconds'] for r in rows),
 'metric_observed_to_visible_max_seconds':max(metric_lags) if metric_lags else None,
 'property_collected_to_visible_max_seconds':max(property_lags) if property_lags else None,
 'property_source_completed_to_visible_max_seconds':max(source_publish) if source_publish else None,
 'capability_still_due_sequence':due,
 'capability_converged_or_bounded_residual': (due[-1] <= 1 and due[-1] <= due[0]),
 'all_active_generation_unchanged':all(r['active_generation_unchanged'] for r in rows),
 'website_served_from_shadow':False,
}
checks=[accept['publication_interval_max_seconds']<=300,
        isinstance(accept['metric_observed_to_visible_max_seconds'],int) and accept['metric_observed_to_visible_max_seconds']<=300,
        isinstance(accept['property_collected_to_visible_max_seconds'],int) and accept['property_collected_to_visible_max_seconds']<=60,
        isinstance(accept['property_source_completed_to_visible_max_seconds'],int) and accept['property_source_completed_to_visible_max_seconds']<=60,
        accept['capability_converged_or_bounded_residual'],accept['all_active_generation_unchanged']]
accept['target_60_300_acceptance']=all(checks)
print('PHASE5H5_RECURRING_ACCEPTANCE='+json.dumps(accept,separators=(',',':'),sort_keys=True))
if not accept['target_60_300_acceptance']: raise SystemExit(2)
PY

verify_timer
systemctl show "$TIMER" --no-pager -p ActiveState -p SubState -p UnitFileState
echo 'PHASE5H5_RECURRING_SHADOW_ACCEPT_OK=1'
echo 'PHASE5H5_RECURRING_ACTIVE_DATA_PATH_NOT_WRITTEN_BY_ACCEPTANCE=1'
echo 'PHASE5H5_RECURRING_WEBSITE_CHANGED=0'
echo 'PHASE5H5_RECURRING_SYSTEMD_CHANGED=0'