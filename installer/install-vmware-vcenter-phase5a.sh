#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5A VMware vCenter transient connection-test layer.
# Expected target: Phase 4 Integrations framework already installed.
# No database schema changes. No credentials are created or persisted.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_ROOT="$REPO_ROOT/layers/phase5"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5a-backup-$STAMP"
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
        echo "ERROR: Phase 5A install failed after commit started; rolling back." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback also failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}

trap cleanup EXIT
trap rollback_on_error ERR

for cmd in git php; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "ERROR: required command not found: $cmd" >&2
        exit 1
    }
done

[[ -d "$TARGET" ]] || {
    echo "ERROR: target phpIPAM root not found: $TARGET" >&2
    exit 1
}

hash_file() {
    git hash-object "$1"
}

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

# Phase 4 target preconditions. Only these two existing Phase 4 source files are
# replaced by this layer; routing/menu core stays untouched.
declare -A BASE_BLOB=(
    ["app/integrations/registry.php"]="2a03e1b96f5fc493eeee2adceceb3c717830d50b"
    ["app/admin/integrations/index.php"]="11a26efaa9c51723afffbffebf13a0067f62c53f"
)

# Exact Phase 5A source blobs. Plain UTF-8 source is stored in layers/phase5.
declare -A SOURCE_BLOB=(
    ["app/integrations/registry.php"]="45f841df2e052a92da44b6e3deb2911844f4d816"
    ["app/admin/integrations/index.php"]="c5a7d8b4b3248e6eb561e9cb7c5af0b82d59e3d4"
    ["app/admin/integrations/vmware-test-result.php"]="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"
    ["functions/classes/class.IntegrationVmwareVcenter.php"]="2ca0d021cbdb11e2b4e82185eed4c47c77a43f4c"
)

NEW_FILES=(
    "app/admin/integrations/vmware-test-result.php"
    "functions/classes/class.IntegrationVmwareVcenter.php"
)

EXISTING_FILES=(
    "app/integrations/registry.php"
    "app/admin/integrations/index.php"
)

echo "Phase 5A VMware vCenter preflight"
echo "  repo:   $REPO_ROOT"
echo "  target: $TARGET"
echo "  policy: transient connection test only; no persistence"

# Confirm this is the Phase 4 Integrations build and not an unknown target.
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1' "$TARGET/index.php" || fail "Phase 4 Integrations route marker missing"
grep -Fq 'PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_V1' "$TARGET/app/admin/integrations/index.php" || fail "Phase 4 admin Integrations marker missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_REGISTRY_V1' "$TARGET/app/integrations/registry.php" || fail "Phase 4 registry marker missing"

for rel in "${!BASE_BLOB[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "Phase 4 target file missing: $rel"
    actual="$(hash_file "$TARGET/$rel")"
    [[ "$actual" == "${BASE_BLOB[$rel]}" ]] || fail "Phase 4 target drift: $rel expected ${BASE_BLOB[$rel]}, got $actual"
done

for rel in "${NEW_FILES[@]}"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5A file already exists: $rel"
done

echo "  Phase 4 target preconditions: OK"

for rel in "${!SOURCE_BLOB[@]}"; do
    src="$SOURCE_ROOT/$rel"
    [[ -f "$src" ]] || fail "Phase 5A source missing: $rel"
    actual="$(hash_file "$src")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || fail "Phase 5A source drift: $rel expected ${SOURCE_BLOB[$rel]}, got $actual"
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$src" "$STAGE/$rel"
done

echo "  Phase 5A source blobs: OK"

while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || fail "candidate PHP lint failed: ${file#$STAGE/}"
done < <(find "$STAGE" -type f -name '*.php' -print0)

echo "  candidate PHP lint: OK"

mkdir -p "$BACKUP"
: > "$BACKUP/CREATED.txt"
: > "$BACKUP/CREATED_DIRS.txt"

for rel in "${EXISTING_FILES[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$rel")"
    cp -a "$TARGET/$rel" "$BACKUP/$rel"
done

for rel in "${NEW_FILES[@]}"; do
    printf '%s\n' "$rel" >> "$BACKUP/CREATED.txt"
done

cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"

while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    rm -f "$TARGET/$rel"
done < "$BACKUP_DIR/CREATED.txt"

while IFS= read -r -d '' src; do
    rel="${src#$BACKUP_DIR/}"
    case "$rel" in
        CREATED.txt|CREATED_DIRS.txt|rollback.sh) continue ;;
    esac
    dst="$TARGET/$rel"
    mkdir -p "$(dirname "$dst")"
    cp -a "$src" "$dst"
done < <(find "$BACKUP_DIR" -type f -print0)

echo "Rollback restored Phase 4 files from: $BACKUP_DIR"
ROLLBACK
chmod 700 "$BACKUP/rollback.sh"

echo "  backup prepared: $BACKUP"

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
for rel in "${EXISTING_FILES[@]}"; do
    install_candidate "$rel"
done
for rel in "${NEW_FILES[@]}"; do
    install_candidate "$rel"
done

for rel in "${!SOURCE_BLOB[@]}"; do
    actual="$(hash_file "$TARGET/$rel")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || fail "post-install blob mismatch: $rel"
    php -l "$TARGET/$rel" >/dev/null || fail "post-install PHP lint failed: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_VCENTER_PHASE5A_V1' "$TARGET/app/integrations/registry.php" || fail "Phase 5A registry marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_VCENTER_TEST_UI_V1' "$TARGET/app/admin/integrations/index.php" || fail "Phase 5A admin UI marker missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_test"' "$TARGET/app/admin/integrations/vmware-test-result.php" || fail "Phase 5A CSRF validation marker missing"

COMMIT_STARTED=0

echo
echo "PHASE5A_VMWARE_INSTALL_OK"
echo "Backup / rollback: $BACKUP"
echo "No database schema changes were made."
echo "No credentials were created or persisted."
