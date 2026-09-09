#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

echo '=== PHASE 5F.2.3 CHART CONTRAST LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.2.3 preflight (READ ONLY)'
echo "target=$TARGET"

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f22-compact.sh" "$TARGET"

echo 'PHASE5F23_BASE_EXACT_OK=1'

REL='app/integrations/vmware/phase5f23-chart-contrast.css'
if [[ -e "$TARGET/$REL" ]]; then
  fail "Phase 5F.2.3 runtime source already exists: $REL"
fi
echo "PHASE5F23_ABSENT=$REL"

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail "VMware workspace index missing"
if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F23_CHART_CONTRAST_V1' "$INDEX"; then
  fail 'Phase 5F.2.3 workspace marker already exists'
fi

echo 'PHASE5F23_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F23_CHART_OWNER_APPROVAL'
