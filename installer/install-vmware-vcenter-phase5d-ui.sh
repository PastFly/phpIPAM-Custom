#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5D VMware Explorer live installer.
#
# Preconditions: exact runtime-verified Phase 5C UI is installed. This installer
# only adds Phase 5D read-only classes/endpoints/panel and appends one reviewed
# include to Administration → Integrations. No DB schema, connector, credential,
# keyring, Rackspace, scheduler, phpIPAM object or vCenter object changes.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5d-ui-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo >&2
        echo "ERROR: Phase 5D Explorer install failed after commit started; rolling back source layer." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic Phase 5D rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR

fail() { echo "ERROR: $*" >&2; exit 1; }
for cmd in git php grep; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done
[[ "$EUID" -eq 0 ]] || fail "Phase 5D Explorer installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Exact Phase 5C + absence gate before any live mutation.
bash "$REPO_ROOT/tools/live-vmware-phase5d-ui-preflight.sh" "$TARGET"

BASE_INDEX_BLOB="2aa9faaac09901d6b62ef17a32af87b8316f388c"
PHASE5C_APPEND_BLOB="fe8d1f13bcc1d61b50431625ddd873d9200e7060"
PHASE5D_APPEND_BLOB="1ac67d9441bb6b440ac03fb4e5d47499631e8d48"
TOPOLOGY_BLOB="bb162ddc4e01f52ca08aa3e00998bf98e21a9fbb"
DATASTORE_BLOB="3f98b2b5c66a47205ab17e18272a87b35f4cdd43"
RESOLVER_BLOB="36a41c8a05d769f40762603888b73cd9e80cb959"
VMDETAIL_BLOB="f639c7046af8342c34a430b257447cd2b05f5e02"
METADATA_BLOB="44d8691af6531c48ea20deb15646b002cebdfb37"
EXPLORER_BLOB="111d70b5897ba71f2bb24a8b0b80b361509523b5"
PANEL_BLOB="670d7c4cebcce7570ce3225054125384720272a8"
RESULT_BLOB="2054ca8e7eaaf9c63408c762eec51427b5539b5f"
CARD_BLOB="4fd47dcdb800ec1ed7c103ad4cae25b82c8f35be"

INDEX_REL="app/admin/integrations/index.php"
TOPOLOGY_REL="functions/classes/class.IntegrationVmwareVcenterTopology.php"
DATASTORE_REL="functions/classes/class.IntegrationVmwareVcenterDatastore.php"
RESOLVER_REL="functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php"
VMDETAIL_REL="functions/classes/class.IntegrationVmwareVcenterVmDetail.php"
METADATA_REL="functions/classes/class.IntegrationVmwareVcenterMetadata.php"
EXPLORER_REL="functions/classes/class.IntegrationVmwareVcenterExplorer.php"
PANEL_REL="app/admin/integrations/vmware-explorer-panel.php"
RESULT_REL="app/admin/integrations/vmware-explorer-result.php"
CARD_REL="app/admin/integrations/vmware-vm-card-result.php"

BASE_INDEX_SOURCE="$REPO_ROOT/layers/phase5b3/app/admin/integrations/index.php"
PHASE5C_APPEND_SOURCE="$REPO_ROOT/layers/phase5c/app/admin/integrations/index-phase5c-append.php"
PHASE5D_APPEND_SOURCE="$REPO_ROOT/layers/phase5d/app/admin/integrations/index-phase5d-append.php"
TOPOLOGY_SOURCE="$REPO_ROOT/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterTopology.php"
DATASTORE_SOURCE="$REPO_ROOT/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastore.php"
RESOLVER_SOURCE="$REPO_ROOT/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php"
VMDETAIL_SOURCE="$REPO_ROOT/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterVmDetail.php"
METADATA_SOURCE="$REPO_ROOT/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterMetadata.php"
EXPLORER_SOURCE="$REPO_ROOT/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterExplorer.php"
PANEL_SOURCE="$REPO_ROOT/layers/phase5d/app/admin/integrations/vmware-explorer-panel.php"
RESULT_SOURCE="$REPO_ROOT/layers/phase5d/app/admin/integrations/vmware-explorer-result.php"
CARD_SOURCE="$REPO_ROOT/layers/phase5d/app/admin/integrations/vmware-vm-card-result.php"

