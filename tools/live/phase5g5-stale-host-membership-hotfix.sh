#!/usr/bin/env bash
set -euo pipefail

REPO='/tmp/phpipam-custom-phase0'
PROTECTED='f7aedad68781bccfcac1985a1b240c3c515715ef'
BASE='86751be5cde6cdd5e350d9ea50d1feaa2d3ef3ab'
PATCH='b92ff9cf4fad29e8191d551577550a89a32cd48c'
BRANCH='phase5g5/stale-host-membership-recovery'
TARGET='/opt/phpipam-custom/inventory-recovery5g5/layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php'
SERVICE='phpipam-vmware-recovery5g5@1.service'
TIMER='phpipam-vmware-recovery5g5@1.timer'
WORK=''
TMP_TARGET=''

cleanup() {
    rc=$?
    trap - EXIT
    if [ -n "${TMP_TARGET:-}" ] && [ -e "$TMP_TARGET" ]; then rm -f -- "$TMP_TARGET"; fi
    if [ -n "${WORK:-}" ] && [ -d "$WORK" ]; then
        for tree in patch base; do
            if [ -d "$WORK/$tree" ]; then git -C "$REPO" worktree remove --force "$WORK/$tree" >/dev/null 2>&1 || true; fi
        done
        rm -rf -- "$WORK"
    fi
    printf '\nPHASE5G5_STALE_HOST_PATCH_BLOCK_EXIT=%s\n' "$rc"
    exit "$rc"
}
trap cleanup EXIT

test "$(id -u)" -eq 0
cd -- "$REPO"
test "$(git rev-parse --show-toplevel)" = "$REPO"
test "$(git rev-parse HEAD)" = "$PROTECTED"
if [ -n "$(git status --porcelain)" ]; then
    echo 'STOP: protected checkout has local changes.'
    exit 1
fi

git fetch --no-tags origin "$BRANCH"
git cat-file -e "${BASE}^{commit}"
git cat-file -e "${PATCH}^{commit}"
test "$(git merge-base "$BASE" "$PATCH")" = "$BASE"

for unit in "$TIMER" "$SERVICE"; do systemctl show "$unit" >/dev/null; done
TIMER_ACTIVE="$(systemctl show "$TIMER" -p ActiveState --value)"
TIMER_SUB="$(systemctl show "$TIMER" -p SubState --value)"
TIMER_ENABLED="$(systemctl show "$TIMER" -p UnitFileState --value)"
SERVICE_ACTIVE="$(systemctl show "$SERVICE" -p ActiveState --value)"
SERVICE_SUB="$(systemctl show "$SERVICE" -p SubState --value)"
printf 'PHASE5G5_STALE_HOST_5G5_STATE=timer:%s/%s/%s service:%s/%s\n' \
    "$TIMER_ACTIVE" "$TIMER_SUB" "$TIMER_ENABLED" "$SERVICE_ACTIVE" "$SERVICE_SUB"
if [ "$TIMER_ACTIVE" != 'active' ] || [ "$TIMER_ENABLED" != 'enabled' ]; then
    echo 'STOP: normal 5G.5 timer is not active/enabled.'
    exit 1
fi
if [ "$SERVICE_ACTIVE" != 'inactive' ]; then
    echo 'PHASE5G5_STALE_HOST_PATCH_DEFERRED=5G5_BUSY'
    exit 0
fi

test -f "$TARGET"
test ! -L "$TARGET"
test "$(readlink -f "$TARGET")" = "$TARGET"
TARGET_META="$(stat -c '%u:%g:%a:%h:%s' "$TARGET")"
IFS=: read -r TARGET_UID TARGET_GID TARGET_MODE TARGET_LINKS TARGET_SIZE <<<"$TARGET_META"
test "$TARGET_LINKS" = '1'
test "$TARGET_SIZE" -gt 1024
test "$TARGET_SIZE" -lt 1048576
if [ "$((8#$TARGET_MODE & 0022))" -ne 0 ]; then
    echo 'STOP: runtime source is group/other writable.'
    exit 1
fi

WORK="$(mktemp -d /var/tmp/phpipam-phase5g5-stale-host.XXXXXXXX)"
chmod 0700 "$WORK"
git worktree add --detach "$WORK/base" "$BASE" >/dev/null
git worktree add --detach "$WORK/patch" "$PATCH" >/dev/null

# Execute the real generated-runtime package tests from the exact patch commit before any live write.
(
    cd -- "$WORK/patch"
    python3 -B tests/phase5g5-runtime-package.test.py
)
echo 'PHASE5G5_STALE_HOST_LOCAL_TESTS_OK=1'

build_source() {
    local root="$1" output="$2"
    ROOT_FOR_BUILD="$root" OUTPUT_FOR_BUILD="$output" python3 - <<'PY'
import importlib.util
import os
from pathlib import Path
root=Path(os.environ['ROOT_FOR_BUILD'])
out=Path(os.environ['OUTPUT_FOR_BUILD'])
spec=importlib.util.spec_from_file_location('recovery_live_build', root/'tools/phase5g5-recover.py')
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
package=module.build_package()
rel='layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php'
data=package['release'][rel]
if not isinstance(data, bytes) or len(data) < 1024 or len(data) > 1048576:
    raise SystemExit('generated_source_invalid')
out.write_bytes(data)
PY
}

