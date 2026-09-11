#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5D VMware Explorer verifier (READ ONLY).
# Reconstructs the expected Administration index from reviewed Phase 5B.3,
# Phase 5C and Phase 5D append sources; verifies exact source blobs/security
# invariants. No DB writes and no vCenter requests.

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

# Phase 5D does not touch DB schema, keyring or Rackspace.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5b3-foundation.sh" "$TARGET"

BASE_INDEX_BLOB="2aa9faaac09901d6b62ef17a32af87b8316f388c"
PHASE5C_APPEND_BLOB="fe8d1f13bcc1d61b50431625ddd873d9200e7060"
PHASE5D_APPEND_BLOB="1ac67d9441bb6b440ac03fb4e5d47499631e8d48"

ACTION_BLOB="aa7c746775183a63b2fde2482a5acac61b93a7ce"
TRANSIENT_BLOB="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"
PHASE5C_PANEL_BLOB="a809540f561aada1b502e932e481135b658bdbe6"
PHASE5C_RESULT_BLOB="cbf77a92a7c7948dd77070fc83d4539eda1f371e"
PHASE5C_INVENTORY_BLOB="c4affaa47d2a72ec4397f373ae321969d9c6cd5c"
PHASE5C_GUEST_BLOB="f4db8a61a937424ce93ec9e5cd00a58ca77bdddd"
PHASE5C_RECONCILER_BLOB="4fc465115be4b5af4f924ea48eb3ebf68400bdc3"
PHASE5C_SNAPSHOT_BLOB="7903faf2302158a4cf38d427039941b11c382f88"

TOPOLOGY_BLOB="bb162ddc4e01f52ca08aa3e00998bf98e21a9fbb"
DATASTORE_BLOB="3f98b2b5c66a47205ab17e18272a87b35f4cdd43"
RESOLVER_BLOB="36a41c8a05d769f40762603888b73cd9e80cb959"
VMDETAIL_BLOB="f639c7046af8342c34a430b257447cd2b05f5e02"
METADATA_BLOB="44d8691af6531c48ea20deb15646b002cebdfb37"
EXPLORER_BLOB="111d70b5897ba71f2bb24a8b0b80b361509523b5"
PANEL_BLOB="670d7c4cebcce7570ce3225054125384720272a8"
RESULT_BLOB="2054ca8e7eaaf9c63408c762eec51427b5539b5f"
CARD_BLOB="4fd47dcdb800ec1ed7c103ad4cae25b82c8f35be"

INDEX_REL="app/admin/integrations/index.php"
ACTION_REL="app/admin/integrations/vmware-config-action.php"
TRANSIENT_REL="app/admin/integrations/vmware-test-result.php"
PHASE5C_PANEL_REL="app/admin/integrations/vmware-inventory-panel.php"
PHASE5C_RESULT_REL="app/admin/integrations/vmware-inventory-result.php"
PHASE5C_INVENTORY_REL="functions/classes/class.IntegrationVmwareVcenterInventory.php"
PHASE5C_GUEST_REL="functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php"
PHASE5C_RECONCILER_REL="functions/classes/class.IntegrationVmwareVcenterReconciler.php"
PHASE5C_SNAPSHOT_REL="functions/classes/class.IntegrationPhpIpamInventorySnapshot.php"

TOPOLOGY_REL="functions/classes/class.IntegrationVmwareVcenterTopology.php"
DATASTORE_REL="functions/classes/class.IntegrationVmwareVcenterDatastore.php"
RESOLVER_REL="functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php"
VMDETAIL_REL="functions/classes/class.IntegrationVmwareVcenterVmDetail.php"
METADATA_REL="functions/classes/class.IntegrationVmwareVcenterMetadata.php"
EXPLORER_REL="functions/classes/class.IntegrationVmwareVcenterExplorer.php"
PANEL_REL="app/admin/integrations/vmware-explorer-panel.php"
RESULT_REL="app/admin/integrations/vmware-explorer-result.php"
CARD_REL="app/admin/integrations/vmware-vm-card-result.php"

BASE_INDEX_SOURCE="$REPO_ROOT/layers/phase5b3/app/admin/integrations/index.php"
PHASE5C_APPEND_SOURCE="$REPO_ROOT/layers/phase5c/app/admin/integrations/index-phase5c-append.php"
PHASE5D_APPEND_SOURCE="$REPO_ROOT/layers/phase5d/app/admin/integrations/index-phase5d-append.php"

