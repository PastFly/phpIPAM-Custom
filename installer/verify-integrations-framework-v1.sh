#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-/var/www/html}"

command -v git >/dev/null 2>&1 || { echo "ERROR: git is required" >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "ERROR: php is required" >&2; exit 1; }

hash_file() { git hash-object "$1"; }
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target not found: $TARGET"

declare -A EXPECTED_SOURCE=(
    ["app/integrations/registry.php"]="2a03e1b96f5fc493eeee2adceceb3c717830d50b"
    ["app/integrations/index.php"]="7411f3bebc259c5b0535e0db57055c0b38aff36e"
    ["app/admin/integrations/index.php"]="11a26efaa9c51723afffbffebf13a0067f62c53f"
)

for rel in "${!EXPECTED_SOURCE[@]}"; do
    file="$TARGET/$rel"
    [[ -f "$file" ]] || fail "missing Integrations source: $rel"
    actual="$(hash_file "$file")"
    [[ "$actual" == "${EXPECTED_SOURCE[$rel]}" ]] || fail "Integrations source blob mismatch: $rel"
done

echo "Exact Integrations source files: OK (${#EXPECTED_SOURCE[@]})"

# Core routing/navigation markers.
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1' "$TARGET/index.php" || fail "top-level Integrations route missing"
grep -Fq 'include_once("app/integrations/index.php")' "$TARGET/index.php" || fail "Integrations page include missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_NAV_CONTEXT_V1' "$TARGET/app/sections/index.php" || fail "Integrations navbar context missing"
grep -Fq '$GET->page=="integrations"' "$TARGET/app/sections/index.php" || fail "Integrations navbar page condition missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_NAV_V1' "$TARGET/app/sections/menu/menu-tools.php" || fail "dynamic Integrations navigation missing"
grep -Fq 'array("integrations", null)' "$TARGET/app/sections/menu/menu-tools.php" || fail "dynamic Integrations route target missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_STATIC_NAV_V1' "$TARGET/app/sections/menu/menu-static.php" || fail "static Integrations navigation missing"
grep -Fq 'PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1' "$TARGET/app/admin/admin-menu-config.php" || fail "Administration Integrations menu missing"
grep -Fq '"integrations"           => _("integrations")' "$TARGET/app/admin/admin-menu-config.php" || fail "Administration Integrations inclusion guard missing"

echo "Integrations routing/navigation invariants: OK"

# Authentication and no-write foundation invariants.
grep -Fq '$User->check_user_session();' "$TARGET/app/integrations/index.php" || fail "Integrations page session guard missing"
grep -Fq '$User->check_user_session();' "$TARGET/app/admin/integrations/index.php" || fail "Admin Integrations session guard missing"
grep -Fq 'if (!$User->is_admin(false)) {' "$TARGET/app/admin/integrations/index.php" || fail "Admin Integrations administrator guard missing"

for rel in app/integrations/index.php app/admin/integrations/index.php; do
    ! grep -Fq '<form' "$TARGET/$rel" || fail "foundation must not expose credential/configuration form: $rel"
    ! grep -Fq '$_POST' "$TARGET/$rel" || fail "foundation must not accept POST writes: $rel"
    ! grep -Fq 'object_modify' "$TARGET/$rel" || fail "foundation must not write phpIPAM objects: $rel"
done

echo "Integrations authentication/no-write invariants: OK"

# Execute the metadata-only registry in isolation and validate its contract.
php -r '
$registry = require $argv[1];
if (!is_array($registry)) { fwrite(STDERR, "registry is not an array\n"); exit(1); }
$expected = array("vmware-vcenter", "hyper-v", "active-directory", "dns", "dhcp");
if (array_keys($registry) !== $expected) { fwrite(STDERR, "unexpected connector registry/order\n"); exit(2); }
$forbidden = array("endpoint", "username", "password", "token", "secret", "private_key", "client_secret", "bind_password", "credential");
foreach ($registry as $id => $connector) {
    if (!is_array($connector) || !isset($connector["id"], $connector["name"], $connector["state"], $connector["mode"])) {
        fwrite(STDERR, "invalid connector contract: $id\n"); exit(3);
    }
    if ($connector["id"] !== $id) { fwrite(STDERR, "connector id mismatch: $id\n"); exit(4); }
    if ($connector["mode"] !== "read-only") { fwrite(STDERR, "connector is not read-only: $id\n"); exit(5); }
    foreach ($forbidden as $key) {
        if (array_key_exists($key, $connector)) { fwrite(STDERR, "secret/config key present in registry: $id/$key\n"); exit(6); }
    }
}
if ($registry["vmware-vcenter"]["state"] !== "next") { fwrite(STDERR, "VMware vCenter is not the next connector\n"); exit(7); }
foreach (array("hyper-v", "active-directory", "dns", "dhcp") as $id) {
    if ($registry[$id]["state"] !== "planned") { fwrite(STDERR, "non-VMware connector must remain planned: $id\n"); exit(8); }
}
echo "Connector registry contract: OK (" . count($registry) . ")\n";
' "$TARGET/app/integrations/registry.php"

PHP_FILES=(
    "index.php"
    "app/sections/index.php"
    "app/sections/menu/menu-tools.php"
    "app/sections/menu/menu-static.php"
    "app/admin/admin-menu-config.php"
    "app/integrations/registry.php"
    "app/integrations/index.php"
    "app/admin/integrations/index.php"
)

for rel in "${PHP_FILES[@]}"; do
    php -l "$TARGET/$rel" >/dev/null || fail "PHP lint failed: $rel"
done

echo "Integrations PHP lint: OK (${#PHP_FILES[@]} files)"
echo
echo "INTEGRATIONS_VERIFY_OK: Phase 4 Integrations framework passes static verification."
