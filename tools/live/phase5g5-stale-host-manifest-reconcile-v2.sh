#!/usr/bin/env bash
set -euo pipefail

REPO='/tmp/phpipam-custom-phase0'
BRANCH='phase5g5/stale-host-membership-recovery'
PATCH_SOURCE='b92ff9cf4fad29e8191d551577550a89a32cd48c'
TARGET_REL='layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php'
RELEASE='/opt/phpipam-custom/inventory-recovery5g5'
TARGET="$RELEASE/$TARGET_REL"
MANIFEST="$RELEASE/release.json"
RECEIPT='/var/backups/phpipam-custom/vmware-inventory-20260914-144127-w63cp9jv/receipt.json'
HOTFIX_BACKUP='/var/backups/phpipam-custom/vmware-stale-host-membership-20260915-124448'
OLD_SOURCE_BACKUP="$HOTFIX_BACKUP/class.IntegrationVmwareInventorySource.php"
SERVICE='phpipam-vmware-recovery5g5@1.service'
TIMER='phpipam-vmware-recovery5g5@1.timer'
OLD_GIT_BLOB='b85c8d63e13f6ba9f52a8c4e298407fbbf48eb1d'
NEW_GIT_BLOB='a8572c6bfed709c2764dc64ec62d85cb303e3e70'

