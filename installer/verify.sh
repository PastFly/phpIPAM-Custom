#!/usr/bin/env bash
set -euo pipefail

# Regression verifier for normalized phpIPAM Custom build.
TARGET="${1:-/var/www/html}"

command -v git >/dev/null 2>&1 || { echo "ERROR: git is required"; exit 1; }
command -v php >/dev/null 2>&1 || { echo "ERROR: php is required"; exit 1; }

hash_file() { git hash-object "$1"; }
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target not found: $TARGET"

declare -A EXPECTED=(
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

for rel in "${!EXPECTED[@]}"; do
    file="$TARGET/$rel"
    [[ -f "$file" ]] || fail "missing: $rel"
    actual="$(hash_file "$file")"
    [[ "$actual" == "${EXPECTED[$rel]}" ]] || fail "blob mismatch: $rel"
done

echo "Exact normalized source files: OK (${#EXPECTED[@]})"

RACKSPACE="$TARGET/functions/classes/class.Rackspace.php"
DRAW_RACK="$TARGET/app/tools/racks/draw_rack.php"
TOOLS_MENU="$TARGET/app/tools/tools-menu.php"
CSS_LIGHT="$TARGET/css/bootstrap/bootstrap-custom.css"
CSS_DARK="$TARGET/css/bootstrap/bootstrap-custom-dark.css"

[[ -f "$RACKSPACE" ]] || fail "missing Rackspace class"
[[ -f "$DRAW_RACK" ]] || fail "missing draw_rack.php"
[[ -f "$TOOLS_MENU" ]] || fail "missing tools-menu.php"

# Normalized Rackspace keeps strict free-U support, mirrors Full Depth rack-content
# occupancy server-side, and removes superseded SVG ID injection.
grep -Fq 'public function free_u($rack, $rack_devices, $rack_contents, $current_device = null, $force_no_overlap = false)' "$RACKSPACE" || fail "force_no_overlap signature missing"
grep -Fq 'if ($force_no_overlap || !$this->settings->rackAllowOverlap) {' "$RACKSPACE" || fail "strict free-U guard missing"
grep -Fq 'PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP' "$RACKSPACE" || fail "Full Depth rack-content backend overlap protection missing"
! grep -Fq 'RACK_V333_DEVICE_ID' "$RACKSPACE" || fail "superseded RACK_V333_DEVICE_ID still present in Rackspace"
! grep -Fq 'data-device-id' "$RACKSPACE" || fail "superseded SVG data-device-id still present"

# draw_rack.php is deliberately pristine upstream in normalized build.
[[ "$(hash_file "$DRAW_RACK")" == "974b750221c5d0af38e4f81a348e60c029a690fb" ]] || fail "draw_rack.php is not pristine v1.8.2"
[[ "$(hash_file "$TOOLS_MENU")" == "01fe8886ef4d8122894076ccce613b015d89c355" ]] || fail "tools-menu.php is not pristine v1.8.2"

for css in "$CSS_LIGHT" "$CSS_DARK"; do
    [[ -f "$css" ]] || fail "missing CSS file: $css"
    count="$(grep -Fc 'PHPIPAM_OUR_UI_MENU_V12_STYLE' "$css" || true)"
    [[ "$count" == "1" ]] || fail "sidebar CSS marker count is $count in $css"
    grep -Fq 'overflow-wrap: anywhere;' "$css" || fail "sidebar wrap rule missing in $css"
done

echo "Normalized cleanup invariants: OK"

# PHP syntax validation across every changed/custom PHP file plus Rackspace.
PHP_FILES=(
    "functions/classes/class.Rackspace.php"
    "app/admin/devices/edit-rack-dropdown.php"
    "app/admin/devices/edit-result.php"
    "app/admin/devices/edit.php"
    "app/admin/racks/edit-rack-devices-free-u.php"
    "app/admin/racks/edit-rack-devices-result.php"
    "app/admin/racks/edit-rack-devices.php"
    "app/tools/racks/print-racks-map.php"
    "app/tools/racks/print-single-rack.php"
    "app/tools/racks/rack-v3-move.php"
    "app/tools/racks/rack-v3.1-controller.php"
    "app/tools/racks/rack-v3.3-data.php"
    "app/tools/racks/rack-v3.3-map-controller.php"
    "app/tools/racks/rack-v3.3-move.php"
    "app/tools/tools-menu-config.php"
)

for rel in "${PHP_FILES[@]}"; do
    php -l "$TARGET/$rel" >/dev/null || fail "PHP lint failed: $rel"
done

echo "PHP lint: OK (${#PHP_FILES[@]} files)"

# Behavioral source invariants.
grep -Fq 'RACK_V314_PARENT_FRONT_REAR' "$TARGET/app/tools/racks/rack-v3.1-controller.php" || fail "V3.1.4 controller marker missing"
grep -Fq 'RACK_V335_LIVE_RACK_DATA' "$TARGET/app/tools/racks/rack-v3.3-map-controller.php" || fail "V3.3.5 controller marker missing"
grep -Fq 'rack-v3.3-data.php' "$TARGET/app/tools/racks/rack-v3.3-map-controller.php" || fail "live rack data endpoint not wired"
grep -Fq 'rack-v3.3-map-controller.php' "$TARGET/app/tools/racks/print-racks-map.php" || fail "cross-rack controller include missing"
grep -Fq 'rack-v3.1-controller.php' "$TARGET/app/tools/racks/print-single-rack.php" || fail "single-rack controller include missing"
grep -Fq 'rack_v2_json_error' "$TARGET/app/admin/racks/edit-rack-devices-free-u.php" || fail "strict free-U endpoint missing"

echo "Behavioral source invariants: OK"
echo
echo "VERIFY_OK: normalized phpIPAM Custom source passes static verification."
