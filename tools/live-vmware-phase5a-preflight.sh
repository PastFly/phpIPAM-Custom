#!/usr/bin/env bash
set -euo pipefail

# READ ONLY classifier for deploying Phase 5A on the runtime-verified Phase 4
# cumulative live tree. It never creates, modifies or removes files.

TARGET="${1:-/var/www/html}"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

hash_file() {
    git hash-object "$1"
}

for cmd in git php; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

echo "phpIPAM Custom Phase 5A live preflight (READ ONLY)"
echo "target=$TARGET"
echo

# Exact Phase 4 source state that was owner/runtime verified before PR #5 merge.
declare -A PHASE4_BLOB=(
    ["app/integrations/registry.php"]="2a03e1b96f5fc493eeee2adceceb3c717830d50b"
    ["app/integrations/index.php"]="7411f3bebc259c5b0535e0db57055c0b38aff36e"
    ["app/admin/integrations/index.php"]="11a26efaa9c51723afffbffebf13a0067f62c53f"
)

PHASE4_OK=1
for rel in "${!PHASE4_BLOB[@]}"; do
    if [[ ! -f "$TARGET/$rel" ]]; then
        printf 'PHASE4_MISSING\t%s\n' "$rel"
        PHASE4_OK=0
        continue
    fi
    actual="$(hash_file "$TARGET/$rel")"
    if [[ "$actual" == "${PHASE4_BLOB[$rel]}" ]]; then
        printf 'PHASE4_OK\t%s\t%s\n' "$rel" "$actual"
    else
        printf 'PHASE4_DRIFT\t%s\texpected=%s\tactual=%s\n' "$rel" "${PHASE4_BLOB[$rel]}" "$actual"
        PHASE4_OK=0
    fi
done

# Routing/navigation should still be the runtime-verified Phase 4 framework.
ROUTING_OK=1
for spec in \
    "index.php|PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1" \
    "app/sections/menu/menu-tools.php|PHPIPAM_CUSTOM_INTEGRATIONS_NAV_V1" \
    "app/admin/admin-menu-config.php|PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1"; do
    rel="${spec%%|*}"
    marker="${spec#*|}"
    if [[ -f "$TARGET/$rel" ]] && grep -Fq "$marker" "$TARGET/$rel"; then
        printf 'ROUTING_OK\t%s\t%s\n' "$rel" "$marker"
    else
        printf 'ROUTING_MISSING\t%s\t%s\n' "$rel" "$marker"
        ROUTING_OK=0
    fi
done

# Phase 5A must not already be partially present.
PHASE5A_ABSENT=1
for rel in \
    app/admin/integrations/vmware-test-result.php \
    functions/classes/class.IntegrationVmwareVcenter.php; do
    if [[ -e "$TARGET/$rel" ]]; then
        printf 'PHASE5A_PRESENT\t%s\n' "$rel"
        PHASE5A_ABSENT=0
    else
        printf 'PHASE5A_ABSENT\t%s\n' "$rel"
    fi
done

# Keep a rack sentinel in the classifier because Phase 4 intentionally left the
# cumulative rack runtime untouched.
RACKSPACE_EXPECTED="c2c9a7424e6caf996633688dea87dfbc594d7eb4"
RACKSPACE_OK=0
if [[ -f "$TARGET/functions/classes/class.Rackspace.php" ]]; then
    RACKSPACE_ACTUAL="$(hash_file "$TARGET/functions/classes/class.Rackspace.php")"
    echo "RACKSPACE_BLOB=$RACKSPACE_ACTUAL"
    [[ "$RACKSPACE_ACTUAL" == "$RACKSPACE_EXPECTED" ]] && RACKSPACE_OK=1
else
    echo "RACKSPACE_BLOB=MISSING"
fi

# The product client requires PHP cURL at runtime. This probe executes only the
# local PHP CLI and performs no network access.
PHP_CURL_OK=0
if php -r 'exit(function_exists("curl_init") ? 0 : 1);'; then
    PHP_CURL_OK=1
fi

PHP_LINT_OK=1
for rel in \
    app/integrations/registry.php \
    app/integrations/index.php \
    app/admin/integrations/index.php; do
    if [[ -f "$TARGET/$rel" ]] && php -l "$TARGET/$rel" >/dev/null; then
        printf 'PHP_LINT_OK\t%s\n' "$rel"
    else
        printf 'PHP_LINT_FAIL\t%s\n' "$rel"
        PHP_LINT_OK=0
    fi
done

echo
echo "PHASE4_EXACT_SOURCE_OK=$PHASE4_OK"
echo "PHASE4_ROUTING_OK=$ROUTING_OK"
echo "PHASE5A_ALREADY_ABSENT=$PHASE5A_ABSENT"
echo "RACKSPACE_SENTINEL_OK=$RACKSPACE_OK"
echo "PHP_CURL_OK=$PHP_CURL_OK"
echo "PHP_LINT_OK=$PHP_LINT_OK"

if [[ "$PHASE4_OK" == "1" && "$ROUTING_OK" == "1" && "$PHASE5A_ABSENT" == "1" && "$RACKSPACE_OK" == "1" && "$PHP_CURL_OK" == "1" && "$PHP_LINT_OK" == "1" ]]; then
    echo "PREFLIGHT_RESULT=READY_FOR_PHASE5A_OWNER_APPROVAL"
    exit 0
fi

echo "PREFLIGHT_RESULT=NOT_READY_RECLASSIFY_BEFORE_DEPLOYMENT"
exit 4
