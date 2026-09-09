#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f32-vm-guest-facts-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.3.2 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail "Phase 5F.3.2 installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f32-ui-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/vm-guest-facts-result.php"]="644a74ff9d0b2828117c63845dd79ef2ff006a94"
  ["app/integrations/vmware/phase5f32-vm-guest-facts.js"]="f7117000fec1f20c0f68c95325f8af49a7924e05"
  ["functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php"]="24a1f1293366abca6b0e10bc71aa2270651ba1c5"
)

for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f32/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.3.2 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3.2 repository source drift: $rel"
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$SRC" "$STAGE/$rel"
done

php -l "$STAGE/app/integrations/vmware/vm-guest-facts-result.php" >/dev/null
php -l "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$STAGE/app/integrations/vmware/phase5f32-vm-guest-facts.js" >/dev/null; fi

CLASS="$STAGE/functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php"
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$CLASS" || fail 'candidate RetrieveProperties reader missing'
grep -Fq "'guest.guestFullName'" "$CLASS" || fail 'candidate guest friendly-name property missing'
grep -Fq "'config.hardware.memoryMB'" "$CLASS" || fail 'candidate configured memory property missing'
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|Refresh)' "$CLASS"; then
  fail 'candidate contains forbidden vCenter mutation operation'
fi
echo 'Phase 5F.3.2 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/vm-guest-facts-result.php" \
  "$TARGET/app/integrations/vmware/phase5f32-vm-guest-facts.js" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php"
echo 'PHASE5F32_VM_GUEST_FACTS_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.3.2 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
  DST="$TARGET/$rel"
  mkdir -p "$(dirname "$DST")"
  TMP="$DST.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$TMP"
  mv -f "$TMP" "$DST"
  [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F.3.2 blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F32_VM_GUEST_FACTS_UI_V1 -->
<script src="app/integrations/vmware/phase5f32-vm-guest-facts.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f32-vm-guest-facts.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F32_VM_GUEST_FACTS_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.3.2 corrects VM-card Guest OS/RAM/vCPU/hardware presentation from authoritative read-only vCenter properties. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
