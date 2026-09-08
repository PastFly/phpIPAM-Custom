#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

for cmd in git php grep; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Phase 5D remains the protected backend/fallback.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/index.php"]="b721ae34b3244aa86dbbcd48f3bde584d58365e2"
  ["app/integrations/registry.php"]="8068e6b8337e926861f65772302742c4d6a6f32a"
  ["app/integrations/vmware/index.php"]="97aeb732cc0a486729936983427c22a4d032efac"
  ["app/integrations/vmware/phase5e.css"]="2916636fc01810981bf0d29b16c7ab1002ebc258"
  ["app/integrations/vmware/workspace-result.php"]="408fed89e2b0583013325b08e2959813458c76fe"
  ["app/integrations/vmware/vm-card-result.php"]="d1a6f3fba93d35054af7d6e05455b557ee5dbd18"
  ["functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"]="d26f9ad06ea4136c487b229e004b9a8c6521b863"
  ["app/sections/menu/menu-tools-admin.php"]="c778652ed50fdbd7d5855d4c37d20c2992545c82"
)

for rel in "${!EXPECTED[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "Phase 5E.2 runtime file missing: $rel"
    [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5E.2 runtime blob mismatch: $rel"
done

echo "Phase 5E.2 exact source: OK (${#EXPECTED[@]})"
php -l "$TARGET/app/integrations/vmware/vm-card-result.php" >/dev/null || fail "Phase 5E.2 VM card PHP lint failed"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E2_NATIVE_IP_LINK_STYLE_V1' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "Phase 5E.2 marker missing"
grep -Fq 'class="phase5e-ip-link"' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "native IP link class missing"
grep -Fq 'class="phase5e-ip-text"' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "plain unresolved IP text class missing"
if grep -Fq 'title="Открыть адрес в phpIPAM"><code>' "$TARGET/app/integrations/vmware/vm-card-result.php"; then
    fail "legacy code-styled phpIPAM IP link remains"
fi
if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$TARGET/app/integrations/vmware/vm-card-result.php"; then
    fail "unexpected phpIPAM write API in Phase 5E.2 VM card"
fi

echo "Phase 5E.2 native IP-link UX/security invariants: OK"
echo "PHASE5E2_IP_STYLE_VERIFY_OK"
