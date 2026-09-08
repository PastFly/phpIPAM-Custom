#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5B.3 persistent VMware Administration UI.
#
# Preconditions: Phase 5B.3 foundation (encrypted keyring + custom_integrations +
# reviewed classes) is already installed and verified. This layer changes only
# Administration PHP source. It does not create rows, credentials, schema, keys,
# or any vCenter write operation.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_ROOT="$REPO_ROOT/layers/phase5b3/app/admin/integrations"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5b3-ui-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() {
    rm -rf "$STAGE"
}

rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo >&2
        echo "ERROR: Phase 5B.3 UI install failed after commit started; rolling back source layer." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic UI rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}

trap cleanup EXIT
trap rollback_on_error ERR

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

for cmd in git php; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done

[[ "$EUID" -eq 0 ]] || fail "Phase 5B.3 UI installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Foundation verification is read-only and also proves keyring runtime access,
# schema version 46, exact Phase 5B classes, and unchanged Rackspace sentinel.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5b3-foundation.sh" "$TARGET"

BASE_INDEX_BLOB="c5a7d8b4b3248e6eb561e9cb7c5af0b82d59e3d4"
TRANSIENT_RESULT_BLOB="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"
NEW_INDEX_BLOB="2aa9faaac09901d6b62ef17a32af87b8316f388c"
NEW_ACTION_BLOB="aa7c746775183a63b2fde2482a5acac61b93a7ce"

INDEX_REL="app/admin/integrations/index.php"
ACTION_REL="app/admin/integrations/vmware-config-action.php"
TRANSIENT_REL="app/admin/integrations/vmware-test-result.php"

hash_file() {
    git hash-object "$1"
}

[[ -f "$TARGET/$INDEX_REL" ]] || fail "Phase 5A Administration index missing"
[[ "$(hash_file "$TARGET/$INDEX_REL")" == "$BASE_INDEX_BLOB" ]] || fail "Administration integrations index drift before Phase 5B.3 UI"
[[ -f "$TARGET/$TRANSIENT_REL" ]] || fail "Phase 5A transient result endpoint missing"
[[ "$(hash_file "$TARGET/$TRANSIENT_REL")" == "$TRANSIENT_RESULT_BLOB" ]] || fail "Phase 5A transient result endpoint drift"
[[ ! -e "$TARGET/$ACTION_REL" ]] || fail "persistent VMware action endpoint already exists"

[[ -f "$SOURCE_ROOT/index.php" ]] || fail "Phase 5B.3 UI index source missing"
[[ -f "$SOURCE_ROOT/vmware-config-action.php" ]] || fail "Phase 5B.3 UI action source missing"
[[ "$(hash_file "$SOURCE_ROOT/index.php")" == "$NEW_INDEX_BLOB" ]] || fail "Phase 5B.3 UI index source drift"
[[ "$(hash_file "$SOURCE_ROOT/vmware-config-action.php")" == "$NEW_ACTION_BLOB" ]] || fail "Phase 5B.3 UI action source drift"

mkdir -p "$STAGE/app/admin/integrations"
cp "$SOURCE_ROOT/index.php" "$STAGE/$INDEX_REL"
cp "$SOURCE_ROOT/vmware-config-action.php" "$STAGE/$ACTION_REL"
php -l "$STAGE/$INDEX_REL" >/dev/null || fail "candidate Administration index PHP lint failed"
php -l "$STAGE/$ACTION_REL" >/dev/null || fail "candidate persistent action PHP lint failed"

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_UI_V1' "$STAGE/$INDEX_REL" || fail "persistent UI marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_ACTION_V1' "$STAGE/$ACTION_REL" || fail "persistent action marker missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_config"' "$STAGE/$ACTION_REL" || fail "persistent action CSRF validation missing"
grep -Fq 'is_admin(false)' "$STAGE/$ACTION_REL" || fail "persistent action administrator guard missing"

echo "Phase 5B.3 persistent UI pre-commit checks: OK"

mkdir -p "$BACKUP/app/admin/integrations"
cp -a "$TARGET/$INDEX_REL" "$BACKUP/$INDEX_REL"
printf '%s\n' "$ACTION_REL" > "$BACKUP/CREATED.txt"

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
echo "PHASE5B3_UI_ROLLBACK_OK"
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"

echo "UI backup / rollback prepared: $BACKUP"

install_candidate() {
    local rel="$1"
    local src="$STAGE/$rel"
    local dst="$TARGET/$rel"
    local parent="$(dirname "$dst")"
    local tmp="$dst.phpipam-custom-tmp-$$"
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
install_candidate "$INDEX_REL"
install_candidate "$ACTION_REL"

[[ "$(hash_file "$TARGET/$INDEX_REL")" == "$NEW_INDEX_BLOB" ]] || fail "post-install Administration index blob mismatch"
[[ "$(hash_file "$TARGET/$ACTION_REL")" == "$NEW_ACTION_BLOB" ]] || fail "post-install persistent action blob mismatch"
[[ "$(hash_file "$TARGET/$TRANSIENT_REL")" == "$TRANSIENT_RESULT_BLOB" ]] || fail "transient endpoint changed during persistent UI install"
php -l "$TARGET/$INDEX_REL" >/dev/null || fail "post-install Administration index PHP lint failed"
php -l "$TARGET/$ACTION_REL" >/dev/null || fail "post-install action endpoint PHP lint failed"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5b3-ui.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo "PHASE5B3_UI_INSTALL_OK"
echo "Backup / rollback: $BACKUP"
echo "No database schema or keyring changes were made by the UI installer."
echo "No connector row or credential was created by the UI installer."
echo "VMware remains read-only."
