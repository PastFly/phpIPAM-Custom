#!/usr/bin/env bash
set -euo pipefail

# phpIPAM Custom — Phase 4 live updater for the exact 2026-09-07 cumulative runtime state.
#
# This updater is intentionally state-specific. It was designed from the Owner's
# read-only preflight against /var/www/html on 2026-09-08.
#
# It changes only the Integrations routing/navigation surface and installs the
# three metadata-only Integrations source files. It deliberately leaves the
# cumulative Rackspace implementation untouched, including the fact that the
# later Phase 1 Full Depth rack-content backend marker is not yet present live.
#
# No database schema changes. No credentials. No external connections.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE_ROOT="$REPO_ROOT/src"
TARGET="${1:-/var/www/html}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$TARGET/.phpipam-custom-live-integrations-v1-backup-$STAMP"
STAGE="$(mktemp -d)"
COMMIT_STARTED=0

cleanup() {
    rm -rf "$STAGE"
}

rollback_on_error() {
    local rc=$?
    trap - ERR
    if [[ "$COMMIT_STARTED" == "1" && -x "$BACKUP/rollback.sh" ]]; then
        echo >&2
        echo "ERROR: live Integrations update failed after commit started; rolling back." >&2
        bash "$BACKUP/rollback.sh" >&2 || echo "ERROR: automatic rollback also failed; use $BACKUP/rollback.sh manually" >&2
    fi
    exit "$rc"
}

trap cleanup EXIT
trap rollback_on_error ERR

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

# Exact live state proven by tools/live-integrations-preflight.sh on 2026-09-08.
RACKSPACE="functions/classes/class.Rackspace.php"
RACKSPACE_LIVE_BLOB="c2c9a7424e6caf996633688dea87dfbc594d7eb4"

# Additional accepted cumulative-runtime sentinels. These are not modified by
# this updater; they ensure we do not deploy against an unknown/drifted server.
declare -A LIVE_SENTINEL_BLOB=(
    ["app/tools/racks/rack-v3.3-map-controller.php"]="aaa8df49b4e00308e201f553a7571fdc187bd634"
    ["app/tools/racks/rack-v3.3-move.php"]="6c87d6d676c0d9a86c00c5062a168e700b9d6364"
    ["app/tools/racks/rack-v3.3-data.php"]="bf333ad6a02c673ba158104d5612d65c399c2bf1"
    ["app/tools/racks/rack-v3.1-controller.php"]="a130d650dfeac0e692c8cbae9d3dde94dfd305ad"
    ["app/tools/racks/rack-v3-move.php"]="9818f072873746d3c8b885201513a831428ae472"
    ["app/tools/tools-menu-config.php"]="56e2b0707fb06ff1f8c5598fb593a3039c04950c"
    ["css/bootstrap/bootstrap-custom.css"]="e3a3c1a4243a594a0bae30cf71236d7b95b77e23"
    ["css/bootstrap/bootstrap-custom-dark.css"]="d86155595df4d6bd636db94d74cf8b8861af73fd"
)

# Exact core files proven pristine by the live preflight. These are the only
# existing production files this updater changes.
declare -A CORE_BLOB=(
    ["index.php"]="bd9d79351cc782ba477d30e9f748720f7bfa6ce3"
    ["app/sections/index.php"]="0c0860e6dc4001ab67aef5dea7436f73efbf82a8"
    ["app/sections/menu/menu-tools.php"]="6be8bed21ec9782c4825fd3785a4da3adb48929b"
    ["app/sections/menu/menu-static.php"]="b19d4e0d1af944a44ed180f181e29fa532c6e629"
    ["app/admin/admin-menu-config.php"]="f6bfa72ef05426a14a5da9bb4d37d3d2b7aec9ce"
)

# Exact plain UTF-8 repository sources for the new product surface.
declare -A SOURCE_BLOB=(
    ["app/integrations/registry.php"]="2a03e1b96f5fc493eeee2adceceb3c717830d50b"
    ["app/integrations/index.php"]="7411f3bebc259c5b0535e0db57055c0b38aff36e"
    ["app/admin/integrations/index.php"]="11a26efaa9c51723afffbffebf13a0067f62c53f"
)

NEW_FILES=(
    "app/integrations/registry.php"
    "app/integrations/index.php"
    "app/admin/integrations/index.php"
)

NEW_DIRS=(
    "app/integrations"
    "app/admin/integrations"
)

