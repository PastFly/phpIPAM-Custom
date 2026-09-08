#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_HOST="${PHASE5B_DB_HOST:-127.0.0.1}"
DB_PORT="${PHASE5B_DB_PORT:-3306}"
DB_USER="${PHASE5B_DB_USER:-root}"
DB_PASSWORD="${PHASE5B_DB_PASSWORD:-phase5b-ci}"
DB_NAME="phase5b3_ui_ci_${GITHUB_RUN_ID:-$$}_${GITHUB_RUN_ATTEMPT:-1}"
DB_NAME="${DB_NAME//[^A-Za-z0-9_]/_}"
TEST_ROOT="$(mktemp -d)"
TARGET="$TEST_ROOT/phpipam"
KEYRING="$TEST_ROOT/etc/phpipam-custom/integrations.key"
RUNTIME_USER="www-data"
PRODUCTION_RACKSPACE_BLOB="c2c9a7424e6caf996633688dea87dfbc594d7eb4"
PHASE5A_INDEX_BLOB="c5a7d8b4b3248e6eb561e9cb7c5af0b82d59e3d4"
PHASE5A_TRANSIENT_BLOB="b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0"
PERSISTENT_INDEX_BLOB="2aa9faaac09901d6b62ef17a32af87b8316f388c"
PERSISTENT_ACTION_BLOB="aa7c746775183a63b2fde2482a5acac61b93a7ce"

REAL_FOUNDATION_INSTALLER="$ROOT/installer/install-vmware-vcenter-phase5b3-foundation.sh"
REAL_FOUNDATION_VERIFIER="$ROOT/installer/verify-vmware-vcenter-phase5b3-foundation.sh"
REAL_UI_INSTALLER="$ROOT/installer/install-vmware-vcenter-phase5b3-ui.sh"
REAL_UI_VERIFIER="$ROOT/installer/verify-vmware-vcenter-phase5b3-ui.sh"
TEST_FOUNDATION_INSTALLER="$ROOT/installer/.phase5b3-ui-test-foundation-installer-$$.sh"
TEST_FOUNDATION_VERIFIER="$ROOT/installer/.phase5b3-ui-test-foundation-verifier-$$.sh"
TEST_UI_INSTALLER="$ROOT/installer/.phase5b3-ui-test-installer-$$.sh"
TEST_UI_VERIFIER="$ROOT/installer/.phase5b3-ui-test-verifier-$$.sh"

for file in "$REAL_FOUNDATION_INSTALLER" "$REAL_FOUNDATION_VERIFIER" "$REAL_UI_INSTALLER" "$REAL_UI_VERIFIER"; do
    [[ -f "$file" ]] || { echo "ERROR: required Phase 5B.3 deployment source missing: $file" >&2; exit 1; }
done
id "$RUNTIME_USER" >/dev/null 2>&1 || { echo "ERROR: runtime user missing: $RUNTIME_USER" >&2; exit 1; }

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
    rm -f "$TEST_FOUNDATION_INSTALLER" "$TEST_FOUNDATION_VERIFIER" "$TEST_UI_INSTALLER" "$TEST_UI_VERIFIER"
    sudo rm -rf "$TEST_ROOT" >/dev/null 2>&1 || true
    unset MYSQL_PWD
}
trap cleanup EXIT

ready=0
for _ in $(seq 1 40); do
    if "${MYSQL[@]}" -e 'SELECT 1;' >/dev/null 2>&1; then ready=1; break; fi
    sleep 1
done
[[ "$ready" == "1" ]] || { echo "ERROR: disposable MariaDB did not become ready" >&2; exit 1; }

"${MYSQL[@]}" -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$DB_NAME" -e 'CREATE TABLE `settings` (`dbversion` int NOT NULL); INSERT INTO `settings` (`dbversion`) VALUES (46);'

