#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

echo '=== PHASE 5F.2.2 COMPACT TOOLBAR LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.2.2 preflight (READ ONLY)'
echo "target=$TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f21-hotfix.sh" "$TARGET"

for rel in \
  "app/integrations/vmware/phase5f22-compact.css" \
  "app/integrations/vmware/phase5f22-compact.js"; do
  if [[ -e "$TARGET/$rel" ]]; then fail "Phase 5F.2.2 source already present: $rel"; fi
  echo "PHASE5F22_ABSENT=$rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail "live VMware workspace index missing"
if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F22_COMPACT_TOOLBAR_V1' "$INDEX"; then
  fail 'Phase 5F.2.2 workspace marker already present'
fi

echo 'PHASE5F22_BASE_EXACT_OK=1'
echo 'PHASE5F22_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F22_COMPACT_OWNER_APPROVAL'
