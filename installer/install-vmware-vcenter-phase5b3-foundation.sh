#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 5B.3 secure persistent configuration foundation.
#
# This installer is intentionally state-specific and fail-closed. It may:
# - deploy the reviewed Phase 5B encryption/config-store classes;
# - create the external keyring;
# - create the custom_integrations table.
#
# It does NOT deploy persistent connector UI, create a connector credential,
# modify phpIPAM settings.dbversion, touch Rackspace, or write to vCenter.
# Running this script on live requires a separate explicit Owner approval.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_ROOT="$REPO_ROOT/layers/phase5b/functions/classes"
MIGRATION="$REPO_ROOT/migrations/phase5b/001-create-custom-integrations.sql"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-vcenter-phase5b3-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0
TEST_MODE="${PHASE5B3_TEST_MODE:-0}"
DEFAULT_KEYRING="/etc/phpipam-custom/integrations.key"
DEFAULT_RUNTIME_USER="www-data"
KEYRING="${PHPIPAM_CUSTOM_KEYRING_PATH:-$DEFAULT_KEYRING}"
RUNTIME_USER="${PHPIPAM_CUSTOM_RUNTIME_USER:-$DEFAULT_RUNTIME_USER}"
RACKSPACE_EXPECTED="c2c9a7424e6caf996633688dea87dfbc594d7eb4"

cleanup() {
    rm -rf "$STAGE"
}

rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo >&2
        echo "ERROR: Phase 5B.3 foundation install failed after commit started; attempting guarded rollback." >&2
        if ! bash "$BACKUP/rollback.sh" >&2; then
            echo "ERROR: guarded rollback refused or failed. Preserve $BACKUP and review live state before any retry." >&2
        fi
    fi
    exit "$rc"
}

trap cleanup EXIT
trap rollback_on_error ERR

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

for cmd in git php stat install chown chmod runuser; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"
done

[[ "$EUID" -eq 0 ]] || fail "Phase 5B.3 foundation installer must run as root"
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
[[ -f "$TARGET/config.php" ]] || fail "phpIPAM config.php not found: $TARGET/config.php"
[[ -f "$MIGRATION" ]] || fail "Phase 5B migration source missing"
id "$RUNTIME_USER" >/dev/null 2>&1 || fail "runtime user does not exist: $RUNTIME_USER"

if [[ "$TEST_MODE" != "1" ]]; then
    [[ "$KEYRING" == "$DEFAULT_KEYRING" ]] || fail "production keyring path override is forbidden"
    [[ "$RUNTIME_USER" == "$DEFAULT_RUNTIME_USER" ]] || fail "production runtime-user override is forbidden"
    bash "$REPO_ROOT/tools/live-vmware-phase5b-preflight.sh" "$TARGET"
