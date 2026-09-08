#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
TARGET="$TEST_ROOT/phpipam"
REAL_INSTALLER="$ROOT/installer/install-vmware-vcenter-phase5c-ui.sh"
REAL_VERIFIER="$ROOT/installer/verify-vmware-vcenter-phase5c-ui.sh"
TEST_INSTALLER="$ROOT/installer/.phase5c-ui-test-installer-$$.sh"
TEST_VERIFIER="$ROOT/installer/.phase5c-ui-test-verifier-$$.sh"
STUB_UI_VERIFIER="$ROOT/installer/.phase5c-ui-base-verifier-$$.sh"
STUB_FOUNDATION_VERIFIER="$ROOT/installer/.phase5c-foundation-base-verifier-$$.sh"

BASE_INDEX_BLOB="2aa9faaac09901d6b62ef17a32af87b8316f388c"
ACTION_BLOB="aa7c746775183a63b2fde2482a5acac61b93a7ce"
TRANSIENT_BLOB="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"

cleanup() {
    rm -f "$TEST_INSTALLER" "$TEST_VERIFIER" "$STUB_UI_VERIFIER" "$STUB_FOUNDATION_VERIFIER"
    sudo rm -rf "$TEST_ROOT" >/dev/null 2>&1 || true
}
trap cleanup EXIT

for file in "$REAL_INSTALLER" "$REAL_VERIFIER"; do
    [[ -f "$file" ]] || { echo "ERROR: missing Phase 5C deployment source: $file" >&2; exit 1; }
done

mkdir -p "$TARGET/app/admin/integrations" "$TARGET/functions/classes"
cp "$ROOT/layers/phase5b3/app/admin/integrations/index.php" "$TARGET/app/admin/integrations/index.php"
cp "$ROOT/layers/phase5b3/app/admin/integrations/vmware-config-action.php" "$TARGET/app/admin/integrations/vmware-config-action.php"
cp "$ROOT/layers/phase5/app/admin/integrations/vmware-test-result.php" "$TARGET/app/admin/integrations/vmware-test-result.php"

[[ "$(git hash-object "$TARGET/app/admin/integrations/index.php")" == "$BASE_INDEX_BLOB" ]] || { echo "ERROR: Phase 5B.3 index fixture drift" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-config-action.php")" == "$ACTION_BLOB" ]] || { echo "ERROR: Phase 5B.3 action fixture drift" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-test-result.php")" == "$TRANSIENT_BLOB" ]] || { echo "ERROR: transient fixture drift" >&2; exit 1; }

cat > "$STUB_UI_VERIFIER" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
TARGET="${1:?target required}"
[[ "$(git hash-object "$TARGET/app/admin/integrations/index.php")" == "2aa9faaac09901d6b62ef17a32af87b8316f388c" ]]
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-config-action.php")" == "aa7c746775183a63b2fde2482a5acac61b93a7ce" ]]
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-test-result.php")" == "b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0" ]]
echo PHASE5B3_UI_VERIFY_OK
STUB

cat > "$STUB_FOUNDATION_VERIFIER" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
TARGET="${1:?target required}"
[[ -d "$TARGET" ]]
echo PHASE5B3_FOUNDATION_VERIFY_OK
STUB
chmod 0700 "$STUB_UI_VERIFIER" "$STUB_FOUNDATION_VERIFIER"

cp "$REAL_INSTALLER" "$TEST_INSTALLER"
cp "$REAL_VERIFIER" "$TEST_VERIFIER"
ui_stub_name="$(basename "$STUB_UI_VERIFIER")"
foundation_stub_name="$(basename "$STUB_FOUNDATION_VERIFIER")"
test_verifier_name="$(basename "$TEST_VERIFIER")"
sed -i "s#verify-vmware-vcenter-phase5b3-ui.sh#$ui_stub_name#g" "$TEST_INSTALLER"
sed -i "s#verify-vmware-vcenter-phase5b3-foundation.sh#$foundation_stub_name#g" "$TEST_VERIFIER"
sed -i "s#verify-vmware-vcenter-phase5c-ui.sh#$test_verifier_name#g" "$TEST_INSTALLER"
chmod 0700 "$TEST_INSTALLER" "$TEST_VERIFIER"

install_output="$(sudo bash "$TEST_INSTALLER" "$TARGET" 2>&1)"
printf '%s\n' "$install_output"
[[ "$install_output" == *"PHASE5C_UI_INSTALL_OK"* ]] || { echo "ERROR: Phase 5C install marker missing" >&2; exit 1; }

for rel in \
    app/admin/integrations/vmware-inventory-panel.php \
    app/admin/integrations/vmware-inventory-result.php \
    functions/classes/class.IntegrationVmwareVcenterInventory.php \
    functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php \
    functions/classes/class.IntegrationVmwareVcenterReconciler.php \
    functions/classes/class.IntegrationPhpIpamInventorySnapshot.php; do
    [[ -f "$TARGET/$rel" ]] || { echo "ERROR: Phase 5C installed file missing: $rel" >&2; exit 1; }
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_INDEX_APPEND_V1' "$TARGET/app/admin/integrations/index.php" || { echo "ERROR: Phase 5C index append missing" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-config-action.php")" == "$ACTION_BLOB" ]] || { echo "ERROR: Phase 5B.3 action changed" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-test-result.php")" == "$TRANSIENT_BLOB" ]] || { echo "ERROR: transient endpoint changed" >&2; exit 1; }

verify_output="$(sudo bash "$TEST_VERIFIER" "$TARGET" 2>&1)"
printf '%s\n' "$verify_output"
[[ "$verify_output" == *"PHASE5C_UI_VERIFY_OK"* ]] || { echo "ERROR: Phase 5C verifier marker missing" >&2; exit 1; }

mapfile -t backups < <(find "$TARGET" -maxdepth 1 -type d -name '.phpipam-custom-vcenter-phase5c-ui-backup-*' -print)
[[ "${#backups[@]}" -eq 1 ]] || { echo "ERROR: expected one Phase 5C UI backup" >&2; exit 1; }
BACKUP="${backups[0]}"
sudo test -x "$BACKUP/rollback.sh" || { echo "ERROR: Phase 5C rollback script missing" >&2; exit 1; }

rollback_output="$(sudo bash "$BACKUP/rollback.sh" 2>&1)"
printf '%s\n' "$rollback_output"
[[ "$rollback_output" == *"PHASE5C_UI_ROLLBACK_OK"* ]] || { echo "ERROR: Phase 5C rollback marker missing" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/index.php")" == "$BASE_INDEX_BLOB" ]] || { echo "ERROR: rollback did not restore Phase 5B.3 index" >&2; exit 1; }

for rel in \
    app/admin/integrations/vmware-inventory-panel.php \
    app/admin/integrations/vmware-inventory-result.php \
    functions/classes/class.IntegrationVmwareVcenterInventory.php \
    functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php \
    functions/classes/class.IntegrationVmwareVcenterReconciler.php \
    functions/classes/class.IntegrationPhpIpamInventorySnapshot.php; do
    [[ ! -e "$TARGET/$rel" ]] || { echo "ERROR: rollback did not remove: $rel" >&2; exit 1; }
done

[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-config-action.php")" == "$ACTION_BLOB" ]] || { echo "ERROR: rollback touched Phase 5B.3 action" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-test-result.php")" == "$TRANSIENT_BLOB" ]] || { echo "ERROR: rollback touched transient endpoint" >&2; exit 1; }

echo "PHASE5C_UI_DEPLOYMENT_REGRESSION_OK"
