#!/usr/bin/env bash
set -euo pipefail

# Capture the known phpIPAM Custom runtime source surface from a live 1.8.2 tree.
# This script intentionally excludes config.php, DB content, uploads, logs, sessions,
# credentials and other runtime secrets.
#
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

PHP_FILES=(
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
)

mkdir -p "$OUT/files"
: > "$OUT/SHA256SUMS"
: > "$OUT/MISSING.txt"
: > "$OUT/PHP_LINT.txt"
: > "$OUT/CUSTOM_MARKERS.txt"
: > "$OUT/BACKUP_DIRS.txt"

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

for rel in "${PHP_FILES[@]}"; do
  src="$ROOT/$rel"
  if [[ -f "$src" ]]; then
    printf '=== %s ===\n' "$rel" >> "$OUT/PHP_LINT.txt"
    php -l "$src" >> "$OUT/PHP_LINT.txt" 2>&1 || true
  fi
done

# Capture only custom marker lines and line numbers, not unrelated runtime data.
for rel in "${FILES[@]}"; do
  src="$ROOT/$rel"
  if [[ -f "$src" ]]; then
    grep -nE 'RACK_V|UI_MENU|rack-v3|rackV3|rackAllowOverlap|strict' "$src" 2>/dev/null \
      | sed "s#^#$rel:#" >> "$OUT/CUSTOM_MARKERS.txt" || true
  fi
done

# Backup directory names are useful chronology evidence and should not contain file contents.
find "$ROOT" -maxdepth 1 -mindepth 1 -type d \
  \( -name '.rack-*backup-*' -o -name '.rack-v*backup-*' -o -name '.ui-menu-*backup-*' -o -name '.rack-v*-pre-repair-*' \) \
  -printf '%f\n' 2>/dev/null | sort > "$OUT/BACKUP_DIRS.txt" || true

{
  echo "captured_at=$STAMP"
  echo "source_root=$ROOT"
  php -v | head -n 1 | sed 's/^/php=/'
  if command -v git >/dev/null 2>&1 && [[ -d "$ROOT/.git" ]]; then
    git -C "$ROOT" rev-parse HEAD 2>/dev/null | sed 's/^/git_head=/' || true
    git -C "$ROOT" status --porcelain=v1 2>/dev/null | sed 's/^/git_status=/' || true
  else
    echo "git_worktree=false"
  fi
} > "$OUT/METADATA.txt"

TAR="${OUT}.tar.gz"
tar -C "$(dirname "$OUT")" -czf "$TAR" "$(basename "$OUT")"
sha256sum "$TAR" > "${TAR}.sha256"

echo "Capture complete:"
echo "  directory: $OUT"
echo "  archive:   $TAR"
echo "  archive sha256: ${TAR}.sha256"
echo "  file checksums: $OUT/SHA256SUMS"
echo "  php lint:       $OUT/PHP_LINT.txt"
echo "  markers:        $OUT/CUSTOM_MARKERS.txt"
echo "  backup dirs:    $OUT/BACKUP_DIRS.txt"
if [[ -s "$OUT/MISSING.txt" ]]; then
  echo "WARNING: some expected files were missing:"
  cat "$OUT/MISSING.txt"
fi