mkdir -p "$TARGET/functions/classes" "$TARGET/app/admin/integrations"
chmod 0755 "$TEST_ROOT" "$TARGET" "$TARGET/functions" "$TARGET/functions/classes" "$TARGET/app" "$TARGET/app/admin" "$TARGET/app/admin/integrations"

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
// Deterministic disposable Rackspace sentinel for Phase 5B.3 UI deployment regression.
class Rackspace {}
PHP
chmod 0644 "$TARGET/functions/classes/class.Rackspace.php"
TEST_RACKSPACE_BLOB="$(git hash-object "$TARGET/functions/classes/class.Rackspace.php")"

cp "$ROOT/layers/phase5/app/admin/integrations/index.php" "$TARGET/app/admin/integrations/index.php"
cp "$ROOT/layers/phase5/app/admin/integrations/vmware-test-result.php" "$TARGET/app/admin/integrations/vmware-test-result.php"
[[ "$(git hash-object "$TARGET/app/admin/integrations/index.php")" == "$PHASE5A_INDEX_BLOB" ]] || { echo "ERROR: Phase 5A index fixture drift" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-test-result.php")" == "$PHASE5A_TRANSIENT_BLOB" ]] || { echo "ERROR: Phase 5A transient fixture drift" >&2; exit 1; }

# Production foundation files retain the exact live Rackspace pin. Rebind only
# disposable copies to the deterministic fixture.
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_FOUNDATION_INSTALLER" || { echo "ERROR: production foundation installer pin missing" >&2; exit 1; }
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_FOUNDATION_VERIFIER" || { echo "ERROR: production foundation verifier pin missing" >&2; exit 1; }
cp "$REAL_FOUNDATION_INSTALLER" "$TEST_FOUNDATION_INSTALLER"
cp "$REAL_FOUNDATION_VERIFIER" "$TEST_FOUNDATION_VERIFIER"
sed -i "s/$PRODUCTION_RACKSPACE_BLOB/$TEST_RACKSPACE_BLOB/g" "$TEST_FOUNDATION_INSTALLER" "$TEST_FOUNDATION_VERIFIER"

# UI scripts call the canonical foundation verifier (and the UI installer calls
# the canonical UI verifier). Point only disposable copies at disposable peers.
cp "$REAL_UI_INSTALLER" "$TEST_UI_INSTALLER"
cp "$REAL_UI_VERIFIER" "$TEST_UI_VERIFIER"
foundation_test_name="$(basename "$TEST_FOUNDATION_VERIFIER")"
ui_test_name="$(basename "$TEST_UI_VERIFIER")"
sed -i "s#verify-vmware-vcenter-phase5b3-foundation.sh#$foundation_test_name#g" "$TEST_UI_INSTALLER" "$TEST_UI_VERIFIER"
sed -i "s#verify-vmware-vcenter-phase5b3-ui.sh#$ui_test_name#g" "$TEST_UI_INSTALLER"
chmod 0700 "$TEST_FOUNDATION_INSTALLER" "$TEST_FOUNDATION_VERIFIER" "$TEST_UI_INSTALLER" "$TEST_UI_VERIFIER"

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

foundation_output="$(sudo env "${COMMON_ENV[@]}" bash "$TEST_FOUNDATION_INSTALLER" "$TARGET" 2>&1)"
printf '%s\n' "$foundation_output"
[[ "$foundation_output" == *"PHASE5B3_FOUNDATION_INSTALL_OK"* ]] || { echo "ERROR: foundation install failed in UI regression" >&2; exit 1; }

ui_output="$(sudo env "${COMMON_ENV[@]}" bash "$TEST_UI_INSTALLER" "$TARGET" 2>&1)"
printf '%s\n' "$ui_output"
[[ "$ui_output" == *"PHASE5B3_UI_INSTALL_OK"* ]] || { echo "ERROR: persistent UI install marker missing" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/index.php")" == "$PERSISTENT_INDEX_BLOB" ]] || { echo "ERROR: persistent Administration index blob mismatch" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-config-action.php")" == "$PERSISTENT_ACTION_BLOB" ]] || { echo "ERROR: persistent action endpoint blob mismatch" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/vmware-test-result.php")" == "$PHASE5A_TRANSIENT_BLOB" ]] || { echo "ERROR: transient endpoint changed by UI installer" >&2; exit 1; }

