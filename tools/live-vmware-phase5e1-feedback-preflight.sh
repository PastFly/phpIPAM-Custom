#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"

fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

for cmd in git php grep; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

echo "=== PHASE 5E.1 VMWARE UX FEEDBACK LIVE PREFLIGHT ==="
echo "phpIPAM Custom Phase 5E.1 feedback preflight (READ ONLY)"
echo "target=$TARGET"

# The Phase 5D functional backend/fallback must remain exact before a UX delta.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh" "$TARGET"

declare -A EXPECTED_LIVE=(
  ["app/integrations/index.php"]="b721ae34b3244aa86dbbcd48f3bde584d58365e2"
  ["app/integrations/registry.php"]="8068e6b8337e926861f65772302742c4d6a6f32a"
  ["app/integrations/vmware/index.php"]="32d6607e1a8ffcb87949de3b1020dc3e66279598"
  ["app/integrations/vmware/phase5e.css"]="2916636fc01810981bf0d29b16c7ab1002ebc258"
  ["app/integrations/vmware/workspace-result.php"]="2f460015d73d427c4d570c3bae36b3b84286b967"
  ["app/integrations/vmware/vm-card-result.php"]="7bc35cb8db18ccde52c79a4b27a3e0f189a75fd4"
  ["app/sections/menu/menu-tools-admin.php"]="090740810979bceeb3be54c8acf0a5326e9ff67a"
)

for rel in "${!EXPECTED_LIVE[@]}"; do
    file="$TARGET/$rel"
    [[ -f "$file" ]] || fail "required Phase 5E.0 live file missing: $rel"
    actual="$(hash_file "$file")"
    echo "PHASE5E1_BASE_BLOB[$rel]=$actual"
    [[ "$actual" == "${EXPECTED_LIVE[$rel]}" ]] || fail "live file drifted from reviewed Phase 5E.0 baseline: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_V1' "$TARGET/app/integrations/vmware/index.php" || fail "Phase 5E workspace marker missing"
grep -Fq 'phase5e-insecure-ack' "$TARGET/app/integrations/vmware/index.php" || fail "expected Phase 5E.0 TLS acknowledgement UI missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_VM_CARD_RESULT_V1' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "Phase 5E VM card marker missing"

NEW_RESOLVER="$TARGET/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"
[[ ! -e "$NEW_RESOLVER" ]] || fail "Phase 5E.1 IP resolver already exists; use the Phase 5E.1 verifier instead of this first-apply preflight"
echo "PHASE5E1_IP_RESOLVER_ABSENT=1"

echo "PHASE5E1_BASE_EXACT_OK=1"
echo "PREFLIGHT_WRITE_OPERATIONS=0"
echo "PREFLIGHT_VCENTER_REQUESTS=0"
echo "PREFLIGHT_RESULT=READY_FOR_PHASE5E1_FEEDBACK_OWNER_APPROVAL"
