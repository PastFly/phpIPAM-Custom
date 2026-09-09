#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

printf '%s\n' '=== PHASE 5F.2.5 VMWARE FOLDER UI LIVE PREFLIGHT ==='
printf 'phpIPAM Custom Phase 5F.2.5 preflight (READ ONLY)\n'
printf 'target=%s\n' "$TARGET"

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
[[ -f "$TARGET/config.php" ]] || fail 'target does not look like a phpIPAM root'

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f24-stability.sh" "$TARGET"
echo 'PHASE5F25_BASE_PHASE5F24_OK=1'

CLASS_REL='functions/classes/class.IntegrationVmwareVcenterFolderInventory.php'
RESULT_REL='app/integrations/vmware/folder-result.php'
JS_REL='app/integrations/vmware/phase5f25-folders.js'
CSS_REL='app/integrations/vmware/phase5f25-folders.css'
INDEX_REL='app/integrations/vmware/index.php'

for rel in "$CLASS_REL" "$RESULT_REL" "$JS_REL" "$CSS_REL"; do
    [[ ! -e "$TARGET/$rel" ]] || fail "Phase 5F.2.5 runtime source already exists: $rel"
    echo "PHASE5F25_ABSENT=$rel"
done
! grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F25_FOLDER_UI_V1' "$TARGET/$INDEX_REL" || fail 'Phase 5F.2.5 workspace hook already exists'
echo 'PHASE5F25_SOURCE_ABSENT=1'

php -l "$REPO_ROOT/layers/phase5f25/$CLASS_REL" >/dev/null
php -l "$REPO_ROOT/layers/phase5f25/$RESULT_REL" >/dev/null
if command -v node >/dev/null 2>&1; then
    node --check "$REPO_ROOT/layers/phase5f25/$JS_REL" >/dev/null
fi

grep -Fq "CURLOPT_PROXY => ''" "$REPO_ROOT/layers/phase5f25/$CLASS_REL" || fail 'direct/no-proxy folder transport marker missing'
grep -Fq "'/api/vcenter/folder'" "$REPO_ROOT/layers/phase5f25/$CLASS_REL" || fail 'folder inventory API marker missing'
grep -Fq "array('folders' => \$folderId)" "$REPO_ROOT/layers/phase5f25/$CLASS_REL" || fail 'folder membership API marker missing'
grep -Fq 'CACHE_TTL_MS = 300000' "$REPO_ROOT/layers/phase5f25/$JS_REL" || fail 'bounded browser cache marker missing'
grep -Fq 'phase5f25-folder-node' "$REPO_ROOT/layers/phase5f25/$JS_REL" || fail 'folder projection marker missing'

! grep -Eiq 'PowerOnVM|PowerOffVM|ReconfigVM|Destroy_Task|MigrateVM|RelocateVM|CreateVM|RefreshNetworkSystem|UpdateNetworkConfig|INSERT[[:space:]]+INTO|UPDATE[[:space:]]+custom_|DELETE[[:space:]]+FROM' \
    "$REPO_ROOT/layers/phase5f25/$CLASS_REL" \
    "$REPO_ROOT/layers/phase5f25/$RESULT_REL" \
    "$REPO_ROOT/layers/phase5f25/$JS_REL" || fail 'forbidden mutation token detected in Phase 5F.2.5 candidate'

echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F25_FOLDER_UI_OWNER_APPROVAL'
