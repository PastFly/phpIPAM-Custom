#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — normalized clean installer
# Source baseline: official phpIPAM v1.8.2
# This installer intentionally refuses unknown/cumulative targets.
# It does not modify the database schema.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SNAPSHOT_ROOT="$REPO_ROOT/snapshots/20260907-152138/files"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-normalized-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() {
    rm -rf "$STAGE"
}
trap cleanup EXIT

for cmd in git php python3; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "ERROR: required command not found: $cmd"
        exit 1
    }
done

[[ -d "$TARGET" ]] || {
    echo "ERROR: target phpIPAM root not found: $TARGET"
    exit 1
}

hash_file() {
    git hash-object "$1"
}

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

# Exact UTF-8 runtime files retained in the normalized build.
declare -A SOURCE_BLOB=(
    ["app/admin/devices/edit-rack-dropdown.php"]="5e29040b9166def4b37075da53bfa1ca01ab4f81"
    ["app/admin/devices/edit-result.php"]="10b9e2eb1874a1107916a3876e72426f641fb540"
    ["app/admin/devices/edit.php"]="d0d4d27c31ff178b7cd65b648de6ff41fc27be6f"
    ["app/admin/racks/edit-rack-devices-free-u.php"]="29b26706e2f3d37357ffcb3a66113edd3c0780f6"
    ["app/admin/racks/edit-rack-devices-result.php"]="c7d1c63fe586e023e62555ae78a761cdfd8e1fb7"
    ["app/admin/racks/edit-rack-devices.php"]="a738ec367139e680f3d43eb9d0abf34b1ccf0ec7"
    ["app/tools/racks/print-racks-map.php"]="b2fc9db312c3ef740fa7cb43583af8cbd38f4717"
    ["app/tools/racks/print-single-rack.php"]="9e0ae0d084015578055e389837ea6006dd30a86d"
    ["app/tools/racks/rack-v3-move.php"]="9818f072873746d3c8b885201513a831428ae472"
    ["app/tools/racks/rack-v3.1-controller.php"]="a130d650dfeac0e692c8cbae9d3dde94dfd305ad"
    ["app/tools/racks/rack-v3.3-data.php"]="bf333ad6a02c673ba158104d5612d65c399c2bf1"
    ["app/tools/racks/rack-v3.3-map-controller.php"]="aaa8df49b4e00308e201f553a7571fdc187bd634"
    ["app/tools/racks/rack-v3.3-move.php"]="6c87d6d676c0d9a86c00c5062a168e700b9d6364"
    ["app/tools/tools-menu-config.php"]="56e2b0707fb06ff1f8c5598fb593a3039c04950c"
)

# Upstream v1.8.2 preconditions for files that are replaced.
declare -A UPSTREAM_BLOB=(
    ["app/admin/devices/edit-rack-dropdown.php"]="ab6da10374ae7e28bcfa41ccfb0080910a625449"
    ["app/admin/devices/edit-result.php"]="8e3ecd2f499f5e66027b53651422d2957796438b"
    ["app/admin/devices/edit.php"]="b6c61c8a213b0554190ad237ad8b7150ec1397f3"
    ["app/admin/racks/edit-rack-devices-result.php"]="a2bf902293df432805ff0a61eb615ed24931d19a"
    ["app/admin/racks/edit-rack-devices.php"]="7cf82e6bf4095902c9fe6ad1e026abfc9c3331f1"
    ["app/tools/racks/print-racks-map.php"]="778f99f46e7fdb66d92728da317302fce3ff22bc"
    ["app/tools/racks/print-single-rack.php"]="9e9dce6c8282b422f44067c9dfc52f4b7b51f44a"
    ["app/tools/tools-menu-config.php"]="f5c3f05418a8a280e8a98c8fe75972411be0fc0b"
)

NEW_FILES=(
    "app/admin/racks/edit-rack-devices-free-u.php"
    "app/tools/racks/rack-v3-move.php"
    "app/tools/racks/rack-v3.1-controller.php"
    "app/tools/racks/rack-v3.3-data.php"
    "app/tools/racks/rack-v3.3-map-controller.php"
    "app/tools/racks/rack-v3.3-move.php"
)

RACKSPACE="functions/classes/class.Rackspace.php"
RACKSPACE_UPSTREAM="7686c174273c3b454c79a8cda567744d9182a8d4"
CSS_LIGHT="css/bootstrap/bootstrap-custom.css"
CSS_LIGHT_UPSTREAM="b1c84072bc3d5665ead8391037204a7b72b42be0"
CSS_DARK="css/bootstrap/bootstrap-custom-dark.css"
CSS_DARK_UPSTREAM="95e64ad3a61f273ca440322b80ca00b6950b65a6"
DRAW_RACK="app/tools/racks/draw_rack.php"
DRAW_RACK_UPSTREAM="974b750221c5d0af38e4f81a348e60c029a690fb"
TOOLS_MENU="app/tools/tools-menu.php"
TOOLS_MENU_UPSTREAM="01fe8886ef4d8122894076ccce613b015d89c355"

