#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 4 Integrations framework installer.
# Expected target: already-normalized Phase 1 phpIPAM Custom tree.
# No database schema changes are made.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_ROOT="$REPO_ROOT/src"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-integrations-v1-backup-$STAMP"
STAGE="$(mktemp -d)"

cleanup() {
    rm -rf "$STAGE"
}
trap cleanup EXIT

for cmd in git php python3; do
    command -v "$cmd" >/dev/null 2>&1 || {
        echo "ERROR: required command not found: $cmd" >&2
        exit 1
    }
done

[[ -d "$TARGET" ]] || {
    echo "ERROR: target phpIPAM root not found: $TARGET" >&2
    exit 1
}

hash_file() {
    git hash-object "$1"
}

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

# The Integrations foundation is layered on top of the normalized Phase 1 build.
RACKSPACE="$TARGET/functions/classes/class.Rackspace.php"
[[ -f "$RACKSPACE" ]] || fail "normalized Rackspace class missing"
grep -Fq 'public function free_u($rack, $rack_devices, $rack_contents, $current_device = null, $force_no_overlap = false)' "$RACKSPACE" || \
    fail "target is not the normalized phpIPAM Custom baseline (strict free_u marker missing)"
grep -Fq 'PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP' "$RACKSPACE" || \
    fail "target is not the Phase 1 normalized baseline (Full Depth backend marker missing)"
[[ -f "$TARGET/app/tools/racks/rack-v3.3-map-controller.php" ]] || \
    fail "normalized V3.3.5 controller missing"

# Exact repository source blobs. Files are plain UTF-8 in Git.
declare -A SOURCE_BLOB=(
    ["app/integrations/registry.php"]="2a03e1b96f5fc493eeee2adceceb3c717830d50b"
    ["app/integrations/index.php"]="7411f3bebc259c5b0535e0db57055c0b38aff36e"
    ["app/admin/integrations/index.php"]="11a26efaa9c51723afffbffebf13a0067f62c53f"
)

# These files remain exact upstream v1.8.2 after the normalized Phase 1 installer.
declare -A CORE_BLOB=(
    ["index.php"]="bd9d79351cc782ba477d30e9f748720f7bfa6ce3"
    ["app/sections/index.php"]="0c0860e6dc4001ab67aef5dea7436f73efbf82a8"
    ["app/sections/menu/menu-tools.php"]="6be8bed21ec9782c4825fd3785a4da3adb48929b"
    ["app/sections/menu/menu-static.php"]="b19d4e0d1af944a44ed180f181e29fa532c6e629"
    ["app/admin/admin-menu-config.php"]="f6bfa72ef05426a14a5da9bb4d37d3d2b7aec9ce"
)

NEW_FILES=(
    "app/integrations/registry.php"
    "app/integrations/index.php"
    "app/admin/integrations/index.php"
)

echo "Phase 4 Integrations framework preflight"
echo "  repo:   $REPO_ROOT"
echo "  target: $TARGET"

for rel in "${!SOURCE_BLOB[@]}"; do
    src="$SOURCE_ROOT/$rel"
    [[ -f "$src" ]] || fail "Phase 4 source missing: $rel"
    actual="$(hash_file "$src")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || \
        fail "Phase 4 source blob mismatch for $rel: expected ${SOURCE_BLOB[$rel]}, got $actual"
done

echo "  Phase 4 source blobs: OK"

for rel in "${!CORE_BLOB[@]}"; do
    dst="$TARGET/$rel"
    [[ -f "$dst" ]] || fail "core target file missing: $rel"
    actual="$(hash_file "$dst")"
    [[ "$actual" == "${CORE_BLOB[$rel]}" ]] || \
        fail "$rel does not match the expected Phase 1 base (expected ${CORE_BLOB[$rel]}, got $actual)"
done

for rel in "${NEW_FILES[@]}"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Integrations file already exists: $rel"
done

echo "  Phase 1/core preconditions: OK"

# Stage exact new source files.
for rel in "${!SOURCE_BLOB[@]}"; do
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$SOURCE_ROOT/$rel" "$STAGE/$rel"
done

# Stage core files and apply deterministic, fail-closed routing/menu deltas.
for rel in "${!CORE_BLOB[@]}"; do
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$TARGET/$rel" "$STAGE/$rel"
done

