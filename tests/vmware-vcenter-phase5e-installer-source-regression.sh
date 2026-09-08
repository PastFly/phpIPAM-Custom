#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bash -n tools/live-vmware-phase5e-ux-preflight.sh
bash -n installer/install-vmware-vcenter-phase5e-ux.sh
bash -n installer/verify-vmware-vcenter-phase5e-ux.sh

check_pin() {
    local rel="$1" expected="$2" actual
    actual="$(git hash-object "layers/phase5e/$rel")"
    [[ "$actual" == "$expected" ]] || {
        echo "Phase 5E source pin mismatch: $rel expected=$expected actual=$actual" >&2
        exit 1
    }
    grep -Fq "[\"$rel\"]=\"$expected\"" installer/install-vmware-vcenter-phase5e-ux.sh || {
        echo "installer pin missing for $rel" >&2; exit 1;
    }
    grep -Fq "[\"$rel\"]=\"$expected\"" installer/verify-vmware-vcenter-phase5e-ux.sh || {
        echo "verifier pin missing for $rel" >&2; exit 1;
    }
}

check_pin app/integrations/index.php b721ae34b3244aa86dbbcd48f3bde584d58365e2
check_pin app/integrations/registry.php 8068e6b8337e926861f65772302742c4d6a6f32a
check_pin app/integrations/vmware/index.php 42429d44ce1eb0495917617fc1c6524ea1f621aa
check_pin app/integrations/vmware/phase5e.css 2916636fc01810981bf0d29b16c7ab1002ebc258
check_pin app/integrations/vmware/workspace-result.php 2f460015d73d427c4d570c3bae36b3b84286b967
check_pin app/integrations/vmware/vm-card-result.php 7bc35cb8db18ccde52c79a4b27a3e0f189a75fd4

grep -Fq 'EXPECTED_INDEX_BLOB="7411f3bebc259c5b0535e0db57055c0b38aff36e"' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'EXPECTED_REGISTRY_BLOB="2a03e1b96f5fc493eeee2adceceb3c717830d50b"' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'verify-vmware-vcenter-phase5d-ui.sh' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'PREFLIGHT_WRITE_OPERATIONS=0' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'PREFLIGHT_VCENTER_REQUESTS=0' tools/live-vmware-phase5e-ux-preflight.sh

grep -Fq 'CREATED.txt' installer/install-vmware-vcenter-phase5e-ux.sh
grep -Fq 'rollback.sh' installer/install-vmware-vcenter-phase5e-ux.sh
grep -Fq 'PHASE5E_UX_ROLLBACK_OK' installer/install-vmware-vcenter-phase5e-ux.sh
grep -Fq 'verify-vmware-vcenter-phase5e-ux.sh' installer/install-vmware-vcenter-phase5e-ux.sh
grep -Fq 'verify-vmware-vcenter-phase5d-ui.sh' installer/verify-vmware-vcenter-phase5e-ux.sh

echo 'PHASE5E_INSTALLER_SOURCE_REGRESSION_OK'
