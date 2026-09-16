#!/usr/bin/env bash
set -euo pipefail
umask 077

REPO='/tmp/phpipam-custom-phase0'
BRANCH='phase5h/freshness-capability-convergence'
SOURCE='78019afb2f404b80afe24a686aa56afa21f12ffc'
REL='tools/live/phase5h5-fresh-series-residual-v2.php'
BLOB='593fcb5c29a717b00a04259335d91a9a04cad6db'
LOCK='/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock'
TMP=''
LOCK_HELD=0

cleanup(){
  rc=$?; trap - EXIT
  if [ "$LOCK_HELD" -eq 1 ]; then flock -u 9 >/dev/null 2>&1 || true; exec 9>&- || true; fi
  [ -n "$TMP" ] && rm -rf -- "$TMP"
  printf '\nPHASE5H5_FRESHRESIDUAL_BLOCK_EXIT=%s\n' "$rc"
  exit "$rc"
}
trap cleanup EXIT

[ "$(id -u)" -eq 0 ] || { echo 'STOP: root required'; exit 1; }
for bin in git php flock runuser stat; do command -v "$bin" >/dev/null; done
cd -- "$REPO"
git fetch --no-tags origin "$BRANCH"
git cat-file -e "${SOURCE}^{commit}"
git merge-base --is-ancestor "$SOURCE" FETCH_HEAD

TMP="$(mktemp -d /var/tmp/phpipam-phase5h5-freshresidual.XXXXXXXX)"
chmod 0711 "$TMP"
PROBE="$TMP/probe.php"
git show "${SOURCE}:${REL}" > "$PROBE"
chmod 0644 "$PROBE"
[ "$(git hash-object "$PROBE")" = "$BLOB" ] || { echo 'STOP: probe blob mismatch'; exit 1; }
php -l "$PROBE" >/dev/null

[ -f "$LOCK" ] && [ ! -L "$LOCK" ] || { echo 'STOP: worker lock missing or linked'; exit 1; }
read -r uid gid mode links size < <(stat -c '%u %g %a %h %s' "$LOCK")
[ "$uid" = 33 ] && [ "$gid" = 33 ] && [ "$mode" = 600 ] && [ "$links" = 1 ] && [ "$size" = 0 ] || { echo 'STOP: worker lock metadata mismatch'; exit 1; }

exec 9<>"$LOCK"
echo 'PHASE5H5_FRESHRESIDUAL_LOCK_WAIT=1'
flock -x -w 180 9 || { echo 'STOP: timed out waiting for worker lock'; exit 1; }
LOCK_HELD=1
echo 'PHASE5H5_FRESHRESIDUAL_LOCK_ACQUIRED=1'
runuser -u www-data -- php -d memory_limit=256M "$PROBE"
flock -u 9; exec 9>&-; LOCK_HELD=0

echo 'PHASE5H5_FRESHRESIDUAL_READ_ONLY=1'
echo 'PHASE5H5_FRESHRESIDUAL_SHADOW_WRITTEN=0'
echo 'PHASE5H5_FRESHRESIDUAL_WEBSITE_CHANGED=0'
echo 'PHASE5H5_FRESHRESIDUAL_SYSTEMD_CHANGED=0'
