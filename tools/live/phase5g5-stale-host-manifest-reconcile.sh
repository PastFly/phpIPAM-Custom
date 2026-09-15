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

echo 'PHASE5G5_STALE_HOST_METADATA_INPUTS_OK=1'

TIMER_ACTIVE="$(systemctl show "$TIMER" -p ActiveState --value)"
TIMER_ENABLED="$(systemctl show "$TIMER" -p UnitFileState --value)"
test "$TIMER_ACTIVE" = 'active'
test "$TIMER_ENABLED" = 'enabled'

# A currently running guard may still fail against the old manifest. Wait only for
# a natural idle window; never stop or disable production.
WAIT_START="$(date +%s)"
ANNOUNCED=0
while [ "$(systemctl show "$SERVICE" -p ActiveState --value)" != 'inactive' ]; do
    if [ "$ANNOUNCED" -eq 0 ]; then
        echo 'PHASE5G5_STALE_HOST_METADATA_WAIT=1'
        ANNOUNCED=1
    fi
    test "$(systemctl show "$TIMER" -p ActiveState --value)" = 'active'
    test "$(systemctl show "$TIMER" -p UnitFileState --value)" = 'enabled'
    NOW="$(date +%s)"
    if [ $((NOW - WAIT_START)) -ge 900 ]; then
        echo 'PHASE5G5_STALE_HOST_METADATA_TIMEOUT=5G5_BUSY'
        exit 1
    fi
    sleep 2
done
printf 'PHASE5G5_STALE_HOST_METADATA_IDLE_WINDOW=1\nPHASE5G5_STALE_HOST_METADATA_WAIT_SECONDS=%s\n' "$(( $(date +%s) - WAIT_START ))"

# Back up the pre-reconciliation metadata exactly once into the existing hotfix backup.
if [ ! -e "$HOTFIX_BACKUP/release.json.before-metadata-reconcile" ]; then
    cp --preserve=mode,ownership,timestamps -- "$MANIFEST" "$HOTFIX_BACKUP/release.json.before-metadata-reconcile"
    cp --preserve=mode,ownership,timestamps -- "$RECEIPT" "$HOTFIX_BACKUP/receipt.json.before-metadata-reconcile"
    chmod 0600 "$HOTFIX_BACKUP/release.json.before-metadata-reconcile" "$HOTFIX_BACKUP/receipt.json.before-metadata-reconcile"
    chown 0:0 "$HOTFIX_BACKUP/release.json.before-metadata-reconcile" "$HOTFIX_BACKUP/receipt.json.before-metadata-reconcile"
fi

TARGET_SHA256="$(sha256sum "$TARGET" | awk '{print $1}')"
OLD_SHA256="$(sha256sum "$OLD_SOURCE_BACKUP" | awk '{print $1}')"
CURRENT_MANIFEST_SHA256="$(sha256sum "$MANIFEST" | awk '{print $1}')"

TARGET_REL="$TARGET_REL" OLD_SHA256="$OLD_SHA256" NEW_SHA256="$TARGET_SHA256" \
MANIFEST="$MANIFEST" RECEIPT="$RECEIPT" PATCH_SOURCE="$PATCH_SOURCE" HOTFIX_BACKUP="$HOTFIX_BACKUP" \
python3 -B - <<'PY'
import hashlib, json, os, tempfile
from pathlib import Path

rel=os.environ['TARGET_REL']
old=os.environ['OLD_SHA256']
new=os.environ['NEW_SHA256']
manifest_path=Path(os.environ['MANIFEST'])
receipt_path=Path(os.environ['RECEIPT'])
patch=os.environ['PATCH_SOURCE']
backup=os.environ['HOTFIX_BACKUP']

def load(path):
    value=json.loads(path.read_text())
    if not isinstance(value, dict): raise SystemExit('metadata_shape')
    return value

def encoded(value):
    return (json.dumps(value, sort_keys=True, ensure_ascii=False) + '\n').encode()

def digest(raw): return hashlib.sha256(raw).hexdigest()