is_new_file() {
    local rel="$1"
    local x
    for x in "${NEW_FILES[@]}"; do
        [[ "$x" == "$rel" ]] && return 0
    done
    return 1
}

echo "Phase 0 normalized clean-install preflight"
echo "  repo:   $REPO_ROOT"
echo "  target: $TARGET"

# Verify repository source blobs before touching target.
for rel in "${!SOURCE_BLOB[@]}"; do
    src="$SNAPSHOT_ROOT/$rel"
    [[ -f "$src" ]] || fail "snapshot source missing: $rel"
    actual="$(hash_file "$src")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || \
        fail "snapshot blob mismatch for $rel: expected ${SOURCE_BLOB[$rel]}, got $actual"
done

echo "  snapshot source blobs: OK"

# Verify target is pristine v1.8.2 for all upstream files we will replace.
for rel in "${!UPSTREAM_BLOB[@]}"; do
    dst="$TARGET/$rel"
    [[ -f "$dst" ]] || fail "upstream target file missing: $rel"
    actual="$(hash_file "$dst")"
    [[ "$actual" == "${UPSTREAM_BLOB[$rel]}" ]] || \
        fail "$rel is not pristine phpIPAM v1.8.2 (expected ${UPSTREAM_BLOB[$rel]}, got $actual)"
done

for rel in "$RACKSPACE" "$CSS_LIGHT" "$CSS_DARK" "$DRAW_RACK" "$TOOLS_MENU"; do
    [[ -f "$TARGET/$rel" ]] || fail "upstream target file missing: $rel"
done

[[ "$(hash_file "$TARGET/$RACKSPACE")" == "$RACKSPACE_UPSTREAM" ]] || fail "$RACKSPACE is not pristine v1.8.2"
[[ "$(hash_file "$TARGET/$CSS_LIGHT")" == "$CSS_LIGHT_UPSTREAM" ]] || fail "$CSS_LIGHT is not pristine v1.8.2"
[[ "$(hash_file "$TARGET/$CSS_DARK")" == "$CSS_DARK_UPSTREAM" ]] || fail "$CSS_DARK is not pristine v1.8.2"
[[ "$(hash_file "$TARGET/$DRAW_RACK")" == "$DRAW_RACK_UPSTREAM" ]] || fail "$DRAW_RACK is not pristine v1.8.2"
[[ "$(hash_file "$TARGET/$TOOLS_MENU")" == "$TOOLS_MENU_UPSTREAM" ]] || fail "$TOOLS_MENU is not pristine v1.8.2"

for rel in "${NEW_FILES[@]}"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "custom file already exists on clean target: $rel"
done

echo "  pristine v1.8.2 target: OK"

# Build all candidates in staging first.
for rel in "${!SOURCE_BLOB[@]}"; do
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$SNAPSHOT_ROOT/$rel" "$STAGE/$rel"
done

mkdir -p "$STAGE/$(dirname "$RACKSPACE")"
cp "$TARGET/$RACKSPACE" "$STAGE/$RACKSPACE"

python3 - "$STAGE/$RACKSPACE" <<'PY'
from pathlib import Path
import sys

p = Path(sys.argv[1])
s = p.read_text(encoding="utf-8")

old_sig = "public function free_u($rack, $rack_devices, $rack_contents, $current_device = null) {"
new_sig = "public function free_u($rack, $rack_devices, $rack_contents, $current_device = null, $force_no_overlap = false) {"
old_guard = "if (!$this->settings->rackAllowOverlap) {"
new_guard = "if ($force_no_overlap || !$this->settings->rackAllowOverlap) {"

old_content_overlap = """\t\t\tforeach (range($c->rack_start,$c->rack_start + $c->rack_size - 1) as $ru) {
\t\t\t\tif (in_array($ru,$request)) return True;
\t\t\t}
"""
new_content_overlap = old_content_overlap + """\t\t\t// PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP
\t\t\t// Full Depth rack content occupies the same physical U on both sides.
\t\t\tif ($c->rack_deep && $c->rack_start>$rack->size) {
\t\t\t\tforeach (range($c->rack_start - $rack->size,$c->rack_start - $rack->size + $c->rack_size - 1) as $ru) {
\t\t\t\t\tif (in_array($ru,$request)) return True;
\t\t\t\t}
\t\t\t} elseif ($c->rack_deep) {
\t\t\t\tforeach (range($c->rack_start + $rack->size,$c->rack_start + $rack->size + $c->rack_size - 1) as $ru) {
\t\t\t\t\tif (in_array($ru,$request)) return True;
\t\t\t\t}
\t\t\t}
"""

if s.count(old_sig) != 1:
    raise SystemExit("Rackspace free_u signature precondition mismatch")
if s.count(old_guard) != 1:
    raise SystemExit("Rackspace overlap guard precondition mismatch")