cleanup() {
    rc=$?
    trap - EXIT
    printf '\nPHASE5G5_STALE_HOST_METADATA_V2_BLOCK_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

test "$(id -u)" -eq 0
cd -- "$REPO"
git fetch --no-tags origin "$BRANCH"

test -f "$TARGET" && test ! -L "$TARGET"
test -f "$MANIFEST" && test ! -L "$MANIFEST"
test -f "$RECEIPT" && test ! -L "$RECEIPT"
test -f "$OLD_SOURCE_BACKUP" && test ! -L "$OLD_SOURCE_BACKUP"
test "$(git hash-object "$TARGET")" = "$NEW_GIT_BLOB"
test "$(git hash-object "$OLD_SOURCE_BACKUP")" = "$OLD_GIT_BLOB"
test "$(stat -c '%u:%g:%a:%h' "$MANIFEST")" = '0:0:444:1'
test "$(stat -c '%u:%g:%a:%h' "$RECEIPT")" = '0:0:600:1'
test "$(stat -c '%u:%g:%a' "$HOTFIX_BACKUP")" = '0:0:700'

echo 'PHASE5G5_STALE_HOST_METADATA_V2_INPUTS_OK=1'

test "$(systemctl show "$TIMER" -p ActiveState --value)" = 'active'
test "$(systemctl show "$TIMER" -p UnitFileState --value)" = 'enabled'

# Type=oneshot can remain ActiveState=failed after a guard failure even though no
# process exists. failed+MainPID=0 is quiescent just like inactive+MainPID=0.
# If a timer start races after this check, release.json is replaced atomically:
# that start sees either the old manifest and fails closed before PHP collection,
# or the new complete manifest and proceeds normally.
WAIT_START="$(date +%s)"
ANNOUNCED=0
while :; do
    ACTIVE="$(systemctl show "$SERVICE" -p ActiveState --value)"
    SUB="$(systemctl show "$SERVICE" -p SubState --value)"
    PID="$(systemctl show "$SERVICE" -p MainPID --value)"
    test "$(systemctl show "$TIMER" -p ActiveState --value)" = 'active'
    test "$(systemctl show "$TIMER" -p UnitFileState --value)" = 'enabled'
    if { [ "$ACTIVE" = 'inactive' ] || [ "$ACTIVE" = 'failed' ]; } && [ "$PID" = '0' ]; then
        printf 'PHASE5G5_STALE_HOST_METADATA_V2_QUIESCENT=%s/%s pid=%s\n' "$ACTIVE" "$SUB" "$PID"
        break
    fi
    if [ "$ANNOUNCED" -eq 0 ]; then
        printf 'PHASE5G5_STALE_HOST_METADATA_V2_WAIT=1 state=%s/%s pid=%s\n' "$ACTIVE" "$SUB" "$PID"
        ANNOUNCED=1
    fi
    NOW="$(date +%s)"
    if [ $((NOW - WAIT_START)) -ge 180 ]; then
        printf 'PHASE5G5_STALE_HOST_METADATA_V2_TIMEOUT=%s/%s pid=%s\n' "$ACTIVE" "$SUB" "$PID"
        exit 1
    fi
    sleep 1
done
printf 'PHASE5G5_STALE_HOST_METADATA_V2_WAIT_SECONDS=%s\n' "$(( $(date +%s) - WAIT_START ))"

# Back up pre-reconciliation metadata exactly once.
if [ ! -e "$HOTFIX_BACKUP/release.json.before-metadata-reconcile" ]; then
    cp --preserve=mode,ownership,timestamps -- "$MANIFEST" "$HOTFIX_BACKUP/release.json.before-metadata-reconcile"
    cp --preserve=mode,ownership,timestamps -- "$RECEIPT" "$HOTFIX_BACKUP/receipt.json.before-metadata-reconcile"
    chmod 0600 "$HOTFIX_BACKUP/release.json.before-metadata-reconcile" "$HOTFIX_BACKUP/receipt.json.before-metadata-reconcile"
    chown 0:0 "$HOTFIX_BACKUP/release.json.before-metadata-reconcile" "$HOTFIX_BACKUP/receipt.json.before-metadata-reconcile"
fi

TARGET_SHA256="$(sha256sum "$TARGET" | awk '{print $1}')"
OLD_SHA256="$(sha256sum "$OLD_SOURCE_BACKUP" | awk '{print $1}')"

TARGET_REL="$TARGET_REL" OLD_SHA256="$OLD_SHA256" NEW_SHA256="$TARGET_SHA256" \
MANIFEST="$MANIFEST" RECEIPT="$RECEIPT" PATCH_SOURCE="$PATCH_SOURCE" HOTFIX_BACKUP="$HOTFIX_BACKUP" \
PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import hashlib, json, os, tempfile
from pathlib import Path

rel=os.environ['TARGET_REL']; old=os.environ['OLD_SHA256']; new=os.environ['NEW_SHA256']
manifest_path=Path(os.environ['MANIFEST']); receipt_path=Path(os.environ['RECEIPT'])
patch=os.environ['PATCH_SOURCE']; backup=os.environ['HOTFIX_BACKUP']

def load(path):
    value=json.loads(path.read_text())
    if not isinstance(value,dict): raise SystemExit('metadata_shape')
    return value

def encoded(value): return (json.dumps(value,sort_keys=True,ensure_ascii=False)+'\n').encode()
def digest(raw): return hashlib.sha256(raw).hexdigest()
def atomic(path,raw,mode):
    fd,name=tempfile.mkstemp(prefix='.stale-host-metadata-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(raw); out.flush(); os.fchmod(out.fileno(),mode); os.fchown(out.fileno(),0,0); os.fsync(out.fileno())
        os.replace(name,path)
        dfd=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
        try: os.fsync(dfd)
        finally: os.close(dfd)
    finally:
        if os.path.exists(name): os.unlink(name)

manifest=load(manifest_path); receipt=load(receipt_path)
if manifest.get('schema') != 1 or receipt.get('schema') != 1: raise SystemExit('metadata_schema')
files=manifest.get('files'); release_files=receipt.get('release_files')
if not isinstance(files,dict) or not isinstance(release_files,dict): raise SystemExit('metadata_shape')
manifest_hash=digest(manifest_path.read_bytes())

if files.get(rel) == new:
    if release_files.get(rel) != new or release_files.get('release.json') != manifest_hash:
        raise SystemExit('metadata_partial_reconcile')
    print('PHASE5G5_STALE_HOST_METADATA_V2_ALREADY_RECONCILED=1')
else:
    if files.get(rel) != old or release_files.get(rel) != old or release_files.get('release.json') != manifest_hash:
        raise SystemExit('metadata_baseline_mismatch')
    manifest['files'][rel]=new
    hotfixes=manifest.setdefault('runtime_hotfixes',[])
    if not isinstance(hotfixes,list): raise SystemExit('metadata_shape')
    hotfixes.append({'id':'stale_host_membership','source':patch})
    new_manifest=encoded(manifest); new_manifest_hash=digest(new_manifest)
    receipt['release_files'][rel]=new
    receipt['release_files']['release.json']=new_manifest_hash
    rh=receipt.setdefault('runtime_hotfixes',[])
    if not isinstance(rh,list): raise SystemExit('metadata_shape')
    rh.append({'id':'stale_host_membership','source':patch,'backup':backup})
    atomic(manifest_path,new_manifest,0o444)
    atomic(receipt_path,encoded(receipt),0o600)
    print('PHASE5G5_STALE_HOST_METADATA_V2_RECONCILE_OK=1')

m2=load(manifest_path); r2=load(receipt_path)
if m2.get('files',{}).get(rel) != new: raise SystemExit('metadata_verify_failed')
if r2.get('release_files',{}).get(rel) != new: raise SystemExit('metadata_verify_failed')
if r2.get('release_files',{}).get('release.json') != digest(manifest_path.read_bytes()): raise SystemExit('metadata_verify_failed')
PY

test "$(git hash-object "$TARGET")" = "$NEW_GIT_BLOB"
test "$(stat -c '%u:%g:%a:%h' "$MANIFEST")" = '0:0:444:1'
test "$(stat -c '%u:%g:%a:%h' "$RECEIPT")" = '0:0:600:1'

STATUS_LOG="$(mktemp /var/tmp/phpipam-phase5g5-stale-host-status.XXXXXXXX.log)"
chmod 0600 "$STATUS_LOG"
set +e
runuser -u www-data -- /usr/bin/python3 "$RELEASE/tools/phase5g4-worker.py" --status --connector=1 >"$STATUS_LOG" 2>&1
STATUS_RC=$?
set -e
printf 'PHASE5G5_STALE_HOST_METADATA_V2_GUARD_STATUS_EXIT=%s\n' "$STATUS_RC"
if [ "$STATUS_RC" -ne 0 ] && [ "$STATUS_RC" -ne 2 ]; then
    echo "PHASE5G5_STALE_HOST_METADATA_V2_GUARD_STATUS_LOG=$STATUS_LOG"
    exit "$STATUS_RC"
fi
STATUS_LOG="$STATUS_LOG" STATUS_RC="$STATUS_RC" PYTHONDONTWRITEBYTECODE=1 python3 -B - <<'PY'
import json, os
from pathlib import Path
path=Path(os.environ['STATUS_LOG']); rc=int(os.environ['STATUS_RC'])
try:
    value=json.loads(path.read_text())
except Exception:
    raise SystemExit('status_json_invalid')
if not isinstance(value,dict): raise SystemExit('status_json_invalid')
state=value.get('state')
if rc == 2 and state != 'error': raise SystemExit('status_exit_state_mismatch')
if rc == 0 and state == 'error': raise SystemExit('status_exit_state_mismatch')
print('PHASE5G5_STALE_HOST_METADATA_V2_GUARD_ACCEPTED=1')
print('PHASE5G5_STALE_HOST_METADATA_V2_STATUS_STATE=' + str(state))
PY
rm -f -- "$STATUS_LOG"

printf 'PHASE5G5_STALE_HOST_METADATA_V2_SERVICE_AFTER=%s/%s pid=%s\n' \
    "$(systemctl show "$SERVICE" -p ActiveState --value)" \
    "$(systemctl show "$SERVICE" -p SubState --value)" \
    "$(systemctl show "$SERVICE" -p MainPID --value)"
test "$(systemctl show "$TIMER" -p ActiveState --value)" = 'active'
test "$(systemctl show "$TIMER" -p UnitFileState --value)" = 'enabled'
echo 'PHASE5G5_STALE_HOST_METADATA_V2_DATA_CHANGED=0'
echo 'PHASE5G5_STALE_HOST_METADATA_V2_SYSTEMD_CONFIG_CHANGED=0'
echo 'PHASE5G5_STALE_HOST_METADATA_V2_WEB_CHANGED=0'
echo "PHASE5G5_STALE_HOST_METADATA_V2_BACKUP=$HOTFIX_BACKUP"
