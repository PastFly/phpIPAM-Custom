#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

for cmd in git php grep; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Phase 5D remains the authoritative read-only backend/fallback.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/index.php"]="b721ae34b3244aa86dbbcd48f3bde584d58365e2"
  ["app/integrations/registry.php"]="8068e6b8337e926861f65772302742c4d6a6f32a"
  ["app/integrations/vmware/index.php"]="42429d44ce1eb0495917617fc1c6524ea1f621aa"
  ["app/integrations/vmware/phase5e.css"]="2916636fc01810981bf0d29b16c7ab1002ebc258"
  ["app/integrations/vmware/workspace-result.php"]="2f460015d73d427c4d570c3bae36b3b84286b967"
  ["app/integrations/vmware/vm-card-result.php"]="7bc35cb8db18ccde52c79a4b27a3e0f189a75fd4"
)

for rel in "${!EXPECTED[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "Phase 5E runtime file missing: $rel"
    [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5E runtime blob mismatch: $rel"
done

echo "Phase 5E exact source: OK (${#EXPECTED[@]})"

for rel in app/integrations/index.php app/integrations/registry.php app/integrations/vmware/index.php app/integrations/vmware/workspace-result.php app/integrations/vmware/vm-card-result.php; do
    php -l "$TARGET/$rel" >/dev/null || fail "Phase 5E PHP lint failed: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_PAGE_PHASE5E_V1' "$TARGET/app/integrations/index.php" || fail "Phase 5E Integrations dispatcher marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_V1' "$TARGET/app/integrations/vmware/index.php" || fail "Phase 5E workspace marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_RESULT_V1' "$TARGET/app/integrations/vmware/workspace-result.php" || fail "Phase 5E workspace endpoint marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_VM_CARD_RESULT_V1' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "Phase 5E VM card marker missing"
grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" "$TARGET/app/integrations/vmware/workspace-result.php" || fail "Phase 5E workspace CSRF validation missing"
grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "Phase 5E VM card CSRF validation missing"
grep -Fq 'phase5e-vm-link' "$TARGET/app/integrations/vmware/workspace-result.php" || fail "Phase 5E clickable VM navigation missing"
grep -Fq 'phase5e-scope-link' "$TARGET/app/integrations/vmware/workspace-result.php" || fail "Phase 5E clickable scope navigation missing"
grep -Fq 'correlation-only, not native VMware stable-ID authority' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "Phase 5E datastore authority disclaimer missing"

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$TARGET/app/integrations/vmware/workspace-result.php" "$TARGET/app/integrations/vmware/vm-card-result.php"; then
    fail "unexpected phpIPAM write API in Phase 5E runtime"
fi
if grep -Eq "'PUT'|'PATCH'|action=(attach|detach)" "$TARGET/app/integrations/vmware/workspace-result.php" "$TARGET/app/integrations/vmware/vm-card-result.php"; then
    fail "unexpected VMware mutation surface in Phase 5E runtime"
fi
if grep -Eqi '(^|[;{[:space:]])(color|background|background-color)[[:space:]]*:' "$TARGET/app/integrations/vmware/phase5e.css"; then
    fail "Phase 5E CSS hardcodes theme foreground/background"
fi
if grep -Eq '#[0-9A-Fa-f]{3,8}([^0-9A-Za-z_-]|$)' "$TARGET/app/integrations/vmware/phase5e.css"; then
    fail "Phase 5E CSS hardcodes hex theme colors"
fi

echo "Phase 5E UX/security invariants: OK"
echo "PHASE5E_UX_VERIFY_OK"
