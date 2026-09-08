#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5e1-feedback-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo >&2
        echo "ERROR: Phase 5E.1 update failed after commit started; rolling back feedback slice." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic Phase 5E.1 rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR

fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }
for cmd in git php grep; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ "$EUID" -eq 0 ]] || fail "Phase 5E.1 updater must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Exact, read-only first-apply gate for the already-deployed Phase 5E.0 state.
bash "$REPO_ROOT/tools/live-vmware-phase5e1-feedback-preflight.sh" "$TARGET"

declare -A SOURCE_BLOB=(
  ["app/integrations/vmware/index.php"]="97aeb732cc0a486729936983427c22a4d032efac"
  ["app/integrations/vmware/workspace-result.php"]="408fed89e2b0583013325b08e2959813458c76fe"
  ["app/integrations/vmware/vm-card-result.php"]="2d58ab4af53f750d945527e0ac1a404d7973e574"
  ["functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"]="d26f9ad06ea4136c487b229e004b9a8c6521b863"
  ["app/sections/menu/menu-tools-admin.php"]="c778652ed50fdbd7d5855d4c37d20c2992545c82"
)

source_path() {
    local rel="$1"
    if [[ "$rel" == "app/sections/menu/menu-tools-admin.php" ]]; then
        printf '%s\n' "$REPO_ROOT/layers/phase5e1/$rel"
    else
        printf '%s\n' "$REPO_ROOT/layers/phase5e/$rel"
    fi
}

REPLACED=(
  app/integrations/vmware/index.php
  app/integrations/vmware/workspace-result.php
  app/integrations/vmware/vm-card-result.php
  app/sections/menu/menu-tools-admin.php
)
CREATED=(
  functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php
)

for rel in "${!SOURCE_BLOB[@]}"; do
    src="$(source_path "$rel")"
    [[ -f "$src" ]] || fail "repository Phase 5E.1 source missing: $rel"
    [[ "$(hash_file "$src")" == "${SOURCE_BLOB[$rel]}" ]] || fail "repository Phase 5E.1 source drift: $rel"
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$src" "$STAGE/$rel"
done

for rel in "${!SOURCE_BLOB[@]}"; do
    php -l "$STAGE/$rel" >/dev/null || fail "candidate PHP lint failed: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_TLS_FROM_CONNECTOR_SETTINGS_V1' "$STAGE/app/integrations/vmware/index.php" || fail "candidate TLS settings marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_GUEST_OS_IP_LINKS_TLS_SETTINGS_V1' "$STAGE/app/integrations/vmware/vm-card-result.php" || fail "candidate VM-card feedback marker missing"
grep -Fq 'exact_unique_ip' "$STAGE/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php" || fail "candidate exact IP resolver marker missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_GLOBAL_NAV_PHASE5E1_V1' "$STAGE/app/sections/menu/menu-tools-admin.php" || fail "candidate global Integrations navigation marker missing"

for rel in app/integrations/vmware/index.php app/integrations/vmware/workspace-result.php app/integrations/vmware/vm-card-result.php; do
    if grep -Fq 'allow_insecure_tls' "$STAGE/$rel"; then fail "legacy TLS acknowledgement field remains in candidate $rel"; fi
done

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' "$STAGE/app/integrations/vmware/workspace-result.php" "$STAGE/app/integrations/vmware/vm-card-result.php" "$STAGE/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"; then
    fail "unexpected phpIPAM write API in Phase 5E.1 candidate"
fi
if grep -Eqi '\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)[[:space:]]' "$STAGE/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"; then
    fail "unexpected SQL write verb in Phase 5E.1 IP resolver candidate"
fi

echo "Phase 5E.1 feedback pre-commit checks: OK"

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
for rel in \
  app/integrations/vmware/index.php \
  app/integrations/vmware/workspace-result.php \
  app/integrations/vmware/vm-card-result.php \
  app/sections/menu/menu-tools-admin.php; do
    cp -a "$BACKUP_DIR/$rel" "$TARGET/$rel"
done
echo "PHASE5E1_FEEDBACK_ROLLBACK_OK"
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5E.1 feedback backup / rollback prepared: $BACKUP"

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
    [[ "$(hash_file "$TARGET/$rel")" == "${SOURCE_BLOB[$rel]}" ]] || fail "post-update Phase 5E.1 blob mismatch: $rel"
done

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5e1-feedback.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo "PHASE5E1_FEEDBACK_INSTALL_OK"
echo "Backup / rollback: $BACKUP"
echo "Phase 5D backend/fallback remains installed and verified."
echo "Phase 5E.1 changes only UX/read-only projection and global navigation; no schema, connector, credential, keyring, Rackspace or scheduler changes were made."
