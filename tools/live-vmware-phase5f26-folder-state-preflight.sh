#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
[[ -f "$TARGET/config.php" ]] || fail "target does not look like phpIPAM: $TARGET"

echo '=== PHASE 5F.2.6 VMWARE FOLDER STATE LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.2.6 preflight (READ ONLY)'
echo "target=$TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f25-folders.sh" "$TARGET"

REL='app/integrations/vmware/phase5f26-folder-state.js'
SRC="$REPO_ROOT/layers/phase5f26/$REL"
[[ -f "$SRC" ]] || fail "Phase 5F.2.6 repository source missing: $REL"
[[ "$(hash_file "$SRC")" == 'a656f9aa3e8d3f3cd7601a8d9190ec4c09a6644f' ]] || fail "Phase 5F.2.6 repository source drift: $REL"
[[ ! -e "$TARGET/$REL" ]] || fail "Phase 5F.2.6 target already exists before owner approval: $REL"

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail 'VMware workspace index missing'
! grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F26_FOLDER_STATE_V1' "$INDEX" || fail 'Phase 5F.2.6 workspace hook already present'

if command -v node >/dev/null 2>&1; then node --check "$SRC" >/dev/null; fi
grep -Fq "window.localStorage" "$SRC" || fail 'browser localStorage persistence missing'
grep -Fq "phpipam.vmware.folder-state.v1:" "$SRC" || fail 'versioned storage namespace missing'
grep -Fq "configId()" "$SRC" || fail 'connector-specific state namespace missing'
grep -Fq "phase5f25-folder-node" "$SRC" || fail 'Folder toggle integration missing'
grep -Fq "MutationObserver" "$SRC" || fail 'Folder DOM re-projection restore hook missing'
! grep -Fq '$.ajax(' "$SRC" || fail 'Phase 5F.2.6 must not add server requests'

echo 'PHASE5F26_BASE_PHASE5F25_OK=1'
echo 'PHASE5F26_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F26_FOLDER_STATE_OWNER_APPROVAL'
