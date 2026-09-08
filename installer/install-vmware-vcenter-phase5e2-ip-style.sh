#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5e2-ip-style-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() { rm -rf "$STAGE"; }
rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo "ERROR: Phase 5E.2 update failed after commit started; rolling back VM card." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}
trap cleanup EXIT
trap rollback_on_error ERR

fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }
for cmd in git php grep; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ "$EUID" -eq 0 ]] || fail "Phase 5E.2 updater must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

bash "$REPO_ROOT/tools/live-vmware-phase5e2-ip-style-preflight.sh" "$TARGET"

REL="app/integrations/vmware/vm-card-result.php"
SRC="$REPO_ROOT/layers/phase5e2/$REL"
EXPECTED_SOURCE="d1a6f3fba93d35054af7d6e05455b557ee5dbd18"
[[ -f "$SRC" ]] || fail "Phase 5E.2 source missing"
[[ "$(hash_file "$SRC")" == "$EXPECTED_SOURCE" ]] || fail "Phase 5E.2 repository source drift"
mkdir -p "$STAGE/$(dirname "$REL")"
cp "$SRC" "$STAGE/$REL"
php -l "$STAGE/$REL" >/dev/null || fail "candidate VM card PHP lint failed"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E2_NATIVE_IP_LINK_STYLE_V1' "$STAGE/$REL" || fail "candidate Phase 5E.2 marker missing"
grep -Fq 'class="phase5e-ip-link"' "$STAGE/$REL" || fail "candidate native IP link missing"
if grep -Fq 'title="Открыть адрес в phpIPAM"><code>' "$STAGE/$REL"; then fail "legacy code-styled IP link remains in candidate"; fi

echo "Phase 5E.2 IP-style pre-commit checks: OK"

mkdir -p "$BACKUP/$(dirname "$REL")"
cp -a "$TARGET/$REL" "$BACKUP/$REL"
cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
REL="app/integrations/vmware/vm-card-result.php"
cp -a "$BACKUP_DIR/$REL" "$TARGET/$REL"
echo "PHASE5E2_IP_STYLE_ROLLBACK_OK"
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"
echo "Phase 5E.2 backup / rollback prepared: $BACKUP"

DST="$TARGET/$REL"
TMP="$DST.phpipam-custom-tmp-$$"
cp -a "$DST" "$TMP"
cat "$STAGE/$REL" > "$TMP"
COMMIT_STARTED=1
mv -f "$TMP" "$DST"
[[ "$(hash_file "$DST")" == "$EXPECTED_SOURCE" ]] || fail "post-update Phase 5E.2 blob mismatch"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5e2-ip-style.sh" "$TARGET"
COMMIT_STARTED=0

echo
echo "PHASE5E2_IP_STYLE_INSTALL_OK"
echo "Backup / rollback: $BACKUP"
echo "Only the Phase 5E VM-card presentation was changed; no schema, connector, credential, keyring, Rackspace, scheduler or vCenter mutation was made."
