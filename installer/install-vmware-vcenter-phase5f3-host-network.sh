#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f3-host-network-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.3 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail "Phase 5F.3 installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f3-ui-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/host-network-result.php"]="f8dfbc733850b7660059eb47367258346ba77828"
  ["app/integrations/vmware/phase5f3-host-network.css"]="d0b27b2f980528942a719a582fc3cec2ab2d86c9"
  ["app/integrations/vmware/phase5f3-host-network.js"]="8fc10bd724c3524261730603694484024aabf962"
  ["functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php"]="9167e7f3f9d3249543d59b0053f74e34cdc70602"
)

for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f3/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.3 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3 repository source drift: $rel"
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$SRC" "$STAGE/$rel"
done

php -l "$STAGE/app/integrations/vmware/host-network-result.php" >/dev/null
php -l "$STAGE/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$STAGE/app/integrations/vmware/phase5f3-host-network.js" >/dev/null; fi

grep -Fq '<QueryNetworkHint ' "$STAGE/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php" || fail "candidate QueryNetworkHint collector missing"
if grep -Eq 'RefreshNetworkSystem|UpdateNetworkConfig|AddVirtualSwitch|RemoveVirtualSwitch|UpdateVirtualSwitch|AddPortGroup|RemovePortGroup|UpdatePortGroup' "$STAGE/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php"; then
  fail "candidate contains forbidden HostNetworkSystem mutation operation"
fi
echo 'Phase 5F.3 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/host-network-result.php" \
  "$TARGET/app/integrations/vmware/phase5f3-host-network.css" \
  "$TARGET/app/integrations/vmware/phase5f3-host-network.js" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php"
echo 'PHASE5F3_HOST_NETWORK_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.3 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
  DST="$TARGET/$rel"
  mkdir -p "$(dirname "$DST")"
  TMP="$DST.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$TMP"
  mv -f "$TMP" "$DST"
  [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F.3 blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F3_HOST_NETWORK_V1 -->
<link rel="stylesheet" href="app/integrations/vmware/phase5f3-host-network.css">
<script src="app/integrations/vmware/phase5f3-host-network.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f3-host-network.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F3_HOST_NETWORK_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.3 adds on-demand read-only ESXi CDP/LLDP peer details only. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
