#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
# Phase 5F.1 remains the protected parent baseline.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f1-ui.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/vmware/phase5f2-ux.js"]="e7ab503dae7d5c611e41c7d881834e72e71cb0c5"
  ["app/integrations/vmware/phase5f2-ux.css"]="44d437c7f809db8c62db794dd9769b85aa584d30"
  ["app/integrations/vmware/vm-annotation-result.php"]="fb8547eb78a590cc7c6c155633441d09ac1885c1"
  ["functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php"]="ae23e39a0970c9ec7943fac515eb1a191aaea43d"
)
for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.2 runtime file missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.2 runtime blob mismatch: $rel"
done

php -l "$TARGET/app/integrations/vmware/vm-annotation-result.php" >/dev/null
php -l "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$TARGET/app/integrations/vmware/phase5f2-ux.js" >/dev/null; fi

INDEX="$TARGET/app/integrations/vmware/index.php"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F2_DETAIL_LAYOUT_V1' "$INDEX" || fail "Phase 5F.2 workspace marker missing"
grep -Fq '<link rel="stylesheet" href="app/integrations/vmware/phase5f2-ux.css">' "$INDEX" || fail "Phase 5F.2 CSS hook missing"
grep -Fq '<script src="app/integrations/vmware/phase5f2-ux.js"></script>' "$INDEX" || fail "Phase 5F.2 UX script hook missing"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F2_DETAIL_LAYOUT_V1' "$INDEX")" == "1" ]] || fail "Phase 5F.2 workspace marker count is not exactly one"

grep -Fq '<pathSet>config.annotation</pathSet>' "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php" || fail "VM annotation property path missing"
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php" || fail "compatible VM annotation PropertyCollector path missing"
if grep -Fq '<RetrievePropertiesEx ' "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php"; then fail "unsupported RetrievePropertiesEx found in VM annotation reader"; fi
grep -Fq 'Поиск VM по всей инфраструктуре' "$TARGET/app/integrations/vmware/phase5f2-ux.js" || fail "dedicated VM search UX missing"
grep -Fq 'phase5f2-splitter' "$TARGET/app/integrations/vmware/phase5f2-ux.js" || fail "resizable pane UX missing"
grep -Fq 'phpipam.vmware.phase5f2.layout.v1' "$TARGET/app/integrations/vmware/phase5f2-ux.js" || fail "pane width persistence key missing"
grep -Fq 'grid-template-columns: var(--phase5f2-left, 18%)' "$TARGET/app/integrations/vmware/phase5f2-ux.css" || fail "Phase 5F.2 default pane proportions missing"

if grep -REni '\b(ReconfigVM_Task|PowerOnVM_Task|PowerOffVM_Task|ResetVM_Task|ShutdownGuest|RebootGuest|EnterMaintenanceMode_Task|ExitMaintenanceMode_Task|Destroy_Task|CreateVM_Task|RelocateVM_Task|MigrateVM_Task|UpdateOptions|UpdateConfig|CreatePerfInterval|RemovePerfInterval|UpdatePerfInterval)\b' \
  "$TARGET/app/integrations/vmware/vm-annotation-result.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php"; then
  fail "forbidden VMware mutation method in Phase 5F.2"
fi
if grep -REni '\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)[[:space:]]' \
  "$TARGET/app/integrations/vmware/vm-annotation-result.php" \
  "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php"; then
  fail "forbidden SQL mutation verb in Phase 5F.2"
fi

echo "Phase 5F.2 exact runtime source: OK (${#EXPECTED[@]})"
echo 'Phase 5F.2 VM detail/layout security invariants: OK'
echo 'PHASE5F2_UI_VERIFY_OK'
