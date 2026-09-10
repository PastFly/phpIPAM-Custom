#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f33-enriched-search-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
  local rc=$?
  trap - ERR
  if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
    echo 'ERROR: Phase 5F.3.3 deployment failed after commit started; rolling back.' >&2
    bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
  fi
  exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail "Phase 5F.3.3 installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f33-ui-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/enriched-search-result.php"]="2307929a9ce7b006d29aa80631b41f808a4ec7a2"
  ["app/integrations/vmware/phase5f33-enriched-search.js"]="85d9eddd9c5e0fb592cfe915f6b6d9516e282ab6"
  ["functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php"]="c68a0a6cddf3acd11cb5141c83cf6c4a83ce56fc"
  ["functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php"]="b5dfe0e67259261f2dc42b8a61fc4c3c8a202992"
  ["functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php"]="65f0e22e0778ad3cf05f4ac009d2a229102b8d5b"
)
for rel in "${!EXPECTED[@]}"; do
  SRC="$REPO_ROOT/layers/phase5f33/$rel"
  [[ -f "$SRC" ]] || fail "Phase 5F.3.3 repository source missing: $rel"
  [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3.3 repository source drift: $rel"
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp "$SRC" "$STAGE/$rel"
done

php -l "$STAGE/app/integrations/vmware/enriched-search-result.php" >/dev/null
php -l "$STAGE/functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php" >/dev/null
php -l "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php" >/dev/null
php -l "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$STAGE/app/integrations/vmware/phase5f33-enriched-search.js" >/dev/null; fi

grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php" || fail 'candidate RetrieveProperties reader missing'
grep -Fq 'list-attached-objects-on-tags' "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php" || fail 'candidate bulk tag association path missing'
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|Refresh)' "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php"; then fail 'candidate contains forbidden vCenter mutation operation'; fi
if grep -Eq 'action=(attach|detach)|/attach|/detach|/create|/update|/delete' "$STAGE/functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php"; then fail 'candidate contains forbidden tagging mutation path'; fi
echo 'Phase 5F.3.3 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/enriched-search-result.php" \
  "$TARGET/app/integrations/vmware/phase5f33-enriched-search.js" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php"
echo 'PHASE5F33_ENRICHED_SEARCH_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.3.3 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
  DST="$TARGET/$rel"
  mkdir -p "$(dirname "$DST")"
  TMP="$DST.phpipam-custom-tmp-$$"
  cp "$STAGE/$rel" "$TMP"
  mv -f "$TMP" "$DST"
  [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F.3.3 blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F33_ENRICHED_SEARCH_UI_V1 -->
<script src="app/integrations/vmware/phase5f33-enriched-search.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f33-enriched-search.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F33_ENRICHED_SEARCH_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.3.3 adds bounded transient main VM search over Notes, Tags/Categories, guest/virtualization facts, MAC, network/IP and placement. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter mutation was made.'