hash_file() { git hash-object "$1"; }
check_source() {
    local file="$1" expected="$2" label="$3"
    [[ -f "$file" ]] || fail "repository source missing: $label"
    [[ "$(hash_file "$file")" == "$expected" ]] || fail "repository source drift: $label"
}

check_source "$BASE_INDEX_SOURCE" "$BASE_INDEX_BLOB" "Phase 5B.3 base index"
check_source "$PHASE5C_APPEND_SOURCE" "$PHASE5C_APPEND_BLOB" "Phase 5C append"
check_source "$PHASE5D_APPEND_SOURCE" "$PHASE5D_APPEND_BLOB" "Phase 5D append"
check_source "$TOPOLOGY_SOURCE" "$TOPOLOGY_BLOB" "Phase 5D topology"
check_source "$DATASTORE_SOURCE" "$DATASTORE_BLOB" "Phase 5D datastore"
check_source "$RESOLVER_SOURCE" "$RESOLVER_BLOB" "Phase 5D resolver"
check_source "$VMDETAIL_SOURCE" "$VMDETAIL_BLOB" "Phase 5D VM detail"
check_source "$METADATA_SOURCE" "$METADATA_BLOB" "Phase 5D metadata"
check_source "$EXPLORER_SOURCE" "$EXPLORER_BLOB" "Phase 5D Explorer model"
check_source "$PANEL_SOURCE" "$PANEL_BLOB" "Phase 5D Explorer panel"
check_source "$RESULT_SOURCE" "$RESULT_BLOB" "Phase 5D Explorer endpoint"
check_source "$CARD_SOURCE" "$CARD_BLOB" "Phase 5D VM card endpoint"

mkdir -p "$STAGE/app/admin/integrations" "$STAGE/functions/classes"
cat "$BASE_INDEX_SOURCE" > "$STAGE/$INDEX_REL"
printf '\n' >> "$STAGE/$INDEX_REL"
cat "$PHASE5C_APPEND_SOURCE" >> "$STAGE/$INDEX_REL"
printf '\n' >> "$STAGE/$INDEX_REL"
cat "$PHASE5D_APPEND_SOURCE" >> "$STAGE/$INDEX_REL"
cp "$TOPOLOGY_SOURCE" "$STAGE/$TOPOLOGY_REL"
cp "$DATASTORE_SOURCE" "$STAGE/$DATASTORE_REL"
cp "$RESOLVER_SOURCE" "$STAGE/$RESOLVER_REL"
cp "$VMDETAIL_SOURCE" "$STAGE/$VMDETAIL_REL"
cp "$METADATA_SOURCE" "$STAGE/$METADATA_REL"
cp "$EXPLORER_SOURCE" "$STAGE/$EXPLORER_REL"
cp "$PANEL_SOURCE" "$STAGE/$PANEL_REL"
cp "$RESULT_SOURCE" "$STAGE/$RESULT_REL"
cp "$CARD_SOURCE" "$STAGE/$CARD_REL"

INSTALL_RELS=("$INDEX_REL" "$TOPOLOGY_REL" "$DATASTORE_REL" "$RESOLVER_REL" "$VMDETAIL_REL" "$METADATA_REL" "$EXPLORER_REL" "$PANEL_REL" "$RESULT_REL" "$CARD_REL")
for rel in "${INSTALL_RELS[@]}"; do
    php -l "$STAGE/$rel" >/dev/null || fail "candidate PHP lint failed: $rel"
done

[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_INDEX_APPEND_V1' "$STAGE/$INDEX_REL")" == "1" ]] || fail "candidate Phase 5C index marker invalid"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_INDEX_APPEND_V1' "$STAGE/$INDEX_REL")" == "1" ]] || fail "candidate Phase 5D index marker invalid"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_PANEL_V1' "$STAGE/$PANEL_REL" || fail "candidate Explorer panel marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_RESULT_V1' "$STAGE/$RESULT_REL" || fail "candidate Explorer endpoint marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_VM_CARD_RESULT_V1' "$STAGE/$CARD_REL" || fail "candidate VM card marker missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_explorer"' "$STAGE/$RESULT_REL" || fail "candidate Explorer CSRF validation missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_explorer"' "$STAGE/$CARD_REL" || fail "candidate VM card CSRF validation missing"
grep -Fq 'correlation_only_not_authoritative_vmware_relation' "$STAGE/$RESOLVER_REL" || fail "candidate datastore provenance missing"

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$STAGE/$RESULT_REL" "$STAGE/$CARD_REL" "$STAGE/$EXPLORER_REL" "$STAGE/$RESOLVER_REL"; then
    fail "unexpected phpIPAM write API reference in candidate Phase 5D source"
