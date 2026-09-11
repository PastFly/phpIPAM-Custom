#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
# Phase 5F remains the protected parent baseline.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f-ui.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/phase5f1-ux.js"]="858e6b4a588002339d93dff434bceca01f7f76a9"
  ["app/integrations/vmware/storage-performance-result.php"]="5190ef408c9f2c91c4b24c0b7f54ea479f90c86b"
  ["functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php"]="3675951e79cfd30b99c15888f15d1270965d5370"
)
for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.1 runtime file missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.1 runtime blob mismatch: $rel"
done

php -l "$TARGET/app/integrations/vmware/storage-performance-result.php" >/dev/null
php -l "$TARGET/functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$TARGET/app/integrations/vmware/phase5f1-ux.js" >/dev/null; fi

INDEX="$TARGET/app/integrations/vmware/index.php"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F1_UX_V1' "$INDEX" || fail "Phase 5F.1 workspace marker missing"
grep -Fq '<script src="app/integrations/vmware/phase5f1-ux.js"></script>' "$INDEX" || fail "Phase 5F.1 UX script hook missing"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F1_UX_V1' "$INDEX")" == "1" ]] || fail "Phase 5F.1 workspace marker count is not exactly one"

grep -Fq 'disk.usage.average' "$TARGET/functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php" || fail "dynamic disk usage counter missing"
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$TARGET/functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php" || fail "compatible PropertyCollector path missing"
grep -Fq 'storage-performance-result.php' "$TARGET/app/integrations/vmware/phase5f1-ux.js" || fail "storage UI hook missing"
grep -Fq 'Поиск применяется автоматически' "$TARGET/app/integrations/vmware/phase5f1-ux.js" || fail "automatic search UX marker missing"
grep -Fq 'phase5f1-tree-filter' "$TARGET/app/integrations/vmware/phase5f1-ux.js" || fail "tree filter UX marker missing"
grep -Fq 'gib <= 1000' "$TARGET/app/integrations/vmware/phase5f1-ux.js" || fail "GiB/TiB threshold missing"

if grep -REni '\b(ReconfigVM_Task|PowerOnVM_Task|PowerOffVM_Task|ResetVM_Task|ShutdownGuest|RebootGuest|EnterMaintenanceMode_Task|ExitMaintenanceMode_Task|Destroy_Task|CreateVM_Task|RelocateVM_Task|MigrateVM_Task|UpdateOptions|UpdateConfig|CreatePerfInterval|RemovePerfInterval|UpdatePerfInterval)\b' \
  "$TARGET/app/integrations/vmware/storage-performance-result.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php"; then
  fail "forbidden VMware mutation method in Phase 5F.1"
fi
if grep -REni '\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)[[:space:]]' \
  "$TARGET/app/integrations/vmware/storage-performance-result.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterStoragePerformance.php"; then
  fail "forbidden SQL mutation verb in Phase 5F.1"
fi

echo "Phase 5F.1 exact runtime source: OK (${#EXPECTED[@]})"
echo 'Phase 5F.1 UX/storage security invariants: OK'
echo 'PHASE5F1_UI_VERIFY_OK'
