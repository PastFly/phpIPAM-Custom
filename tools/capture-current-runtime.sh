#!/usr/bin/env bash
set -euo pipefail

# Capture only the known phpIPAM Custom source files from a live 1.8.2 tree.
# No config.php, database, uploads, logs, sessions, credentials or secrets.
# Run from the phpIPAM application root, normally /var/www/html.

ROOT="$(pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="${1:-/tmp/phpipam-custom-runtime-$STAMP}"

FILES=(
  "functions/classes/class.Rackspace.php"
  "app/admin/racks/edit-rack-devices.php"
  "app/admin/racks/edit-rack-devices-result.php"
  "app/admin/racks/edit-rack-devices-free-u.php"
  "app/admin/devices/edit.php"
  "app/admin/devices/edit-rack-dropdown.php"
  "app/admin/devices/edit-result.php"
  "app/tools/racks/print-single-rack.php"
  "app/tools/racks/rack-v3.1-controller.php"
  "app/tools/racks/rack-v3-move.php"
  "app/tools/racks/print-racks-map.php"
  "app/tools/racks/rack-v3.3-map-controller.php"
  "app/tools/racks/rack-v3.3-move.php"
  "app/tools/racks/rack-v3.3-data.php"
  "app/tools/racks/draw_rack.php"
  "app/tools/tools-menu-config.php"
  "app/tools/tools-menu.php"
  "css/bootstrap/bootstrap-custom.css"
  "css/bootstrap/bootstrap-custom-dark.css"
)

mkdir -p "$OUT/files"
: > "$OUT/SHA256SUMS"
: > "$OUT/MISSING.txt"

for rel in "${FILES[@]}"; do
  src="$ROOT/$rel"
  if [[ -f "$src" ]]; then
    mkdir -p "$OUT/files/$(dirname "$rel")"
    cp -a "$src" "$OUT/files/$rel"
    sha256sum "$src" | sed "s#  $ROOT/#  #" >> "$OUT/SHA256SUMS"
  else
    printf '%s\n' "$rel" >> "$OUT/MISSING.txt"
  fi
done

{
  echo "captured_at=$STAMP"
  echo "source_root=$ROOT"
  php -v | head -n 1 | sed 's/^/php=/'
} > "$OUT/METADATA.txt"

TAR="${OUT}.tar.gz"
tar -C "$(dirname "$OUT")" -czf "$TAR" "$(basename "$OUT")"

echo "Capture complete:"
echo "  directory: $OUT"
echo "  archive:   $TAR"
echo "  checksums: $OUT/SHA256SUMS"
if [[ -s "$OUT/MISSING.txt" ]]; then
  echo "WARNING: some expected files were missing:"
  cat "$OUT/MISSING.txt"
fi
