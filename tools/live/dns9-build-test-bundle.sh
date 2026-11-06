#!/usr/bin/env bash
set -euo pipefail
umask 077

BUILDER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
SHA="$(git rev-parse HEAD)"
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || { echo invalid_sha >&2; exit 1; }
if ! git cat-file blob "$SHA:tools/live/dns9-build-test-bundle.sh" | cmp -s "$BUILDER" -; then
  echo builder_not_at_head >&2
  exit 1
fi
SHORT="${SHA:0:12}"
OUT="${1:-dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/tools/live" "$STAGE/tests/dns9" "$STAGE/collectors/windows/dns"
for file in \
  tools/live/dns9-phase-b3-diagnose.py \
  tools/live/dns9_winrm_common.py \
  tests/dns9/phase-b3-diagnose-source.test.py \
  tools/live/dns9-phase-b3-first-cycle.py \
  tools/live/dns9-phase-b3-recover.py \
  tools/live/dns9-phase-b3-post-check.py \
  tools/live/dns9-phase-b3-transport-probe.py \
  tests/dns9/phase-b3-transport-probe.test.py \
  tools/live/dns9-runner-tls-upgrade.py \
  tests/dns9/runner-tls-upgrade.test.py \
  tools/live/dns9-b3-auto-once.py \
  tools/live/dns9-b3-after-pdo.py \
  tools/live/dns9-b4-list-zones.py \
  tests/dns9/b4-list-zones.test.py \
  tools/live/dns9-ui-deploy.py \
  tools/live/dns9-scheduler-deploy.py \
  tools/live/dns9-activation-preflight.py \
  tools/live/dns9-activation-preflight.ps1 \
  tools/live/dns9-activation-coordinator.py \
  tools/live/dns9-activation-task-control.ps1 \
  tests/dns9/activation-coordinator.test.py \
  tools/live/dns9-autonomous-observe.py \
  tests/dns9/autonomous-observe.test.py \
  tools/live/dns9-activation-queue.sql \
  tools/live/dns9-activation-mutex.php \
  tests/dns9/activation-mutex.test.php \
  tests/dns1/memory-repository.php \
  tests/dns9/activation-preflight.test.py \
  deploy/dns9/phpipam-dns-autonomous.service \
  deploy/dns9/phpipam-dns-autonomous.timer \
  tests/dns9/scheduler-deploy.test.py \
  tests/dns9/autonomous-scheduler.test.php \
  layers/dns9/functions/classes/class.IntegrationDnsAutonomousScheduler.php \
  layers/dns9/functions/scripts/dns-autonomous-scheduler.php \
  tools/live/dns9-ui-smoke.php \
  tests/dns9/ui-deploy.test.py \
  tests/dns9/ui-smoke.test.php \
  layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php \
  layers/dns1/functions/classes/class.IntegrationDnsAdminView.php \
  layers/dns1/app/admin/integrations/dns/config.js \
  tests/dns9/b3-after-pdo.test.py \
  tools/live/dns9-queue-cli.php \
  tests/dns9/b3-auto-once.test.py \
  tests/dns9/b3-retry-guard.test.py \
  tests/dns9/queue-cli.test.php \
  tools/live/dns9-system-probe.py \
  tools/live/dns9-system-probe.ps1 \
  tools/live/dns9-system-probe-control.ps1 \
  tests/dns9/system-probe.test.py \
  tools/live/dns9-pdo-hotfix.py \
  tools/live/dns9-cold-db-check.php \
  tests/dns9/pdo-hotfix.test.py \
  tests/dns9/lazy-database.test.php \
  layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php \
  layers/dns5/functions/classes/class.IntegrationDnsCollectorMachineHttpController.php \
  layers/dns5/app/integrations/dns/machine-control.php \
  tests/dns3/control-memory-repository.php \
  layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php \
  layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php \
  layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php \
  layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php \
  layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php \
  collectors/windows/dns/Invoke-PhpIpamDnsCollectorScheduled.ps1 \
  tools/live/dns9-test-bundle-run.sh; do
  mkdir -p "$(dirname "$STAGE/$file")"
  git cat-file blob "$SHA:$file" > "$STAGE/$file"
  mode="$(git ls-tree "$SHA" -- "$file")"
  case "${mode%% *}" in
    100644) ;;
    100755) chmod 700 "$STAGE/$file" ;;
    *) echo invalid_payload_mode >&2; exit 1 ;;
  esac
done
printf '%s\n' "$SHA" > "$STAGE/EXPECTED_SHA"
printf '%s\n' 'b3-readonly-diagnostic+disk-inventory' > "$STAGE/STAGE"
(
 cd "$STAGE"
 find tools tests collectors layers deploy -type f -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
 sha256sum EXPECTED_SHA STAGE >> SHA256SUMS
)
PACKAGE="$OUT/phpipam-dns9-test-$SHORT.tar.gz"
tar --sort=name --mtime='UTC 1970-01-01' --owner=0 --group=0 --numeric-owner \
  -C "$STAGE" -czf "$PACKAGE" .
(cd "$OUT" && sha256sum "$(basename "$PACKAGE")" > "$(basename "$PACKAGE").sha256")
RUN="$OUT/phpipam-dns9-test-$SHORT.run"
cat > "$RUN" <<'HEADER'
#!/usr/bin/env bash
set -euo pipefail
umask 077
TMP="$(mktemp -d /tmp/phpipam-dns9-bundle.XXXXXXXX)"
trap 'rm -rf "$TMP"' EXIT
PAYLOAD_LINE="$(awk '/^__ARCHIVE_BELOW__$/ {print NR+1;exit}' "$0")"
[[ "$PAYLOAD_LINE" =~ ^[0-9]+$ ]] || { echo 'TEST_BUNDLE_ERROR=archive_marker_missing'; exit 1; }
tail -n +"$PAYLOAD_LINE" "$0" | tar -xz -C "$TMP"
cd "$TMP"
sha256sum --status -c SHA256SUMS || { echo 'TEST_BUNDLE_ERROR=checksum_invalid'; exit 1; }
exec 4>&1
bash tools/live/dns9-test-bundle-run.sh "$@"
exit $?
__ARCHIVE_BELOW__
HEADER
cat "$PACKAGE" >> "$RUN"
chmod 700 "$RUN"
(cd "$OUT" && sha256sum "$(basename "$RUN")" > "$(basename "$RUN").sha256")
echo "TEST_PACKAGE=$RUN"
echo "TEST_PACKAGE_SHA=$SHA"
