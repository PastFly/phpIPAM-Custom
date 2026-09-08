#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom Phase 5B — READ-ONLY live preflight.
#
# This script does not create/modify database objects, files, key material or
# vCenter sessions. It classifies the current Phase 5A live state and reports
# only non-secret runtime metadata needed before a separately approved schema
# migration and keyring creation.

TARGET="${1:-/var/www/html}"
KEYRING="/etc/phpipam-custom/integrations.key"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

command -v git >/dev/null 2>&1 || fail "git is required for exact live blob classification"
command -v php >/dev/null 2>&1 || fail "php CLI is required"
[[ -d "$TARGET" ]] || fail "phpIPAM root not found: $TARGET"
[[ -f "$TARGET/config.php" ]] || fail "phpIPAM config.php not found: $TARGET/config.php"

printf 'phpIPAM Custom Phase 5B live preflight (READ ONLY)\n'
printf 'target=%s\n\n' "$TARGET"

# Exact source installed by the runtime-verified Phase 5A layer.
declare -A PHASE5A_BLOB=(
    ["app/integrations/registry.php"]="45f841df2e052a92da44b6e3deb2911844f4d816"
    ["app/admin/integrations/index.php"]="c5a7d8b4b3248e6eb561e9cb7c5af0b82d59e3d4"
    ["app/admin/integrations/vmware-test-result.php"]="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"
    ["functions/classes/class.IntegrationVmwareVcenter.php"]="2ca0d021cbdb11e2b4e82185eed4c47c77a43f4c"
)

phase5a_source_ok=1
for rel in "${!PHASE5A_BLOB[@]}"; do
    if [[ ! -f "$TARGET/$rel" ]]; then
        printf 'PHASE5A_MISSING\t%s\n' "$rel"
        phase5a_source_ok=0
        continue
    fi
    actual="$(git hash-object "$TARGET/$rel")"
    if [[ "$actual" == "${PHASE5A_BLOB[$rel]}" ]]; then
        printf 'PHASE5A_OK\t%s\t%s\n' "$rel" "$actual"
    else
        printf 'PHASE5A_DRIFT\t%s\texpected=%s\tactual=%s\n' "$rel" "${PHASE5A_BLOB[$rel]}" "$actual"
        phase5a_source_ok=0
    fi
done

routing_ok=1
for marker_path in \
    'PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1|index.php' \
    'PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1|app/admin/admin-menu-config.php' \
    'PHPIPAM_CUSTOM_VMWARE_VCENTER_PHASE5A_V1|app/integrations/registry.php'; do
    marker="${marker_path%%|*}"
    rel="${marker_path#*|}"
    if [[ -f "$TARGET/$rel" ]] && grep -Fq "$marker" "$TARGET/$rel"; then
        printf 'ROUTING_OK\t%s\t%s\n' "$rel" "$marker"
    else
        printf 'ROUTING_MISSING\t%s\t%s\n' "$rel" "$marker"
        routing_ok=0
    fi
done

rackspace_expected="c2c9a7424e6caf996633688dea87dfbc594d7eb4"
rackspace_actual="MISSING"
rackspace_ok=0
if [[ -f "$TARGET/functions/classes/class.Rackspace.php" ]]; then
    rackspace_actual="$(git hash-object "$TARGET/functions/classes/class.Rackspace.php")"
    [[ "$rackspace_actual" == "$rackspace_expected" ]] && rackspace_ok=1
fi
printf 'RACKSPACE_BLOB=%s\n' "$rackspace_actual"

php_openssl_ok=0
if php -r 'exit(extension_loaded("openssl") && function_exists("openssl_encrypt") && in_array("aes-256-gcm", openssl_get_cipher_methods(), true) ? 0 : 1);'; then
    php_openssl_ok=1
fi
php_curl_ok=0
if php -r 'exit(extension_loaded("curl") && function_exists("curl_init") ? 0 : 1);'; then
    php_curl_ok=1