verify_output="$(sudo env "${COMMON_ENV[@]}" bash "$TEST_UI_VERIFIER" "$TARGET" 2>&1)"
printf '%s\n' "$verify_output"
[[ "$verify_output" == *"PHASE5B3_UI_VERIFY_OK"* ]] || { echo "ERROR: persistent UI verifier marker missing" >&2; exit 1; }
[[ "$("${MYSQL[@]}" "$DB_NAME" -e 'SELECT COUNT(*) FROM custom_integrations;')" == "0" ]] || { echo "ERROR: UI installer created connector rows" >&2; exit 1; }

mapfile -t ui_backups < <(find "$TARGET" -maxdepth 1 -type d -name '.phpipam-custom-vcenter-phase5b3-ui-backup-*' -print)
[[ "${#ui_backups[@]}" -eq 1 ]] || { echo "ERROR: expected one persistent UI backup" >&2; exit 1; }
UI_BACKUP="${ui_backups[0]}"
sudo test -x "$UI_BACKUP/rollback.sh" || { echo "ERROR: persistent UI rollback script missing" >&2; exit 1; }
ui_rollback_output="$(sudo bash "$UI_BACKUP/rollback.sh" 2>&1)"
printf '%s\n' "$ui_rollback_output"
[[ "$ui_rollback_output" == *"PHASE5B3_UI_ROLLBACK_OK"* ]] || { echo "ERROR: persistent UI rollback marker missing" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/app/admin/integrations/index.php")" == "$PHASE5A_INDEX_BLOB" ]] || { echo "ERROR: UI rollback did not restore Phase 5A index" >&2; exit 1; }
[[ ! -e "$TARGET/app/admin/integrations/vmware-config-action.php" ]] || { echo "ERROR: UI rollback did not remove persistent action endpoint" >&2; exit 1; }
sudo test -f "$KEYRING" || { echo "ERROR: UI rollback touched foundation keyring" >&2; exit 1; }
[[ "$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='custom_integrations';")" == "1" ]] || { echo "ERROR: UI rollback touched foundation schema" >&2; exit 1; }

mapfile -t foundation_backups < <(find "$TARGET" -maxdepth 1 -type d -name '.phpipam-custom-vcenter-phase5b3-backup-*' -print)
[[ "${#foundation_backups[@]}" -eq 1 ]] || { echo "ERROR: expected one foundation backup" >&2; exit 1; }
FOUNDATION_BACKUP="${foundation_backups[0]}"
foundation_rollback_output="$(sudo env "${COMMON_ENV[@]}" bash "$FOUNDATION_BACKUP/rollback.sh" 2>&1)"
printf '%s\n' "$foundation_rollback_output"
[[ "$foundation_rollback_output" == *"PHASE5B3_GUARDED_ROLLBACK_OK"* ]] || { echo "ERROR: foundation rollback marker missing" >&2; exit 1; }
[[ "$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='custom_integrations';")" == "0" ]] || { echo "ERROR: final foundation rollback did not remove empty schema" >&2; exit 1; }
sudo test ! -e "$KEYRING" || { echo "ERROR: final foundation rollback did not remove keyring" >&2; exit 1; }
[[ "$(git hash-object "$TARGET/functions/classes/class.Rackspace.php")" == "$TEST_RACKSPACE_BLOB" ]] || { echo "ERROR: Rackspace fixture changed" >&2; exit 1; }
[[ "$("${MYSQL[@]}" "$DB_NAME" -e 'SELECT dbversion FROM settings LIMIT 1;')" == "46" ]] || { echo "ERROR: dbversion changed" >&2; exit 1; }

# Production scripts themselves remain untouched and retain production pins.
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_FOUNDATION_INSTALLER" || { echo "ERROR: production foundation installer mutated" >&2; exit 1; }
grep -Fq "$PRODUCTION_RACKSPACE_BLOB" "$REAL_FOUNDATION_VERIFIER" || { echo "ERROR: production foundation verifier mutated" >&2; exit 1; }

echo "PHASE5B3_UI_DEPLOYMENT_REGRESSION_OK"
