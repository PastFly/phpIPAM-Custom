#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom Phase 0
# Capture pre-custom source copies from timestamped hotfix backups.
#
# READ-ONLY with respect to /var/www/html:
# - does not modify phpIPAM
# - does not read config.php, DB data, uploads, logs, sessions or credentials
# - copies only explicitly whitelisted source files from our own backup directories
#
# Run from /var/www/html.

ROOT="$(pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="${1:-/tmp/phpipam-precustom-baseline-$STAMP}"

mkdir -p "$OUT/files"
: > "$OUT/PROVENANCE.tsv"
: > "$OUT/SHA256SUMS"
: > "$OUT/GIT_BLOB_SHA1.tsv"
: > "$OUT/PHP_LINT.txt"
: > "$OUT/ERRORS.txt"

copy_from_backup() {
    local rel="$1"
    local backup="$2"
    local src="$ROOT/$backup/$rel"
    local dst="$OUT/files/$rel"

    if [[ ! -f "$src" ]]; then
        printf 'MISSING\t%s\t%s\n' "$rel" "$backup" >> "$OUT/ERRORS.txt"
        return 1
    fi

    mkdir -p "$(dirname "$dst")"
    cp -a "$src" "$dst"

    printf '%s\t%s\n' "$rel" "$backup" >> "$OUT/PROVENANCE.tsv"
    sha256sum "$dst" | sed "s#  $OUT/files/#  #" >> "$OUT/SHA256SUMS"

    if command -v git >/dev/null 2>&1; then
        printf '%s\t%s\n' "$rel" "$(git hash-object "$dst")" >> "$OUT/GIT_BLOB_SHA1.tsv"
    fi
}

# Earliest known pre-change copies for each source surface.
copy_from_backup "functions/classes/class.Rackspace.php" \
    ".rack-v2-backup-20260907-114949"

copy_from_backup "app/admin/racks/edit-rack-devices.php" \
    ".rack-v2-backup-20260907-114949"

copy_from_backup "app/admin/racks/edit-rack-devices-result.php" \
    ".rack-v2-backup-20260907-114949"

copy_from_backup "app/admin/devices/edit.php" \
    ".rack-v2.1-backup-20260907-120911"

copy_from_backup "app/admin/devices/edit-rack-dropdown.php" \
    ".rack-v2.1-backup-20260907-120911"

copy_from_backup "app/admin/devices/edit-result.php" \
    ".rack-v2.1-backup-20260907-120911"

copy_from_backup "app/tools/racks/print-single-rack.php" \
    ".rack-v3.0-backup-20260907-124438"

copy_from_backup "app/tools/racks/print-racks-map.php" \
    ".rack-v3.3-backup-20260907-132810"

copy_from_backup "app/tools/racks/draw_rack.php" \
    ".rack-v3.3.3-backup-20260907-134220"

copy_from_backup "app/tools/tools-menu-config.php" \
    ".ui-menu-v1-backup-20260907-130414"

copy_from_backup "app/tools/tools-menu.php" \
    ".ui-menu-v1-backup-20260907-130414"

copy_from_backup "css/bootstrap/bootstrap-custom.css" \
    ".ui-menu-v1-backup-20260907-130414"

copy_from_backup "css/bootstrap/bootstrap-custom-dark.css" \
    ".ui-menu-v1-backup-20260907-130414"

# Fail closed if any expected baseline source was unavailable.
if [[ -s "$OUT/ERRORS.txt" ]]; then
    echo "ERROR: one or more expected backup files were not found."
    cat "$OUT/ERRORS.txt"
    echo
    echo "No baseline archive was created."
    exit 2
fi

find "$OUT/files" -type f -name '*.php' -print0 \
  | sort -z \
  | while IFS= read -r -d '' file; do
        rel="${file#"$OUT/files/"}"
        printf '=== %s ===\n' "$rel" >> "$OUT/PHP_LINT.txt"
        php -l "$file" >> "$OUT/PHP_LINT.txt" 2>&1 || true
    done

{
    echo "captured_at=$STAMP"
    echo "source_root=$ROOT"
    echo "purpose=pre-custom baseline reconstruction from hotfix backups"
    echo "expected_upstream=phpIPAM 1.8.2"
    php -v | head -n 1 | sed 's/^/php=/'
} > "$OUT/METADATA.txt"

TAR="${OUT}.tar.gz"
tar -C "$(dirname "$OUT")" -czf "$TAR" "$(basename "$OUT")"
sha256sum "$TAR" > "${TAR}.sha256"

echo "Pre-custom baseline capture complete:"
echo "  directory:       $OUT"
echo "  archive:         $TAR"
echo "  archive sha256:  ${TAR}.sha256"
echo "  provenance:      $OUT/PROVENANCE.tsv"
echo "  file checksums:  $OUT/SHA256SUMS"
echo "  git blob SHA1:   $OUT/GIT_BLOB_SHA1.tsv"
echo "  php lint:        $OUT/PHP_LINT.txt"
