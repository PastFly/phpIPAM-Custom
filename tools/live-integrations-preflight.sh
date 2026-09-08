#!/usr/bin/env bash
set -euo pipefail

# Read-only live preflight for Phase 4 Integrations.
# It does not create, modify, delete or chmod anything under the target tree.

TARGET="${1:-/var/www/html}"

for cmd in git php; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "ERROR: required command not found: $cmd" >&2
        exit 2
    }
done

[[ -d "$TARGET" ]] || {
    echo "ERROR: target not found: $TARGET" >&2
    exit 2
}

blob() {
    git hash-object "$1"
}

show_blob() {
    local rel="$1"
    local file="$TARGET/$rel"
    if [[ -f "$file" ]]; then
        printf 'BLOB\t%s\t%s\n' "$rel" "$(blob "$file")"
    else
        printf 'MISSING\t%s\n' "$rel"
    fi
}

echo "phpIPAM Custom Phase 4 live preflight (READ ONLY)"
echo "target=$TARGET"
echo

RACKSPACE="$TARGET/functions/classes/class.Rackspace.php"
CAPTURED_RACKSPACE_BLOB="c2c9a7424e6caf996633688dea87dfbc594d7eb4"

if [[ ! -f "$RACKSPACE" ]]; then
    echo "CLASSIFICATION=UNKNOWN"
    echo "ERROR: functions/classes/class.Rackspace.php missing"
    exit 3
fi

rack_blob="$(blob "$RACKSPACE")"
phase1_strict=0
phase1_deep=0

grep -Fq 'public function free_u($rack, $rack_devices, $rack_contents, $current_device = null, $force_no_overlap = false)' "$RACKSPACE" && phase1_strict=1 || true
grep -Fq 'PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP' "$RACKSPACE" && phase1_deep=1 || true

if [[ "$phase1_strict" -eq 1 && "$phase1_deep" -eq 1 ]]; then
    classification="PHASE1_NORMALIZED_OR_LATER"
elif [[ "$rack_blob" == "$CAPTURED_RACKSPACE_BLOB" ]]; then
    classification="EXACT_20260907_CAPTURE_RACKSPACE"
else
    classification="UNKNOWN_OR_INTERMEDIATE"
fi

printf 'CLASSIFICATION=%s\n' "$classification"
printf 'RACKSPACE_BLOB=%s\n' "$rack_blob"
printf 'PHASE1_STRICT_FREE_U=%s\n' "$phase1_strict"
printf 'PHASE1_DEEP_RACK_CONTENT_FIX=%s\n' "$phase1_deep"
echo

# Core files Phase 4 needs to patch. They are expected to remain pristine
# v1.8.2 even in the accepted rack runtime/Phase 1 normalized build.
declare -A EXPECTED_CORE=(
    ["index.php"]="bd9d79351cc782ba477d30e9f748720f7bfa6ce3"
    ["app/sections/index.php"]="0c0860e6dc4001ab67aef5dea7436f73efbf82a8"
    ["app/sections/menu/menu-tools.php"]="6be8bed21ec9782c4825fd3785a4da3adb48929b"
    ["app/sections/menu/menu-static.php"]="b19d4e0d1af944a44ed180f181e29fa532c6e629"
    ["app/admin/admin-menu-config.php"]="f6bfa72ef05426a14a5da9bb4d37d3d2b7aec9ce"
)

core_ok=1
for rel in \
    index.php \
    app/sections/index.php \
    app/sections/menu/menu-tools.php \
    app/sections/menu/menu-static.php \
    app/admin/admin-menu-config.php; do
    file="$TARGET/$rel"
    if [[ ! -f "$file" ]]; then
        printf 'CORE_MISSING\t%s\n' "$rel"
        core_ok=0
        continue
    fi
    actual="$(blob "$file")"
    expected="${EXPECTED_CORE[$rel]}"
    if [[ "$actual" == "$expected" ]]; then
        printf 'CORE_OK\t%s\t%s\n' "$rel" "$actual"
    else
        printf 'CORE_MISMATCH\t%s\texpected=%s\tactual=%s\n' "$rel" "$expected" "$actual"
        core_ok=0
    fi
done

echo

# Rack files relevant to deciding whether a Phase 1 live normalization step is
# required before Phase 4. These hashes are evidence only; no mutation occurs.
for rel in \
    app/tools/racks/rack-v3.3-map-controller.php \
    app/tools/racks/rack-v3.3-move.php \
    app/tools/racks/rack-v3.3-data.php \
    app/tools/racks/rack-v3.1-controller.php \
    app/tools/racks/rack-v3-move.php \
    app/tools/tools-menu-config.php \
    css/bootstrap/bootstrap-custom.css \
    css/bootstrap/bootstrap-custom-dark.css; do
    show_blob "$rel"
done

echo

integrations_present=0
for rel in \
    app/integrations/registry.php \
    app/integrations/index.php \
    app/admin/integrations/index.php; do
    if [[ -e "$TARGET/$rel" ]]; then
        printf 'INTEGRATIONS_PRESENT\t%s\t%s\n' "$rel" "$(blob "$TARGET/$rel" 2>/dev/null || echo non-file)"
        integrations_present=1
    else
        printf 'INTEGRATIONS_ABSENT\t%s\n' "$rel"
    fi
done

echo

php_lint_ok=1
for rel in \
    index.php \
    app/sections/index.php \
    app/sections/menu/menu-tools.php \
    app/sections/menu/menu-static.php \
    app/admin/admin-menu-config.php \
    functions/classes/class.Rackspace.php; do
    if [[ -f "$TARGET/$rel" ]]; then
        if php -l "$TARGET/$rel" >/dev/null; then
            printf 'PHP_LINT_OK\t%s\n' "$rel"
        else
            printf 'PHP_LINT_FAIL\t%s\n' "$rel"
            php_lint_ok=0
        fi
    fi
done

echo
printf 'CORE_PHASE4_PRECONDITIONS_OK=%s\n' "$core_ok"
printf 'INTEGRATIONS_ALREADY_PRESENT=%s\n' "$integrations_present"
printf 'PHP_LINT_OK=%s\n' "$php_lint_ok"

if [[ "$core_ok" -eq 1 && "$integrations_present" -eq 0 && "$php_lint_ok" -eq 1 ]]; then
    echo "PREFLIGHT_RESULT=READY_FOR_STATE_SPECIFIC_UPDATER_DESIGN"
    exit 0
fi

echo "PREFLIGHT_RESULT=REVIEW_REQUIRED"
exit 1
