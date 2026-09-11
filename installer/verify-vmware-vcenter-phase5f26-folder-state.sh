#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f25-folders.sh" "$TARGET"

JS="$TARGET/app/integrations/vmware/phase5f26-folder-state.js"
INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$JS" && -f "$INDEX" ]] || fail 'Phase 5F.2.6 runtime file missing'
[[ "$(hash_file "$JS")" == 'a656f9aa3e8d3f3cd7601a8d9190ec4c09a6644f' ]] || fail 'Phase 5F.2.6 JS blob mismatch'

if command -v node >/dev/null 2>&1; then node --check "$JS" >/dev/null; fi

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F26_FOLDER_STATE_V1' "$INDEX" || fail 'Phase 5F.2.6 workspace marker missing'
grep -Fq '<script src="app/integrations/vmware/phase5f26-folder-state.js"></script>' "$INDEX" || fail 'Phase 5F.2.6 JS hook missing'
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F26_FOLDER_STATE_V1' "$INDEX")" == '1' ]] || fail 'Phase 5F.2.6 workspace marker count is not exactly one'

grep -Fq 'window.localStorage' "$JS" || fail 'Phase 5F.2.6 localStorage persistence missing'
grep -Fq 'phpipam.vmware.folder-state.v1:' "$JS" || fail 'Phase 5F.2.6 versioned storage namespace missing'
grep -Fq 'phase5f25-folder-node' "$JS" || fail 'Phase 5F.2.6 Folder toggle listener missing'
grep -Fq 'MutationObserver' "$JS" || fail 'Phase 5F.2.6 Folder restore observer missing'
grep -Fq 'MAX_FOLDER_IDS = 512' "$JS" || fail 'Phase 5F.2.6 storage bound missing'
! grep -Fq '$.ajax(' "$JS" || fail 'Phase 5F.2.6 unexpectedly adds server requests'

echo 'Phase 5F.2.6 browser-local Folder state invariants: OK'
echo 'PHASE5F26_FOLDER_STATE_VERIFY_OK'
