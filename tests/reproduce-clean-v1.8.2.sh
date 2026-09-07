#!/usr/bin/env bash
set -euo pipefail

# Clean reproducibility gate.
# Downloads the official phpIPAM 1.8.2 release into /tmp, verifies its SHA256,
# installs the normalized overlay into that throw-away tree, runs verify.sh,
# then executes Phase 1 rack regression tests against the actual normalized
# source. The live /var/www/html tree is never touched.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
WORK="${TMPDIR:-/tmp}/phpipam-custom-repro-$STAMP"
ARCHIVE="$WORK/phpipam-v1.8.2.tgz"
EXTRACT="$WORK/extracted"
URL="https://github.com/phpipam/phpipam/releases/download/v1.8.2/phpipam-v1.8.2.tgz"
EXPECTED_SHA256="12745b9cc9f937b219043cc39ee6533b95f9e8ed8b4381ea6f6d96005487bf8d"

mkdir -p "$WORK" "$EXTRACT"

echo "phpIPAM Custom clean reproduction"
echo "  work: $WORK"

bash -n "$REPO_ROOT/installer/install-clean-v1.8.2.sh"
bash -n "$REPO_ROOT/installer/verify.sh"
php -l "$REPO_ROOT/tests/rack-free-u-regression.php" >/dev/null
php -l "$REPO_ROOT/tests/rack-backend-regression.php" >/dev/null
php -l "$REPO_ROOT/tests/rack-endpoint-security-regression.php" >/dev/null
php -l "$REPO_ROOT/tests/rack-dnd-source-regression.php" >/dev/null
echo "  installer/test syntax: OK"

if command -v curl >/dev/null 2>&1; then
    curl -fL --retry 3 --connect-timeout 15 -o "$ARCHIVE" "$URL"
elif command -v wget >/dev/null 2>&1; then
    wget -O "$ARCHIVE" "$URL"
else
    echo "ERROR: curl or wget is required" >&2
    exit 1
fi

actual="$(sha256sum "$ARCHIVE" | awk '{print $1}')"
if [[ "$actual" != "$EXPECTED_SHA256" ]]; then
    echo "ERROR: official phpIPAM archive SHA256 mismatch" >&2
    echo "expected=$EXPECTED_SHA256" >&2
    echo "actual=$actual" >&2
    exit 2
fi

echo "  official release SHA256: OK"

tar -xzf "$ARCHIVE" -C "$EXTRACT"

# Release archives may contain files directly or one top-level directory.
TARGET="$EXTRACT"
if [[ ! -f "$TARGET/functions/functions.php" ]]; then
    mapfile -t candidates < <(find "$EXTRACT" -mindepth 1 -maxdepth 1 -type d -print)
    if [[ "${#candidates[@]}" -eq 1 && -f "${candidates[0]}/functions/functions.php" ]]; then
        TARGET="${candidates[0]}"
    else
        echo "ERROR: could not identify extracted phpIPAM application root" >&2
        find "$EXTRACT" -mindepth 1 -maxdepth 2 -type f | head -50 >&2 || true
        exit 3
    fi
fi

echo "  pristine tree: $TARGET"

# Record pristine hashes for normalized delta/upstream-preserved files.
for rel in \
    functions/classes/class.Rackspace.php \
    css/bootstrap/bootstrap-custom.css \
    css/bootstrap/bootstrap-custom-dark.css \
    app/tools/racks/draw_rack.php \
    app/tools/tools-menu.php; do
    printf '  upstream %-52s %s\n' "$rel" "$(git hash-object "$TARGET/$rel")"
done

bash "$REPO_ROOT/installer/install-clean-v1.8.2.sh" "$TARGET"
bash "$REPO_ROOT/installer/verify.sh" "$TARGET"

# Phase 1: exercise actual normalized rack placement methods and source guards.
php "$REPO_ROOT/tests/rack-free-u-regression.php" "$TARGET"
php "$REPO_ROOT/tests/rack-backend-regression.php" "$TARGET"
php "$REPO_ROOT/tests/rack-endpoint-security-regression.php" "$TARGET"
php "$REPO_ROOT/tests/rack-dnd-source-regression.php" "$TARGET"

echo
echo "REPRODUCE_OK: clean official phpIPAM 1.8.2 -> normalized phpIPAM Custom reproduction and rack regressions passed."
echo "Throw-away test tree retained at: $WORK"
echo "Delete it when no longer needed: rm -rf '$WORK'"
