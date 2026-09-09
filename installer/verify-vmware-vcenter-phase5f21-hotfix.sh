#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
# Phase 5F.2 remains the protected parent baseline.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f2-ui.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/phase5f21-hotfix.css"]="e5a408bfed7799c96753ef48183a5c99b62d7d42"
  ["app/integrations/vmware/phase5f21-hotfix.js"]="f4a43ace1a61d5a59af12ed0d3d4830c87f7a6b6"
)
for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.2.1 runtime file missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.2.1 runtime blob mismatch: $rel"
done

if command -v node >/dev/null 2>&1; then node --check "$TARGET/app/integrations/vmware/phase5f21-hotfix.js" >/dev/null; fi

INDEX="$TARGET/app/integrations/vmware/index.php"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F21_LAYOUT_TLS_HOTFIX_V1' "$INDEX" || fail "Phase 5F.2.1 workspace marker missing"
grep -Fq '<link rel="stylesheet" href="app/integrations/vmware/phase5f21-hotfix.css">' "$INDEX" || fail "Phase 5F.2.1 CSS hook missing"
grep -Fq '<script src="app/integrations/vmware/phase5f21-hotfix.js"></script>' "$INDEX" || fail "Phase 5F.2.1 JS hook missing"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F21_LAYOUT_TLS_HOTFIX_V1' "$INDEX")" == "1" ]] || fail "Phase 5F.2.1 workspace marker count is not exactly one"

grep -Fq '.phase5e-workspace-row.phase5f2-resizable::before' "$TARGET/app/integrations/vmware/phase5f21-hotfix.css" || fail "Bootstrap row pseudo-element hotfix missing"
grep -Fq 'content: none !important' "$TARGET/app/integrations/vmware/phase5f21-hotfix.css" || fail "Bootstrap clearfix suppression missing"
grep -Fq 'phase5f21-pane-left' "$TARGET/app/integrations/vmware/phase5f21-hotfix.js" || fail "explicit pane role assignment missing"
grep -Fq 'phase5f21-pane-center' "$TARGET/app/integrations/vmware/phase5f21-hotfix.js" || fail "center pane role assignment missing"
grep -Fq 'phase5f21-pane-right' "$TARGET/app/integrations/vmware/phase5f21-hotfix.js" || fail "right pane role assignment missing"
grep -Fq '#phase5e-insecure-row' "$TARGET/app/integrations/vmware/phase5f21-hotfix.css" || fail "full TLS warning suppression missing"
grep -Fq 'TLS INSECURE' "$TARGET/app/integrations/vmware/phase5f21-hotfix.js" || fail "compact TLS indicator missing"
grep -Fq 'compactDetailTlsWarnings' "$TARGET/app/integrations/vmware/phase5f21-hotfix.js" || fail "detail TLS compaction missing"

echo "Phase 5F.2.1 exact runtime source: OK (${#EXPECTED[@]})"
echo 'Phase 5F.2.1 layout/TLS presentation invariants: OK'
echo 'PHASE5F21_HOTFIX_VERIFY_OK'