fi
if grep -Eq 'action=(attach|detach)|/api/cis/tagging/(tag|category).*(create|update|delete)' "$STAGE/$METADATA_REL"; then
    fail "unexpected VMware tag mutation endpoint in candidate metadata source"
fi
if grep -Eq "'PUT'|'PATCH'" "$STAGE/$TOPOLOGY_REL" "$STAGE/$DATASTORE_REL" "$STAGE/$VMDETAIL_REL" "$STAGE/$METADATA_REL"; then
    fail "unexpected mutating HTTP method in candidate Phase 5D client"
fi

echo "Phase 5D Explorer pre-commit checks: OK"

mkdir -p "$BACKUP/app/admin/integrations"
cp -a "$TARGET/$INDEX_REL" "$BACKUP/$INDEX_REL"
printf '%s\n' "$TOPOLOGY_REL" "$DATASTORE_REL" "$RESOLVER_REL" "$VMDETAIL_REL" "$METADATA_REL" "$EXPLORER_REL" "$PANEL_REL" "$RESULT_REL" "$CARD_REL" > "$BACKUP/CREATED.txt"

cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    rm -f "$TARGET/$rel"
done < "$BACKUP_DIR/CREATED.txt"
cp -a "$BACKUP_DIR/app/admin/integrations/index.php" "$TARGET/app/admin/integrations/index.php"
echo "PHASE5D_UI_ROLLBACK_OK"
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5D Explorer backup / rollback prepared: $BACKUP"

install_candidate() {
    local rel="$1" src="$STAGE/$1" dst="$TARGET/$1" parent tmp
    parent="$(dirname "$dst")"
    tmp="$dst.phpipam-custom-tmp-$$"
    mkdir -p "$parent"
    if [[ -e "$dst" ]]; then
        cp -a "$dst" "$tmp"
        cat "$src" > "$tmp"
    else
        cp "$src" "$tmp"
        chown --reference="$parent" "$tmp" 2>/dev/null || true
        chmod 0644 "$tmp"
    fi
    mv -f "$tmp" "$dst"
}

COMMIT_STARTED=1
for rel in "${INSTALL_RELS[@]}"; do
    install_candidate "$rel"
done

[[ "$(hash_file "$TARGET/$INDEX_REL")" == "$(hash_file "$STAGE/$INDEX_REL")" ]] || fail "post-install cumulative Administration index mismatch"
[[ "$(hash_file "$TARGET/$TOPOLOGY_REL")" == "$TOPOLOGY_BLOB" ]] || fail "post-install topology blob mismatch"
[[ "$(hash_file "$TARGET/$DATASTORE_REL")" == "$DATASTORE_BLOB" ]] || fail "post-install datastore blob mismatch"
[[ "$(hash_file "$TARGET/$RESOLVER_REL")" == "$RESOLVER_BLOB" ]] || fail "post-install resolver blob mismatch"
[[ "$(hash_file "$TARGET/$VMDETAIL_REL")" == "$VMDETAIL_BLOB" ]] || fail "post-install VM detail blob mismatch"
[[ "$(hash_file "$TARGET/$METADATA_REL")" == "$METADATA_BLOB" ]] || fail "post-install metadata blob mismatch"
[[ "$(hash_file "$TARGET/$EXPLORER_REL")" == "$EXPLORER_BLOB" ]] || fail "post-install Explorer model blob mismatch"
[[ "$(hash_file "$TARGET/$PANEL_REL")" == "$PANEL_BLOB" ]] || fail "post-install Explorer panel blob mismatch"
[[ "$(hash_file "$TARGET/$RESULT_REL")" == "$RESULT_BLOB" ]] || fail "post-install Explorer endpoint blob mismatch"
[[ "$(hash_file "$TARGET/$CARD_REL")" == "$CARD_BLOB" ]] || fail "post-install VM card endpoint blob mismatch"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo "PHASE5D_UI_INSTALL_OK"
echo "Backup / rollback: $BACKUP"
echo "No database schema, connector, credential, keyring, Rackspace or scheduler changes were made."
echo "Phase 5D Explorer remains administrator-triggered, read-only and non-persistent."
