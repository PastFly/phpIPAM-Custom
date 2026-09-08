#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5C read-only VMware inventory/reconciliation UI.
#
# Preconditions: runtime-verified Phase 5B.3 persistent UI is installed exactly.
# This installer adds only PHP source for GET-only inventory/reconciliation and
# appends one deterministic include to Administration → Integrations.
# It does not alter DB schema, connector rows, credentials, keyring, Rackspace,
# scheduler configuration, phpIPAM objects or vCenter objects.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5c-ui-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() {
    rm -rf "$STAGE"
}

rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo >&2
        echo "ERROR: Phase 5C UI install failed after commit started; rolling back source layer." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic Phase 5C UI rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}

trap cleanup EXIT
trap rollback_on_error ERR

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

for cmd in git php grep; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done

[[ "$EUID" -eq 0 ]] || fail "Phase 5C UI installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Before Phase 5C changes the Administration index, prove the exact Phase 5B.3
# runtime state including schema/keyring/source/security invariants.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5b3-ui.sh" "$TARGET"

BASE_INDEX_BLOB="2aa9faaac09901d6b62ef17a32af87b8316f388c"
ACTION_BLOB="aa7c746775183a63b2fde2482a5acac61b93a7ce"
TRANSIENT_BLOB="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"
INVENTORY_BLOB="c4affaa47d2a72ec4397f373ae321969d9c6cd5c"
GUEST_BLOB="f4db8a61a937424ce93ec9e5cd00a58ca77bdddd"
RECONCILER_BLOB="4fc465115be4b5af4f924ea48eb3ebf68400bdc3"
SNAPSHOT_BLOB="7903faf2302158a4cf38d427039941b11c382f88"
PANEL_BLOB="a809540f561aada1b502e932e481135b658bdbe6"
RESULT_BLOB="cbf77a92a7c7948dd77070fc83d4539eda1f371e"
APPEND_BLOB="fe8d1f13bcc1d61b50431625ddd873d9200e7060"

INDEX_REL="app/admin/integrations/index.php"
ACTION_REL="app/admin/integrations/vmware-config-action.php"
TRANSIENT_REL="app/admin/integrations/vmware-test-result.php"
PANEL_REL="app/admin/integrations/vmware-inventory-panel.php"
RESULT_REL="app/admin/integrations/vmware-inventory-result.php"
INVENTORY_REL="functions/classes/class.IntegrationVmwareVcenterInventory.php"
GUEST_REL="functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php"
RECONCILER_REL="functions/classes/class.IntegrationVmwareVcenterReconciler.php"
SNAPSHOT_REL="functions/classes/class.IntegrationPhpIpamInventorySnapshot.php"

BASE_INDEX_SOURCE="$REPO_ROOT/layers/phase5b3/app/admin/integrations/index.php"
APPEND_SOURCE="$REPO_ROOT/layers/phase5c/app/admin/integrations/index-phase5c-append.php"
PANEL_SOURCE="$REPO_ROOT/layers/phase5c/app/admin/integrations/vmware-inventory-panel.php"
RESULT_SOURCE="$REPO_ROOT/layers/phase5c/app/admin/integrations/vmware-inventory-result.php"
INVENTORY_SOURCE="$REPO_ROOT/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterInventory.php"
GUEST_SOURCE="$REPO_ROOT/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php"
RECONCILER_SOURCE="$REPO_ROOT/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterReconciler.php"
SNAPSHOT_SOURCE="$REPO_ROOT/layers/phase5c/functions/classes/class.IntegrationPhpIpamInventorySnapshot.php"

hash_file() {
    git hash-object "$1"
}

[[ "$(hash_file "$TARGET/$INDEX_REL")" == "$BASE_INDEX_BLOB" ]] || fail "Administration Integrations index drift before Phase 5C"
[[ "$(hash_file "$TARGET/$ACTION_REL")" == "$ACTION_BLOB" ]] || fail "Phase 5B.3 persistent action endpoint drift"
[[ "$(hash_file "$TARGET/$TRANSIENT_REL")" == "$TRANSIENT_BLOB" ]] || fail "Phase 5A transient endpoint drift"

