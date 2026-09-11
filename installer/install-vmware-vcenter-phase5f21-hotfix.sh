#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f21-hotfix-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.2.1 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail "Phase 5F.2.1 installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f21-hotfix-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/phase5f21-hotfix.css"]="e5a408bfed7799c96753ef48183a5c99b62d7d42"
  ["app/integrations/vmware/phase5f21-hotfix.js"]="f4a43ace1a61d5a59af12ed0d3d4830c87f7a6b6"
)
for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f21/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.2.1 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.2.1 repository source drift: $rel"
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$SRC" "$STAGE/$rel"
done
if command -v node >/dev/null 2>&1; then node --check "$STAGE/app/integrations/vmware/phase5f21-hotfix.js" >/dev/null; fi

grep -Fq '.phase5e-workspace-row.phase5f2-resizable::before' "$STAGE/app/integrations/vmware/phase5f21-hotfix.css" || fail "candidate Bootstrap grid hotfix missing"
grep -Fq 'content: none !important' "$STAGE/app/integrations/vmware/phase5f21-hotfix.css" || fail "candidate clearfix suppression missing"
grep -Fq 'compactDetailTlsWarnings' "$STAGE/app/integrations/vmware/phase5f21-hotfix.js" || fail "candidate compact TLS behavior missing"
echo 'Phase 5F.2.1 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/phase5f21-hotfix.css" \
  "$TARGET/app/integrations/vmware/phase5f21-hotfix.js"
echo 'PHASE5F21_HOTFIX_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.2.1 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
  DST="$TARGET/$rel"
  mkdir -p "$(dirname "$DST")"
  TMP="$DST.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$TMP"
  mv -f "$TMP" "$DST"
  [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F.2.1 blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F21_LAYOUT_TLS_HOTFIX_V1 -->
<link rel="stylesheet" href="app/integrations/vmware/phase5f21-hotfix.css">
<script src="app/integrations/vmware/phase5f21-hotfix.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f21-hotfix.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F21_HOTFIX_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.2.1 changes presentation only: Bootstrap grid pseudo-elements are neutralized for the VMware split view and TLS warnings are compacted. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
