#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
[[ -f "$TARGET/config.php" ]] || fail "target does not look like phpIPAM: $TARGET"

printf '%s\n' '=== PHASE 5F.3 HOST NETWORK UI LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.3 preflight (READ ONLY)'
echo "target=$TARGET"

# Phase 5F.3 is stacked strictly after the accepted Phase 5F.2.6 Folder UX baseline.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f26-folder-state.sh" "$TARGET"

# Repository sources must be exact and remain absent from production before approval.
declare -A EXPECTED=(
  ["app/integrations/vmware/host-network-result.php"]="f8dfbc733850b7660059eb47367258346ba77828"
  ["app/integrations/vmware/phase5f3-host-network.css"]="d0b27b2f980528942a719a582fc3cec2ab2d86c9"
  ["app/integrations/vmware/phase5f3-host-network.js"]="8fc10bd724c3524261730603694484024aabf962"
  ["functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php"]="9167e7f3f9d3249543d59b0053f74e34cdc70602"
)

for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f3/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.3 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3 repository source drift: $rel"
  [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5F.3 target already exists before owner approval: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail "VMware workspace index missing"
! grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F3_HOST_NETWORK_V1' "$INDEX" || fail "Phase 5F.3 workspace hook already present"

php -l "$REPO_ROOT/layers/phase5f3/app/integrations/vmware/host-network-result.php" >/dev/null
php -l "$REPO_ROOT/layers/phase5f3/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$REPO_ROOT/layers/phase5f3/app/integrations/vmware/phase5f3-host-network.js" >/dev/null
fi

CLASS="$REPO_ROOT/layers/phase5f3/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php"
ENDPOINT="$REPO_ROOT/layers/phase5f3/app/integrations/vmware/host-network-result.php"
JS="$REPO_ROOT/layers/phase5f3/app/integrations/vmware/phase5f3-host-network.js"

grep -Fq 'configManager.networkSystem' "$CLASS" || fail "bounded HostNetworkSystem property read missing"
grep -Fq '<QueryNetworkHint ' "$CLASS" || fail "QueryNetworkHint operation missing"
grep -Fq "'persistent_inventory_written' => false" "$CLASS" || fail "read-only persistence marker missing"
grep -Fq "'phpipam_objects_modified' => false" "$CLASS" || fail "phpIPAM mutation marker missing"
grep -Fq "'vcenter_objects_modified' => false" "$CLASS" || fail "vCenter mutation marker missing"
if grep -Eq 'RefreshNetworkSystem|UpdateNetworkConfig|AddVirtualSwitch|RemoveVirtualSwitch|UpdateVirtualSwitch|AddPortGroup|RemovePortGroup|UpdatePortGroup' "$CLASS"; then
  fail "Phase 5F.3 class contains forbidden HostNetworkSystem mutation operation"
fi

grep -Fq '$User->is_admin(false)' "$ENDPOINT" || fail "admin guard missing"
grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" "$ENDPOINT" || fail "CSRF validation missing"
grep -Fq 'host-network-result.php' "$JS" || fail "Phase 5F.3 UI endpoint hook missing"

echo "PHASE5F3_BASE_PHASE5F26_OK=1"
echo "PHASE5F3_SOURCE_ABSENT=1"
echo "PREFLIGHT_WRITE_OPERATIONS=0"
echo "PREFLIGHT_VCENTER_REQUESTS=0"
echo "PREFLIGHT_RESULT=READY_FOR_PHASE5F3_UI_OWNER_APPROVAL"
