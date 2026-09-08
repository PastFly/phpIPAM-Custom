#!/usr/bin/env bash
set -euo pipefail

# Regression gate for the state-specific production Integrations updater.
#
# Production exact-state pins come from the Owner's read-only live preflight.
# Some cumulative historical files (notably exact live Rackspace/CSS) are not
# retained as normalized source files in the current repository. CI therefore
# keeps every production pin intact in the real updater, but uses a temporary
# updater copy whose sentinel hashes are rebound to a deterministic throw-away
# surrogate tree. This exercises staging, fail-closed patching, installation,
# verification and exact rollback without weakening production preconditions.
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
TEST_UPDATER="$REPO_ROOT/installer/.install-live-integrations-regression-$STAMP.sh"
VERIFIER="$REPO_ROOT/installer/verify-integrations-framework-v1.sh"
PRODUCTION_RACKSPACE_BLOB="c2c9a7424e6caf996633688dea87dfbc594d7eb4"

# Exact production sentinels captured by the Owner's 2026-09-08 preflight.
declare -A PRODUCTION_SENTINEL_BLOB=(
    ["app/tools/racks/rack-v3.3-map-controller.php"]="aaa8df49b4e00308e201f553a7571fdc187bd634"
    ["app/tools/racks/rack-v3.3-move.php"]="6c87d6d676c0d9a86c00c5062a168e700b9d6364"
    ["app/tools/racks/rack-v3.3-data.php"]="bf333ad6a02c673ba158104d5612d65c399c2bf1"
    ["app/tools/racks/rack-v3.1-controller.php"]="a130d650dfeac0e692c8cbae9d3dde94dfd305ad"
    ["app/tools/racks/rack-v3-move.php"]="9818f072873746d3c8b885201513a831428ae472"
    ["app/tools/tools-menu-config.php"]="56e2b0707fb06ff1f8c5598fb593a3039c04950c"
    ["css/bootstrap/bootstrap-custom.css"]="e3a3c1a4243a594a0bae30cf71236d7b95b77e23"
    ["css/bootstrap/bootstrap-custom-dark.css"]="d86155595df4d6bd636db94d74cf8b8861af73fd"
)

fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }
cleanup() { rm -f "$TEST_UPDATER"; }
trap cleanup EXIT

mkdir -p "$WORK" "$EXTRACT"

bash -n "$UPDATER"
bash -n "$VERIFIER"
[[ -d "$SNAPSHOT" ]] || fail "snapshot source tree missing: $SNAPSHOT"
grep -Fq "RACKSPACE_LIVE_BLOB=\"$PRODUCTION_RACKSPACE_BLOB\"" "$UPDATER" || \
    fail "production updater no longer pins the proven live Rackspace blob"
for rel in "${!PRODUCTION_SENTINEL_BLOB[@]}"; do
    grep -Fq "[\"$rel\"]=\"${PRODUCTION_SENTINEL_BLOB[$rel]}\"" "$UPDATER" || \
        fail "production updater no longer pins live sentinel: $rel"
done

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

# Overlay all durable cumulative source files retained in the normalized repo.
# The five Integrations core files remain pristine official v1.8.2, matching the
# actual live preflight.
cp -a "$SNAPSHOT/." "$TARGET/"

# Build a deterministic test-only Rackspace state: strict free_u is present,
# while the later Phase 1 Full Depth rack-content marker is absent.
SIM_RACKSPACE="$TARGET/functions/classes/class.Rackspace.php"
python3 - "$SIM_RACKSPACE" <<'PY'
from pathlib import Path
import sys

p = Path(sys.argv[1])
s = p.read_text(encoding="utf-8")
old_sig = "public function free_u($rack, $rack_devices, $rack_contents, $current_device = null) {"
new_sig = "public function free_u($rack, $rack_devices, $rack_contents, $current_device = null, $force_no_overlap = false) {"
old_guard = "if (!$this->settings->rackAllowOverlap) {"
new_guard = "if ($force_no_overlap || !$this->settings->rackAllowOverlap) {"
if s.count(old_sig) != 1:
    raise SystemExit(f"test Rackspace signature precondition mismatch: {s.count(old_sig)}")
