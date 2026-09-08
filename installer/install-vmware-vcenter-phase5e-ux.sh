#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5e-ux-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo >&2
        echo "ERROR: Phase 5E install failed after commit started; rolling back UX layer." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic Phase 5E rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR

fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }
for cmd in git php grep; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ "$EUID" -eq 0 ]] || fail "Phase 5E installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Read-only exact live precondition gate.
bash "$REPO_ROOT/tools/live-vmware-phase5e-ux-preflight.sh" "$TARGET"

SOURCE_ROOT="$REPO_ROOT/layers/phase5e"
declare -A SOURCE_BLOB=(
  ["app/integrations/index.php"]="b721ae34b3244aa86dbbcd48f3bde584d58365e2"
  ["app/integrations/registry.php"]="8068e6b8337e926861f65772302742c4d6a6f32a"
  ["app/integrations/vmware/index.php"]="32d6607e1a8ffcb87949de3b1020dc3e66279598"
  ["app/integrations/vmware/phase5e.css"]="2916636fc01810981bf0d29b16c7ab1002ebc258"
  ["app/integrations/vmware/workspace-result.php"]="2f460015d73d427c4d570c3bae36b3b84286b967"
  ["app/integrations/vmware/vm-card-result.php"]="7bc35cb8db18ccde52c79a4b27a3e0f189a75fd4"
)

REPLACED=(app/integrations/index.php app/integrations/registry.php)
CREATED=(
  app/integrations/vmware/index.php
  app/integrations/vmware/phase5e.css
  app/integrations/vmware/workspace-result.php
  app/integrations/vmware/vm-card-result.php
)

for rel in "${!SOURCE_BLOB[@]}"; do
    src="$SOURCE_ROOT/$rel"
    [[ -f "$src" ]] || fail "repository Phase 5E source missing: $rel"
    [[ "$(hash_file "$src")" == "${SOURCE_BLOB[$rel]}" ]] || fail "repository Phase 5E source drift: $rel"
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$src" "$STAGE/$rel"
done

for rel in app/integrations/index.php app/integrations/registry.php app/integrations/vmware/index.php app/integrations/vmware/workspace-result.php app/integrations/vmware/vm-card-result.php; do
    php -l "$STAGE/$rel" >/dev/null || fail "candidate PHP lint failed: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_V1' "$STAGE/app/integrations/vmware/index.php" || fail "candidate workspace marker missing"
grep -Fq 'class.IntegrationVmwareVcenter.php' "$STAGE/app/integrations/vmware/index.php" || fail "candidate VMware client dependency missing"
grep -Fq 'phase5e-vm-link' "$STAGE/app/integrations/vmware/workspace-result.php" || fail "candidate clickable VM navigation missing"
if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$STAGE/app/integrations/vmware/workspace-result.php" "$STAGE/app/integrations/vmware/vm-card-result.php"; then
    fail "unexpected phpIPAM write API in Phase 5E candidate"
fi
if grep -Eqi '(^|[;{[:space:]])(color|background|background-color)[[:space:]]*:' "$STAGE/app/integrations/vmware/phase5e.css"; then
    fail "Phase 5E candidate CSS hardcodes theme foreground/background"
fi

echo "Phase 5E UX pre-commit checks: OK"

mkdir -p "$BACKUP"
for rel in "${REPLACED[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$rel")"
    cp -a "$TARGET/$rel" "$BACKUP/$rel"
done
printf '%s\n' "${CREATED[@]}" > "$BACKUP/CREATED.txt"

cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    rm -f "$TARGET/$rel"
done < "$BACKUP_DIR/CREATED.txt"
cp -a "$BACKUP_DIR/app/integrations/index.php" "$TARGET/app/integrations/index.php"
cp -a "$BACKUP_DIR/app/integrations/registry.php" "$TARGET/app/integrations/registry.php"
rmdir "$TARGET/app/integrations/vmware" 2>/dev/null || true
echo "PHASE5E_UX_ROLLBACK_OK"
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5E UX backup / rollback prepared: $BACKUP"

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
for rel in "${!SOURCE_BLOB[@]}"; do install_candidate "$rel"; done

for rel in "${!SOURCE_BLOB[@]}"; do
    [[ "$(hash_file "$TARGET/$rel")" == "${SOURCE_BLOB[$rel]}" ]] || fail "post-install Phase 5E blob mismatch: $rel"
done

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5e-ux.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo "PHASE5E_UX_INSTALL_OK"
echo "Backup / rollback: $BACKUP"
echo "Phase 5D backend/fallback remains installed and verified."
echo "No database schema, connector, credential, keyring, Rackspace or scheduler changes were made."
