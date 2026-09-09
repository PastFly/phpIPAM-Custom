#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

echo '=== PHASE 5F.2.1 LAYOUT / TLS HOTFIX LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.2.1 preflight (READ ONLY)'
echo "target=$TARGET"

# The broken-but-known Phase 5F.2 runtime is the exact parent baseline.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f2-ui.sh" "$TARGET"

for rel in \
  "app/integrations/vmware/phase5f21-hotfix.css" \
  "app/integrations/vmware/phase5f21-hotfix.js"; do
  if [[ -e "$TARGET/$rel" ]]; then fail "Phase 5F.2.1 source already present: $rel"; fi
  echo "PHASE5F21_ABSENT=$rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail "live VMware workspace index missing"
if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F21_LAYOUT_TLS_HOTFIX_V1' "$INDEX"; then
  fail 'Phase 5F.2.1 workspace marker already present'
fi

echo 'PHASE5F21_BASE_EXACT_OK=1'
echo 'PHASE5F21_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F21_HOTFIX_OWNER_APPROVAL'
