#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5B.3 persistent VMware Administration UI verifier.
# Read-only. Verifies source/security invariants on top of the already verified
# Phase 5B.3 foundation.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
INDEX_REL="app/admin/integrations/index.php"
ACTION_REL="app/admin/integrations/vmware-config-action.php"
TRANSIENT_REL="app/admin/integrations/vmware-test-result.php"
INDEX_BLOB="2aa9faaac09901d6b62ef17a32af87b8316f388c"
ACTION_BLOB="aa7c746775183a63b2fde2482a5acac61b93a7ce"
TRANSIENT_BLOB="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

for cmd in git php grep; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5b3-foundation.sh" "$TARGET"

for rel in "$INDEX_REL" "$ACTION_REL" "$TRANSIENT_REL"; do
    [[ -f "$TARGET/$rel" ]] || fail "required VMware Administration source missing: $rel"
    php -l "$TARGET/$rel" >/dev/null || fail "PHP lint failed: $rel"
done

[[ "$(git hash-object "$TARGET/$INDEX_REL")" == "$INDEX_BLOB" ]] || fail "persistent Administration index drift"
[[ "$(git hash-object "$TARGET/$ACTION_REL")" == "$ACTION_BLOB" ]] || fail "persistent configuration action endpoint drift"
[[ "$(git hash-object "$TARGET/$TRANSIENT_REL")" == "$TRANSIENT_BLOB" ]] || fail "Phase 5A transient endpoint drift"

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_UI_V1' "$TARGET/$INDEX_REL" || fail "persistent UI marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_ACTION_V1' "$TARGET/$ACTION_REL" || fail "persistent action marker missing"
grep -Fq '$User->check_user_session();' "$TARGET/$ACTION_REL" || fail "action endpoint session guard missing"
grep -Fq 'is_admin(false)' "$TARGET/$ACTION_REL" || fail "action endpoint administrator guard missing"
grep -Fq 'csrf_cookie("validate", "integration_vmware_vcenter_config"' "$TARGET/$ACTION_REL" || fail "action endpoint CSRF validation missing"
grep -Fq "array('save', 'test', 'delete_credential', 'delete_config', 'rotate')" "$TARGET/$ACTION_REL" || fail "action allowlist contract missing"
grep -Fq 'IntegrationSecretCipher::from_keyring_file()' "$TARGET/$ACTION_REL" || fail "encrypted keyring bootstrap missing"
grep -Fq 'load_runtime_config($id, false)' "$TARGET/$ACTION_REL" || fail "stored-credential runtime load missing"
grep -Fq 'mark_test_failure($id, $category)' "$TARGET/$ACTION_REL" || fail "sanitized failure persistence missing"
grep -Fq "'provider'," "$TARGET/$ACTION_REL" || fail "provider-scoped config delete guard missing"
grep -Fq 'IntegrationVmwareVcenterConfigStore::PROVIDER' "$TARGET/$ACTION_REL" || fail "provider-scoped delete value missing"
grep -Fq 'Sensitive exception details were suppressed.' "$TARGET/$ACTION_REL" || fail "generic exception suppression response missing"

# The persistent UI must never render secret envelope/key material fields and
# must preserve the transient password-clear behavior. Escape '$' in these
# double-quoted shell literals so jQuery selectors cannot be interpreted as
# shell command substitution.
if grep -Eq 'secret_envelope|secret_key_id' "$TARGET/$INDEX_REL"; then
    fail "Administration UI references secret database fields"
fi
grep -Fq "\$('#vmware-config-password').val('');" "$TARGET/$INDEX_REL" || fail "persistent password field clear behavior missing"
grep -Fq "\$('#vmware-vcenter-password').val('');" "$TARGET/$INDEX_REL" || fail "transient password field clear behavior missing"
grep -Fq 'allow_insecure_tls' "$TARGET/$INDEX_REL" || fail "explicit insecure TLS acknowledgement UI missing"
grep -Fq 'verify_tls' "$TARGET/$INDEX_REL" || fail "TLS verification control missing"

# No vCenter-mutating verbs/endpoints are introduced by the persistent action
# endpoint. The only actual vCenter client call remains test_connection().
if grep -Eq '/api/vcenter/.+(create|delete|update|patch)|/rest/.+(create|delete|update|patch)' "$TARGET/$ACTION_REL"; then
    fail "unexpected vCenter write endpoint pattern in persistent action source"
fi
grep -Fq 'test_connection(' "$TARGET/$ACTION_REL" || fail "stored read-only connection test call missing"

echo "Phase 5B.3 persistent UI source/security invariants: OK"
echo "PHASE5B3_UI_VERIFY_OK"
