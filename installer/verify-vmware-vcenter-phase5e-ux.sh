#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"

# Phase 5E.1 is the current final UX surface for this stacked PR. Reuse the
# stricter exact-source/security verifier so a clean Phase 5E install and the
# incremental live 5E.0 -> 5E.1 updater converge on one verified runtime state.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5e1-feedback.sh" "$TARGET"

echo "PHASE5E_UX_VERIFY_OK"
