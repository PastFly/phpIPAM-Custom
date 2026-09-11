#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f23-chart-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.2.3 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail 'Phase 5F.2.3 installer must run as root'
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f23-chart-preflight.sh" "$TARGET"

REL='app/integrations/vmware/phase5f23-chart-contrast.css'
EXPECTED='a4a7f5b7d256c75b5a1f395f8c718e55cba5a46c'
SRC="$REPO_ROOT/layers/phase5f23/$REL"
[[ -f "$SRC" ]] || fail "Phase 5F.2.3 repository source missing: $REL"
[[ "$(hash_file "$SRC")" == "$EXPECTED" ]] || fail "Phase 5F.2.3 repository source drift: $REL"
mkdir -p "$STAGE/$(dirname "$REL")"
cp "$SRC" "$STAGE/$REL"

grep -Fq 'background: #ffffff;' "$STAGE/$REL" || fail 'candidate white chart background missing'
grep -Fq '#vmware-phase5e .phase5f-chart svg polyline' "$STAGE/$REL" || fail 'candidate series preservation rule missing'
echo 'Phase 5F.2.3 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f "$TARGET/app/integrations/vmware/phase5f23-chart-contrast.css"
echo 'PHASE5F23_CHART_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.2.3 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
DST="$TARGET/$REL"
mkdir -p "$(dirname "$DST")"
TMP="$DST.phpipam-custom-tmp-$$"
cp "$STAGE/$REL" "$TMP"
mv -f "$TMP" "$DST"
[[ "$(hash_file "$DST")" == "$EXPECTED" ]] || fail "post-deploy Phase 5F.2.3 blob mismatch: $REL"

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F23_CHART_CONTRAST_V1 -->
<link rel="stylesheet" href="app/integrations/vmware/phase5f23-chart-contrast.css">
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f23-chart.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F23_CHART_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.2.3 changes chart presentation only: white chart surfaces, neutral grid and dark axis labels. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