hash_file() { git hash-object "$1"; }

[[ "$(hash_file "$BASE_INDEX_SOURCE")" == "$BASE_INDEX_BLOB" ]] || fail "repository Phase 5B.3 base index drift"
[[ "$(hash_file "$PHASE5C_APPEND_SOURCE")" == "$PHASE5C_APPEND_BLOB" ]] || fail "repository Phase 5C index append drift"
[[ "$(hash_file "$PHASE5D_APPEND_SOURCE")" == "$PHASE5D_APPEND_BLOB" ]] || fail "repository Phase 5D index append drift"

cat "$BASE_INDEX_SOURCE" > "$TMP/index.php"
printf '\n' >> "$TMP/index.php"
cat "$PHASE5C_APPEND_SOURCE" >> "$TMP/index.php"
printf '\n' >> "$TMP/index.php"
cat "$PHASE5D_APPEND_SOURCE" >> "$TMP/index.php"
php -l "$TMP/index.php" >/dev/null || fail "expected cumulative Administration index lint failed"

REQUIRED=(
    "$INDEX_REL" "$ACTION_REL" "$TRANSIENT_REL"
    "$PHASE5C_PANEL_REL" "$PHASE5C_RESULT_REL" "$PHASE5C_INVENTORY_REL" "$PHASE5C_GUEST_REL" "$PHASE5C_RECONCILER_REL" "$PHASE5C_SNAPSHOT_REL"
    "$TOPOLOGY_REL" "$DATASTORE_REL" "$RESOLVER_REL" "$VMDETAIL_REL" "$METADATA_REL" "$EXPLORER_REL" "$PANEL_REL" "$RESULT_REL" "$CARD_REL"
)
for rel in "${REQUIRED[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "required VMware Explorer source missing: $rel"
done

[[ "$(hash_file "$TARGET/$INDEX_REL")" == "$(hash_file "$TMP/index.php")" ]] || fail "Phase 5D Administration index drift"
[[ "$(hash_file "$TARGET/$ACTION_REL")" == "$ACTION_BLOB" ]] || fail "Phase 5B.3 persistent action endpoint drift"
[[ "$(hash_file "$TARGET/$TRANSIENT_REL")" == "$TRANSIENT_BLOB" ]] || fail "Phase 5A transient endpoint drift"
[[ "$(hash_file "$TARGET/$PHASE5C_PANEL_REL")" == "$PHASE5C_PANEL_BLOB" ]] || fail "Phase 5C panel drift"
[[ "$(hash_file "$TARGET/$PHASE5C_RESULT_REL")" == "$PHASE5C_RESULT_BLOB" ]] || fail "Phase 5C result drift"
[[ "$(hash_file "$TARGET/$PHASE5C_INVENTORY_REL")" == "$PHASE5C_INVENTORY_BLOB" ]] || fail "Phase 5C inventory client drift"
[[ "$(hash_file "$TARGET/$PHASE5C_GUEST_REL")" == "$PHASE5C_GUEST_BLOB" ]] || fail "Phase 5C guest client drift"
[[ "$(hash_file "$TARGET/$PHASE5C_RECONCILER_REL")" == "$PHASE5C_RECONCILER_BLOB" ]] || fail "Phase 5C reconciler drift"
[[ "$(hash_file "$TARGET/$PHASE5C_SNAPSHOT_REL")" == "$PHASE5C_SNAPSHOT_BLOB" ]] || fail "Phase 5C snapshot drift"

[[ "$(hash_file "$TARGET/$TOPOLOGY_REL")" == "$TOPOLOGY_BLOB" ]] || fail "Phase 5D topology source drift"
[[ "$(hash_file "$TARGET/$DATASTORE_REL")" == "$DATASTORE_BLOB" ]] || fail "Phase 5D datastore source drift"
[[ "$(hash_file "$TARGET/$RESOLVER_REL")" == "$RESOLVER_BLOB" ]] || fail "Phase 5D datastore resolver source drift"
[[ "$(hash_file "$TARGET/$VMDETAIL_REL")" == "$VMDETAIL_BLOB" ]] || fail "Phase 5D VM detail source drift"
[[ "$(hash_file "$TARGET/$METADATA_REL")" == "$METADATA_BLOB" ]] || fail "Phase 5D metadata source drift"
[[ "$(hash_file "$TARGET/$EXPLORER_REL")" == "$EXPLORER_BLOB" ]] || fail "Phase 5D Explorer model source drift"
[[ "$(hash_file "$TARGET/$PANEL_REL")" == "$PANEL_BLOB" ]] || fail "Phase 5D Explorer panel source drift"
[[ "$(hash_file "$TARGET/$RESULT_REL")" == "$RESULT_BLOB" ]] || fail "Phase 5D Explorer result source drift"
[[ "$(hash_file "$TARGET/$CARD_REL")" == "$CARD_BLOB" ]] || fail "Phase 5D VM card source drift"

