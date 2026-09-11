#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f31-vm-network-path-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.3.1 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail "Phase 5F.3.1 installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f31-ui-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/vm-network-path-result.php"]="c483e943d1a02037422fbeded2e5217e1d71041a"
  ["app/integrations/vmware/phase5f31-vm-network-path.js"]="08cc549fa138abb75ab440d4d1bb12bec75cbece"
  ["app/integrations/vmware/phase5f31-vm-network-path.css"]="0726fc928342a73bb344929f744cb0e4735d1040"
  ["functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php"]="987b03a66acd8023d1010cc20167cf1da60791d4"
)

for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f31/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.3.1 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3.1 repository source drift: $rel"
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$SRC" "$STAGE/$rel"
done

php -l "$STAGE/app/integrations/vmware/vm-network-path-result.php" >/dev/null
php -l "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$STAGE/app/integrations/vmware/phase5f31-vm-network-path.js" >/dev/null; fi

CLASS="$STAGE/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php"
grep -Fq '<QueryNetworkHint ' "$CLASS" || fail "candidate QueryNetworkHint reader missing"
grep -Fq "'current_uplink_proven' => false" "$CLASS" || fail "candidate no-guess current-uplink guard missing"
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|RefreshNetworkSystem)' "$CLASS"; then
  fail "candidate contains forbidden vCenter mutation operation"
fi
echo 'Phase 5F.3.1 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/vm-network-path-result.php" \
  "$TARGET/app/integrations/vmware/phase5f31-vm-network-path.js" \
  "$TARGET/app/integrations/vmware/phase5f31-vm-network-path.css" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php"
echo 'PHASE5F31_VM_NETWORK_PATH_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.3.1 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
  DST="$TARGET/$rel"
  mkdir -p "$(dirname "$DST")"
  TMP="$DST.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$TMP"
  mv -f "$TMP" "$DST"
  [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F.3.1 blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F31_VM_NETWORK_PATH_UI_V1 -->
<link rel="stylesheet" href="app/integrations/vmware/phase5f31-vm-network-path.css">
<script src="app/integrations/vmware/phase5f31-vm-network-path.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f31-vm-network-path.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F31_VM_NETWORK_PATH_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.3.1 adds on-demand read-only VM network path evidence only. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
