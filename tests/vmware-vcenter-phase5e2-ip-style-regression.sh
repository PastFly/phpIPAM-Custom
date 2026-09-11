#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bash -n tools/live-vmware-phase5e2-ip-style-preflight.sh
bash -n installer/install-vmware-vcenter-phase5e2-ip-style.sh
bash -n installer/verify-vmware-vcenter-phase5e2-ip-style.sh
php -l layers/phase5e2/app/integrations/vmware/vm-card-result.php >/dev/null

BASE_BLOB="$(git hash-object layers/phase5e/app/integrations/vmware/vm-card-result.php)"
FINAL_BLOB="$(git hash-object layers/phase5e2/app/integrations/vmware/vm-card-result.php)"
[[ "$BASE_BLOB" == "2d58ab4af53f750d945527e0ac1a404d7973e574" ]] || { echo "Phase 5E.1 source layer drift: $BASE_BLOB" >&2; exit 1; }
[[ "$FINAL_BLOB" == "d1a6f3fba93d35054af7d6e05455b557ee5dbd18" ]] || { echo "Phase 5E.2 source pin mismatch: $FINAL_BLOB" >&2; exit 1; }

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E2_NATIVE_IP_LINK_STYLE_V1' layers/phase5e2/app/integrations/vmware/vm-card-result.php
grep -Fq 'class="phase5e-ip-link"' layers/phase5e2/app/integrations/vmware/vm-card-result.php
grep -Fq 'class="phase5e-ip-text"' layers/phase5e2/app/integrations/vmware/vm-card-result.php
if grep -Fq 'title="Открыть адрес в phpIPAM"><code>' layers/phase5e2/app/integrations/vmware/vm-card-result.php; then
    echo "Legacy code-styled clickable IP remains" >&2
    exit 1
fi

grep -Fq 'EXPECTED="2d58ab4af53f750d945527e0ac1a404d7973e574"' tools/live-vmware-phase5e2-ip-style-preflight.sh
grep -Fq 'EXPECTED_SOURCE="d1a6f3fba93d35054af7d6e05455b557ee5dbd18"' installer/install-vmware-vcenter-phase5e2-ip-style.sh
grep -Fq '["app/integrations/vmware/vm-card-result.php"]="d1a6f3fba93d35054af7d6e05455b557ee5dbd18"' installer/verify-vmware-vcenter-phase5e2-ip-style.sh

echo 'PHASE5E2_IP_STYLE_REGRESSION_OK'
