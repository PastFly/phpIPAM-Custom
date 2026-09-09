#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f22-compact.sh" "$TARGET"

REL='app/integrations/vmware/phase5f23-chart-contrast.css'
EXPECTED='a4a7f5b7d256c75b5a1f395f8c718e55cba5a46c'
[[ -f "$TARGET/$REL" ]] || fail "Phase 5F.2.3 runtime file missing: $REL"
[[ "$(hash_file "$TARGET/$REL")" == "$EXPECTED" ]] || fail "Phase 5F.2.3 runtime blob mismatch: $REL"

INDEX="$TARGET/app/integrations/vmware/index.php"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F23_CHART_CONTRAST_V1' "$INDEX" || fail 'Phase 5F.2.3 workspace marker missing'
grep -Fq '<link rel="stylesheet" href="app/integrations/vmware/phase5f23-chart-contrast.css">' "$INDEX" || fail 'Phase 5F.2.3 CSS hook missing'
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F23_CHART_CONTRAST_V1' "$INDEX")" == "1" ]] || fail 'Phase 5F.2.3 workspace marker count is not exactly one'

grep -Fq 'background: #ffffff;' "$TARGET/$REL" || fail 'white chart background rule missing'
grep -Fq 'fill: #333333 !important;' "$TARGET/$REL" || fail 'dark chart label rule missing'
grep -Fq 'stroke: #d8d8d8 !important;' "$TARGET/$REL" || fail 'light chart grid rule missing'
grep -Fq '#vmware-phase5e .phase5f-chart svg polyline' "$TARGET/$REL" || fail 'metric series preservation rule missing'

echo 'Phase 5F.2.3 exact runtime source: OK (1)'
echo 'Phase 5F.2.3 chart contrast presentation invariants: OK'
echo 'PHASE5F23_CHART_VERIFY_OK'
