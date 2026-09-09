#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5f25-folders-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo 'ERROR: Phase 5F.2.5 deployment failed after commit started; rolling back.' >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ "$EUID" -eq 0 ]] || fail 'Phase 5F.2.5 installer must run as root'
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/tools/live-vmware-phase5f25-ui-preflight.sh" "$TARGET"

CLASS_REL='functions/classes/class.IntegrationVmwareVcenterFolderInventory.php'
RESULT_REL='app/integrations/vmware/folder-result.php'
JS_REL='app/integrations/vmware/phase5f25-folders.js'
CSS_REL='app/integrations/vmware/phase5f25-folders.css'
INDEX_REL='app/integrations/vmware/index.php'

CLASS_EXPECTED='c809ddd5c53266192076f10759f9faf323f04d98'
RESULT_EXPECTED='d00ba2f16611291debaf244bef50bcec8dac491e'
JS_EXPECTED='d9effbb24c8f08febb7444faa9f98bfd90d5f990'
CSS_EXPECTED='e67bb37aaee03849bd86bca3677e1615a3549eff'

for rel in "$CLASS_REL" "$RESULT_REL" "$JS_REL" "$CSS_REL"; do
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$REPO_ROOT/layers/phase5f25/$rel" "$STAGE/$rel"
done

[[ "$(hash_file "$STAGE/$CLASS_REL")" == "$CLASS_EXPECTED" ]] || fail 'repository Phase 5F.2.5 collector drift'
[[ "$(hash_file "$STAGE/$RESULT_REL")" == "$RESULT_EXPECTED" ]] || fail 'repository Phase 5F.2.5 result endpoint drift'
[[ "$(hash_file "$STAGE/$JS_REL")" == "$JS_EXPECTED" ]] || fail 'repository Phase 5F.2.5 JS drift'
[[ "$(hash_file "$STAGE/$CSS_REL")" == "$CSS_EXPECTED" ]] || fail 'repository Phase 5F.2.5 CSS drift'
php -l "$STAGE/$CLASS_REL" >/dev/null
php -l "$STAGE/$RESULT_REL" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$STAGE/$JS_REL" >/dev/null; fi

echo 'Phase 5F.2.5 pre-commit checks: OK'

mkdir -p "$BACKUP/app/integrations/vmware"
cp -a "$TARGET/$INDEX_REL" "$BACKUP/$INDEX_REL"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
cp -a "$BACKUP_DIR/app/integrations/vmware/index.php" "$TARGET/app/integrations/vmware/index.php"
rm -f \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterFolderInventory.php" \
    "$TARGET/app/integrations/vmware/folder-result.php" \
    "$TARGET/app/integrations/vmware/phase5f25-folders.js" \
    "$TARGET/app/integrations/vmware/phase5f25-folders.css"
echo 'PHASE5F25_FOLDER_UI_ROLLBACK_OK'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5F.2.5 backup / rollback prepared: $BACKUP"

COMMIT_STARTED=1
for rel in "$CLASS_REL" "$RESULT_REL" "$JS_REL" "$CSS_REL"; do
    dst="$TARGET/$rel"
    mkdir -p "$(dirname "$dst")"
    tmp="$dst.phpipam-custom-tmp-$$"
    cp "$STAGE/$rel" "$tmp"
    mv -f "$tmp" "$dst"
done

INDEX="$TARGET/$INDEX_REL"
INDEX_TMP="$INDEX.phpipam-custom-tmp-$$"
cp -a "$INDEX" "$INDEX_TMP"
cat >> "$INDEX_TMP" <<'HOOK'
<!-- PHPIPAM_CUSTOM_VMWARE_PHASE5F25_FOLDER_UI_V1 -->
<link rel="stylesheet" href="app/integrations/vmware/phase5f25-folders.css">
<script src="app/integrations/vmware/phase5f25-folders.js"></script>
HOOK
mv -f "$INDEX_TMP" "$INDEX"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f25-folders.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo 'PHASE5F25_FOLDER_UI_INSTALL_OK'
echo "Backup / rollback: $BACKUP"
echo 'Phase 5F.2.5 adds transient read-only VMware HOST Folder hierarchy to the Infrastructure tree. Root host folders remain implicit; user folders are rendered by stable vCenter Folder IDs. No schema, connector, credential, keyring, scheduler, phpIPAM object or vCenter inventory mutation was made.'
