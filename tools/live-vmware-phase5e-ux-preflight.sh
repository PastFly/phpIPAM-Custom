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

# The top-level Integrations index is still the reviewed Phase 4 surface.
# The registry was intentionally replaced by Phase 5A and remains the live
# metadata registry through Phase 5B/5C/5D. Do not compare it to the older
# pre-Phase-5A Phase 4 blob.
EXPECTED_INDEX_BLOB="7411f3bebc259c5b0535e0db57055c0b38aff36e"
EXPECTED_REGISTRY_BLOB="45f841df2e052a92da44b6e3deb2911844f4d816"
ACTUAL_INDEX_BLOB="$(hash_file "$INTEGRATIONS_INDEX")"
ACTUAL_REGISTRY_BLOB="$(hash_file "$REGISTRY")"

echo "PHASE5E_BASE_INTEGRATIONS_INDEX_BLOB=$ACTUAL_INDEX_BLOB"
echo "PHASE5E_BASE_INTEGRATIONS_REGISTRY_BLOB=$ACTUAL_REGISTRY_BLOB"

[[ "$ACTUAL_INDEX_BLOB" == "$EXPECTED_INDEX_BLOB" ]] || fail "live Integrations index drift: expected $EXPECTED_INDEX_BLOB, got $ACTUAL_INDEX_BLOB"
[[ "$ACTUAL_REGISTRY_BLOB" == "$EXPECTED_REGISTRY_BLOB" ]] || fail "live Integrations registry drift: expected Phase 5A registry $EXPECTED_REGISTRY_BLOB, got $ACTUAL_REGISTRY_BLOB"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_VCENTER_PHASE5A_V1' "$REGISTRY" || fail "live Integrations registry is missing the reviewed Phase 5A VMware marker"
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
