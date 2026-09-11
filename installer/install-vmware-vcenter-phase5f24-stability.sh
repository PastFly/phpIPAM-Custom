#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f24-stability-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.2.4 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail 'Phase 5F.2.4 installer must run as root'
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f24-stability-preflight.sh" "$TARGET"

JS_REL='app/integrations/vmware/phase5f24-stability.js'
CSS_REL='app/integrations/vmware/phase5f24-stability.css'
JS_EXPECTED='92b39cf47234300d58ec4ced1af05c8150eb0529'
CSS_EXPECTED='ba06c5c25be02148c25f22dedb0c67e04c820633'

for rel in "$JS_REL" "$CSS_REL"; do
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$REPO_ROOT/layers/phase5f24/$rel" "$STAGE/$rel"
done
[[ "$(hash_file "$STAGE/$JS_REL")" == "$JS_EXPECTED" ]] || fail 'repository Phase 5F.2.4 JS drift'
[[ "$(hash_file "$STAGE/$CSS_REL")" == "$CSS_EXPECTED" ]] || fail 'repository Phase 5F.2.4 CSS drift'

VMCARD_REL='app/integrations/vmware/vm-card-result.php'
mkdir -p "$STAGE/$(dirname "$VMCARD_REL")"
cp "$TARGET/$VMCARD_REL" "$STAGE/$VMCARD_REL"
php "$REPO_ROOT/tools/phase5f24-patch-vmcard.php" "$STAGE/$VMCARD_REL"
php -l "$STAGE/$VMCARD_REL" >/dev/null
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F24_OPTIONAL_GUEST_NETWORK_V1' "$STAGE/$VMCARD_REL" || fail 'staged VM-card optional marker missing'

grep -Fq 'vmCardSequence' "$STAGE/$JS_REL" || fail 'candidate stale-request protection missing'
grep -Fq 'phase5f24-tls-tooltip' "$STAGE/$JS_REL" || fail 'candidate TLS tooltip hook missing'
grep -Fq 'max-width: 360px' "$STAGE/$CSS_REL" || fail 'candidate TLS tooltip width missing'
echo 'Phase 5F.2.4 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cp -a "$TARGET/$VMCARD_REL" "$BACKUP/$VMCARD_REL"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
cp -a "$BACKUP_DIR/app/integrations/vmware/vm-card-result.php" "$TARGET/app/integrations/vmware/vm-card-result.php"
rm -f "$TARGET/app/integrations/vmware/phase5f24-stability.js" "$TARGET/app/integrations/vmware/phase5f24-stability.css"
echo 'PHASE5F24_STABILITY_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.2.4 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "$JS_REL" "$CSS_REL" "$VMCARD_REL"; do
  dst="$TARGET/$rel"
  mkdir -p "$(dirname "$dst")"
  tmp="$dst.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$tmp"
  mv -f "$tmp" "$dst"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F24_STABILITY_V1 -->
<link rel="stylesheet" href="app/integrations/vmware/phase5f24-stability.css">
<script src="app/integrations/vmware/phase5f24-stability.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f24-stability.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F24_STABILITY_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.2.4 makes Guest Networking optional for VM-card rendering, enforces latest-request-wins for VM-card AJAX, and constrains TLS tooltip width. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