fi
printf 'PHP_OPENSSL_AES256GCM_OK=%s\n' "$php_openssl_ok"
printf 'PHP_CURL_OK=%s\n' "$php_curl_ok"
printf 'PHP_VERSION=%s\n' "$(php -r 'echo PHP_VERSION;')"

# Determine actual non-root web worker identities without printing process args.
web_users="$(ps -eo user=,comm= 2>/dev/null | awk '$1 != "root" && ($2 ~ /^apache2$/ || $2 ~ /^httpd$/ || $2 ~ /^php-fpm/ || $2 ~ /^php-fpm[0-9.]*$/) {print $1}' | sort -u | paste -sd, - || true)"
if [[ -z "$web_users" ]]; then
    web_users="UNRESOLVED"
fi
printf 'WEB_RUNTIME_USERS=%s\n' "$web_users"

# Keyring is inspected by metadata only. Contents are never read here.
keyring_state="ABSENT"
keyring_safe=1
if [[ -L "$KEYRING" ]]; then
    keyring_state="SYMLINK_REJECTED"
    keyring_safe=0
elif [[ -e "$KEYRING" ]]; then
    if [[ -f "$KEYRING" ]]; then
        keyring_state="PRESENT_REGULAR_FILE"
        key_mode="$(stat -c '%a' "$KEYRING" 2>/dev/null || echo UNKNOWN)"
        key_owner="$(stat -c '%U:%G' "$KEYRING" 2>/dev/null || echo UNKNOWN)"
        printf 'KEYRING_MODE=%s\n' "$key_mode"
        printf 'KEYRING_OWNER=%s\n' "$key_owner"
        case "$key_mode" in
            600|640|400|440) ;;
            *) keyring_safe=0 ;;
        esac
    else
        keyring_state="PRESENT_NON_REGULAR_REJECTED"
        keyring_safe=0
    fi
fi
printf 'KEYRING_STATE=%s\n' "$keyring_state"
printf 'KEYRING_METADATA_SAFE=%s\n' "$keyring_safe"

trust_store_present=0
for ca in /etc/ssl/certs/ca-certificates.crt /etc/pki/tls/certs/ca-bundle.crt /etc/ssl/cert.pem; do
    if [[ -r "$ca" && -s "$ca" ]]; then
        trust_store_present=1
        printf 'SYSTEM_CA_BUNDLE=%s\n' "$ca"
        break
    fi
done
printf 'SYSTEM_CA_BUNDLE_PRESENT=%s\n' "$trust_store_present"
printf 'VCENTER_TLS_CHAIN_CHECK=NOT_RUN_NO_PERSISTED_CONNECTOR\n'

# Read-only database probe. config.php is loaded inside PHP, credentials stay in
# memory, and no host/user/password/DSN is printed. Only version/schema state is
# returned. Raw exception details are intentionally suppressed.
export PHPIPAM_PHASE5B_PREFLIGHT_TARGET="$TARGET"
set +e
db_output="$(php <<'PHP'
<?php
$target = getenv('PHPIPAM_PHASE5B_PREFLIGHT_TARGET');
$db = array();
$config = array();
ob_start();
try {
    require $target . '/config.php';
} finally {
    ob_end_clean();
}

function safe_meta($value) {
    return preg_replace('/[^A-Za-z0-9._:+()\/-]/', '_', (string)$value);
}

