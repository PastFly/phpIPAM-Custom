#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

printf '%s\n' '=== PHASE 5F.1 OBSERVABILITY UX LIVE PREFLIGHT ==='
printf 'phpIPAM Custom Phase 5F.1 UX preflight (READ ONLY)\ntarget=%s\n' "$TARGET"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Authoritative current live baseline: Phase 5F must already be exact and verified.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f-ui.sh" "$TARGET"

for rel in \
  app/integrations/vmware/phase5f1-ux.js \
  app/integrations/vmware/storage-performance-result.php \
  functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php; do
    if [[ -e "$TARGET/$rel" ]]; then fail "Phase 5F.1 runtime source already present: $rel"; fi
    echo "PHASE5F1_UI_ABSENT=$rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail "VMware workspace index missing"
if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F1_UX_V1' "$INDEX"; then fail "Phase 5F.1 workspace marker already present"; fi

echo 'PHASE5F1_UI_BASE_EXACT_OK=1'
echo 'PHASE5F1_UI_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F1_UI_OWNER_APPROVAL'
