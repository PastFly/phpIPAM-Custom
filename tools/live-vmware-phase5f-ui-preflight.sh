#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

for cmd in git php grep; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

printf '%s\n' '=== PHASE 5F OBSERVABILITY UI LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F host/performance UI preflight (READ ONLY)'
echo "target=$TARGET"

# Exact current live baseline: protected Phase 5D fallback plus accepted Phase 5E.2 UX.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5e2-ip-style.sh" "$TARGET"

BASE_INDEX_BLOB="97aeb732cc0a486729936983427c22a4d032efac"
ACTUAL_INDEX_BLOB="$(hash_file "$TARGET/app/integrations/vmware/index.php")"
echo "PHASE5F_UI_BASE_INDEX_BLOB=$ACTUAL_INDEX_BLOB"
[[ "$ACTUAL_INDEX_BLOB" == "$BASE_INDEX_BLOB" ]] || fail "live VMware workspace index drifted from verified Phase 5E.2 baseline"

declare -a NEW_FILES=(
  "app/integrations/vmware/host-card-result.php"
  "app/integrations/vmware/performance-result.php"
  "app/integrations/vmware/phase5f-observability.js"
  "functions/classes/class.IntegrationVmwareVcenterDirectTransport.php"
  "functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php"
  "functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php"
  "functions/classes/class.IntegrationVmwareVcenterPerformance.php"
)

for rel in "${NEW_FILES[@]}"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5F UI source already present on live: $rel"
    echo "PHASE5F_UI_ABSENT=$rel"
done

if grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F_OBSERVABILITY_UI_V1' "$TARGET/app/integrations/vmware/index.php"; then
    fail "Phase 5F workspace marker is already present"
fi

echo 'PHASE5F_UI_BASE_EXACT_OK=1'
echo 'PHASE5F_UI_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F_UI_OWNER_APPROVAL'