if s.count(old_content_overlap) != 1:
    raise SystemExit("Rackspace rack-content overlap precondition mismatch")
if "PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP" in s:
    raise SystemExit("Rackspace deep rack-content overlap patch already present")

s = s.replace(old_sig, new_sig, 1)
s = s.replace(old_guard, new_guard, 1)
s = s.replace(old_content_overlap, new_content_overlap, 1)

# Deliberately do NOT reintroduce superseded RACK_V333_DEVICE_ID/data-device-id code.
if "RACK_V333_DEVICE_ID" in s or "data-device-id" in s:
    raise SystemExit("superseded V3.3.3 SVG identity leaked into normalized Rackspace")

p.write_text(s, encoding="utf-8")
PY

CSS_BLOCK='/* PHPIPAM_OUR_UI_MENU_V12_STYLE
 * Keep long localized tool names inside the fixed-width sidebar.
 */
.toolsMenu ul.list-group li.list-group-item {
    box-sizing: border-box;
    max-width: 100%;
    overflow: hidden;
    min-height: 32px;
}

.toolsMenu ul.list-group li.list-group-item > a {
    display: block;
    box-sizing: border-box;
    position: relative;
    width: 100%;
    max-width: 100%;
    padding-right: 18px;
    white-space: normal !important;
    overflow-wrap: anywhere;
    word-wrap: break-word;
    word-break: normal;
    line-height: 1.25;
}

.toolsMenu ul.list-group li.list-group-item > a > i.pull-right {
    position: absolute;
    right: 0;
    top: 0.15em;
    float: none !important;
    margin: 0;
}'

for rel in "$CSS_LIGHT" "$CSS_DARK"; do
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$TARGET/$rel" "$STAGE/$rel"
    printf '\n\n%s\n' "$CSS_BLOCK" >> "$STAGE/$rel"
done

# Syntax-check every PHP candidate before backup/install.
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || fail "candidate PHP lint failed: ${file#$STAGE/}"
done < <(find "$STAGE" -type f -name '*.php' -print0)

echo "  candidate PHP lint: OK"

# Backup exactly what will be changed. New files are recorded for rollback deletion.
mkdir -p "$BACKUP"
: > "$BACKUP/CREATED.txt"

for rel in "${!SOURCE_BLOB[@]}" "$RACKSPACE" "$CSS_LIGHT" "$CSS_DARK"; do
    dst="$TARGET/$rel"
    if [[ -e "$dst" ]]; then
        mkdir -p "$BACKUP/$(dirname "$rel")"
        cp -a "$dst" "$BACKUP/$rel"
    else
        printf '%s\n' "$rel" >> "$BACKUP/CREATED.txt"
    fi
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
    [[ "$rel" == "CREATED.txt" || "$rel" == "rollback.sh" ]] && continue
    dst="$TARGET/$rel"
    mkdir -p "$(dirname "$dst")"
    cp -a "$src" "$dst"
done < <(find "$BACKUP_DIR" -type f -print0)

echo "Rollback restored files from: $BACKUP_DIR"
ROLLBACK
chmod 700 "$BACKUP/rollback.sh"

echo "  backup: $BACKUP"

install_candidate() {
    local rel="$1"
    local src="$STAGE/$rel"
    local dst="$TARGET/$rel"
    local tmp="$dst.phpipam-custom-tmp-$$"

    mkdir -p "$(dirname "$dst")"

    if [[ -e "$dst" ]]; then
        cp -a "$dst" "$tmp"
        cat "$src" > "$tmp"
    else
        cp "$src" "$tmp"
        chown --reference="$(dirname "$dst")" "$tmp" 2>/dev/null || true
        chmod 0644 "$tmp"
    fi

    mv -f "$tmp" "$dst"
}

COMMIT_STARTED=1
for rel in "${!SOURCE_BLOB[@]}"; do
    install_candidate "$rel"
done
install_candidate "$RACKSPACE"
install_candidate "$CSS_LIGHT"
install_candidate "$CSS_DARK"

# draw_rack.php and tools-menu.php intentionally stay pristine upstream.
[[ "$(hash_file "$TARGET/$DRAW_RACK")" == "$DRAW_RACK_UPSTREAM" ]] || fail "$DRAW_RACK changed unexpectedly"
[[ "$(hash_file "$TARGET/$TOOLS_MENU")" == "$TOOLS_MENU_UPSTREAM" ]] || fail "$TOOLS_MENU changed unexpectedly"

# Post-install exact blob check for copied runtime sources.
for rel in "${!SOURCE_BLOB[@]}"; do
    actual="$(hash_file "$TARGET/$rel")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || \
        fail "post-install blob mismatch for $rel"
done

php -l "$TARGET/$RACKSPACE" >/dev/null || fail "post-install Rackspace PHP lint failed"

echo
echo "Normalized phpIPAM Custom install completed."
echo "Backup / rollback: $BACKUP"
echo "Run: $REPO_ROOT/installer/verify.sh '$TARGET'"
echo "No database schema changes were made."