if s.count(old_guard) != 1:
    raise SystemExit(f"test Rackspace guard precondition mismatch: {s.count(old_guard)}")
if "PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP" in s:
    raise SystemExit("test Rackspace unexpectedly already has Phase 1 deep marker")
s = s.replace(old_sig, new_sig, 1)
s = s.replace(old_guard, new_guard, 1)
p.write_text(s, encoding="utf-8")
PY

SIM_RACKSPACE_BLOB="$(hash_file "$SIM_RACKSPACE")"
grep -Fq 'public function free_u($rack, $rack_devices, $rack_contents, $current_device = null, $force_no_overlap = false)' "$SIM_RACKSPACE" || \
    fail "simulated strict free_u marker missing"
! grep -Fq 'PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP' "$SIM_RACKSPACE" || \
    fail "simulated live state unexpectedly contains Phase 1 deep marker"

# Use a temporary updater copy. Production updater pins remain untouched.
cp "$UPDATER" "$TEST_UPDATER"

# Rebind only the Rackspace expected blob in the temporary test copy.
python3 - "$TEST_UPDATER" "$PRODUCTION_RACKSPACE_BLOB" "$SIM_RACKSPACE_BLOB" <<'PY'
from pathlib import Path
import sys

p = Path(sys.argv[1])
production_blob = sys.argv[2]
sim_blob = sys.argv[3]
s = p.read_text(encoding="utf-8")
needle = f'RACKSPACE_LIVE_BLOB="{production_blob}"'
replacement = f'RACKSPACE_LIVE_BLOB="{sim_blob}"'
if s.count(needle) != 1:
    raise SystemExit(f"temporary updater Rackspace pin precondition mismatch: {s.count(needle)}")
p.write_text(s.replace(needle, replacement, 1), encoding="utf-8")
PY

# Rebind each live sentinel in the temporary copy to the corresponding file in
# the surrogate target. The real updater still requires the exact live hashes.
for rel in "${!PRODUCTION_SENTINEL_BLOB[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "surrogate sentinel file missing: $rel"
    surrogate_blob="$(hash_file "$TARGET/$rel")"
    production_blob="${PRODUCTION_SENTINEL_BLOB[$rel]}"
    python3 - "$TEST_UPDATER" "$rel" "$production_blob" "$surrogate_blob" <<'PY'
from pathlib import Path
import sys

p = Path(sys.argv[1])
rel = sys.argv[2]
production_blob = sys.argv[3]
surrogate_blob = sys.argv[4]
s = p.read_text(encoding="utf-8")
needle = f'["{rel}"]="{production_blob}"'
replacement = f'["{rel}"]="{surrogate_blob}"'
if s.count(needle) != 1:
    raise SystemExit(f"temporary updater sentinel pin precondition mismatch for {rel}: {s.count(needle)}")
p.write_text(s.replace(needle, replacement, 1), encoding="utf-8")
PY
done

bash -n "$TEST_UPDATER"
echo "  live-state mutation surrogate: OK"

bash "$TEST_UPDATER" "$TARGET"
bash "$VERIFIER" "$TARGET"

[[ "$(hash_file "$SIM_RACKSPACE")" == "$SIM_RACKSPACE_BLOB" ]] || fail "updater changed Rackspace"

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
[[ "$(hash_file "$SIM_RACKSPACE")" == "$SIM_RACKSPACE_BLOB" ]] || fail "rollback altered Rackspace"

echo "  rollback exact restoration: OK"
echo
echo "LIVE_INTEGRATIONS_UPDATER_REGRESSION_OK"
echo "Throw-away tree retained at: $WORK"
