#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5B.3 live foundation verifier.
# Read-only with respect to phpIPAM/vCenter state. It validates deployed source,
# keyring metadata/usability and custom_integrations schema without printing
# credentials or key material.

TARGET="${1:-/var/www/html}"
DEFAULT_KEYRING="/etc/phpipam-custom/integrations.key"
DEFAULT_RUNTIME_USER="www-data"
TEST_MODE="${PHASE5B3_TEST_MODE:-0}"
KEYRING="${PHPIPAM_CUSTOM_KEYRING_PATH:-$DEFAULT_KEYRING}"
RUNTIME_USER="${PHPIPAM_CUSTOM_RUNTIME_USER:-$DEFAULT_RUNTIME_USER}"
RACKSPACE_EXPECTED="c2c9a7424e6caf996633688dea87dfbc594d7eb4"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

for cmd in git php stat runuser; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
[[ -f "$TARGET/config.php" ]] || fail "phpIPAM config.php missing"

if [[ "$TEST_MODE" != "1" ]]; then
    [[ "$KEYRING" == "$DEFAULT_KEYRING" ]] || fail "production keyring override forbidden"
    [[ "$RUNTIME_USER" == "$DEFAULT_RUNTIME_USER" ]] || fail "production runtime-user override forbidden"
fi

declare -A EXPECTED_BLOB=(
    ["functions/classes/class.IntegrationSecretCipher.php"]="a3bb3f8f6fbcec0bf6a0119bc068a81a294632db"
    ["functions/classes/class.IntegrationVmwareVcenterConfigStore.php"]="d0cad58978b736624d50523f491710b581ae9fa8"
)

for rel in "${!EXPECTED_BLOB[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "Phase 5B.3 deployed class missing: $rel"
    actual="$(git hash-object "$TARGET/$rel")"
    [[ "$actual" == "${EXPECTED_BLOB[$rel]}" ]] || fail "Phase 5B.3 deployed class drift: $rel"
    php -l "$TARGET/$rel" >/dev/null || fail "PHP lint failed: $rel"
done

echo "Phase 5B.3 deployed source: OK (2)"

[[ ! -L "$KEYRING" && -f "$KEYRING" ]] || fail "integration keyring must be a regular non-symlink file"
mode="$(stat -c '%a' "$KEYRING")"
owner="$(stat -c '%U:%G' "$KEYRING")"
[[ "$mode" == "640" ]] || fail "keyring mode must be 0640, got $mode"
[[ "$owner" == "root:$RUNTIME_USER" ]] || fail "keyring owner/group must be root:$RUNTIME_USER, got $owner"

runuser -u "$RUNTIME_USER" -- php -r '
require $argv[1];
$cipher = IntegrationSecretCipher::from_keyring_file($argv[2], true);
$probe = "phase5b3-verify-" . bin2hex(random_bytes(8));
$env = $cipher->encrypt($probe, "vmware_vcenter");
if (strpos($env, $probe) !== false) { exit(5); }
exit($cipher->decrypt($env, "vmware_vcenter") === $probe ? 0 : 6);
' "$TARGET/functions/classes/class.IntegrationSecretCipher.php" "$KEYRING" || fail "runtime keyring encryption/decryption verification failed"

echo "Phase 5B.3 keyring metadata/runtime access: OK"

if [[ ! -f "$TARGET/functions/classes/class.Rackspace.php" ]]; then
    fail "Rackspace sentinel missing"
fi
rackspace="$(git hash-object "$TARGET/functions/classes/class.Rackspace.php")"
[[ "$rackspace" == "$RACKSPACE_EXPECTED" ]] || fail "Rackspace sentinel changed"

export PHPIPAM_PHASE5B3_VERIFY_TARGET="$TARGET"
db_output="$(php <<'PHP'
<?php
$target = getenv('PHPIPAM_PHASE5B3_VERIFY_TARGET');
$db = array();
$config = array();
ob_start();
try { require $target . '/config.php'; } finally { ob_end_clean(); }

try {
    $port = isset($db['port']) ? (int)$db['port'] : 3306;
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . $port . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    $options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5);
    if (!empty($db['ssl'])) {
        $map = array(
            'ssl_key' => 'PDO::MYSQL_ATTR_SSL_KEY',
            'ssl_cert' => 'PDO::MYSQL_ATTR_SSL_CERT',
            'ssl_ca' => 'PDO::MYSQL_ATTR_SSL_CA',
            'ssl_capath' => 'PDO::MYSQL_ATTR_SSL_CAPATH',
            'ssl_cipher' => 'PDO::MYSQL_ATTR_SSL_CIPHER'
        );
        foreach ($map as $k => $constantName) {
            if (!empty($db[$k]) && defined($constantName)) {
                $options[constant($constantName)] = $db[$k];
            }
        }
        if (isset($db['ssl_verify']) && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = (bool)$db['ssl_verify'];
        }
    }
    $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    $dbVersion = (int)$pdo->query('SELECT `dbversion` FROM `settings` LIMIT 1')->fetchColumn();
    if ($dbVersion !== 46) { throw new RuntimeException('dbversion'); }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute(array('custom_integrations'));
    if ((int)$stmt->fetchColumn() !== 1) { throw new RuntimeException('table'); }

    $comment = $pdo->query("SELECT TABLE_COMMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'custom_integrations'")->fetchColumn();
    if (strpos((string)$comment, 'PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1') === false) { throw new RuntimeException('comment'); }

    $columns = $pdo->query("SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'custom_integrations'")->fetchAll(PDO::FETCH_ASSOC);
    $seen = array();
    foreach ($columns as $col) { $seen[$col['COLUMN_NAME']] = $col; }
    foreach (array('id','provider','name','enabled','endpoint','username','verify_tls','secret_envelope','secret_key_id','created_by','updated_by','created_at','updated_at','last_test_at','last_success_at','last_error_at','last_error_code') as $required) {
        if (!isset($seen[$required])) { throw new RuntimeException('column'); }
    }
    if ((string)$seen['enabled']['COLUMN_DEFAULT'] !== '0') { throw new RuntimeException('enabled_default'); }
    if ((string)$seen['verify_tls']['COLUMN_DEFAULT'] !== '1') { throw new RuntimeException('tls_default'); }

    $indexes = $pdo->query("SHOW INDEX FROM `custom_integrations`")->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array();
    foreach ($indexes as $idx) { $indexNames[(string)$idx['Key_name']] = true; }
    foreach (array('PRIMARY','uq_custom_integrations_provider_endpoint','idx_custom_integrations_provider_enabled','idx_custom_integrations_last_success') as $requiredIndex) {
        if (!isset($indexNames[$requiredIndex])) { throw new RuntimeException('index'); }
    }

    $rows = (int)$pdo->query('SELECT COUNT(*) FROM `custom_integrations`')->fetchColumn();
    echo 'DB_SCHEMA_OK=1' . PHP_EOL;
    echo 'PHPIPAM_DBVERSION=46' . PHP_EOL;
    echo 'CUSTOM_INTEGRATIONS_ROW_COUNT=' . $rows . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo 'DB_SCHEMA_OK=0' . PHP_EOL;
    exit(20);
}
PHP
)" || fail "Phase 5B.3 database schema verification failed"
unset PHPIPAM_PHASE5B3_VERIFY_TARGET
printf '%s\n' "$db_output"

echo "Rackspace unchanged: $rackspace"
echo

echo "PHASE5B3_FOUNDATION_VERIFY_OK"
