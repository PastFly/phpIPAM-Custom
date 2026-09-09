#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

echo '=== PHASE 5F.2.4 VM CARD STABILITY LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.2.4 preflight (READ ONLY)'
echo "target=$TARGET"

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f23-chart.sh" "$TARGET"

echo 'PHASE5F24_BASE_PHASE5F23_OK=1'

VMCARD="$TARGET/app/integrations/vmware/vm-card-result.php"
[[ -f "$VMCARD" ]] || fail 'live VM-card endpoint missing'
EXPECTED_VMCARD='d1a6f3fba93d35054af7d6e05455b557ee5dbd18'
ACTUAL_VMCARD="$(hash_file "$VMCARD")"
[[ "$ACTUAL_VMCARD" == "$EXPECTED_VMCARD" ]] || fail "live VM-card endpoint drifted from reviewed Phase 5E.2 baseline: $ACTUAL_VMCARD"
echo 'PHASE5F24_VMCARD_BASE_EXACT_OK=1'

for rel in \
  app/integrations/vmware/phase5f24-stability.js \
  app/integrations/vmware/phase5f24-stability.css; do
  [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5F.2.4 runtime source already exists: $rel"
  echo "PHASE5F24_ABSENT=$rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail 'VMware workspace index missing'
! grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F24_STABILITY_V1' "$INDEX" || fail 'Phase 5F.2.4 workspace marker already exists'
! grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F24_OPTIONAL_GUEST_NETWORK_V1' "$VMCARD" || fail 'Phase 5F.2.4 VM-card marker already exists'

echo 'PHASE5F24_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F24_STABILITY_OWNER_APPROVAL'
