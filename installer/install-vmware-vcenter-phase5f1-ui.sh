#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f1-ui-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.1 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail "Phase 5F.1 installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f1-ui-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/phase5f1-ux.js"]="858e6b4a588002339d93dff434bceca01f7f76a9"
  ["app/integrations/vmware/storage-performance-result.php"]="5190ef408c9f2c91c4b24c0b7f54ea479f90c86b"
  ["functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php"]="3675951e79cfd30b99c15888f15d1270965d5370"
)
for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f1/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.1 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.1 repository source drift: $rel"
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$SRC" "$STAGE/$rel"
done

php -l "$STAGE/app/integrations/vmware/storage-performance-result.php" >/dev/null
php -l "$STAGE/functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$STAGE/app/integrations/vmware/phase5f1-ux.js" >/dev/null; fi

echo 'Phase 5F.1 pre-commit checks: OK'
mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/phase5f1-ux.js" \
  "$TARGET/app/integrations/vmware/storage-performance-result.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php"
echo 'PHASE5F1_UI_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.1 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
  DST="$TARGET/$rel"
  mkdir -p "$(dirname "$DST")"
  TMP="$DST.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$TMP"
  mv -f "$TMP" "$DST"
  [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F.1 blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F1_UX_V1 -->
<script src="app/integrations/vmware/phase5f1-ux.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f1-ui.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F1_UI_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.1 adds only read-only UX automation, optional disk throughput observation and presentation formatting; no schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
