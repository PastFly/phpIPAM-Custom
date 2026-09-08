#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5D Explorer live preflight (READ ONLY).
# Proves the exact Phase 5C live surface and that Phase 5D deployment targets
# are absent. It performs no file writes, DB writes or vCenter requests.

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

printf 'phpIPAM Custom Phase 5D Explorer live preflight (READ ONLY)\n'
printf 'target=%s\n\n' "$TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5c-ui.sh" "$TARGET"

PHASE5D_TARGETS=(
    "app/admin/integrations/vmware-explorer-panel.php"
    "app/admin/integrations/vmware-explorer-result.php"
    "app/admin/integrations/vmware-vm-card-result.php"
    "functions/classes/class.IntegrationVmwareVcenterTopology.php"
    "functions/classes/class.IntegrationVmwareVcenterDatastore.php"
    "functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php"
    "functions/classes/class.IntegrationVmwareVcenterVmDetail.php"
    "functions/classes/class.IntegrationVmwareVcenterMetadata.php"
    "functions/classes/class.IntegrationVmwareVcenterExplorer.php"
)

ABSENT=1
for rel in "${PHASE5D_TARGETS[@]}"; do
    if [[ -e "$TARGET/$rel" ]]; then
        echo "PHASE5D_PRESENT=$rel"
        ABSENT=0
    else
        echo "PHASE5D_ABSENT=$rel"
    fi
done

INDEX="$TARGET/app/admin/integrations/index.php"
[[ -f "$INDEX" ]] || fail "Administration Integrations index missing"
if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_INDEX_APPEND_V1' "$INDEX"; then
    echo "PHASE5D_INDEX_MARKER_PRESENT=1"
    ABSENT=0
else
    echo "PHASE5D_INDEX_MARKER_PRESENT=0"
fi

RACKSPACE="$TARGET/functions/classes/class.Rackspace.php"
[[ -f "$RACKSPACE" ]] || fail "Rackspace sentinel missing"
echo "RACKSPACE_BLOB=$(git hash-object "$RACKSPACE")"

printf '\nPHASE5C_UI_EXACT_OK=1\n'
printf 'PHASE5D_SOURCE_ABSENT=%s\n' "$ABSENT"
printf 'PREFLIGHT_WRITE_OPERATIONS=0\n'
printf 'PREFLIGHT_VCENTER_REQUESTS=0\n'

if [[ "$ABSENT" != "1" ]]; then
    fail "Phase 5D source/index marker is already present; refuse first-install preflight"
fi

echo "PREFLIGHT_RESULT=READY_FOR_PHASE5D_EXPLORER_OWNER_APPROVAL"