try {
    if (!isset($db['host'], $db['user'], $db['pass'], $db['name'])) {
        throw new RuntimeException('config');
    }
    $port = isset($db['port']) ? (int)$db['port'] : 3306;
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . $port . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    $options = array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    );

    if (!empty($db['ssl'])) {
        $sslMap = array(
            'ssl_key' => 'PDO::MYSQL_ATTR_SSL_KEY',
            'ssl_cert' => 'PDO::MYSQL_ATTR_SSL_CERT',
            'ssl_ca' => 'PDO::MYSQL_ATTR_SSL_CA',
            'ssl_capath' => 'PDO::MYSQL_ATTR_SSL_CAPATH',
            'ssl_cipher' => 'PDO::MYSQL_ATTR_SSL_CIPHER'
        );
        foreach ($sslMap as $configKey => $constantName) {
            if (!empty($db[$configKey]) && defined($constantName)) {
                $options[constant($constantName)] = $db[$configKey];
            }
        }
        if (isset($db['ssl_verify']) && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = (bool)$db['ssl_verify'];
        }
    }

    $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    $serverVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
    $versionComment = $pdo->query('SELECT @@version_comment')->fetchColumn();
    $dbVersion = $pdo->query('SELECT `dbversion` FROM `settings` LIMIT 1')->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute(array('custom_integrations'));
    $tableExists = ((int)$stmt->fetchColumn() === 1);

    echo 'DB_CONNECTIVITY_OK=1' . PHP_EOL;
    echo 'DB_SERVER_VERSION=' . safe_meta($serverVersion) . PHP_EOL;
    echo 'DB_SERVER_COMMENT=' . safe_meta($versionComment) . PHP_EOL;
    echo 'PHPIPAM_DBVERSION=' . safe_meta($dbVersion) . PHP_EOL;
    echo 'CUSTOM_INTEGRATIONS_TABLE_EXISTS=' . ($tableExists ? '1' : '0') . PHP_EOL;

    if ($tableExists) {
        $rowCount = (int)$pdo->query('SELECT COUNT(*) FROM `custom_integrations`')->fetchColumn();
        echo 'CUSTOM_INTEGRATIONS_ROW_COUNT=' . $rowCount . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    echo 'DB_CONNECTIVITY_OK=0' . PHP_EOL;
    echo 'DB_PREFLIGHT_ERROR=SANITIZED_FAILURE' . PHP_EOL;
    exit(12);
}
PHP
)"
db_rc=$?
set -e
unset PHPIPAM_PHASE5B_PREFLIGHT_TARGET
printf '%s\n' "$db_output"

db_ok=0
table_absent=0
if [[ "$db_rc" -eq 0 ]] && grep -Fq 'DB_CONNECTIVITY_OK=1' <<<"$db_output"; then
    db_ok=1
fi
if grep -Fq 'CUSTOM_INTEGRATIONS_TABLE_EXISTS=0' <<<"$db_output"; then
    table_absent=1
fi

printf '\nPHASE5A_EXACT_SOURCE_OK=%s\n' "$phase5a_source_ok"
printf 'PHASE5A_ROUTING_OK=%s\n' "$routing_ok"
printf 'RACKSPACE_SENTINEL_OK=%s\n' "$rackspace_ok"
printf 'DB_READONLY_PROBE_OK=%s\n' "$db_ok"
printf 'CUSTOM_INTEGRATIONS_TABLE_ABSENT=%s\n' "$table_absent"

ready=1
[[ "$phase5a_source_ok" == "1" ]] || ready=0
[[ "$routing_ok" == "1" ]] || ready=0
[[ "$rackspace_ok" == "1" ]] || ready=0
[[ "$php_openssl_ok" == "1" ]] || ready=0
[[ "$php_curl_ok" == "1" ]] || ready=0
[[ "$db_ok" == "1" ]] || ready=0
[[ "$table_absent" == "1" ]] || ready=0
[[ "$keyring_safe" == "1" ]] || ready=0
[[ "$web_users" != "UNRESOLVED" ]] || ready=0
[[ "$trust_store_present" == "1" ]] || ready=0

if [[ "$ready" == "1" ]]; then
    echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5B_SCHEMA_AND_KEY_OWNER_APPROVAL'
else
    echo 'PREFLIGHT_RESULT=NOT_READY_REVIEW_OUTPUT'
    exit 20
fi
