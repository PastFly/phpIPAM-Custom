#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f22-compact-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.2.2 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail "Phase 5F.2.2 installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f22-compact-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/phase5f22-compact.css"]="64e18426b0f74f99b24a7c3310667309e9a2b4fe"
  ["app/integrations/vmware/phase5f22-compact.js"]="27855f4c708ff49d5e56db490f0a8ad7fea24633"
)
for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f22/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.2.2 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.2.2 repository source drift: $rel"
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$SRC" "$STAGE/$rel"
done
if command -v node >/dev/null 2>&1; then node --check "$STAGE/app/integrations/vmware/phase5f22-compact.js" >/dev/null; fi

grep -Fq '.phase5e-toolbar > .panel > .panel-body' "$STAGE/app/integrations/vmware/phase5f22-compact.css" || fail "candidate compact toolbar CSS missing"
grep -Fq 'compactRuntimeSummary' "$STAGE/app/integrations/vmware/phase5f22-compact.js" || fail "candidate compact summary JS missing"
echo 'Phase 5F.2.2 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/phase5f22-compact.css" \
  "$TARGET/app/integrations/vmware/phase5f22-compact.js"
echo 'PHASE5F22_COMPACT_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.2.2 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
  DST="$TARGET/$rel"
  mkdir -p "$(dirname "$DST")"
  TMP="$DST.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$TMP"
  mv -f "$TMP" "$DST"
  [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F.2.2 blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F22_COMPACT_TOOLBAR_V1 -->
<link rel="stylesheet" href="app/integrations/vmware/phase5f22-compact.css">
<script src="app/integrations/vmware/phase5f22-compact.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f22-compact.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F22_COMPACT_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.2.2 changes presentation only: toolbar spacing and TLS/status indicators are compacted. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
