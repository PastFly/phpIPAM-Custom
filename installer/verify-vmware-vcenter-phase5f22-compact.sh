#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f21-hotfix.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/phase5f22-compact.css"]="64e18426b0f74f99b24a7c3310667309e9a2b4fe"
  ["app/integrations/vmware/phase5f22-compact.js"]="27855f4c708ff49d5e56db490f0a8ad7fea24633"
)
for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.2.2 runtime file missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.2.2 runtime blob mismatch: $rel"
done

if command -v node >/dev/null 2>&1; then node --check "$TARGET/app/integrations/vmware/phase5f22-compact.js" >/dev/null; fi

INDEX="$TARGET/app/integrations/vmware/index.php"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F22_COMPACT_TOOLBAR_V1' "$INDEX" || fail "Phase 5F.2.2 workspace marker missing"
grep -Fq '<link rel="stylesheet" href="app/integrations/vmware/phase5f22-compact.css">' "$INDEX" || fail "Phase 5F.2.2 CSS hook missing"
grep -Fq '<script src="app/integrations/vmware/phase5f22-compact.js"></script>' "$INDEX" || fail "Phase 5F.2.2 JS hook missing"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F22_COMPACT_TOOLBAR_V1' "$INDEX")" == "1" ]] || fail "Phase 5F.2.2 workspace marker count is not exactly one"

grep -Fq '.phase5e-toolbar > .panel > .panel-body' "$TARGET/app/integrations/vmware/phase5f22-compact.css" || fail "compact toolbar spacing rule missing"
grep -Fq '.phase5e-toolbar .form-group' "$TARGET/app/integrations/vmware/phase5f22-compact.css" || fail "compact form-group rule missing"
grep -Fq 'compactTlsIcons' "$TARGET/app/integrations/vmware/phase5f22-compact.js" || fail "TLS icon compaction missing"
grep -Fq 'compactRuntimeSummary' "$TARGET/app/integrations/vmware/phase5f22-compact.js" || fail "runtime summary compaction missing"
grep -Fq 'API=modern · read-only' "$TARGET/app/integrations/vmware/phase5f22-compact.js" || fail "minimal runtime summary marker missing"

echo "Phase 5F.2.2 exact runtime source: OK (${#EXPECTED[@]})"
echo 'Phase 5F.2.2 compact toolbar/TLS presentation invariants: OK'
echo 'PHASE5F22_COMPACT_VERIFY_OK'
