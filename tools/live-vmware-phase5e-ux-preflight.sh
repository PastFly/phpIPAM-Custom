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

echo "=== PHASE 5E VMWARE EXPLORER UX LIVE PREFLIGHT ==="
echo "phpIPAM Custom Phase 5E UX preflight (READ ONLY)"
echo "target=$TARGET"

# Phase 5D must already be the exact verified live foundation.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh" "$TARGET"

INTEGRATIONS_INDEX="$TARGET/app/integrations/index.php"
REGISTRY="$TARGET/app/integrations/registry.php"
[[ -f "$INTEGRATIONS_INDEX" ]] || fail "live Integrations index missing"
[[ -f "$REGISTRY" ]] || fail "live Integrations registry missing"

EXPECTED_INDEX_BLOB="7411f3bebc259c5b0535e0db57055c0b38aff36e"
EXPECTED_REGISTRY_BLOB="2a03e1b96f5fc493eeee2adceceb3c717830d50b"
[[ "$(hash_file "$INTEGRATIONS_INDEX")" == "$EXPECTED_INDEX_BLOB" ]] || fail "live Integrations index drifted from reviewed Phase 4/5D surface"
[[ "$(hash_file "$REGISTRY")" == "$EXPECTED_REGISTRY_BLOB" ]] || fail "live Integrations registry drifted from reviewed Phase 4/5D surface"
echo "PHASE5E_BASE_INTEGRATIONS_EXACT_OK=1"

new_targets=(
  app/integrations/vmware/index.php
  app/integrations/vmware/phase5e.css
  app/integrations/vmware/workspace-result.php
  app/integrations/vmware/vm-card-result.php
)
for rel in "${new_targets[@]}"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5E target already exists: $rel"
    echo "PHASE5E_ABSENT=$rel"
done

echo "PHASE5E_SOURCE_ABSENT=1"
echo "PREFLIGHT_WRITE_OPERATIONS=0"
echo "PREFLIGHT_VCENTER_REQUESTS=0"
echo "PREFLIGHT_RESULT=READY_FOR_PHASE5E_UX_OWNER_APPROVAL"
