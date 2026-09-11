#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f26-folder-state.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/host-network-result.php"]="f8dfbc733850b7660059eb47367258346ba77828"
  ["app/integrations/vmware/phase5f3-host-network.css"]="d0b27b2f980528942a719a582fc3cec2ab2d86c9"
  ["app/integrations/vmware/phase5f3-host-network.js"]="8fc10bd724c3524261730603694484024aabf962"
  ["functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php"]="9167e7f3f9d3249543d59b0053f74e34cdc70602"
)

for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.3 runtime file missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3 runtime blob mismatch: $rel"
done

php -l "$TARGET/app/integrations/vmware/host-network-result.php" >/dev/null
php -l "$TARGET/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$TARGET/app/integrations/vmware/phase5f3-host-network.js" >/dev/null
fi

INDEX="$TARGET/app/integrations/vmware/index.php"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F3_HOST_NETWORK_V1' "$INDEX" || fail "Phase 5F.3 workspace marker missing"
grep -Fq '<link rel="stylesheet" href="app/integrations/vmware/phase5f3-host-network.css">' "$INDEX" || fail "Phase 5F.3 CSS hook missing"
grep -Fq '<script src="app/integrations/vmware/phase5f3-host-network.js"></script>' "$INDEX" || fail "Phase 5F.3 JS hook missing"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F3_HOST_NETWORK_V1' "$INDEX")" == "1" ]] || fail "Phase 5F.3 workspace marker count is not exactly one"

CLASS="$TARGET/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php"
ENDPOINT="$TARGET/app/integrations/vmware/host-network-result.php"
JS="$TARGET/app/integrations/vmware/phase5f3-host-network.js"

grep -Fq 'configManager.networkSystem' "$CLASS" || fail "HostNetworkSystem property path missing"
grep -Fq '<QueryNetworkHint ' "$CLASS" || fail "QueryNetworkHint operation missing"
grep -Fq "'persistent_inventory_written' => false" "$CLASS" || fail "read-only persistence invariant missing"
grep -Fq "'phpipam_objects_modified' => false" "$CLASS" || fail "phpIPAM read-only invariant missing"
grep -Fq "'vcenter_objects_modified' => false" "$CLASS" || fail "vCenter read-only invariant missing"
if grep -Eq 'RefreshNetworkSystem|UpdateNetworkConfig|AddVirtualSwitch|RemoveVirtualSwitch|UpdateVirtualSwitch|AddPortGroup|RemovePortGroup|UpdatePortGroup' "$CLASS"; then
  fail "forbidden HostNetworkSystem mutation operation found"
fi

grep -Fq '$User->is_admin(false)' "$ENDPOINT" || fail "Phase 5F.3 admin guard missing"
grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" "$ENDPOINT" || fail "Phase 5F.3 CSRF guard missing"
grep -Fq 'host-network-result.php' "$JS" || fail "Phase 5F.3 endpoint hook missing"
grep -Fq 'ajaxComplete' "$JS" || fail "Phase 5F.3 must remain append-only after the primary host card"

echo "PHASE5F3_BASE_PHASE5F26_OK=1"
echo "Phase 5F.3 exact runtime source: OK (${#EXPECTED[@]})"
echo 'Phase 5F.3 host network read-only/security invariants: OK'
echo 'PHASE5F3_HOST_NETWORK_VERIFY_OK'
