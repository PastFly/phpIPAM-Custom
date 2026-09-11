#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
command -v git >/dev/null 2>&1 || fail "git not found"

echo "=== PHASE 5E.2 NATIVE IP LINK STYLE LIVE PREFLIGHT ==="
echo "phpIPAM Custom Phase 5E.2 IP-style preflight (READ ONLY)"
echo "target=$TARGET"

# The exact Phase 5E.1 runtime is the immutable baseline for this one-file delta.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5e1-feedback.sh" "$TARGET"

VM_CARD="$TARGET/app/integrations/vmware/vm-card-result.php"
[[ -f "$VM_CARD" ]] || fail "live Phase 5E.1 VM card missing"
ACTUAL="$(git hash-object "$VM_CARD")"
EXPECTED="2d58ab4af53f750d945527e0ac1a404d7973e574"
echo "PHASE5E2_BASE_VM_CARD_BLOB=$ACTUAL"
[[ "$ACTUAL" == "$EXPECTED" ]] || fail "live VM card drift: expected Phase 5E.1 $EXPECTED, got $ACTUAL"

echo "PHASE5E2_BASE_EXACT_OK=1"
echo "PREFLIGHT_WRITE_OPERATIONS=0"
echo "PREFLIGHT_VCENTER_REQUESTS=0"
echo "PREFLIGHT_RESULT=READY_FOR_PHASE5E2_IP_STYLE_OWNER_APPROVAL"
