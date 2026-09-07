#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom Phase 0 — baseline capture v2
# Reconstruct the pre-custom source baseline from timestamped hotfix backups
# and verify each reconstructed file byte-for-byte against upstream phpIPAM v1.8.2
# using Git blob SHA-1 (git hash-object).
#
# READ-ONLY with respect to /var/www/html.
# No config.php, DB data, uploads, logs, sessions or credentials are read.
#
# Run from /var/www/html.

ROOT="$(pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="${1:-/tmp/phpipam-precustom-baseline-$STAMP}"

if ! command -v git >/dev/null 2>&1; then
    echo "ERROR: git is required for exact upstream blob verification."
    exit 1
fi

mkdir -p "$OUT/files"
: > "$OUT/PROVENANCE.tsv"
: > "$OUT/SHA256SUMS"
: > "$OUT/GIT_BLOB_SHA1.tsv"
: > "$OUT/UPSTREAM_VERIFY.tsv"
: > "$OUT/PHP_LINT.txt"
: > "$OUT/ERRORS.txt"

copy_and_verify() {
    local rel="$1"
    local backup="$2"
    local expected_blob="$3"
    local src="$ROOT/$backup/$rel"
    local dst="$OUT/files/$rel"

    if [[ ! -f "$src" ]]; then
        printf 'MISSING\t%s\t%s\n' "$rel" "$backup" >> "$OUT/ERRORS.txt"
        return
    fi

    mkdir -p "$(dirname "$dst")"
    cp -a "$src" "$dst"

    local actual_blob
    actual_blob="$(git hash-object "$dst")"

    printf '%s\t%s\n' "$rel" "$backup" >> "$OUT/PROVENANCE.tsv"
    sha256sum "$dst" | sed "s#  $OUT/files/#  #" >> "$OUT/SHA256SUMS"
    printf '%s\t%s\n' "$rel" "$actual_blob" >> "$OUT/GIT_BLOB_SHA1.tsv"

    if [[ "$actual_blob" == "$expected_blob" ]]; then
        printf 'OK\t%s\t%s\n' "$rel" "$actual_blob" >> "$OUT/UPSTREAM_VERIFY.tsv"
    else
        printf 'MISMATCH\t%s\texpected=%s\tactual=%s\tbackup=%s\n' "$rel" "$expected_blob" "$actual_blob" "$backup" >> "$OUT/UPSTREAM_VERIFY.tsv"
        printf 'UPSTREAM_MISMATCH\t%s\texpected=%s\tactual=%s\tbackup=%s\n' "$rel" "$expected_blob" "$actual_blob" "$backup" >> "$OUT/ERRORS.txt"
    fi
}

copy_and_verify "functions/classes/class.Rackspace.php" ".rack-v2-backup-20260907-114949" "7686c174273c3b454c79a8cda567744d9182a8d4"
copy_and_verify "app/admin/racks/edit-rack-devices.php" ".rack-v2-backup-20260907-114949" "7cf82e6bf4095902c9fe6ad1e026abfc9c3331f1"
copy_and_verify "app/admin/racks/edit-rack-devices-result.php" ".rack-v2-backup-20260907-114949" "a2bf902293df432805ff0a61eb615ed24931d19a"
copy_and_verify "app/admin/devices/edit.php" ".rack-v2.1-backup-20260907-120911" "b6c61c8a213b0554190ad237ad8b7150ec1397f3"
copy_and_verify "app/admin/devices/edit-rack-dropdown.php" ".rack-v2.1-backup-20260907-120911" "ab6da10374ae7e28bcfa41ccfb0080910a625449"
copy_and_verify "app/admin/devices/edit-result.php" ".rack-v2.1-backup-20260907-120911" "8e3ecd2f499f5e66027b53651422d2957796438b"
copy_and_verify "app/tools/racks/print-single-rack.php" ".rack-v3.0-backup-20260907-124438" "9e9dce6c8282b422f44067c9dfc52f4b7b51f44a"
copy_and_verify "app/tools/racks/print-racks-map.php" ".rack-v3.3-backup-20260907-132810" "778f99f46e7fdb66d92728da317302fce3ff22bc"
copy_and_verify "app/tools/racks/draw_rack.php" ".rack-v3.3.3-backup-20260907-134220" "974b750221c5d0af38e4f81a348e60c029a690fb"
copy_and_verify "app/tools/tools-menu-config.php" ".ui-menu-v1-backup-20260907-130414" "f5c3f05418a8a280e8a98c8fe75972411be0fc0b"
copy_and_verify "app/tools/tools-menu.php" ".ui-menu-v1-backup-20260907-130414" "01fe8886ef4d8122894076ccce613b015d89c355"
copy_and_verify "css/bootstrap/bootstrap-custom.css" ".ui-menu-v1.2-backup-20260907-130853" "b1c84072bc3d5665ead8391037204a7b72b42be0"
copy_and_verify "css/bootstrap/bootstrap-custom-dark.css" ".ui-menu-v1.2-backup-20260907-130853" "95e64ad3a61f273ca440322b80ca00b6950b65a6"

if [[ -s "$OUT/ERRORS.txt" ]]; then
    echo "ERROR: reconstructed pre-custom baseline is not fully verified."
    echo
    cat "$OUT/ERRORS.txt"
    echo
    echo "Verification table:"
    cat "$OUT/UPSTREAM_VERIFY.tsv"
    echo
    echo "No baseline archive was created."
    exit 2
fi

find "$OUT/files" -type f -name '*.php' -print0 | sort -z | while IFS= read -r -d '' file; do
    rel="${file#"$OUT/files/"}"
    printf '=== %s ===\n' "$rel" >> "$OUT/PHP_LINT.txt"
    php -l "$file" >> "$OUT/PHP_LINT.txt" 2>&1 || true
done

if grep -q 'Errors parsing' "$OUT/PHP_LINT.txt"; then
    echo "ERROR: at least one reconstructed PHP file failed lint."
    cat "$OUT/PHP_LINT.txt"
    exit 3
fi

{
    echo "captured_at=$STAMP"
    echo "source_root=$ROOT"
    echo "purpose=pre-custom baseline reconstruction from hotfix backups"
    echo "verified_upstream=phpipam/phpipam v1.8.2"
    echo "verification=git_blob_sha1_exact"
    echo "verified_file_count=13"
    php -v | head -n 1 | sed 's/^/php=/'
} > "$OUT/METADATA.txt"

TAR="${OUT}.tar.gz"
tar -C "$(dirname "$OUT")" -czf "$TAR" "$(basename "$OUT")"
sha256sum "$TAR" > "${TAR}.sha256"

echo "Pre-custom baseline capture VERIFIED:"
echo "  directory:       $OUT"
echo "  archive:         $TAR"
echo "  archive sha256:  ${TAR}.sha256"
echo "  provenance:      $OUT/PROVENANCE.tsv"
echo "  upstream verify: $OUT/UPSTREAM_VERIFY.tsv"
echo "  file checksums:  $OUT/SHA256SUMS"
echo "  git blob SHA1:   $OUT/GIT_BLOB_SHA1.tsv"
echo "  php lint:        $OUT/PHP_LINT.txt"
echo
echo "All 13 reconstructed files exactly match upstream phpIPAM v1.8.2 Git blobs."
