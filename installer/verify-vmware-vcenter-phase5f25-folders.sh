#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f24-stability.sh" "$TARGET"

CLASS="$TARGET/functions/classes/class.IntegrationVmwareVcenterFolderInventory.php"
RESULT="$TARGET/app/integrations/vmware/folder-result.php"
JS="$TARGET/app/integrations/vmware/phase5f25-folders.js"
CSS="$TARGET/app/integrations/vmware/phase5f25-folders.css"
INDEX="$TARGET/app/integrations/vmware/index.php"

[[ -f "$CLASS" && -f "$RESULT" && -f "$JS" && -f "$CSS" && -f "$INDEX" ]] || fail 'Phase 5F.2.5 runtime file missing'
[[ "$(hash_file "$CLASS")" == 'c809ddd5c53266192076f10759f9faf323f04d98' ]] || fail 'Phase 5F.2.5 collector blob mismatch'
[[ "$(hash_file "$RESULT")" == 'd00ba2f16611291debaf244bef50bcec8dac491e' ]] || fail 'Phase 5F.2.5 result endpoint blob mismatch'
[[ "$(hash_file "$JS")" == 'd9effbb24c8f08febb7444faa9f98bfd90d5f990' ]] || fail 'Phase 5F.2.5 JS blob mismatch'
[[ "$(hash_file "$CSS")" == 'e67bb37aaee03849bd86bca3677e1615a3549eff' ]] || fail 'Phase 5F.2.5 CSS blob mismatch'

php -l "$CLASS" >/dev/null
php -l "$RESULT" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$JS" >/dev/null; fi

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F25_FOLDER_RESULT_V1' "$RESULT" || fail 'Phase 5F.2.5 result marker missing'
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F25_FOLDER_UI_V1' "$INDEX" || fail 'Phase 5F.2.5 workspace hook missing'
grep -Fq 'phase5f25-folders.css' "$INDEX" || fail 'Phase 5F.2.5 CSS hook missing'
grep -Fq 'phase5f25-folders.js' "$INDEX" || fail 'Phase 5F.2.5 JS hook missing'
grep -Fq "CURLOPT_PROXY => ''" "$CLASS" || fail 'Phase 5F.2.5 direct/no-proxy transport missing'
grep -Fq 'persistent_inventory_written' "$CLASS" "$RESULT" || fail 'Phase 5F.2.5 no-persistence contract missing'
grep -Fq 'root_folder_by_datacenter' "$CLASS" || fail 'Phase 5F.2.5 root-folder projection missing'
grep -Fq 'phase5f25-folder-node' "$JS" "$CSS" || fail 'Phase 5F.2.5 folder UI marker missing'
grep -Fq 'CACHE_TTL_MS = 300000' "$JS" || fail 'Phase 5F.2.5 browser cache bound missing'

! grep -Eiq 'PowerOnVM|PowerOffVM|ReconfigVM|Destroy_Task|MigrateVM|RelocateVM|CreateVM|RefreshNetworkSystem|UpdateNetworkConfig|INSERT[[:space:]]+INTO|UPDATE[[:space:]]+custom_|DELETE[[:space:]]+FROM' \
    "$CLASS" "$RESULT" "$JS" || fail 'forbidden mutation token detected in Phase 5F.2.5 runtime'

echo 'Phase 5F.2.5 VMware Folder hierarchy/security invariants: OK'
echo 'PHASE5F25_FOLDER_UI_VERIFY_OK'
