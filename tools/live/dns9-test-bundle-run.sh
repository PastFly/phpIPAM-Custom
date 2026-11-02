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
echo 'TEST_BUNDLE_REMOTE_MUTATION=0'
echo 'TEST_BUNDLE_DB_MUTATION=0'
echo 'TEST_BUNDLE_DNS_WRITE=0'