for rel in "$PANEL_REL" "$RESULT_REL" "$INVENTORY_REL" "$GUEST_REL" "$RECONCILER_REL" "$SNAPSHOT_REL"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5C target already exists: $rel"
done

[[ "$(hash_file "$BASE_INDEX_SOURCE")" == "$BASE_INDEX_BLOB" ]] || fail "repository Phase 5B.3 base index drift"
[[ "$(hash_file "$APPEND_SOURCE")" == "$APPEND_BLOB" ]] || fail "Phase 5C index append source drift"
[[ "$(hash_file "$PANEL_SOURCE")" == "$PANEL_BLOB" ]] || fail "Phase 5C panel source drift"
[[ "$(hash_file "$RESULT_SOURCE")" == "$RESULT_BLOB" ]] || fail "Phase 5C result endpoint source drift"
[[ "$(hash_file "$INVENTORY_SOURCE")" == "$INVENTORY_BLOB" ]] || fail "Phase 5C inventory client source drift"
[[ "$(hash_file "$GUEST_SOURCE")" == "$GUEST_BLOB" ]] || fail "Phase 5C guest-network client source drift"
[[ "$(hash_file "$RECONCILER_SOURCE")" == "$RECONCILER_BLOB" ]] || fail "Phase 5C reconciler source drift"
[[ "$(hash_file "$SNAPSHOT_SOURCE")" == "$SNAPSHOT_BLOB" ]] || fail "Phase 5C phpIPAM snapshot source drift"

mkdir -p "$STAGE/app/admin/integrations" "$STAGE/functions/classes"
cat "$BASE_INDEX_SOURCE" > "$STAGE/$INDEX_REL"
printf '\n' >> "$STAGE/$INDEX_REL"
cat "$APPEND_SOURCE" >> "$STAGE/$INDEX_REL"
cp "$PANEL_SOURCE" "$STAGE/$PANEL_REL"
cp "$RESULT_SOURCE" "$STAGE/$RESULT_REL"
cp "$INVENTORY_SOURCE" "$STAGE/$INVENTORY_REL"
cp "$GUEST_SOURCE" "$STAGE/$GUEST_REL"
cp "$RECONCILER_SOURCE" "$STAGE/$RECONCILER_REL"
cp "$SNAPSHOT_SOURCE" "$STAGE/$SNAPSHOT_REL"

for rel in "$INDEX_REL" "$PANEL_REL" "$RESULT_REL" "$INVENTORY_REL" "$GUEST_REL" "$RECONCILER_REL" "$SNAPSHOT_REL"; do
    php -l "$STAGE/$rel" >/dev/null || fail "candidate PHP lint failed: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_INDEX_APPEND_V1' "$STAGE/$INDEX_REL" || fail "Phase 5C index append marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_PANEL_V1' "$STAGE/$PANEL_REL" || fail "Phase 5C panel marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_UI_RESULT_V1' "$STAGE/$RESULT_REL" || fail "Phase 5C result marker missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_inventory"' "$STAGE/$RESULT_REL" || fail "Phase 5C inventory CSRF validation missing"
grep -Fq 'is_admin(false)' "$STAGE/$RESULT_REL" || fail "Phase 5C inventory administrator guard missing"
grep -Fq 'load_runtime_config($configId, true)' "$STAGE/$RESULT_REL" || fail "enabled stored connector requirement missing"
grep -Fq 'read_core_inventory(' "$STAGE/$RESULT_REL" || fail "core inventory call missing"
grep -Fq 'read_vm_interfaces(' "$STAGE/$RESULT_REL" || fail "guest-network observation call missing"
grep -Fq 'read_projection()' "$STAGE/$RESULT_REL" || fail "phpIPAM read-only projection call missing"
grep -Fq -- '->reconcile(' "$STAGE/$RESULT_REL" || fail "advisory reconciliation call missing"

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$STAGE/$RESULT_REL" "$STAGE/$SNAPSHOT_REL" "$STAGE/$RECONCILER_REL"; then
    fail "unexpected write API reference in Phase 5C read-only source"
