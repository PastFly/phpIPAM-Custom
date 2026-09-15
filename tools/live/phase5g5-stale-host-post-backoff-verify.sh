#!/usr/bin/env bash
set -euo pipefail

RELEASE='/opt/phpipam-custom/inventory-recovery5g5'
TARGET="$RELEASE/layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php"
SERVICE='phpipam-vmware-recovery5g5@1.service'
TIMER='phpipam-vmware-recovery5g5@1.timer'
NEW_GIT_BLOB='a8572c6bfed709c2764dc64ec62d85cb303e3e70'
BASELINE_COMPLETED=686
BASELINE_TOTAL=779
BASELINE_PUBLISHED=1789463467
MAX_WAIT=240
STATUS_LOG=''

cleanup() {
    rc=$?
    trap - EXIT
    if [ -n "${STATUS_LOG:-}" ] && [ -e "$STATUS_LOG" ]; then rm -f -- "$STATUS_LOG"; fi
    printf '\nPHASE5G5_POSTBACKOFF_BLOCK_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

test "$(id -u)" -eq 0
test -f "$TARGET" && test ! -L "$TARGET"
test "$(git hash-object "$TARGET")" = "$NEW_GIT_BLOB"
test "$(systemctl show "$TIMER" -p ActiveState --value)" = 'active'
test "$(systemctl show "$TIMER" -p UnitFileState --value)" = 'enabled'
echo 'PHASE5G5_POSTBACKOFF_INPUTS_OK=1'

START="$(date +%s)"
ITER=0
while :; do
    ITER=$((ITER + 1))
    STATUS_LOG="$(mktemp /var/tmp/phpipam-phase5g5-postbackoff.XXXXXXXX.log)"
    chmod 0600 "$STATUS_LOG"

    set +e
    runuser -u www-data -- /usr/bin/python3 "$RELEASE/tools/phase5g4-worker.py" --status --connector=1 >"$STATUS_LOG" 2>&1
    STATUS_RC=$?
    set -e

    if [ "$STATUS_RC" -ne 0 ] && [ "$STATUS_RC" -ne 2 ]; then
        printf 'PHASE5G5_POSTBACKOFF_GUARD_FAILURE_EXIT=%s\n' "$STATUS_RC"
        echo "PHASE5G5_POSTBACKOFF_GUARD_FAILURE_LOG=$STATUS_LOG"
        STATUS_LOG=''
        exit "$STATUS_RC"
    fi

    set +e
    SAFE_JSON="$(STATUS_LOG="$STATUS_LOG" STATUS_RC="$STATUS_RC" BASELINE_COMPLETED="$BASELINE_COMPLETED" BASELINE_TOTAL="$BASELINE_TOTAL" BASELINE_PUBLISHED="$BASELINE_PUBLISHED" PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os, time
from pathlib import Path
p=Path(os.environ['STATUS_LOG']); rc=int(os.environ['STATUS_RC'])
base_completed=int(os.environ['BASELINE_COMPLETED']); base_total=int(os.environ['BASELINE_TOTAL']); base_published=int(os.environ['BASELINE_PUBLISHED'])
try: v=json.loads(p.read_text())
except Exception: raise SystemExit(30)
if not isinstance(v,dict): raise SystemExit(30)
state=v.get('state'); last=v.get('last_error')
if rc == 2 and state != 'error': raise SystemExit(31)
if rc == 0 and state == 'error': raise SystemExit(31)
progress=v.get('progress') if isinstance(v.get('progress'),dict) else {}
completed=progress.get('completed') if isinstance(progress.get('completed'),int) else None
total=progress.get('total') if isinstance(progress.get('total'),int) else None
published=v.get('published_at') if isinstance(v.get('published_at'),int) else None
retry=v.get('retry_at') if isinstance(v.get('retry_at'),int) else None
safe={
 'state':state,
 'last_error':last if isinstance(last,str) or last is None else 'invalid',
 'completed':completed,
 'total':total,
 'published_at':published,
 'retry_in_seconds':max(0,retry-int(time.time())) if retry is not None else None,
 'heartbeat_at':v.get('heartbeat_at') if isinstance(v.get('heartbeat_at'),int) else None,
 'worker_silent':bool(v.get('worker_silent')),
 'stale':bool(v.get('stale')),
}
print(json.dumps(safe,separators=(',',':'),sort_keys=True))
# Full recovery/new publication.
if state == 'ready' and last is None and published is not None and published > base_published: raise SystemExit(10)
# Permanent stale-MOR path was exercised and the pending generation was discarded.
if last == 'membership_changed': raise SystemExit(11)
# A new generation has clearly started: old progress 686/779 cannot move backwards in-place.
if state == 'collecting' and completed is not None and total is not None and total <= base_total and completed < base_completed: raise SystemExit(12)
# Unexpected recurrence after the patch.
if state == 'error' and last == 'collection_failed': raise SystemExit(13)
raise SystemExit(0)
PY
)"
    CLASS_RC=$?
    set -e

    rm -f -- "$STATUS_LOG"
    STATUS_LOG=''

    if [ "$CLASS_RC" -ge 30 ]; then
        printf 'PHASE5G5_POSTBACKOFF_STATUS_PARSE_ERROR=%s\n' "$CLASS_RC"
        exit 1
    fi

    printf 'PHASE5G5_POSTBACKOFF_STATUS_%s=%s\n' "$ITER" "$SAFE_JSON"
    printf 'PHASE5G5_POSTBACKOFF_SERVICE_%s=%s/%s pid=%s\n' "$ITER" \
        "$(systemctl show "$SERVICE" -p ActiveState --value)" \
        "$(systemctl show "$SERVICE" -p SubState --value)" \
        "$(systemctl show "$SERVICE" -p MainPID --value)"

    case "$CLASS_RC" in
        10)
            echo 'PHASE5G5_POSTBACKOFF_RECOVERED_READY=1'
            exit 0
            ;;
        11)
            echo 'PHASE5G5_POSTBACKOFF_MEMBERSHIP_DRIFT_HANDLED=1'
            exit 0
            ;;
        12)
            echo 'PHASE5G5_POSTBACKOFF_NEW_GENERATION_STARTED=1'
            exit 0
            ;;
        13)
            echo 'PHASE5G5_POSTBACKOFF_COLLECTION_FAILED_RECURRED=1'
            exit 2
            ;;
    esac

    NOW="$(date +%s)"
    if [ $((NOW - START)) -ge "$MAX_WAIT" ]; then
        echo 'PHASE5G5_POSTBACKOFF_OBSERVATION_TIMEOUT=1'
        exit 0
    fi
    sleep 5
done
