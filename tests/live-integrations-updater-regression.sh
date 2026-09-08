#!/usr/bin/env bash
set -euo pipefail

# Regression gate for the state-specific production Integrations updater.
# Builds a throw-away phpIPAM 1.8.2 tree, overlays the exact 2026-09-07 live
# runtime capture, applies the updater, verifies it, then exercises rollback.
# Never touches /var/www/html.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
WORK="${TMPDIR:-/tmp}/phpipam-live-integrations-regression-$STAMP"
ARCHIVE="$WORK/phpipam-v1.8.2.tgz"
EXTRACT="$WORK/extracted"
URL="https://github.com/phpipam/phpipam/releases/download/v1.8.2/phpipam-v1.8.2.tgz"
EXPECTED_SHA256="12745b9cc9f937b219043cc39ee6533b95f9e8ed8b4381ea6f6d96005487bf8d"
SNAPSHOT="$REPO_ROOT/snapshots/20260907-152138/files"
UPDATER="$REPO_ROOT/installer/install-live-integrations-exact-20260907.sh"
VERIFIER="$REPO_ROOT/installer/verify-integrations-framework-v1.sh"

fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

mkdir -p "$WORK" "$EXTRACT"

bash -n "$UPDATER"
bash -n "$VERIFIER"
[[ -d "$SNAPSHOT" ]] || fail "snapshot source tree missing: $SNAPSHOT"

echo "Live Integrations updater regression"
echo "  work: $WORK"

if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 3 --connect-timeout 15 -o "$ARCHIVE" "$URL"
elif command -v wget >/dev/null 2>&1; then
    wget -q -O "$ARCHIVE" "$URL"
else
    fail "curl or wget is required"
fi

actual="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
[[ "$actual" == "$EXPECTED_SHA256" ]] || fail "official phpIPAM archive SHA256 mismatch"

tar -xzf "$ARCHIVE" -C "$EXTRACT"

TARGET="$EXTRACT"
if [[ ! -f "$TARGET/functions/functions.php" ]]; then
    mapfile -t candidates < <(find "$EXTRACT" -mindepth 1 -maxdepth 1 -type d -print)
    if [[ "${#candidates[@]}" -eq 1 && -f "${candidates[0]}/functions/functions.php" ]]; then
        TARGET="${candidates[0]}"
    else
        fail "could not identify extracted phpIPAM application root"
    fi
fi

echo "  pristine tree: $TARGET"

# Overlay the exact captured cumulative custom source surface. The five core
# files used by Integrations were not customized in that capture and therefore
# remain pristine official v1.8.2, matching the real live preflight.
cp -a "$SNAPSHOT/." "$TARGET/"

[[ "$(hash_file "$TARGET/functions/classes/class.Rackspace.php")" == "c2c9a7424e6caf996633688dea87dfbc594d7eb4" ]] || \
    fail "simulated live Rackspace blob mismatch"
! grep -Fq 'PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP' "$TARGET/functions/classes/class.Rackspace.php" || \
    fail "simulated live state unexpectedly contains Phase 1 deep marker"

echo "  exact live-state simulation: OK"

bash "$UPDATER" "$TARGET"
bash "$VERIFIER" "$TARGET"

[[ "$(hash_file "$TARGET/functions/classes/class.Rackspace.php")" == "c2c9a7424e6caf996633688dea87dfbc594d7eb4" ]] || \
    fail "updater changed Rackspace"

echo "  live updater apply + verify: OK"

mapfile -t backups < <(find "$TARGET" -mindepth 1 -maxdepth 1 -type d -name '.phpipam-custom-live-integrations-v1-backup-*' -print)
[[ "${#backups[@]}" -eq 1 ]] || fail "expected exactly one updater backup, found ${#backups[@]}"
BACKUP="${backups[0]}"
[[ -x "$BACKUP/rollback.sh" ]] || fail "rollback script missing"

bash "$BACKUP/rollback.sh"

# Existing core must be exactly restored.
declare -A CORE_BLOB=(
    ["index.php"]="bd9d79351cc782ba477d30e9f748720f7bfa6ce3"
    ["app/sections/index.php"]="0c0860e6dc4001ab67aef5dea7436f73efbf82a8"
    ["app/sections/menu/menu-tools.php"]="6be8bed21ec9782c4825fd3785a4da3adb48929b"
    ["app/sections/menu/menu-static.php"]="b19d4e0d1af944a44ed180f181e29fa532c6e629"
    ["app/admin/admin-menu-config.php"]="f6bfa72ef05426a14a5da9bb4d37d3d2b7aec9ce"
)

for rel in "${!CORE_BLOB[@]}"; do
    [[ "$(hash_file "$TARGET/$rel")" == "${CORE_BLOB[$rel]}" ]] || fail "rollback core mismatch: $rel"
done

for rel in \
    app/integrations/registry.php \
    app/integrations/index.php \
    app/admin/integrations/index.php; do
    [[ ! -e "$TARGET/$rel" ]] || fail "rollback did not remove new file: $rel"
done

[[ ! -d "$TARGET/app/integrations" ]] || fail "rollback left created app/integrations directory"
[[ ! -d "$TARGET/app/admin/integrations" ]] || fail "rollback left created app/admin/integrations directory"
[[ "$(hash_file "$TARGET/functions/classes/class.Rackspace.php")" == "c2c9a7424e6caf996633688dea87dfbc594d7eb4" ]] || \
    fail "rollback altered Rackspace"

echo "  rollback exact restoration: OK"
echo
echo "LIVE_INTEGRATIONS_UPDATER_REGRESSION_OK"
echo "Throw-away tree retained at: $WORK"
