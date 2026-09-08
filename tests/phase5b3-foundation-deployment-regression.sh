#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REAL_INSTALLER="$ROOT/installer/install-vmware-vcenter-phase5b3-foundation.sh"
REAL_VERIFIER="$ROOT/installer/verify-vmware-vcenter-phase5b3-foundation.sh"
PRODUCTION_RACKSPACE_BLOB="c2c9a7424e6caf996633688dea87dfbc594d7eb4"
DB_HOST="${PHASE5B_DB_HOST:-127.0.0.1}"
DB_PORT="${PHASE5B_DB_PORT:-3306}"
DB_USER="${PHASE5B_DB_USER:-root}"
DB_PASSWORD="${PHASE5B_DB_PASSWORD:-phase5b-ci}"
DB_NAME="phase5b3_ci_${GITHUB_RUN_ID:-$$}_${GITHUB_RUN_ATTEMPT:-1}"
DB_NAME="${DB_NAME//[^A-Za-z0-9_]/_}"
TEST_ROOT="$(mktemp -d)"
TARGET="$TEST_ROOT/phpipam"
KEYRING="$TEST_ROOT/etc/phpipam-custom/integrations.key"
TEST_INSTALLER="$ROOT/installer/.phase5b3-foundation-test-installer-$$.sh"
TEST_VERIFIER="$ROOT/installer/.phase5b3-foundation-test-verifier-$$.sh"
RUNTIME_USER="www-data"

[[ -f "$REAL_INSTALLER" ]] || { echo "ERROR: Phase 5B.3 foundation installer missing" >&2; exit 1; }
[[ -f "$REAL_VERIFIER" ]] || { echo "ERROR: Phase 5B.3 foundation verifier missing" >&2; exit 1; }
id "$RUNTIME_USER" >/dev/null 2>&1 || { echo "ERROR: disposable runtime user missing: $RUNTIME_USER" >&2; exit 1; }

if command -v mariadb >/dev/null 2>&1; then
    MYSQL=(mariadb --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --batch --skip-column-names)
elif command -v mysql >/dev/null 2>&1; then
    MYSQL=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --batch --skip-column-names)
else
    echo "ERROR: mariadb/mysql client is required" >&2
    exit 1
fi

export MYSQL_PWD="$DB_PASSWORD"
cleanup() {
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" >/dev/null 2>&1 || true
    rm -f "$TEST_INSTALLER" "$TEST_VERIFIER"
    sudo rm -rf "$TEST_ROOT" >/dev/null 2>&1 || true
    unset MYSQL_PWD
}
trap cleanup EXIT

ready=0
for _ in $(seq 1 40); do
    if "${MYSQL[@]}" -e 'SELECT 1;' >/dev/null 2>&1; then
        ready=1
        break
    fi
    sleep 1
done
[[ "$ready" == "1" ]] || { echo "ERROR: disposable MariaDB did not become ready" >&2; exit 1; }

"${MYSQL[@]}" -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB_NAME" -e 'CREATE TABLE `settings` (`dbversion` int NOT NULL); INSERT INTO `settings` (`dbversion`) VALUES (46);'

mkdir -p "$TARGET/functions/classes"
chmod 0755 "$TEST_ROOT" "$TARGET" "$TARGET/functions" "$TARGET/functions/classes"

cat > "$TARGET/config.php" <<'PHP'
<?php
$db = array(
    'host' => getenv('PHASE5B_DB_HOST'),
    'user' => getenv('PHASE5B_DB_USER'),
    'pass' => getenv('PHASE5B_DB_PASSWORD'),
    'name' => getenv('PHASE5B3_DB_NAME'),
    'port' => (int)getenv('PHASE5B_DB_PORT')
);
$config = array();
PHP
chmod 0644 "$TARGET/config.php"

