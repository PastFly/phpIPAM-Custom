#!/usr/bin/env bash
set -euo pipefail

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

declare -A EXPECTED_BLOB=(
    ["app/integrations/registry.php"]="45f841df2e052a92da44b6e3deb2911844f4d816"
    ["app/admin/integrations/index.php"]="c5a7d8b4b3248e6eb561e9cb7c5af0b82d59e3d4"
    ["app/admin/integrations/vmware-test-result.php"]="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"
    ["functions/classes/class.IntegrationVmwareVcenter.php"]="2ca0d021cbdb11e2b4e82185eed4c47c77a43f4c"
)

for rel in "${!EXPECTED_BLOB[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "missing Phase 5A file: $rel"
    actual="$(hash_file "$TARGET/$rel")"
    [[ "$actual" == "${EXPECTED_BLOB[$rel]}" ]] || fail "Phase 5A blob mismatch: $rel expected ${EXPECTED_BLOB[$rel]}, got $actual"
done

echo "Exact VMware Phase 5A source files: OK (4)"

# Existing Phase 4 routing and navigation must remain intact.
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1' "$TARGET/index.php" || fail "Phase 4 root route marker missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_NAV_V1' "$TARGET/app/sections/menu/menu-tools.php" || fail "Phase 4 dynamic navigation marker missing"
grep -Fq 'PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1' "$TARGET/app/admin/admin-menu-config.php" || fail "Phase 4 admin navigation marker missing"

echo "Phase 4 routing/navigation inheritance: OK"

ADMIN="$TARGET/app/admin/integrations/index.php"
ENDPOINT="$TARGET/app/admin/integrations/vmware-test-result.php"
CLIENT="$TARGET/functions/classes/class.IntegrationVmwareVcenter.php"
REGISTRY="$TARGET/app/integrations/registry.php"

# UI and endpoint authorization/security contract.
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_VCENTER_TEST_UI_V1' "$ADMIN" || fail "VMware admin UI marker missing"
grep -Fq 'csrf_cookie("create", "integration_vmware_vcenter_test")' "$ADMIN" || fail "VMware CSRF token creation missing"
grep -Fq 'name="verify_tls" value="1" checked' "$ADMIN" || fail "TLS verification is not enabled by default"
grep -Fq "\$('#vmware-vcenter-password').val('');" "$ADMIN" || fail "browser password clearing missing"
grep -Fq '!$User->is_admin(false)' "$ENDPOINT" || fail "VMware test endpoint admin guard missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_test"' "$ENDPOINT" || fail "VMware test endpoint CSRF validation missing"

for forbidden in 'object_modify' 'INSERT INTO' 'UPDATE integrations' 'error_log' 'var_dump' 'print_r'; do
    if grep -Fq "$forbidden" "$ENDPOINT"; then
        fail "forbidden endpoint persistence/debug primitive present: $forbidden"
    fi
done

echo "VMware endpoint auth/CSRF/no-persistence invariants: OK"

# Client transport safety and read-only scope.
grep -Fq 'vCenter endpoint must use HTTPS' "$CLIENT" || fail "HTTPS-only endpoint validation missing"
grep -Fq 'CURLOPT_FOLLOWLOCATION => false' "$CLIENT" || fail "redirect blocking missing"
grep -Fq 'CURLOPT_SSL_VERIFYPEER' "$CLIENT" || fail "TLS verification control missing"
grep -Fq 'CURLPROTO_HTTPS' "$CLIENT" || fail "HTTPS cURL protocol restriction missing"
grep -Fq '/api/vcenter/datacenter' "$CLIENT" || fail "modern Datacenter read endpoint missing"
grep -Fq '/rest/vcenter/datacenter' "$CLIENT" || fail "legacy Datacenter fallback missing"
grep -Fq 'DELETE' "$CLIENT" || fail "session cleanup missing"
if grep -Eq '(/api/vcenter/(vm|host|cluster).*(POST|PATCH|PUT)|CURLOPT_UPLOAD|object_modify|INSERT INTO|UPDATE )' "$CLIENT"; then
    fail "unexpected write primitive detected in VMware client"
fi

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_VCENTER_PHASE5A_V1' "$REGISTRY" || fail "VMware registry marker missing"
grep -Fq '"mode" => "read-only"' "$REGISTRY" || fail "VMware registry is not read-only"

echo "VMware client transport/read-only invariants: OK"

for rel in \
    app/integrations/registry.php \
    app/admin/integrations/index.php \
    app/admin/integrations/vmware-test-result.php \
    functions/classes/class.IntegrationVmwareVcenter.php; do
    php -l "$TARGET/$rel" >/dev/null || fail "PHP lint failed: $rel"
done

echo "VMware Phase 5A PHP lint: OK (4 files)"
echo
echo "VMWARE_PHASE5A_VERIFY_OK: transient vCenter connection-test layer passes static verification."
