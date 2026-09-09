#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f26-folder-state-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == '1' && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.2.6 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail 'Phase 5F.2.6 installer must run as root'
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f26-folder-state-preflight.sh" "$TARGET"

REL='app/integrations/vmware/phase5f26-folder-state.js'
SRC="$REPO_ROOT/layers/phase5f26/$REL"
[[ -f "$SRC" ]] || fail "Phase 5F.2.6 repository source missing: $REL"
[[ "$(hash_file "$SRC")" == 'a656f9aa3e8d3f3cd7601a8d9190ec4c09a6644f' ]] || fail 'Phase 5F.2.6 repository source drift'
mkdir -p "$STAGE/$(dirname "$REL")"
cp "$SRC" "$STAGE/$REL"
if command -v node >/dev/null 2>&1; then node --check "$STAGE/$REL" >/dev/null; fi

echo 'Phase 5F.2.6 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f "$TARGET/app/integrations/vmware/phase5f26-folder-state.js"
echo 'PHASE5F26_FOLDER_STATE_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.2.6 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
DST="$TARGET/$REL"
TMP="$DST.phpipam-custom-tmp-$$"
cp "$STAGE/$REL" "$TMP"
mv -f "$TMP" "$DST"
[[ "$(hash_file "$DST")" == 'a656f9aa3e8d3f3cd7601a8d9190ec4c09a6644f' ]] || fail 'post-deploy Phase 5F.2.6 JS blob mismatch'

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F26_FOLDER_STATE_V1 -->
<script src="app/integrations/vmware/phase5f26-folder-state.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f26-folder-state.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F26_FOLDER_STATE_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.2.6 persists VMware Folder collapse state only in browser localStorage, namespaced per connector. No schema, DB preference, connector, credential, scheduler, phpIPAM object or vCenter mutation was made.'