cat > "$TARGET/functions/classes/class.Rackspace.php" <<'PHP'
<?php
// Deterministic disposable Rackspace sentinel for Phase 5B.3 deployment regression.
class Rackspace {}
PHP
chmod 0644 "$TARGET/functions/classes/class.Rackspace.php"
TEST_RACKSPACE_BLOB="$(git hash-object "$TARGET/functions/classes/class.Rackspace.php")"

# The real production scripts must retain the exact reviewed live Rackspace pin.
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_INSTALLER" || {
    echo "ERROR: production installer Rackspace pin was weakened" >&2
    exit 1
}
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_VERIFIER" || {
    echo "ERROR: production verifier Rackspace pin was weakened" >&2
    exit 1
}

# Rebind only disposable copies to the deterministic surrogate. This mirrors the
# exact-live updater regression strategy without weakening production gates.
cp "$REAL_INSTALLER" "$TEST_INSTALLER"
cp "$REAL_VERIFIER" "$TEST_VERIFIER"
sed -i "s/$PRODUCTION_RACKSPACE_BLOB/$TEST_RACKSPACE_BLOB/g" "$TEST_INSTALLER" "$TEST_VERIFIER"
chmod 0700 "$TEST_INSTALLER" "$TEST_VERIFIER"
grep -Fq "$TEST_RACKSPACE_BLOB" "$TEST_INSTALLER" || { echo "ERROR: test installer rebind failed" >&2; exit 1; }
grep -Fq "$TEST_RACKSPACE_BLOB" "$TEST_VERIFIER" || { echo "ERROR: test verifier rebind failed" >&2; exit 1; }

COMMON_ENV=(
    "PHASE5B3_TEST_MODE=1"
    "PHPIPAM_CUSTOM_KEYRING_PATH=$KEYRING"
    "PHPIPAM_CUSTOM_RUNTIME_USER=$RUNTIME_USER"
    "PHASE5B_DB_HOST=$DB_HOST"
    "PHASE5B_DB_PORT=$DB_PORT"
    "PHASE5B_DB_USER=$DB_USER"
    "PHASE5B_DB_PASSWORD=$DB_PASSWORD"
    "PHASE5B3_DB_NAME=$DB_NAME"
)

install_output="$(sudo env "${COMMON_ENV[@]}" bash "$TEST_INSTALLER" "$TARGET" 2>&1)"
printf '%s\n' "$install_output"
[[ "$install_output" == *"PHASE5B3_DATABASE_MIGRATION_OK"* ]] || {
    echo "ERROR: Phase 5B.3 disposable migration marker missing" >&2
    exit 1
}
[[ "$install_output" == *"PHASE5B3_FOUNDATION_INSTALL_OK"* ]] || {
    echo "ERROR: Phase 5B.3 disposable install marker missing" >&2
    exit 1
}

verify_output="$(sudo env "${COMMON_ENV[@]}" bash "$TEST_VERIFIER" "$TARGET" 2>&1)"
printf '%s\n' "$verify_output"
[[ "$verify_output" == *"PHASE5B3_FOUNDATION_VERIFY_OK"* ]] || {
    echo "ERROR: Phase 5B.3 disposable verifier marker missing" >&2
    exit 1
}
[[ "$verify_output" == *"CUSTOM_INTEGRATIONS_ROW_COUNT=0"* ]] || {
    echo "ERROR: Phase 5B.3 foundation unexpectedly created connector rows" >&2
    exit 1
}

[[ -f "$KEYRING" && ! -L "$KEYRING" ]] || { echo "ERROR: disposable keyring missing" >&2; exit 1; }
[[ "$(stat -c '%a' "$KEYRING")" == "640" ]] || { echo "ERROR: disposable keyring mode mismatch" >&2; exit 1; }
[[ "$(stat -c '%U:%G' "$KEYRING")" == "root:$RUNTIME_USER" ]] || { echo "ERROR: disposable keyring owner/group mismatch" >&2; exit 1; }

