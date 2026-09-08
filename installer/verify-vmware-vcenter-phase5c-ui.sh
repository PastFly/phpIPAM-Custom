#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5C read-only inventory/reconciliation UI verifier.
# Read-only. Rebuilds the expected Administration index from reviewed repository
# sources and verifies exact new source blobs plus inherited Phase 5B.3 state.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

for cmd in git php grep; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Foundation verifier remains valid because Phase 5C does not touch schema,
# keyring, Phase 5B classes or Rackspace.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5b3-foundation.sh" "$TARGET"

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

hash_file() {
    git hash-object "$1"
}

[[ "$(hash_file "$BASE_INDEX_SOURCE")" == "$BASE_INDEX_BLOB" ]] || fail "repository Phase 5B.3 base index drift"
[[ "$(hash_file "$APPEND_SOURCE")" == "$APPEND_BLOB" ]] || fail "repository Phase 5C append drift"

cat "$BASE_INDEX_SOURCE" > "$TMP/index.php"
printf '\n' >> "$TMP/index.php"
cat "$APPEND_SOURCE" >> "$TMP/index.php"

for rel in "$INDEX_REL" "$ACTION_REL" "$TRANSIENT_REL" "$PANEL_REL" "$RESULT_REL" "$INVENTORY_REL" "$GUEST_REL" "$RECONCILER_REL" "$SNAPSHOT_REL"; do
    [[ -f "$TARGET/$rel" ]] || fail "required VMware source missing: $rel"
done

[[ "$(hash_file "$TARGET/$INDEX_REL")" == "$(hash_file "$TMP/index.php")" ]] || fail "Phase 5C Administration index drift"
[[ "$(hash_file "$TARGET/$ACTION_REL")" == "$ACTION_BLOB" ]] || fail "Phase 5B.3 persistent action endpoint drift"
[[ "$(hash_file "$TARGET/$TRANSIENT_REL")" == "$TRANSIENT_BLOB" ]] || fail "Phase 5A transient endpoint drift"
[[ "$(hash_file "$TARGET/$PANEL_REL")" == "$PANEL_BLOB" ]] || fail "Phase 5C inventory panel drift"
[[ "$(hash_file "$TARGET/$RESULT_REL")" == "$RESULT_BLOB" ]] || fail "Phase 5C inventory result endpoint drift"
[[ "$(hash_file "$TARGET/$INVENTORY_REL")" == "$INVENTORY_BLOB" ]] || fail "Phase 5C inventory client drift"
[[ "$(hash_file "$TARGET/$GUEST_REL")" == "$GUEST_BLOB" ]] || fail "Phase 5C guest-network client drift"
[[ "$(hash_file "$TARGET/$RECONCILER_REL")" == "$RECONCILER_BLOB" ]] || fail "Phase 5C reconciler drift"
[[ "$(hash_file "$TARGET/$SNAPSHOT_REL")" == "$SNAPSHOT_BLOB" ]] || fail "Phase 5C phpIPAM snapshot drift"

for rel in "$INDEX_REL" "$ACTION_REL" "$TRANSIENT_REL" "$PANEL_REL" "$RESULT_REL" "$INVENTORY_REL" "$GUEST_REL" "$RECONCILER_REL" "$SNAPSHOT_REL"; do
    php -l "$TARGET/$rel" >/dev/null || fail "PHP lint failed: $rel"
done

[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_INDEX_APPEND_V1' "$TARGET/$INDEX_REL")" == "1" ]] || fail "Phase 5C index append marker missing or duplicated"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_UI_V1' "$TARGET/$INDEX_REL" || fail "inherited persistent UI marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_PANEL_V1' "$TARGET/$PANEL_REL" || fail "Phase 5C panel marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_UI_RESULT_V1' "$TARGET/$RESULT_REL" || fail "Phase 5C result marker missing"
grep -Fq '$User->check_user_session();' "$TARGET/$RESULT_REL" || fail "Phase 5C result session guard missing"
grep -Fq 'is_admin(false)' "$TARGET/$RESULT_REL" || fail "Phase 5C result administrator guard missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_inventory"' "$TARGET/$RESULT_REL" || fail "Phase 5C result CSRF validation missing"
grep -Fq 'load_runtime_config($configId, true)' "$TARGET/$RESULT_REL" || fail "Phase 5C enabled connector guard missing"
grep -Fq "empty(\$runtime['verify_tls']) && !\$allowInsecureTls" "$TARGET/$RESULT_REL" || fail "Phase 5C insecure TLS runtime gate missing"
grep -Fq '$guestLimit > 250' "$TARGET/$RESULT_REL" || fail "Phase 5C interactive guest bound missing"
grep -Fq 'read_core_inventory(' "$TARGET/$RESULT_REL" || fail "Phase 5C core inventory call missing"
grep -Fq 'read_vm_interfaces(' "$TARGET/$RESULT_REL" || fail "Phase 5C guest network call missing"
grep -Fq 'read_projection()' "$TARGET/$RESULT_REL" || fail "Phase 5C phpIPAM projection call missing"
grep -Fq '->reconcile(' "$TARGET/$RESULT_REL" || fail "Phase 5C advisory reconciliation call missing"
grep -Fq 'Sensitive exception details were suppressed' "$TARGET/$RESULT_REL" || fail "Phase 5C sanitized exception response missing"
grep -Fq 'phase5c_inventory_zero_secret' "$TARGET/$RESULT_REL" || fail "Phase 5C secret zeroing missing"

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$TARGET/$RESULT_REL" "$TARGET/$SNAPSHOT_REL" "$TARGET/$RECONCILER_REL"; then
    fail "unexpected phpIPAM write API reference in Phase 5C read-only source"
fi
if grep -Eq 'mark_test_success|mark_test_failure' "$TARGET/$RESULT_REL"; then
    fail "Phase 5C inventory result must not persist connector health metadata"
fi
if grep -Eq '/api/vcenter/.+(create|update|patch)|/rest/vcenter/.+(create|update|patch)' "$TARGET/$INVENTORY_REL" "$TARGET/$GUEST_REL"; then
    fail "unexpected vCenter write endpoint pattern in Phase 5C clients"
fi

grep -Fq 'SELECT `id`,`hostname` FROM `devices`' "$TARGET/$SNAPSHOT_REL" || fail "phpIPAM Device projection query missing"
grep -Fq 'SELECT `id`,`ip_addr`,`switch`,`mac` FROM `ipaddresses`' "$TARGET/$SNAPSHOT_REL" || fail "phpIPAM address projection query missing"
grep -Fq 'no persistence' "$TARGET/$PANEL_REL" || fail "non-persistence UI declaration missing"
grep -Fq 'scheduled inventory' "$TARGET/$PANEL_REL" || fail "TLS/scheduler safety copy missing"

echo "Phase 5C inventory UI source/security invariants: OK"
echo "PHASE5C_UI_VERIFY_OK"
