#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FORWARD="$ROOT/migrations/phase5b/001-create-custom-integrations.sql"
ROLLBACK="$ROOT/migrations/phase5b/001-rollback-custom-integrations.sql"
DB_HOST="${PHASE5B_DB_HOST:-127.0.0.1}"
DB_PORT="${PHASE5B_DB_PORT:-3306}"
DB_USER="${PHASE5B_DB_USER:-root}"
DB_PASSWORD="${PHASE5B_DB_PASSWORD:-phase5b-ci}"
DB_NAME="phase5b_ci_${GITHUB_RUN_ID:-$$}_${GITHUB_RUN_ATTEMPT:-1}"
DB_NAME="${DB_NAME//[^A-Za-z0-9_]/_}"

[[ -f "$FORWARD" ]] || { echo "ERROR: forward migration missing" >&2; exit 1; }
[[ -f "$ROLLBACK" ]] || { echo "ERROR: rollback migration missing" >&2; exit 1; }

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
"${MYSQL[@]}" "$DB_NAME" < "$FORWARD"

table_comment="$("${MYSQL[@]}" "$DB_NAME" -e "SELECT TABLE_COMMENT FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='custom_integrations';")"
[[ "$table_comment" == "PHPIPAM_CUSTOM_PHASE5B_INTEGRATIONS_SCHEMA_V1" ]] || {
    echo "ERROR: schema marker/comment mismatch: $table_comment" >&2
    exit 1
}

column_count="$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='custom_integrations';")"
[[ "$column_count" == "17" ]] || { echo "ERROR: expected 17 columns, got $column_count" >&2; exit 1; }

verify_tls_default="$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='custom_integrations' AND column_name='verify_tls';")"
enabled_default="$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='custom_integrations' AND column_name='enabled';")"
[[ "$verify_tls_default" == "1" ]] || { echo "ERROR: verify_tls default is not 1" >&2; exit 1; }
[[ "$enabled_default" == "0" ]] || { echo "ERROR: enabled default is not 0" >&2; exit 1; }

unique_count="$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='custom_integrations' AND index_name='uq_custom_integrations_provider_endpoint' AND non_unique=0;")"
[[ "$unique_count" == "2" ]] || { echo "ERROR: provider/endpoint unique index not present as 2-column index" >&2; exit 1; }

"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO custom_integrations (provider,name,endpoint,username,secret_envelope,secret_key_id) VALUES ('vmware_vcenter','CI VC','https://ci-vcenter.example','svc-ci','{\"v\":1}','ci-key');"

set +e
rollback_output="$("${MYSQL[@]}" "$DB_NAME" < "$ROLLBACK" 2>&1)"
rollback_rc=$?
set -e
[[ "$rollback_rc" -ne 0 ]] || {
    echo "ERROR: rollback unexpectedly dropped a non-empty table" >&2
    exit 1
}
[[ "$rollback_output" == *"Refusing Phase 5B rollback"* ]] || {
    echo "ERROR: rollback failed for an unexpected reason" >&2
    echo "$rollback_output" >&2
    exit 1
}

still_exists="$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='custom_integrations';")"
[[ "$still_exists" == "1" ]] || { echo "ERROR: non-empty rollback did not preserve table" >&2; exit 1; }

"${MYSQL[@]}" "$DB_NAME" -e 'DELETE FROM custom_integrations;'
"${MYSQL[@]}" "$DB_NAME" < "$ROLLBACK"

gone="$("${MYSQL[@]}" "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='custom_integrations';")"
[[ "$gone" == "0" ]] || { echo "ERROR: empty-table rollback did not remove custom_integrations" >&2; exit 1; }

# A second rollback against an already-absent table is intentionally harmless.
"${MYSQL[@]}" "$DB_NAME" < "$ROLLBACK"

echo "PHASE5B_MIGRATION_REGRESSION_OK"
