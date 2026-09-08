#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

scripts=(
  tools/live-vmware-phase5e-ux-preflight.sh
  tools/live-vmware-phase5e1-feedback-preflight.sh
  installer/install-vmware-vcenter-phase5e-ux.sh
  installer/install-vmware-vcenter-phase5e1-feedback.sh
  installer/verify-vmware-vcenter-phase5e-ux.sh
  installer/verify-vmware-vcenter-phase5e1-feedback.sh
)
for script in "${scripts[@]}"; do bash -n "$script"; done

check_clean_pin() {
    local source="$1" rel="$2" expected="$3" actual
    actual="$(git hash-object "$source")"
    [[ "$actual" == "$expected" ]] || {
        echo "Phase 5E source pin mismatch: $rel expected=$expected actual=$actual source=$source" >&2
        exit 1
    }
    grep -Fq "[\"$rel\"]=\"$expected\"" installer/install-vmware-vcenter-phase5e-ux.sh || {
        echo "clean installer pin missing for $rel" >&2; exit 1;
    }
    grep -Fq "[\"$rel\"]=\"$expected\"" installer/verify-vmware-vcenter-phase5e1-feedback.sh || {
        echo "final verifier pin missing for $rel" >&2; exit 1;
    }
}

check_clean_pin layers/phase5e/app/integrations/index.php app/integrations/index.php b721ae34b3244aa86dbbcd48f3bde584d58365e2
check_clean_pin layers/phase5e/app/integrations/registry.php app/integrations/registry.php 8068e6b8337e926861f65772302742c4d6a6f32a
check_clean_pin layers/phase5e/app/integrations/vmware/index.php app/integrations/vmware/index.php 97aeb732cc0a486729936983427c22a4d032efac
check_clean_pin layers/phase5e/app/integrations/vmware/phase5e.css app/integrations/vmware/phase5e.css 2916636fc01810981bf0d29b16c7ab1002ebc258
check_clean_pin layers/phase5e/app/integrations/vmware/workspace-result.php app/integrations/vmware/workspace-result.php 408fed89e2b0583013325b08e2959813458c76fe
check_clean_pin layers/phase5e/app/integrations/vmware/vm-card-result.php app/integrations/vmware/vm-card-result.php 2d58ab4af53f750d945527e0ac1a404d7973e574
check_clean_pin layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php d26f9ad06ea4136c487b229e004b9a8c6521b863
check_clean_pin layers/phase5e1/app/sections/menu/menu-tools-admin.php app/sections/menu/menu-tools-admin.php c778652ed50fdbd7d5855d4c37d20c2992545c82

# The incremental updater must pin every file it changes/adds on the already
# deployed Phase 5E.0 runtime.
for spec in \
  'app/integrations/vmware/index.php=97aeb732cc0a486729936983427c22a4d032efac' \
  'app/integrations/vmware/workspace-result.php=408fed89e2b0583013325b08e2959813458c76fe' \
  'app/integrations/vmware/vm-card-result.php=2d58ab4af53f750d945527e0ac1a404d7973e574' \
  'functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php=d26f9ad06ea4136c487b229e004b9a8c6521b863' \
  'app/sections/menu/menu-tools-admin.php=c778652ed50fdbd7d5855d4c37d20c2992545c82'; do
    rel="${spec%%=*}"; expected="${spec#*=}"
    grep -Fq "[\"$rel\"]=\"$expected\"" installer/install-vmware-vcenter-phase5e1-feedback.sh || {
        echo "Phase 5E.1 incremental installer pin missing for $rel" >&2; exit 1;
    }
done

grep -Fq 'EXPECTED_INDEX_BLOB="7411f3bebc259c5b0535e0db57055c0b38aff36e"' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'EXPECTED_REGISTRY_BLOB="45f841df2e052a92da44b6e3deb2911844f4d816"' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'EXPECTED_GLOBAL_MENU_BLOB="090740810979bceeb3be54c8acf0a5326e9ff67a"' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_VCENTER_PHASE5A_V1' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'PREFLIGHT_WRITE_OPERATIONS=0' tools/live-vmware-phase5e-ux-preflight.sh
grep -Fq 'PREFLIGHT_VCENTER_REQUESTS=0' tools/live-vmware-phase5e-ux-preflight.sh

grep -Fq 'PHASE5E1_BASE_EXACT_OK=1' tools/live-vmware-phase5e1-feedback-preflight.sh
grep -Fq 'PREFLIGHT_WRITE_OPERATIONS=0' tools/live-vmware-phase5e1-feedback-preflight.sh
grep -Fq 'PREFLIGHT_VCENTER_REQUESTS=0' tools/live-vmware-phase5e1-feedback-preflight.sh
grep -Fq 'PHASE5E1_IP_RESOLVER_ABSENT=1' tools/live-vmware-phase5e1-feedback-preflight.sh

for installer in installer/install-vmware-vcenter-phase5e-ux.sh installer/install-vmware-vcenter-phase5e1-feedback.sh; do
  grep -Fq 'CREATED.txt' "$installer"
  grep -Fq 'rollback.sh' "$installer"
  grep -Fq 'COMMIT_STARTED=1' "$installer"
done

grep -Fq 'PHASE5E_UX_ROLLBACK_OK' installer/install-vmware-vcenter-phase5e-ux.sh
grep -Fq 'PHASE5E1_FEEDBACK_ROLLBACK_OK' installer/install-vmware-vcenter-phase5e1-feedback.sh
grep -Fq 'verify-vmware-vcenter-phase5e1-feedback.sh' installer/verify-vmware-vcenter-phase5e-ux.sh
grep -Fq 'verify-vmware-vcenter-phase5d-ui.sh' installer/verify-vmware-vcenter-phase5e1-feedback.sh

echo 'PHASE5E_INSTALLER_SOURCE_REGRESSION_OK'
