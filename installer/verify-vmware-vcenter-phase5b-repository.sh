#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CIPHER="$ROOT/layers/phase5b/functions/classes/class.IntegrationSecretCipher.php"
STORE="$ROOT/layers/phase5b/functions/classes/class.IntegrationVmwareVcenterConfigStore.php"
FORWARD="$ROOT/migrations/phase5b/001-create-custom-integrations.sql"
ROLLBACK="$ROOT/migrations/phase5b/001-rollback-custom-integrations.sql"
REGRESSION="$ROOT/tests/phase5b-secure-config-regression.php"
MIGRATION_TEST="$ROOT/tests/phase5b-migration-regression.sh"

for file in "$CIPHER" "$STORE" "$FORWARD" "$ROLLBACK" "$REGRESSION" "$MIGRATION_TEST"; do
    [[ -f "$file" ]] || { echo "ERROR: missing Phase 5B artifact: ${file#$ROOT/}" >&2; exit 1; }
done

php -l "$CIPHER" >/dev/null
php -l "$STORE" >/dev/null
php -l "$REGRESSION" >/dev/null
bash -n "$MIGRATION_TEST"

grep -Fq 'aes-256-gcm' "$CIPHER" || { echo 'ERROR: AES-256-GCM contract missing' >&2; exit 1; }
grep -Fq "DEFAULT_KEYRING_PATH = '/etc/phpipam-custom/integrations.key'" "$CIPHER" || { echo 'ERROR: external keyring path contract missing' >&2; exit 1; }
grep -Fq "'ct' => bin2hex" "$CIPHER" || { echo 'ERROR: hexadecimal ciphertext envelope contract missing' >&2; exit 1; }
! grep -Eiq 'base64_(encode|decode)' "$CIPHER" || { echo 'ERROR: Phase 5B cipher unexpectedly uses base64' >&2; exit 1; }

grep -Fq "const TABLE = 'custom_integrations'" "$STORE" || { echo 'ERROR: custom integration table contract missing' >&2; exit 1; }
grep -Fq "const PROVIDER = 'vmware_vcenter'" "$STORE" || { echo 'ERROR: VMware provider contract missing' >&2; exit 1; }
grep -Fq "getObjectsQuery('no_html_escape'" "$STORE" || { echo 'ERROR: raw secret-bearing read contract missing' >&2; exit 1; }
grep -Fq 'explicit insecure-TLS acknowledgement' "$STORE" || { echo 'ERROR: persistent insecure TLS acknowledgement gate missing' >&2; exit 1; }

grep -Fq 'PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1' "$FORWARD" || { echo 'ERROR: migration marker missing' >&2; exit 1; }
grep -Fq 'UNIQUE KEY `uq_custom_integrations_provider_endpoint`' "$FORWARD" || { echo 'ERROR: provider/endpoint uniqueness missing' >&2; exit 1; }
! grep -Fq 'settings`' "$FORWARD" || { echo 'ERROR: custom migration must not change phpIPAM settings.dbversion' >&2; exit 1; }
grep -Fq "SIGNAL SQLSTATE '45000'" "$ROLLBACK" || { echo 'ERROR: fail-closed non-empty rollback guard missing' >&2; exit 1; }

echo 'PHASE5B_REPOSITORY_VERIFY_OK'
