#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
echo '=== PHASE 5F.3.1 VM NETWORK PATH UI LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.3.1 preflight (READ ONLY)'
echo "target=$TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f3-host-network.sh" "$TARGET"
echo 'PHASE5F31_BASE_PHASE5F3_OK=1'

for rel in \
  app/integrations/vmware/vm-network-path-result.php \
  app/integrations/vmware/phase5f31-vm-network-path.js \
  app/integrations/vmware/phase5f31-vm-network-path.css \
  functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php; do
  if [[ -e "$TARGET/$rel" ]]; then
    fail "Phase 5F.3.1 target already exists before owner approval: $rel"
  fi
  echo "PHASE5F31_ABSENT=$rel"
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F31_VM_NETWORK_PATH_V1' "$REPO_ROOT/layers/phase5f31/app/integrations/vmware/vm-network-path-result.php" || fail 'Phase 5F.3.1 endpoint marker missing'
grep -Fq 'current_uplink_proven' "$REPO_ROOT/layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php" || fail 'Phase 5F.3.1 current-uplink evidence guard missing'
grep -Fq "'persistent_inventory_written' => false" "$REPO_ROOT/layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php" || fail 'Phase 5F.3.1 read-only persistence invariant missing'
grep -Fq "'phpipam_objects_modified' => false" "$REPO_ROOT/layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php" || fail 'Phase 5F.3.1 phpIPAM read-only invariant missing'
grep -Fq "'vcenter_objects_modified' => false" "$REPO_ROOT/layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php" || fail 'Phase 5F.3.1 vCenter read-only invariant missing'
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|RefreshNetworkSystem)' "$REPO_ROOT/layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php"; then
  fail 'Phase 5F.3.1 candidate contains forbidden vCenter mutation operation'
fi

php -l "$REPO_ROOT/layers/phase5f31/app/integrations/vmware/vm-network-path-result.php" >/dev/null
php -l "$REPO_ROOT/layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$REPO_ROOT/layers/phase5f31/app/integrations/vmware/phase5f31-vm-network-path.js" >/dev/null; fi

echo 'PHASE5F31_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F31_VM_NETWORK_PATH_OWNER_APPROVAL'
