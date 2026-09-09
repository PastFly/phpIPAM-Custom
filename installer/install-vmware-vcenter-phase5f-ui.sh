#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f-ui-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo "ERROR: Phase 5F UI deployment failed after commit started; rolling back." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR

fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }
for cmd in git php grep awk; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ "$EUID" -eq 0 ]] || fail "Phase 5F installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

bash "$REPO_ROOT/tools/live-vmware-phase5f-ui-preflight.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/host-card-result.php"]="851ef60f3cc39e03bcc4ad97de50927dad23a398"
  ["app/integrations/vmware/performance-result.php"]="4a5ab086045ca4e8178644c096d0948ae517f075"
  ["app/integrations/vmware/phase5f-observability.js"]="42fd777098dd523c8aecca6b3912e615ba9a0b3e"
  ["functions/classes/class.IntegrationVmwareVcenterDirectTransport.php"]="e6f976342a58da825f07987a3e66162f23d855c7"
  ["functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php"]="7c39aa71bb5a70826eaf0c603be400b03e4b90be"
  ["functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php"]="3cd45eda9632063c771bba075330e5a6e3d0559c"
  ["functions/classes/class.IntegrationVmwareVcenterPerformance.php"]="426c8c32632037d2b2ceaac23ef11eb79a06edd2"
)

for rel in "${!EXPECTED[@]}"; do
    SRC="$REPO_ROOT/layers/phase5f/$rel"
    [[ -f "$SRC" ]] || fail "Phase 5F repository source missing: $rel"
    [[ "$(hash_file "$SRC")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F repository source drift: $rel"
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$SRC" "$STAGE/$rel"
done

for rel in \
  "app/integrations/vmware/host-card-result.php" \
  "app/integrations/vmware/performance-result.php" \
  "functions/classes/class.IntegrationVmwareVcenterDirectTransport.php" \
  "functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php" \
  "functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php" \
  "functions/classes/class.IntegrationVmwareVcenterPerformance.php"; do
    php -l "$STAGE/$rel" >/dev/null || fail "candidate Phase 5F PHP lint failed: $rel"
done
if command -v node >/dev/null 2>&1; then
    node --check "$STAGE/app/integrations/vmware/phase5f-observability.js" >/dev/null || fail "candidate Phase 5F JavaScript syntax failed"
fi

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F_HOST_CARD_V1' "$STAGE/app/integrations/vmware/host-card-result.php" || fail "candidate host-card marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F_VM_PERFORMANCE_V1' "$STAGE/app/integrations/vmware/performance-result.php" || fail "candidate VM performance marker missing"
grep -Fq "CURLOPT_PROXY => ''" "$STAGE/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php" || fail "candidate direct no-proxy policy missing"
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$STAGE/functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php" || fail "candidate compatible HostSystem PropertyCollector path missing"
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$STAGE/functions/classes/class.IntegrationVmwareVcenterPerformance.php" || fail "candidate compatible PerformanceManager PropertyCollector path missing"

echo 'Phase 5F UI pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/app/integrations/vmware/index.php" "$BACKUP/app/integrations/vmware/index.php"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
  "$TARGET/app/integrations/vmware/host-card-result.php" \
  "$TARGET/app/integrations/vmware/performance-result.php" \
  "$TARGET/app/integrations/vmware/phase5f-observability.js" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterPerformance.php"
echo 'PHASE5F_UI_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F UI backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "${!EXPECTED[@]}"; do
    DST="$TARGET/$rel"
    mkdir -p "$(dirname "$DST")"
    TMP="$DST.phpipam-custom-tmp-$$"
    cp "$STAGE/$rel" "$TMP"
    mv -f "$TMP" "$DST"
    [[ "$(hash_file "$DST")" == "${EXPECTED[$rel]}" ]] || fail "post-deploy Phase 5F blob mismatch: $rel"
done

INDEX="$TARGET/app/integrations/vmware/index.php"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F_OBSERVABILITY_UI_V1 -->
<script src="app/integrations/vmware/phase5f-observability.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f-ui.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F_UI_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F adds only administrator-triggered read-only ESXi/VM observability. No schema, connector, credential, keyring, Rackspace, scheduler, phpIPAM object or vCenter mutation was made.'
