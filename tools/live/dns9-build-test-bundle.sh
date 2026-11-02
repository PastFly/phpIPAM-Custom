#!/usr/bin/env bash
set -euo pipefail
umask 077

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"
SHA="$(git rev-parse HEAD)"
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] || { echo invalid_sha >&2; exit 1; }
SHORT="${SHA:0:12}"
OUT="${1:-dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/tools/live" "$STAGE/tests/dns9"
for file in \
  tools/live/dns9-phase-b3-diagnose.py \
  tools/live/dns9_winrm_common.py \
  tests/dns9/phase-b3-diagnose-source.test.py \
  tools/live/dns9-test-bundle-run.sh; do
  cp "$file" "$STAGE/$file"
done
printf '%s\n' "$SHA" > "$STAGE/EXPECTED_SHA"
printf '%s\n' 'b3-readonly-diagnostic+disk-inventory' > "$STAGE/STAGE"
(
 cd "$STAGE"
 find tools tests -type f -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
 sha256sum EXPECTED_SHA STAGE >> SHA256SUMS
)
PACKAGE="$OUT/phpipam-dns9-test-$SHORT.tar.gz"
tar --sort=name --mtime='UTC 1970-01-01' --owner=0 --group=0 --numeric-owner \
  -C "$STAGE" -czf "$PACKAGE" .
sha256sum "$PACKAGE" > "$PACKAGE.sha256"
echo "TEST_PACKAGE=$PACKAGE"
echo "TEST_PACKAGE_SHA=$SHA"
