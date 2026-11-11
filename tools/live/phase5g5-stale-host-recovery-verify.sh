#!/usr/bin/env bash
set -euo pipefail

RELEASE='/opt/phpipam-custom/inventory-recovery5g5'
TARGET="$RELEASE/layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php"
SERVICE='phpipam-vmware-recovery5g5@1.service'
TIMER='phpipam-vmware-recovery5g5@1.timer'
NEW_GIT_BLOB='a8572c6bfed709c2764dc64ec62d85cb303e3e70'
MAX_WAIT=120
STATUS_LOG=''

cleanup() {
    rc=$?
    trap - EXIT
    if [ -n "${STATUS_LOG:-}" ] && [ -e "$STATUS_LOG" ]; then rm -f -- "$STATUS_LOG"; fi
    printf '\nPHASE5G5_STALE_HOST_VERIFY_BLOCK_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

test "$(id -u)" -eq 0
test -f "$TARGET" && test ! -L "$TARGET"
test "$(git hash-object "$TARGET")" = "$NEW_GIT_BLOB"
test "$(systemctl show "$TIMER" -p ActiveState --value)" = 'active'
test "$(systemctl show "$TIMER" -p UnitFileState --value)" = 'enabled'
echo 'PHASE5G5_STALE_HOST_VERIFY_INPUTS_OK=1'

START="$(date +%s)"
ITERATION=0
while :; do
    ITERATION=$((ITERATION + 1))
    STATUS_LOG="$(mktemp /var/tmp/phpipam-phase5g5-stale-host-verify.XXXXXXXX.log)"
    chmod 0600 "$STATUS_LOG"

    set +e
    runuser -u www-data -- /usr/bin/python3 "$RELEASE/tools/phase5g4-worker.py" --status --connector=1 >"$STATUS_LOG" 2>&1
    STATUS_RC=$?
    set -e

    if [ "$STATUS_RC" -ne 0 ] && [ "$STATUS_RC" -ne 2 ]; then
        printf 'PHASE5G5_STALE_HOST_VERIFY_GUARD_FAILURE_EXIT=%s\n' "$STATUS_RC"
        echo "PHASE5G5_STALE_HOST_VERIFY_GUARD_FAILURE_LOG=$STATUS_LOG"
        STATUS_LOG=''
        exit "$STATUS_RC"
    fi

    set +e
    SAFE_JSON="$(STATUS_LOG="$STATUS_LOG" STATUS_RC="$STATUS_RC" PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os, time
from pathlib import Path
path=Path(os.environ['STATUS_LOG']); rc=int(os.environ['STATUS_RC'])
try:
    value=json.loads(path.read_text())
except Exception:
    raise SystemExit(30)
if not isinstance(value,dict): raise SystemExit(30)
state=value.get('state')
last=value.get('last_error')
if rc == 2 and state != 'error': raise SystemExit(31)
if rc == 0 and state == 'error': raise SystemExit(31)
progress=value.get('progress') if isinstance(value.get('progress'),dict) else {}
retry=value.get('retry_at') if isinstance(value.get('retry_at'),int) else None
published=value.get('published_at') if isinstance(value.get('published_at'),int) else None
heartbeat=value.get('heartbeat_at') if isinstance(value.get('heartbeat_at'),int) else None
safe={
    'state': state,
    'last_error': last if isinstance(last,str) or last is None else 'invalid',
    'completed': progress.get('completed') if isinstance(progress.get('completed'),int) else None,
    'total': progress.get('total') if isinstance(progress.get('total'),int) else None,
    'published_at': published,
    'retry_in_seconds': max(0,retry-int(time.time())) if retry is not None else None,
    'heartbeat_at': heartbeat,
    'worker_silent': bool(value.get('worker_silent')),
    'stale': bool(value.get('stale')),
}
print(json.dumps(safe,separators=(',',':'),sort_keys=True))
if state == 'ready' and last is None: raise SystemExit(10)
if last == 'membership_changed': raise SystemExit(11)
if safe['retry_in_seconds'] is not None and safe['retry_in_seconds'] > 120: raise SystemExit(12)
raise SystemExit(0)
PY
)"
    CLASS_RC=$?
    set -e

    rm -f -- "$STATUS_LOG"
    STATUS_LOG=''

    if [ "$CLASS_RC" -ge 30 ]; then
        printf 'PHASE5G5_STALE_HOST_VERIFY_STATUS_PARSE_ERROR=%s\n' "$CLASS_RC"
        exit 1
    fi

    printf 'PHASE5G5_STALE_HOST_VERIFY_STATUS_%s=%s\n' "$ITERATION" "$SAFE_JSON"
    printf 'PHASE5G5_STALE_HOST_VERIFY_SERVICE_%s=%s/%s pid=%s\n' "$ITERATION" \
        "$(systemctl show "$SERVICE" -p ActiveState --value)" \
        "$(systemctl show "$SERVICE" -p SubState --value)" \
        "$(systemctl show "$SERVICE" -p MainPID --value)"

    case "$CLASS_RC" in
        10)
            echo 'PHASE5G5_STALE_HOST_VERIFY_RECOVERED_READY=1'
            exit 0
            ;;
        11)
            echo 'PHASE5G5_STALE_HOST_VERIFY_MEMBERSHIP_DRIFT_HANDLED=1'
            echo 'PHASE5G5_STALE_HOST_VERIFY_OLD_PENDING_DISCARDED_BY_WORKER_CONTRACT=1'
            exit 0
            ;;
        12)
            echo 'PHASE5G5_STALE_HOST_VERIFY_RECOVERY_PENDING_BACKOFF=1'
            exit 0
            ;;
    esac

    NOW="$(date +%s)"
    if [ $((NOW - START)) -ge "$MAX_WAIT" ]; then
        echo 'PHASE5G5_STALE_HOST_VERIFY_OBSERVATION_TIMEOUT=1'
        exit 0
    fi
    sleep 5
done