OLD="$WORK/old-source.php"
NEW="$WORK/new-source.php"
build_source "$WORK/base" "$OLD"
build_source "$WORK/patch" "$NEW"
php -l "$OLD" >/dev/null
php -l "$NEW" >/dev/null
if cmp -s "$OLD" "$NEW"; then
    echo 'STOP: patch generation produced no source change.'
    exit 1
fi
if ! grep -Fq "throw new RuntimeException('mirror_membership_changed');" "$NEW" || ! grep -Fq 'ManagedObjectNotFound' "$NEW"; then
    echo 'STOP: generated replacement lacks reviewed stale-MOR boundary.'
    exit 1
fi
if grep -Fq 'ManagedObjectNotFound' "$OLD"; then
    echo 'STOP: baseline unexpectedly already contains stale-MOR boundary.'
    exit 1
fi

OLD_BLOB="$(git hash-object "$OLD")"
NEW_BLOB="$(git hash-object "$NEW")"
TARGET_BLOB="$(git hash-object "$TARGET")"
printf 'PHASE5G5_STALE_HOST_BASE_SOURCE_BLOB=%s\n' "$OLD_BLOB"
printf 'PHASE5G5_STALE_HOST_NEW_SOURCE_BLOB=%s\n' "$NEW_BLOB"
printf 'PHASE5G5_STALE_HOST_INSTALLED_SOURCE_BLOB=%s\n' "$TARGET_BLOB"

if [ "$TARGET_BLOB" = "$NEW_BLOB" ]; then
    echo 'PHASE5G5_STALE_HOST_PATCH_ALREADY_APPLIED=1'
    echo 'PHASE5G5_STALE_HOST_WORKER_CHANGED=0'
    echo 'PHASE5G5_STALE_HOST_DATA_CHANGED=0'
    echo 'PHASE5G5_STALE_HOST_SYSTEMD_CHANGED=0'
    echo 'PHASE5G5_STALE_HOST_WEB_CHANGED=0'
    exit 0
fi
if [ "$TARGET_BLOB" != "$OLD_BLOB" ]; then
    echo 'STOP: installed 5G.5 source does not match reviewed baseline.'
    exit 1
fi

BACKUP="/var/backups/phpipam-custom/vmware-stale-host-membership-$(date -u +%Y%m%d-%H%M%S)"
install -d -m 0700 -o 0 -g 0 "$BACKUP"
cp --preserve=mode,ownership,timestamps -- "$TARGET" "$BACKUP/class.IntegrationVmwareInventorySource.php"
test "$(git hash-object "$BACKUP/class.IntegrationVmwareInventorySource.php")" = "$OLD_BLOB"

TARGET_DIR="$(dirname "$TARGET")"
TMP_TARGET="$(mktemp "$TARGET_DIR/.stale-host-membership.XXXXXXXX")"
cat -- "$NEW" > "$TMP_TARGET"
chown "$TARGET_UID:$TARGET_GID" "$TMP_TARGET"
chmod "$TARGET_MODE" "$TMP_TARGET"
php -l "$TMP_TARGET" >/dev/null
test "$(git hash-object "$TMP_TARGET")" = "$NEW_BLOB"
mv -f -- "$TMP_TARGET" "$TARGET"
TMP_TARGET=''
sync -f "$TARGET" 2>/dev/null || true

test "$(git hash-object "$TARGET")" = "$NEW_BLOB"
test "$(stat -c '%u:%g:%a:%h' "$TARGET")" = "$TARGET_UID:$TARGET_GID:$TARGET_MODE:1"
php -l "$TARGET" >/dev/null

printf 'PHASE5G5_STALE_HOST_PATCH_SOURCE=%s\n' "$PATCH"
printf 'PHASE5G5_STALE_HOST_PATCH_BACKUP=%s\n' "$BACKUP"
echo 'PHASE5G5_STALE_HOST_PATCH_OK=1'
echo 'PHASE5G5_STALE_HOST_WORKER_CHANGED=1'
echo 'PHASE5G5_STALE_HOST_DATA_CHANGED=0'
echo 'PHASE5G5_STALE_HOST_SYSTEMD_CHANGED=0'
echo 'PHASE5G5_STALE_HOST_WEB_CHANGED=0'

TIMER_ACTIVE_AFTER="$(systemctl show "$TIMER" -p ActiveState --value)"
TIMER_SUB_AFTER="$(systemctl show "$TIMER" -p SubState --value)"
TIMER_ENABLED_AFTER="$(systemctl show "$TIMER" -p UnitFileState --value)"
SERVICE_ACTIVE_AFTER="$(systemctl show "$SERVICE" -p ActiveState --value)"
SERVICE_SUB_AFTER="$(systemctl show "$SERVICE" -p SubState --value)"
printf 'PHASE5G5_STALE_HOST_5G5_STATE_AFTER=timer:%s/%s/%s service:%s/%s\n' \
    "$TIMER_ACTIVE_AFTER" "$TIMER_SUB_AFTER" "$TIMER_ENABLED_AFTER" "$SERVICE_ACTIVE_AFTER" "$SERVICE_SUB_AFTER"
test "$TIMER_ACTIVE_AFTER" = 'active'
test "$TIMER_ENABLED_AFTER" = 'enabled'