else
    [[ "$KEYRING" == /* ]] || fail "test keyring path must be absolute"
fi

hash_file() {
    git hash-object "$1"
}

# Exact source blobs merged by Phase 5B.1.
declare -A SOURCE_BLOB=(
    ["class.IntegrationSecretCipher.php"]="a3bb3f8f6fbcec0bf6a0119bc068a81a294632db"
    ["class.IntegrationVmwareVcenterConfigStore.php"]="d0cad58978b736624d50523f491710b581ae9fa8"
)

NEW_CLASS_FILES=(
    "functions/classes/class.IntegrationSecretCipher.php"
    "functions/classes/class.IntegrationVmwareVcenterConfigStore.php"
)

for base in "${!SOURCE_BLOB[@]}"; do
    src="$SOURCE_ROOT/$base"
    [[ -f "$src" ]] || fail "Phase 5B source missing: $base"
    actual="$(hash_file "$src")"
    [[ "$actual" == "${SOURCE_BLOB[$base]}" ]] || fail "Phase 5B source drift: $base expected ${SOURCE_BLOB[$base]}, got $actual"
    php -l "$src" >/dev/null || fail "Phase 5B source PHP lint failed: $base"
done

grep -Fq 'PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1' "$MIGRATION" || fail "Phase 5B migration marker missing"
[[ "$(hash_file "$MIGRATION")" == "0cb3d1e2a692a257a4ae4d473dfa547c024d7e1e" ]] || fail "Phase 5B migration source drift"

for rel in "${NEW_CLASS_FILES[@]}"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5B class already exists on target: $rel"
done

[[ ! -L "$KEYRING" ]] || fail "keyring path is a symbolic link"
[[ ! -e "$KEYRING" ]] || fail "keyring already exists: $KEYRING"

if [[ -f "$TARGET/functions/classes/class.Rackspace.php" ]]; then
    rackspace_before="$(hash_file "$TARGET/functions/classes/class.Rackspace.php")"
else
    fail "Rackspace sentinel file missing"
fi
[[ "$rackspace_before" == "$RACKSPACE_EXPECTED" ]] || fail "Rackspace sentinel drift before Phase 5B.3"

# Prepare source candidates and a fresh keyring without printing key material.
mkdir -p "$STAGE/functions/classes"
cp "$SOURCE_ROOT/class.IntegrationSecretCipher.php" "$STAGE/functions/classes/"
cp "$SOURCE_ROOT/class.IntegrationVmwareVcenterConfigStore.php" "$STAGE/functions/classes/"

umask 077
php -r '
$path = $argv[1];
$keyId = "k" . gmdate("YmdHis") . "_" . bin2hex(random_bytes(4));
$data = array(
    "version" => 1,
    "current_key_id" => $keyId,
    "keys" => array($keyId => bin2hex(random_bytes(32)))
);
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
    exit(2);
}
' "$STAGE/integrations.key"
chmod 0600 "$STAGE/integrations.key"

php -r '
require $argv[1];
$cipher = IntegrationSecretCipher::from_keyring_file($argv[2], false);
$probe = "phase5b3-roundtrip-" . bin2hex(random_bytes(8));
$env = $cipher->encrypt($probe, "vmware_vcenter");
if ($cipher->decrypt($env, "vmware_vcenter") !== $probe) {
    exit(3);
}
' "$STAGE/functions/classes/class.IntegrationSecretCipher.php" "$STAGE/integrations.key" || fail "staged keyring encryption round-trip failed"

echo "Phase 5B.3 foundation pre-commit checks: OK"

mkdir -p "$BACKUP"
printf '%s\n' "target=$TARGET" "keyring=$KEYRING" "runtime_user=$RUNTIME_USER" "rackspace_before=$rackspace_before" > "$BACKUP/STATE.txt"
printf '%s\n' "${NEW_CLASS_FILES[@]}" > "$BACKUP/CREATED_FILES.txt"
printf '%s\n' "$KEYRING" > "$BACKUP/CREATED_KEYRING_PATH.txt"

cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"
KEYRING="$(cat "$BACKUP_DIR/CREATED_KEYRING_PATH.txt")"

export PHPIPAM_PHASE5B3_ROLLBACK_TARGET="$TARGET"
set +e
php <<'PHP'
<?php
$target = getenv('PHPIPAM_PHASE5B3_ROLLBACK_TARGET');
$db = array();
$config = array();
ob_start();
try { require $target . '/config.php'; } finally { ob_end_clean(); }
try {
    $port = isset($db['port']) ? (int)$db['port'] : 3306;
    $dsn = 'mysql:host=' . $db['host'] . ';port=' . $port . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $q->execute(array('custom_integrations'));
    if ((int)$q->fetchColumn() === 1) {
        $rows = (int)$pdo->query('SELECT COUNT(*) FROM `custom_integrations`')->fetchColumn();
        if ($rows > 0) {
            fwrite(STDERR, "Refusing Phase 5B.3 rollback: custom_integrations contains data.\n");
            exit(42);
        }
        $pdo->exec('DROP TABLE `custom_integrations`');
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Phase 5B.3 rollback database check failed.\n");
    exit(43);
}
PHP
db_rc=$?
set -e
unset PHPIPAM_PHASE5B3_ROLLBACK_TARGET

if [[ "$db_rc" -ne 0 ]]; then
    exit "$db_rc"
fi

while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    rm -f "$TARGET/$rel"
done < "$BACKUP_DIR/CREATED_FILES.txt"

rm -f "$KEYRING"
keydir="$(dirname "$KEYRING")"
rmdir "$keydir" 2>/dev/null || true

echo "PHASE5B3_GUARDED_ROLLBACK_OK"
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"

echo "Backup / guarded rollback prepared: $BACKUP"

install_new_class() {
    local rel="$1"
    local base="$(basename "$rel")"
    local dst="$TARGET/$rel"
    local parent="$(dirname "$dst")"
    local tmp="$dst.phpipam-custom-tmp-$$"
    mkdir -p "$parent"
    cp "$STAGE/functions/classes/$base" "$tmp"
    chown --reference="$parent" "$tmp" 2>/dev/null || true
    chmod 0644 "$tmp"
    mv -f "$tmp" "$dst"
}

COMMIT_STARTED=1

for rel in "${NEW_CLASS_FILES[@]}"; do
    install_new_class "$rel"
done

keydir="$(dirname "$KEYRING")"
install -d -o root -g "$RUNTIME_USER" -m 0750 "$keydir"
keytmp="$keydir/.integrations.key.phpipam-custom-tmp-$$"
install -o root -g "$RUNTIME_USER" -m 0640 "$STAGE/integrations.key" "$keytmp"
mv -f "$keytmp" "$KEYRING"

# Verify the actual web runtime identity can read and authenticate with the keyring.
runuser -u "$RUNTIME_USER" -- php -r '
require $argv[1];
$cipher = IntegrationSecretCipher::from_keyring_file($argv[2], true);
$probe = "phase5b3-worker-" . bin2hex(random_bytes(8));
$env = $cipher->encrypt($probe, "vmware_vcenter");
exit($cipher->decrypt($env, "vmware_vcenter") === $probe ? 0 : 4);
' "$TARGET/functions/classes/class.IntegrationSecretCipher.php" "$KEYRING" || fail "runtime user cannot securely use integration keyring"

# Apply the one-table migration through PDO using the existing phpIPAM config.
# Database credentials remain in PHP process memory and are never printed or
# placed in command-line arguments.
export PHPIPAM_PHASE5B3_TARGET="$TARGET"
export PHPIPAM_PHASE5B3_MIGRATION="$MIGRATION"
php <<'PHP'
<?php
$target = getenv('PHPIPAM_PHASE5B3_TARGET');
$migration = getenv('PHPIPAM_PHASE5B3_MIGRATION');
$db = array();
$config = array();
ob_start();
try { require $target . '/config.php'; } finally { ob_end_clean(); }

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
    if ($dbVersion !== 46) {
        throw new RuntimeException('dbversion');
    }
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $check->execute(array('custom_integrations'));
    if ((int)$check->fetchColumn() !== 0) {
        throw new RuntimeException('table_exists');
    }
    $sql = file_get_contents($migration);
    if (!is_string($sql) || strpos($sql, 'PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1') === false) {
        throw new RuntimeException('migration');
    }
    $pdo->exec($sql);
    $check->execute(array('custom_integrations'));
    if ((int)$check->fetchColumn() !== 1) {
        throw new RuntimeException('table_missing');
    }
    $rows = (int)$pdo->query('SELECT COUNT(*) FROM `custom_integrations`')->fetchColumn();
    if ($rows !== 0) {
        throw new RuntimeException('unexpected_rows');
    }
    $comment = $pdo->query("SELECT TABLE_COMMENT FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'custom_integrations'")->fetchColumn();
    if (strpos((string)$comment, 'PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1') === false) {
        throw new RuntimeException('comment');
    }
    if ((int)$pdo->query('SELECT `dbversion` FROM `settings` LIMIT 1')->fetchColumn() !== 46) {
        throw new RuntimeException('dbversion_changed');
    }
    echo "PHASE5B3_DATABASE_MIGRATION_OK\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Phase 5B.3 database migration failed (sanitized).\n");
    exit(15);
}
PHP
unset PHPIPAM_PHASE5B3_TARGET PHPIPAM_PHASE5B3_MIGRATION

# Post-install source and sentinel checks.
for rel in "${NEW_CLASS_FILES[@]}"; do
    base="$(basename "$rel")"
    actual="$(hash_file "$TARGET/$rel")"
    [[ "$actual" == "${SOURCE_BLOB[$base]}" ]] || fail "post-install class blob mismatch: $rel"
    php -l "$TARGET/$rel" >/dev/null || fail "post-install PHP lint failed: $rel"
done

[[ ! -L "$KEYRING" && -f "$KEYRING" ]] || fail "post-install keyring is not a regular file"
[[ "$(stat -c '%a' "$KEYRING")" == "640" ]] || fail "post-install keyring mode is not 0640"
[[ "$(stat -c '%U:%G' "$KEYRING")" == "root:$RUNTIME_USER" ]] || fail "post-install keyring owner/group mismatch"

rackspace_after="$(hash_file "$TARGET/functions/classes/class.Rackspace.php")"
[[ "$rackspace_after" == "$rackspace_before" && "$rackspace_after" == "$RACKSPACE_EXPECTED" ]] || fail "Rackspace changed during Phase 5B.3 foundation install"

COMMIT_STARTED=0

echo
echo "PHASE5B3_FOUNDATION_INSTALL_OK"
echo "Backup / guarded rollback: $BACKUP"
echo "custom_integrations created empty; phpIPAM settings.dbversion remains 46."
echo "External keyring created at $KEYRING with root:$RUNTIME_USER 0640."
echo "No connector credential was created."
echo "No persistent Administration UI was deployed by this foundation installer."
echo "Rackspace unchanged: $rackspace_after"