mapfile -t backups < <(find "$TARGET" -maxdepth 1 -type d -name '.phpipam-custom-vcenter-phase5b3-backup-*' -print)
[[ "${#backups[@]}" -eq 1 ]] || { echo "ERROR: expected exactly one Phase 5B.3 backup" >&2; exit 1; }
BACKUP="${backups[0]}"
[[ -x "$BACKUP/rollback.sh" ]] || { echo "ERROR: guarded rollback script missing" >&2; exit 1; }

# A non-empty connector table must make rollback fail closed and preserve the
# table, keyring and deployed classes.
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO custom_integrations (provider,name,endpoint,username) VALUES ('vmware_vcenter','rollback-guard','https://rollback-guard.invalid','svc-guard');"
set +e
refusal_output="$(sudo env "${COMMON_ENV[@]}" bash "$BACKUP/rollback.sh" 2>&1)"
refusal_rc=$?
set -e
printf '%s\n' "$refusal_output"
[[ "$refusal_rc" -eq 42 ]] || {
    echo "ERROR: guarded rollback returned $refusal_rc instead of 42 for non-empty table" >&2
    exit 1
}
[[ "$refusal_output" == *"Refusing Phase 5B.3 rollback: custom_integrations contains data."* ]] || {
    echo "ERROR: guarded rollback refusal reason mismatch" >&2
    exit 1
}
[[ "$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='custom_integrations';")" == "1" ]] || {
    echo "ERROR: guarded rollback removed non-empty table" >&2
    exit 1
}
[[ -f "$KEYRING" ]] || { echo "ERROR: guarded rollback removed keyring while data existed" >&2; exit 1; }
[[ -f "$TARGET/functions/classes/class.IntegrationSecretCipher.php" ]] || { echo "ERROR: guarded rollback removed cipher while data existed" >&2; exit 1; }
[[ -f "$TARGET/functions/classes/class.IntegrationVmwareVcenterConfigStore.php" ]] || { echo "ERROR: guarded rollback removed config store while data existed" >&2; exit 1; }

# Once the disposable row is removed, rollback must become retry-safe and clean
# only the Phase 5B.3 foundation artifacts.
"${MYSQL[@]}" "$DB_NAME" -e 'DELETE FROM custom_integrations;'
rollback_output="$(sudo env "${COMMON_ENV[@]}" bash "$BACKUP/rollback.sh" 2>&1)"
printf '%s\n' "$rollback_output"
[[ "$rollback_output" == *"PHASE5B3_GUARDED_ROLLBACK_OK"* ]] || {
    echo "ERROR: empty-table Phase 5B.3 rollback marker missing" >&2
    exit 1
}

[[ "$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='custom_integrations';")" == "0" ]] || {
    echo "ERROR: rollback did not remove empty custom_integrations table" >&2
    exit 1
}
[[ ! -e "$KEYRING" ]] || { echo "ERROR: rollback did not remove disposable keyring" >&2; exit 1; }
[[ ! -e "$TARGET/functions/classes/class.IntegrationSecretCipher.php" ]] || { echo "ERROR: rollback did not remove cipher class" >&2; exit 1; }
[[ ! -e "$TARGET/functions/classes/class.IntegrationVmwareVcenterConfigStore.php" ]] || { echo "ERROR: rollback did not remove config-store class" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/functions/classes/class.Rackspace.php")" == "$TEST_RACKSPACE_BLOB" ]] || {
    echo "ERROR: Rackspace surrogate changed during apply/rollback" >&2
    exit 1
}
[[ "$("${MYSQL[@]}" "$DB_NAME" -e 'SELECT dbversion FROM settings LIMIT 1;')" == "46" ]] || {
    echo "ERROR: phpIPAM dbversion changed during Phase 5B.3 regression" >&2
    exit 1
}

# The production scripts themselves must still carry the exact live pin after
# the test copies have been exercised.
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_INSTALLER" || { echo "ERROR: production installer mutated during regression" >&2; exit 1; }
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_VERIFIER" || { echo "ERROR: production verifier mutated during regression" >&2; exit 1; }

echo "PHASE5B3_FOUNDATION_DEPLOYMENT_REGRESSION_OK"
