#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5C Administration inventory UI live preflight.
# READ ONLY: does not copy files, write DB state, create schema/keyring entries,
# or contact vCenter. It proves the exact Phase 5B.3 live base expected by the
# Phase 5C UI installer and confirms that Phase 5C targets are not already live.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

for cmd in git php grep; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

echo "phpIPAM Custom Phase 5C UI live preflight (READ ONLY)"
echo "target=$TARGET"
echo

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5b3-ui.sh" "$TARGET"

phase5c_absent=1
for rel in \
    app/admin/integrations/vmware-inventory-panel.php \
    app/admin/integrations/vmware-inventory-result.php \
    functions/classes/class.IntegrationVmwareVcenterInventory.php \
    functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php \
    functions/classes/class.IntegrationVmwareVcenterReconciler.php \
    functions/classes/class.IntegrationPhpIpamInventorySnapshot.php; do
    if [[ -e "$TARGET/$rel" ]]; then
        echo "PHASE5C_PRESENT=$rel"
        phase5c_absent=0
    else
        echo "PHASE5C_ABSENT=$rel"
    fi
done

[[ "$phase5c_absent" == "1" ]] || fail "Phase 5C target source is already present or partially present; reconcile live state before deployment"

index_blob="$(git hash-object "$TARGET/app/admin/integrations/index.php")"
rack_blob="$(git hash-object "$TARGET/functions/classes/class.Rackspace.php")"
echo "PHASE5B3_ADMIN_INDEX_BLOB=$index_blob"
echo "RACKSPACE_BLOB=$rack_blob"

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_UI_V1' "$TARGET/app/admin/integrations/index.php" || fail "persistent VMware Administration UI marker missing"
if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_INDEX_APPEND_V1' "$TARGET/app/admin/integrations/index.php"; then
    fail "Phase 5C index append marker is already present"
fi

echo
echo "PHASE5B3_UI_EXACT_OK=1"
echo "PHASE5C_SOURCE_ABSENT=1"
echo "PREFLIGHT_WRITE_OPERATIONS=0"
echo "PREFLIGHT_VCENTER_REQUESTS=0"
echo "PREFLIGHT_RESULT=READY_FOR_PHASE5C_UI_OWNER_APPROVAL"