echo "phpIPAM Custom Phase 4 exact-state LIVE updater"
echo "  target: $TARGET"
echo "  backup: $BACKUP"
echo "  policy: fail closed on any state drift"

# ----- exact live-state gate -------------------------------------------------
[[ -f "$TARGET/$RACKSPACE" ]] || fail "live Rackspace class missing"
actual="$(hash_file "$TARGET/$RACKSPACE")"
[[ "$actual" == "$RACKSPACE_LIVE_BLOB" ]] || \
    fail "live Rackspace drifted: expected $RACKSPACE_LIVE_BLOB, got $actual"

grep -Fq 'public function free_u($rack, $rack_devices, $rack_contents, $current_device = null, $force_no_overlap = false)' "$TARGET/$RACKSPACE" || \
    fail "expected strict live free_u signature is missing"
if grep -Fq 'PHPIPAM_CUSTOM_DEEP_RACK_CONTENT_OVERLAP' "$TARGET/$RACKSPACE"; then
    fail "live Rackspace state changed: Phase 1 deep rack-content marker is now present; use a newly classified updater"
fi

for rel in "${!LIVE_SENTINEL_BLOB[@]}"; do
    file="$TARGET/$rel"
    [[ -f "$file" ]] || fail "live sentinel missing: $rel"
    actual="$(hash_file "$file")"
    [[ "$actual" == "${LIVE_SENTINEL_BLOB[$rel]}" ]] || \
        fail "live sentinel drift: $rel expected ${LIVE_SENTINEL_BLOB[$rel]}, got $actual"
done

for rel in "${!CORE_BLOB[@]}"; do
    file="$TARGET/$rel"
    [[ -f "$file" ]] || fail "core target file missing: $rel"
    actual="$(hash_file "$file")"
    [[ "$actual" == "${CORE_BLOB[$rel]}" ]] || \
        fail "core drift: $rel expected ${CORE_BLOB[$rel]}, got $actual"
done

for rel in "${NEW_FILES[@]}"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Integrations file already exists: $rel"
done

echo "  exact cumulative live state: OK"

# ----- repository-source gate ----------------------------------------------
for rel in "${!SOURCE_BLOB[@]}"; do
    src="$SOURCE_ROOT/$rel"
    [[ -f "$src" ]] || fail "Phase 4 repository source missing: $rel"
    actual="$(hash_file "$src")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || \
        fail "Phase 4 source drift: $rel expected ${SOURCE_BLOB[$rel]}, got $actual"
done

echo "  Phase 4 source blobs: OK"

# ----- stage exact candidates before touching production --------------------
for rel in "${!SOURCE_BLOB[@]}"; do
    mkdir -p "$STAGE/$(dirname "$rel")"
    cp "$SOURCE_ROOT/$rel" "$STAGE/$rel"
done

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
        raise SystemExit(f"{label}: Integrations marker already present")
    p.write_text(s.replace(old, new, 1), encoding="utf-8")

replace_once(
    "index.php",
    '''\t\t\t/* content */\n\t\t\telse {''',
    '''\t\t\t/* PHPIPAM_CUSTOM_INTEGRATIONS_ROUTE_V1 */\n\t\t\telseif($GET->page=="integrations") {\n\t\t\t\tprint "<div id='integrations'>";\n\t\t\t\tinclude_once("app/integrations/index.php");\n\t\t\t\tprint "</div>";\n\t\t\t}\n\t\t\t/* content */\n\t\t\telse {''',
    "index.php integrations route",
)

replace_once(
    "app/sections/index.php",
    '''            if (!isset($GET->page) || $GET->page=="dashboard" || $GET->page=="tools") {\n                include("menu/menu-tools.php");\n            }''',
    '''            // PHPIPAM_CUSTOM_INTEGRATIONS_NAV_CONTEXT_V1\n            if (!isset($GET->page) || $GET->page=="dashboard" || $GET->page=="tools" || $GET->page=="integrations") {\n                include("menu/menu-tools.php");\n            }''',
    "sections integrations nav context",
)

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

replace_once(
    "app/sections/menu/menu-static.php",
    '''\t?>\n</ul>''',
    '''\t// PHPIPAM_CUSTOM_INTEGRATIONS_STATIC_NAV_V1\n\t$integrations_active = $GET->page=="integrations" ? "active" : "";\n\tprint "<li class='$integrations_active'><a href='".create_link("integrations")."'><i class='fa fa-plug'></i> Интеграции</a></li>";\n\t?>\n</ul>''',
    "static integrations navigation",
)

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