fi

echo "Phase 5C inventory UI pre-commit checks: OK"

mkdir -p "$BACKUP/app/admin/integrations"
cp -a "$TARGET/$INDEX_REL" "$BACKUP/$INDEX_REL"
printf '%s\n' \
    "$PANEL_REL" \
    "$RESULT_REL" \
    "$INVENTORY_REL" \
    "$GUEST_REL" \
    "$RECONCILER_REL" \
    "$SNAPSHOT_REL" > "$BACKUP/CREATED.txt"

cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"

while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    rm -f "$TARGET/$rel"
done < "$BACKUP_DIR/CREATED.txt"

cp -a "$BACKUP_DIR/app/admin/integrations/index.php" "$TARGET/app/admin/integrations/index.php"
echo "PHASE5C_UI_ROLLBACK_OK"
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"

echo "Phase 5C UI backup / rollback prepared: $BACKUP"

install_candidate() {
    local rel="$1"
    local src="$STAGE/$rel"
    local dst="$TARGET/$rel"
    local parent="$(dirname "$dst")"
    local tmp="$dst.phpipam-custom-tmp-$$"
    mkdir -p "$parent"

    if [[ -e "$dst" ]]; then
        cp -a "$dst" "$tmp"
        cat "$src" > "$tmp"
    else
        cp "$src" "$tmp"
        chown --reference="$parent" "$tmp" 2>/dev/null || true
        chmod 0644 "$tmp"
    fi
    mv -f "$tmp" "$dst"
}

COMMIT_STARTED=1
for rel in "$INDEX_REL" "$PANEL_REL" "$RESULT_REL" "$INVENTORY_REL" "$GUEST_REL" "$RECONCILER_REL" "$SNAPSHOT_REL"; do
    install_candidate "$rel"
done

[[ "$(hash_file "$TARGET/$INDEX_REL")" == "$(hash_file "$STAGE/$INDEX_REL")" ]] || fail "post-install Phase 5C Administration index mismatch"
[[ "$(hash_file "$TARGET/$PANEL_REL")" == "$PANEL_BLOB" ]] || fail "post-install panel blob mismatch"
[[ "$(hash_file "$TARGET/$RESULT_REL")" == "$RESULT_BLOB" ]] || fail "post-install result endpoint blob mismatch"
[[ "$(hash_file "$TARGET/$INVENTORY_REL")" == "$INVENTORY_BLOB" ]] || fail "post-install inventory client blob mismatch"
[[ "$(hash_file "$TARGET/$GUEST_REL")" == "$GUEST_BLOB" ]] || fail "post-install guest-network client blob mismatch"
[[ "$(hash_file "$TARGET/$RECONCILER_REL")" == "$RECONCILER_BLOB" ]] || fail "post-install reconciler blob mismatch"
[[ "$(hash_file "$TARGET/$SNAPSHOT_REL")" == "$SNAPSHOT_BLOB" ]] || fail "post-install phpIPAM snapshot blob mismatch"
[[ "$(hash_file "$TARGET/$ACTION_REL")" == "$ACTION_BLOB" ]] || fail "Phase 5B.3 action endpoint changed during Phase 5C install"
[[ "$(hash_file "$TARGET/$TRANSIENT_REL")" == "$TRANSIENT_BLOB" ]] || fail "Phase 5A transient endpoint changed during Phase 5C install"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5c-ui.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo "PHASE5C_UI_INSTALL_OK"
echo "Backup / rollback: $BACKUP"
echo "No database schema, connector, credential, keyring, Rackspace or scheduler changes were made."
echo "Inventory and reconciliation remain administrator-triggered, read-only and non-persistent."
