#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
[[ -f "$TARGET/app/integrations/vmware/index.php" ]] || fail "VMware index.php missing"

echo '=== PHASE 5F.3.3 ENRICHED VM SEARCH UI LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.3.3 preflight (READ ONLY)'
echo "target=$TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f32-vm-guest-facts.sh" "$TARGET"
echo 'PHASE5F33_BASE_PHASE5F32_OK=1'

TARGETS=(
  'app/integrations/vmware/enriched-search-result.php'
  'app/integrations/vmware/phase5f33-enriched-search.js'
  'functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php'
  'functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php'
  'functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php'
)
for rel in "${TARGETS[@]}"; do
  if [[ -e "$TARGET/$rel" ]]; then
    fail "Phase 5F.3.3 target already exists before owner approval: $rel"
  fi
  echo "PHASE5F33_ABSENT=$rel"
done

if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F33_ENRICHED_SEARCH_UI_V1' "$TARGET/app/integrations/vmware/index.php"; then
  fail 'Phase 5F.3.3 index hook already exists before owner approval'
fi

echo 'PHASE5F33_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F33_ENRICHED_SEARCH_OWNER_APPROVAL'
