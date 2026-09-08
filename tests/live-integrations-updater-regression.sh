#!/usr/bin/env bash
set -euo pipefail

# Regression gate for the state-specific production Integrations updater.
#
# The exact live Rackspace source is intentionally not durable normalized source
# in the current repository. The production updater nevertheless hard-pins its
# proven live blob. For CI mutation/rollback testing we create a deterministic
# Rackspace surrogate with the same strict-free-U / no-Phase1-deep-marker state,
# then patch only a temporary copy of the updater's expected Rackspace blob.
# All other live sentinels are overlaid from the durable 2026-09-07 snapshot.
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

# Overlay the exact captured cumulative source files that are retained in the
# normalized repository (rack controllers, tools menu config and CSS sentinels).
# The Integrations core files remain pristine official v1.8.2, matching the
# Owner's live preflight.
cp -a "$SNAPSHOT/." "$TARGET/"

# Build a deterministic test-only Rackspace state: strict free_u is present,
# while the later Phase 1 Full Depth rack-content marker is absent. Production
# does NOT use this surrogate; it is only to exercise updater/rollback mechanics.
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

# Use a temporary updater copy so the production script keeps its exact live
# blob pin unchanged in Git and in real deployments.
cp "$UPDATER" "$TEST_UPDATER"
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
bash -n "$TEST_UPDATER"

echo "  live-state mutation surrogate: OK ($SIM_RACKSPACE_BLOB)"

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