while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null || fail "candidate PHP lint failed: ${file#$STAGE/}"
done < <(find "$STAGE" -type f -name '*.php' -print0)

echo "  candidate PHP lint: OK"

# ----- backup / rollback ----------------------------------------------------
mkdir -p "$BACKUP"
: > "$BACKUP/CREATED.txt"
: > "$BACKUP/CREATED_DIRS.txt"

for rel in "${!CORE_BLOB[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$rel")"
    cp -a "$TARGET/$rel" "$BACKUP/$rel"
done

for rel in "${NEW_FILES[@]}"; do
    printf '%s\n' "$rel" >> "$BACKUP/CREATED.txt"
done

for rel in "${NEW_DIRS[@]}"; do
    if [[ ! -d "$TARGET/$rel" ]]; then
        printf '%s\n' "$rel" >> "$BACKUP/CREATED_DIRS.txt"
    fi
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
    case "$rel" in
        CREATED.txt|CREATED_DIRS.txt|rollback.sh) continue ;;
    esac
    dst="$TARGET/$rel"
    mkdir -p "$(dirname "$dst")"
    cp -a "$src" "$dst"
done < <(find "$BACKUP_DIR" -type f -print0)

if [[ -f "$BACKUP_DIR/CREATED_DIRS.txt" ]]; then
    mapfile -t dirs < "$BACKUP_DIR/CREATED_DIRS.txt"
    for (( i=${#dirs[@]}-1; i>=0; i-- )); do
        rel="${dirs[$i]}"
        [[ -n "$rel" ]] || continue
        rmdir "$TARGET/$rel" 2>/dev/null || true
    done
fi

echo "Rollback restored files from: $BACKUP_DIR"
ROLLBACK
chmod 700 "$BACKUP/rollback.sh"

echo "  backup prepared: $BACKUP"

install_candidate() {
    local rel="$1"
    local src="$STAGE/$rel"
    local dst="$TARGET/$rel"
    local tmp="$dst.phpipam-custom-tmp-$$"
    local parent="$(dirname "$dst")"

    if [[ ! -d "$parent" ]]; then
        mkdir -p "$parent"
        if [[ "$rel" == app/integrations/* ]]; then
            chown --reference="$TARGET/app" "$parent" 2>/dev/null || true
            chmod --reference="$TARGET/app" "$parent" 2>/dev/null || true
        elif [[ "$rel" == app/admin/integrations/* ]]; then
            chown --reference="$TARGET/app/admin" "$parent" 2>/dev/null || true
            chmod --reference="$TARGET/app/admin" "$parent" 2>/dev/null || true
        fi
    fi

    if [[ -e "$dst" ]]; then
        cp -a "$dst" "$tmp"
        cat "$src" > "$tmp"
    else
        cp "$src" "$tmp"
        chown --reference="$parent" "$tmp" 2>/dev/null || true
        chmod 0644 "$tmp"
    fi

    mv -f "$tmp" "$dst"
}

# ----- production commit ---------------------------------------------------
COMMIT_STARTED=1
for rel in "${!CORE_BLOB[@]}"; do
    install_candidate "$rel"
done
for rel in "${NEW_FILES[@]}"; do
    install_candidate "$rel"
done

# New source is exact; core is checked by markers plus PHP lint/verifier.
for rel in "${!SOURCE_BLOB[@]}"; do
    actual="$(hash_file "$TARGET/$rel")"
    [[ "$actual" == "${SOURCE_BLOB[$rel]}" ]] || fail "post-install source blob mismatch: $rel"
done

for rel in "${!CORE_BLOB[@]}" "${NEW_FILES[@]}"; do
    php -l "$TARGET/$rel" >/dev/null || fail "post-install PHP lint failed: $rel"
done

bash "$REPO_ROOT/installer/verify-integrations-framework-v1.sh" "$TARGET"

# Critical scope invariant: rack runtime must remain byte-for-byte unchanged.
actual="$(hash_file "$TARGET/$RACKSPACE")"
[[ "$actual" == "$RACKSPACE_LIVE_BLOB" ]] || fail "Rackspace changed unexpectedly during Integrations deployment"

COMMIT_STARTED=0

echo
echo "LIVE_INTEGRATIONS_UPDATE_OK"
echo "Backup / rollback: $BACKUP"
echo "Rackspace intentionally unchanged: $RACKSPACE_LIVE_BLOB"
echo "No database schema changes were made."