def atomic(path, raw, mode):
    fd,name=tempfile.mkstemp(prefix='.stale-host-metadata-', dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(raw); out.flush(); os.fchmod(out.fileno(), mode); os.fchown(out.fileno(),0,0); os.fsync(out.fileno())
        os.replace(name,path)
        dfd=os.open(path.parent, os.O_RDONLY|os.O_DIRECTORY)
        try: os.fsync(dfd)
        finally: os.close(dfd)
    finally:
        if os.path.exists(name): os.unlink(name)

manifest=load(manifest_path)
receipt=load(receipt_path)
if manifest.get('schema') != 1 or receipt.get('schema') != 1: raise SystemExit('metadata_schema')
files=manifest.get('files'); release_files=receipt.get('release_files')
if not isinstance(files,dict) or not isinstance(release_files,dict): raise SystemExit('metadata_shape')
manifest_raw=manifest_path.read_bytes()
manifest_hash=digest(manifest_raw)

# Idempotent path: accept already-reconciled metadata only when all hashes agree.
if files.get(rel) == new:
    if release_files.get(rel) != new or release_files.get('release.json') != manifest_hash:
        raise SystemExit('metadata_partial_reconcile')
    print('PHASE5G5_STALE_HOST_METADATA_ALREADY_RECONCILED=1')
    raise SystemExit(0)

if files.get(rel) != old or release_files.get(rel) != old or release_files.get('release.json') != manifest_hash:
    raise SystemExit('metadata_baseline_mismatch')

manifest['files'][rel]=new
hotfixes=manifest.setdefault('runtime_hotfixes',[])
if not isinstance(hotfixes,list): raise SystemExit('metadata_shape')
hotfixes.append({'id':'stale_host_membership','source':patch})
new_manifest=encoded(manifest)
new_manifest_hash=digest(new_manifest)

receipt['release_files'][rel]=new
receipt['release_files']['release.json']=new_manifest_hash
rh=receipt.setdefault('runtime_hotfixes',[])
if not isinstance(rh,list): raise SystemExit('metadata_shape')
rh.append({'id':'stale_host_membership','source':patch,'backup':backup})
new_receipt=encoded(receipt)

# Worker guard only consumes release.json; replace it first. Receipt follows for
# receipt-aware verification/recovery history.
atomic(manifest_path,new_manifest,0o444)
atomic(receipt_path,new_receipt,0o600)

# Re-read exact persisted bytes.
m2=load(manifest_path); r2=load(receipt_path)
if m2.get('files',{}).get(rel) != new: raise SystemExit('metadata_verify_failed')
if r2.get('release_files',{}).get(rel) != new: raise SystemExit('metadata_verify_failed')
if r2.get('release_files',{}).get('release.json') != digest(manifest_path.read_bytes()): raise SystemExit('metadata_verify_failed')
print('PHASE5G5_STALE_HOST_METADATA_RECONCILE_OK=1')
PY

test "$(git hash-object "$TARGET")" = "$NEW_GIT_BLOB"
test "$(stat -c '%u:%g:%a:%h' "$MANIFEST")" = '0:0:444:1'
test "$(stat -c '%u:%g:%a:%h' "$RECEIPT")" = '0:0:600:1'

# Prove the immutable release guard now accepts the hotfixed release. --status is
# local-only and does not start a collection.
STATUS_LOG="$(mktemp /var/tmp/phpipam-phase5g5-stale-host-status.XXXXXXXX.log)"
chmod 0600 "$STATUS_LOG"
set +e
runuser -u www-data -- /usr/bin/python3 "$RELEASE/tools/phase5g4-worker.py" --status --connector=1 >"$STATUS_LOG" 2>&1
STATUS_RC=$?
set -e
printf 'PHASE5G5_STALE_HOST_WORKER_GUARD_STATUS_EXIT=%s\n' "$STATUS_RC"
if [ "$STATUS_RC" -ne 0 ]; then
    echo "PHASE5G5_STALE_HOST_WORKER_GUARD_STATUS_LOG=$STATUS_LOG"
    exit "$STATUS_RC"
fi
rm -f -- "$STATUS_LOG"

echo 'PHASE5G5_STALE_HOST_METADATA_DATA_CHANGED=0'
echo 'PHASE5G5_STALE_HOST_METADATA_SYSTEMD_CHANGED=0'
echo 'PHASE5G5_STALE_HOST_METADATA_WEB_CHANGED=0'
echo "PHASE5G5_STALE_HOST_METADATA_BACKUP=$HOTFIX_BACKUP"
echo 'PHASE5G5_STALE_HOST_METADATA_BLOCK_EXIT=0'
