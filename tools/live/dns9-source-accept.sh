#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

python3 tests/dns9/autonomous-source.test.py
python3 tests/dns9/windows-fixture-stage-source.test.py
python3 tests/dns9/production-preflight-source.test.py
python3 tests/dns9/server-foundation-source.test.py
python3 - <<'PY'
from pathlib import Path
source=Path("tools/live/dns9-windows-fixture-stage.py").read_text()
compile(source,"tools/live/dns9-windows-fixture-stage.py","exec")
print("DNS9_SOURCE_FIXTURE_STAGE_PYTHON_COMPILE=1")
PY

php -l layers/dns9/functions/classes/class.IntegrationDnsAutonomousScheduler.php
php -l layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php
php -l layers/dns9/functions/scripts/dns-autonomous-scheduler.php
php -l layers/dns1/functions/classes/class.IntegrationDnsAdminController.php
php -l layers/dns1/functions/classes/class.IntegrationDnsAdminView.php
php -l layers/dns1/app/admin/integrations/dns-config-action.php
php -l layers/dns1/app/admin/integrations/dns-config-panel.php

php tests/dns9/autonomous-scheduler.test.php
php tests/dns9/autonomous-admin.test.php

if command -v node >/dev/null 2>&1; then
    node --check layers/dns1/app/admin/integrations/dns/config.js
    echo "DNS9_SOURCE_NODE_CHECK=1"
else
    echo "DNS9_SOURCE_NODE_CHECK=SKIPPED_NODE_MISSING"
fi

if command -v systemd-analyze >/dev/null 2>&1; then
    systemd-analyze verify         deploy/dns9/phpipam-dns-autonomous.service         deploy/dns9/phpipam-dns-autonomous.timer
    echo "DNS9_SOURCE_SYSTEMD_VERIFY=1"
else
    echo "DNS9_SOURCE_SYSTEMD_VERIFY=SKIPPED_SYSTEMD_ANALYZE_MISSING"
fi

echo "DNS9_SOURCE_DB_MUTATION=0"
echo "DNS9_SOURCE_SYSTEMD_MUTATION=0"
echo "DNS9_SOURCE_WINDOWS_TASK_MUTATION=0"
echo "DNS9_SOURCE_ACCEPT_OK=1"
