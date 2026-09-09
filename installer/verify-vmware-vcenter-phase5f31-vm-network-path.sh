#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f3-host-network.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/vm-network-path-result.php"]="c483e943d1a02037422fbeded2e5217e1d71041a"
  ["app/integrations/vmware/phase5f31-vm-network-path.js"]="08cc549fa138abb75ab440d4d1bb12bec75cbece"
  ["app/integrations/vmware/phase5f31-vm-network-path.css"]="0726fc928342a73bb344929f744cb0e4735d1040"
  ["functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php"]="987b03a66acd8023d1010cc20167cf1da60791d4"
)

for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.3.1 runtime file missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3.1 runtime blob mismatch: $rel"
done

php -l "$TARGET/app/integrations/vmware/vm-network-path-result.php" >/dev/null
php -l "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$TARGET/app/integrations/vmware/phase5f31-vm-network-path.js" >/dev/null; fi

INDEX="$TARGET/app/integrations/vmware/index.php"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F31_VM_NETWORK_PATH_UI_V1' "$INDEX" || fail "Phase 5F.3.1 workspace marker missing"
grep -Fq '<link rel="stylesheet" href="app/integrations/vmware/phase5f31-vm-network-path.css">' "$INDEX" || fail "Phase 5F.3.1 CSS hook missing"
grep -Fq '<script src="app/integrations/vmware/phase5f31-vm-network-path.js"></script>' "$INDEX" || fail "Phase 5F.3.1 JS hook missing"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F31_VM_NETWORK_PATH_UI_V1' "$INDEX")" == "1" ]] || fail "Phase 5F.3.1 workspace marker count is not exactly one"

CLASS="$TARGET/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php"
ENDPOINT="$TARGET/app/integrations/vmware/vm-network-path-result.php"
JS="$TARGET/app/integrations/vmware/phase5f31-vm-network-path.js"

grep -Fq '<pathSet>runtime.host</pathSet>' "$CLASS" || fail "VirtualMachine.runtime.host read missing"
grep -Fq '<pathSet>config.network</pathSet>' "$CLASS" || fail "HostSystem config.network read missing"
grep -Fq '<QueryNetworkHint ' "$CLASS" || fail "QueryNetworkHint read missing"
grep -Fq "'current_uplink_proven' => false" "$CLASS" || fail "No-guess current-uplink invariant missing"
grep -Fq "'persistent_inventory_written' => false" "$CLASS" || fail "read-only persistence invariant missing"
grep -Fq "'phpipam_objects_modified' => false" "$CLASS" || fail "phpIPAM read-only invariant missing"
grep -Fq "'vcenter_objects_modified' => false" "$CLASS" || fail "vCenter read-only invariant missing"
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|RefreshNetworkSystem)' "$CLASS"; then
  fail "forbidden vCenter mutation operation found"
fi

grep -Fq '$User->is_admin(false)' "$ENDPOINT" || fail "Phase 5F.3.1 admin guard missing"
grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" "$ENDPOINT" || fail "Phase 5F.3.1 CSRF guard missing"
grep -Fq 'vm-network-path-result.php' "$JS" || fail "Phase 5F.3.1 endpoint hook missing"
grep -Fq 'vm-card-result.php' "$JS" || fail "Phase 5F.3.1 must remain append-only after primary VM card"
grep -Fq 'sequence' "$JS" || fail "Phase 5F.3.1 latest-request guard missing"

echo 'PHASE5F31_BASE_PHASE5F3_OK=1'
echo "Phase 5F.3.1 exact runtime source: OK (${#EXPECTED[@]})"
echo 'Phase 5F.3.1 VM network path read-only/security invariants: OK'
echo 'PHASE5F31_VM_NETWORK_PATH_VERIFY_OK'