for rel in "${REQUIRED[@]}"; do
    php -l "$TARGET/$rel" >/dev/null || fail "PHP lint failed: $rel"
done

[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_INDEX_APPEND_V1' "$TARGET/$INDEX_REL")" == "1" ]] || fail "Phase 5C index marker missing or duplicated"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_INDEX_APPEND_V1' "$TARGET/$INDEX_REL")" == "1" ]] || fail "Phase 5D index marker missing or duplicated"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_PANEL_V1' "$TARGET/$PANEL_REL" || fail "Phase 5D Explorer panel marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_RESULT_V1' "$TARGET/$RESULT_REL" || fail "Phase 5D Explorer result marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_VM_CARD_RESULT_V1' "$TARGET/$CARD_REL" || fail "Phase 5D VM card marker missing"

grep -Fq '$User->check_user_session();' "$TARGET/$RESULT_REL" || fail "Explorer result session guard missing"
grep -Fq 'is_admin(false)' "$TARGET/$RESULT_REL" || fail "Explorer result admin guard missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_explorer"' "$TARGET/$RESULT_REL" || fail "Explorer result CSRF guard missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_explorer"' "$TARGET/$CARD_REL" || fail "VM card CSRF guard missing"
grep -Fq "empty(\$runtime['verify_tls']) && !\$allowInsecureTls" "$TARGET/$RESULT_REL" || fail "Explorer insecure-TLS gate missing"
grep -Fq "empty(\$runtime['verify_tls']) && !\$allowInsecureTls" "$TARGET/$CARD_REL" || fail "VM card insecure-TLS gate missing"
grep -Fq '$pageSize > 100' "$TARGET/$RESULT_REL" || fail "Explorer page-size bound missing"
grep -Fq 'Sensitive exception details were suppressed' "$TARGET/$RESULT_REL" || fail "Explorer sanitized error response missing"
grep -Fq 'Sensitive exception details were suppressed' "$TARGET/$CARD_REL" || fail "VM card sanitized error response missing"
grep -Fq 'phase5d_explorer_zero_secret' "$TARGET/$RESULT_REL" || fail "Explorer secret zeroing missing"
grep -Fq 'phase5d_vmcard_zero_secret' "$TARGET/$CARD_REL" || fail "VM card secret zeroing missing"

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$TARGET/$RESULT_REL" "$TARGET/$CARD_REL" "$TARGET/$EXPLORER_REL" "$TARGET/$RESOLVER_REL"; then
    fail "unexpected phpIPAM write API reference in Phase 5D Explorer source"
fi
if grep -Eq 'action=(attach|detach)|/api/cis/tagging/(tag|category).*(create|update|delete)' "$TARGET/$METADATA_REL"; then
    fail "unexpected VMware tag mutation endpoint in Phase 5D metadata source"
fi
if grep -Eq "'PUT'|'PATCH'" "$TARGET/$TOPOLOGY_REL" "$TARGET/$DATASTORE_REL" "$TARGET/$VMDETAIL_REL" "$TARGET/$METADATA_REL"; then
    fail "unexpected vCenter mutating HTTP method in Phase 5D clients"
fi

grep -Fq 'correlation_only_not_authoritative_vmware_relation' "$TARGET/$RESOLVER_REL" || fail "datastore correlation provenance marker missing"
grep -Fq 'list-attached-tags' "$TARGET/$METADATA_REL" || fail "read-only VMware tag association path missing"
grep -Fq 'Search is display filtering only' "$TARGET/$EXPLORER_REL" || fail "Explorer search non-inference contract missing"
grep -Fq 'persistent_inventory_written=false' "$TARGET/$RESULT_REL" || fail "Explorer no-persistence declaration missing"
grep -Fq 'persistent_inventory_written=false' "$TARGET/$CARD_REL" || fail "VM card no-persistence declaration missing"

echo "Phase 5D Explorer source/security invariants: OK"
echo "PHASE5D_UI_VERIFY_OK"
