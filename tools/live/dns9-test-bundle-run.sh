#!/usr/bin/env bash
set -euo pipefail
umask 077
cd "$(dirname "$0")/../.."
SHA="$(cat EXPECTED_SHA)"
STAGE="$(cat STAGE)"
LOG="/tmp/phpipam-dns9-test-${SHA:0:12}-$(date -u +%Y%m%d-%H%M%S)-$$.log"
touch "$LOG"
chmod 600 "$LOG"
exec 3>&1
result=FAIL
finish() {
  rc=$?
  if [[ $rc -eq 0 ]]; then result=PASS; fi
  { printf 'TEST_BUNDLE_SHA=%s\n' "$SHA"; printf 'TEST_BUNDLE_STAGE=%s\n' "$STAGE"; printf 'TEST_BUNDLE_RESULT=%s\n' "$result"; printf 'TEST_BUNDLE_LOG=%s\n' "$LOG"; } | tee -a "$LOG" >&3
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
exec > >(tee -a "$LOG" >&3) 2>&1
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$STAGE" == 'b3-readonly-diagnostic+disk-inventory' ]]
sha256sum --status -c SHA256SUMS
printf 'TEST_BUNDLE_INTEGRITY=PASS\n'
python3 - <<'PY'
from pathlib import Path
for item in ['tools/live/dns9-phase-b3-diagnose.py','tools/live/dns9_winrm_common.py','tests/dns9/phase-b3-diagnose-source.test.py']:
    compile(Path(item).read_text(),item,'exec')
print('TEST_BUNDLE_PYTHON_COMPILE=PASS')
PY
python3 tests/dns9/phase-b3-diagnose-source.test.py
if [[ "$(id -u)" != 0 ]]; then echo 'TEST_BUNDLE_ERROR=root_required'; exit 1; fi
if [[ "${1:-}" == '--b3-live-once' ]]; then
  STAGE='b3-controlled-live-once'
  echo 'TEST_BUNDLE_STAGE=b3-controlled-live-once'
  python3 - <<'PY'
from pathlib import Path
p=Path('tools/live/dns9-phase-b3-first-cycle.py')
compile(p.read_text(),str(p),'exec')
print('DNS9_B3_LIVE_SOURCE_COMPILE=PASS')
PY
  RECEIPT='/var/backups/phpipam-custom/dns9-winrm-bootstrap-20260930-122257/receipt.json'
  [[ -f "$RECEIPT" ]] || { echo 'DNS9_B3_ERROR=receipt_missing'; exit 1; }
  python3 tools/live/dns9-phase-b3-first-cycle.py --bootstrap-receipt "$RECEIPT"
  read -r -p 'WinRM domain username: ' DNS9_USER </dev/tty
  [[ -n "$DNS9_USER" ]] || { echo 'DNS9_B3_ERROR=username_missing'; exit 1; }
  # The Python entrypoint obtains the password before creating its one-shot guard.
  # A previously recorded attempt must not be cleared by this wrapper.
  python3 tools/live/dns9-phase-b3-first-cycle.py --bootstrap-receipt "$RECEIPT" --username "$DNS9_USER" --apply
  echo 'DNS9_B3_CONTROLLED_CYCLE_COMPLETED=1'
  exit 0
fi
[[ $# -eq 0 ]] || { echo 'TEST_BUNDLE_ERROR=unknown_option'; exit 1; }
echo 'TEST_BUNDLE_SERVER_POSTSTATE_BEGIN'
DB_ROW="$(mariadb --batch --skip-column-names phpipam <<'SQL'
SELECT CONCAT(state,'|',attempt_count,'|',
 IF(lease_id IS NULL OR lease_id='',0,1),'|',
 IF(credential_delivered_at IS NULL,0,1),'|',
 IF(result_code IS NULL OR result_code='',0,1),'|',
 IF(result_payload IS NULL OR OCTET_LENGTH(result_payload)=0,0,1),'|',
 IF(finished_at IS NULL,0,1))
FROM custom_dns_control_jobs
WHERE id='a4ee3e5987dd15495d7e2419e853d9c8';
SQL
)"
echo "DNS9_PILOT_POSTSTATE=$DB_ROW"
[[ "$DB_ROW" == 'pending|0|0|0|0|0|0' ]] || { echo 'TEST_BUNDLE_ERROR=pilot_not_pristine'; exit 1; }
if systemctl is-enabled --quiet phpipam-dns-autonomous.timer; then
  echo 'TEST_BUNDLE_ERROR=timer_enabled'; exit 1
fi
if systemctl is-active --quiet phpipam-dns-autonomous.timer; then
  echo 'TEST_BUNDLE_ERROR=timer_active'; exit 1
fi
echo 'DNS9_SERVER_TIMER=disabled_inactive'
echo 'TEST_BUNDLE_SERVER_POSTSTATE_END'
echo 'TEST_BUNDLE_DISK_INVENTORY_BEGIN'
for arg in '/' '/var' '/var/lib' '/var/log' '/usr' '/opt' '/home' '/var/cache'; do
  echo "DISK_SECTION=$arg"
  du -xhd1 "$arg" 2>&1 || true
done
df -h / || true
df -ih / || true
journalctl --disk-usage 2>&1 || true
if command -v docker >/dev/null 2>&1; then docker system df 2>&1 || true; fi
if command -v lsof >/dev/null 2>&1; then lsof -nP +L1 2>&1 || true; fi
echo 'DISK_LARGE_FILES_BEGIN'
find / -xdev -type f -size +200M -printf '%s %p\n' 2>/dev/null | sort -nr | head -n 60 || true
echo 'TEST_BUNDLE_DISK_INVENTORY_END'
echo 'TEST_BUNDLE_DNS9_DIAGNOSTIC_BEGIN'
read -r -p 'WinRM diagnostic username: ' DNS9_USER </dev/tty
[[ -n "$DNS9_USER" ]] || { echo 'TEST_BUNDLE_ERROR=username_required'; exit 1; }
python3 tools/live/dns9-phase-b3-diagnose.py --username "$DNS9_USER" --suffix 4aafbf3f8edc
echo 'TEST_BUNDLE_DNS9_DIAGNOSTIC_END'
echo 'TEST_BUNDLE_SERVER_POSTSTATE_BEGIN'
DB_ROW="$(mariadb --batch --skip-column-names phpipam <<'SQL'
SELECT CONCAT(state,'|',attempt_count,'|',
 IF(lease_id IS NULL OR lease_id='',0,1),'|',
 IF(credential_delivered_at IS NULL,0,1),'|',
 IF(result_code IS NULL OR result_code='',0,1),'|',
 IF(result_payload IS NULL OR OCTET_LENGTH(result_payload)=0,0,1),'|',
 IF(finished_at IS NULL,0,1))
FROM custom_dns_control_jobs
WHERE id='a4ee3e5987dd15495d7e2419e853d9c8';
SQL
)"
echo "DNS9_PILOT_POSTSTATE=$DB_ROW"
[[ "$DB_ROW" == 'pending|0|0|0|0|0|0' ]] || { echo 'TEST_BUNDLE_ERROR=pilot_not_pristine'; exit 1; }
if systemctl is-enabled --quiet phpipam-dns-autonomous.timer; then
  echo 'TEST_BUNDLE_ERROR=timer_enabled'; exit 1
fi
if systemctl is-active --quiet phpipam-dns-autonomous.timer; then
  echo 'TEST_BUNDLE_ERROR=timer_active'; exit 1
fi
echo 'DNS9_SERVER_TIMER=disabled_inactive'
echo 'TEST_BUNDLE_SERVER_POSTSTATE_END'

echo 'TEST_BUNDLE_REMOTE_MUTATION=0'
echo 'TEST_BUNDLE_DB_MUTATION=0'
echo 'TEST_BUNDLE_DNS_WRITE=0'
