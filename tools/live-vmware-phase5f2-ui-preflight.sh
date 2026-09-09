#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

echo '=== PHASE 5F.2 VM DETAIL / LAYOUT LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.2 preflight (READ ONLY)'
echo "target=$TARGET"

# The already deployed Phase 5F.1 runtime is the exact protected baseline.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f1-ui.sh" "$TARGET"

declare -a NEW_FILES=(
  "app/integrations/vmware/phase5f2-ux.js"
  "app/integrations/vmware/phase5f2-ux.css"
  "app/integrations/vmware/vm-annotation-result.php"
  "functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php"
)
for rel in "${NEW_FILES[@]}"; do
  [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5F.2 runtime source already present: $rel"
  echo "PHASE5F2_UI_ABSENT=$rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail "VMware workspace index missing"
if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F2_DETAIL_LAYOUT_V1' "$INDEX"; then
  fail "Phase 5F.2 workspace marker already present"
fi

for rel in "${NEW_FILES[@]}"; do
  [[ -f "$REPO_ROOT/layers/phase5f2/$rel" ]] || fail "Phase 5F.2 repository source missing: $rel"
done

echo 'PHASE5F2_UI_BASE_EXACT_OK=1'
echo 'PHASE5F2_UI_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F2_UI_OWNER_APPROVAL'