python3 - "$STAGE" <<'PY'
from pathlib import Path
import sys

root = Path(sys.argv[1])

def replace_once(path, old, new, label):
    p = root / path
    s = p.read_text(encoding="utf-8")
    count = s.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one precondition match, got {count}")
    if "PHPIPAM_CUSTOM_INTEGRATIONS_" in s:
        raise SystemExit(f"{label}: Integrations patch marker already present")
    p.write_text(s.replace(old, new, 1), encoding="utf-8")

# Root route: render Integrations as a dedicated full-width product page.
replace_once(
    "index.php",
    '''\t\t\t/* content */\n\t\t\telse {''',
    '''\t\t\t/* PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1 */\n\t\t\telseif($GET->page=="integrations") {\n\t\t\t\tprint "<div id='integrations'>";\n\t\t\t\tinclude_once("app/integrations/index.php");\n\t\t\t\tprint "</div>";\n\t\t\t}\n\t\t\t/* content */\n\t\t\telse {''',
    "index.php integrations route",
)

# Dynamic navigation: Integrations uses the tools-style infrastructure navbar.
replace_once(
    "app/sections/index.php",
    '''            if (!isset($GET->page) || $GET->page=="dashboard" || $GET->page=="tools") {\n                include("menu/menu-tools.php");\n            }''',
    '''            // PHPIPAM_CUSTOM_INTEGRATIONS_NAV_CONTEXT_V1\n            if (!isset($GET->page) || $GET->page=="dashboard" || $GET->page=="tools" || $GET->page=="integrations") {\n                include("menu/menu-tools.php");\n            }''',
    "sections integrations nav context",
)

# Dynamic tools-style navbar: add a dedicated top-level Integrations item.
p = root / "app/sections/menu/menu-tools.php"
s = p.read_text(encoding="utf-8")
if "PHPIPAM_CUSTOM_INTEGRATIONS_NAV_V1" in s:
    raise SystemExit("menu-tools.php: Integrations marker already present")
anchor = '''// search\n$tool_items["search"] = array ('''
insert = '''// PHPIPAM_CUSTOM_INTEGRATIONS_NAV_V1\n$tool_items["integrations"] = array (\n                        "name"=>"Интеграции",\n                        "href"=>array("integrations", null),\n                        "title"=>"Интеграции с внешними системами",\n                        "icon"=>"fa-plug"\n                        );\n\n// search\n$tool_items["search"] = array ('''
if s.count(anchor) != 1:
    raise SystemExit(f"menu-tools.php item anchor mismatch: {s.count(anchor)}")
s = s.replace(anchor, insert, 1)
old_active = '''        $active = $GET->section==$k ? "active" : "";'''
new_active = '''        $active = ($k === "integrations")\n            ? ($GET->page === "integrations" ? "active" : "")\n            : ($GET->section==$k ? "active" : "");'''
if s.count(old_active) != 1:
    raise SystemExit(f"menu-tools.php active anchor mismatch: {s.count(old_active)}")
s = s.replace(old_active, new_active, 1)
p.write_text(s, encoding="utf-8")

# Static navbar mode: expose the same top-level Integrations entry.
replace_once(
    "app/sections/menu/menu-static.php",
    '''\t?>\n</ul>''',
    '''\t// PHPIPAM_CUSTOM_INTEGRATIONS_STATIC_NAV_V1\n\t$integrations_active = $GET->page=="integrations" ? "active" : "";\n\tprint "<li class='$integrations_active'><a href='".create_link("integrations")."'><i class='fa fa-plug'></i> Интеграции</a></li>";\n\t?>\n</ul>''',
    "static integrations navigation",
)

# Administration menu: configuration surface is visible only through the
# existing admin-only Administration route.
p = root / "app/admin/admin-menu-config.php"
s = p.read_text(encoding="utf-8")
if "PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1" in s:
    raise SystemExit("admin-menu-config.php: Integrations marker already present")
admin_anchor = '''$admin_menu[_('Server management')][] =     ["show"=>true, "icon"=>"fa-cogs",           "href"=>"api",                    "name"=>_("API"),                      "description"=>_("API settings")];'''
admin_insert = admin_anchor + '''\n// PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1\n$admin_menu[_('Server management')][] =     ["show"=>true, "icon"=>"fa-plug",           "href"=>"integrations",           "name"=>_("Integrations"),             "description"=>_("External infrastructure integrations")];'''
if s.count(admin_anchor) != 1:
    raise SystemExit(f"admin-menu-config.php menu anchor mismatch: {s.count(admin_anchor)}")
s = s.replace(admin_anchor, admin_insert, 1)
items_anchor = '''                    "import-export"          => _("import-export"),'''
items_insert = '''                    "import-export"          => _("import-export"),\n                    "integrations"           => _("integrations"),'''
if s.count(items_anchor) != 1:
    raise SystemExit(f"admin-menu-config.php inclusion anchor mismatch: {s.count(items_anchor)}")
s = s.replace(items_anchor, items_insert, 1)
p.write_text(s, encoding="utf-8")
PY

# Validate every staged PHP candidate before touching the target.
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || fail "candidate PHP lint failed: ${file#$STAGE/}"
done < <(find "$STAGE" -type f -name '*.php' -print0)

echo "  candidate PHP lint: OK"

# Backup every existing file that will be changed and record new files for rollback.
mkdir -p "$BACKUP"
: > "$BACKUP/CREATED.txt"

for rel in "${!CORE_BLOB[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$rel")"
    cp -a "$TARGET/$rel" "$BACKUP/$rel"
done

for rel in "${NEW_FILES[@]}"; do
    printf '%s\n' "$rel" >> "$BACKUP/CREATED.txt"
done

cat > "$BACKUP/rollback.sh" <<'ROLLBACK'
#!/usr/bin/env bash
set -euo pipefail
BACKUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET="$(dirname "$BACKUP_DIR")"

while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    rm -f "$TARGET/$rel"
done < "$BACKUP_DIR/CREATED.txt"

while IFS= read -r -d '' src; do
    rel="${src#$BACKUP_DIR/}"
    [[ "$rel" == "CREATED.txt" || "$rel" == "rollback.sh" ]] && continue
    dst="$TARGET/$rel"
    mkdir -p "$(dirname "$dst")"
    cp -a "$src" "$dst"
done < <(find "$BACKUP_DIR" -type f -print0)

echo "Rollback restored files from: $BACKUP_DIR"
ROLLBACK
chmod 700 "$BACKUP/rollback.sh"

echo "  backup: $BACKUP"

install_candidate() {
    local rel="$1"
    local src="$STAGE/$rel"
    local dst="$TARGET/$rel"
    local tmp="$dst.phpipam-custom-tmp-$$"

    mkdir -p "$(dirname "$dst")"

    if [[ -e "$dst" ]]; then
        cp -a "$dst" "$tmp"
        cat "$src" > "$tmp"
    else
        cp "$src" "$tmp"
        chown --reference="$(dirname "$dst")" "$tmp" 2>/dev/null || true
        chmod 0644 "$tmp"
    fi

    mv -f "$tmp" "$dst"
}

for rel in "${!CORE_BLOB[@]}"; do
    install_candidate "$rel"
done
for rel in "${NEW_FILES[@]}"; do
    install_candidate "$rel"
done

# Post-install exact source and syntax checks.
for rel in "${!SOURCE_BLOB[@]}"; do
    actual="$(hash_file "$TARGET/$rel")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || fail "post-install source blob mismatch for $rel"
done

for rel in "${!CORE_BLOB[@]}" "${NEW_FILES[@]}"; do
    php -l "$TARGET/$rel" >/dev/null || fail "post-install PHP lint failed: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1' "$TARGET/index.php" || fail "Integrations route marker missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_NAV_V1' "$TARGET/app/sections/menu/menu-tools.php" || fail "Integrations dynamic nav marker missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_STATIC_NAV_V1' "$TARGET/app/sections/menu/menu-static.php" || fail "Integrations static nav marker missing"
grep -Fq 'PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_MENU_V1' "$TARGET/app/admin/admin-menu-config.php" || fail "Integrations admin menu marker missing"

echo
echo "Phase 4 Integrations framework install completed."
echo "Backup / rollback: $BACKUP"
echo "Run: $REPO_ROOT/installer/verify-integrations-framework-v1.sh '$TARGET'"
echo "No database schema changes were made."
