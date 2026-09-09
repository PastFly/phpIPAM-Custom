#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
TMP_INDEX="$(mktemp)"
trap 'rm -f "$TMP_INDEX"' EXIT
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

for cmd in git php grep awk; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Protected backend/fallback remains authoritative.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh" "$TARGET"

# Phase 5E.2 remains byte-exact except for explicitly pinned successor layers.
# By default only the original Phase 5E.2 VM-card blob is accepted. A later
# verifier may request one known successor blob via PHASE5F_ALLOWED_VMCARD_BLOB;
# arbitrary values are rejected here so the parent verifier cannot be bypassed.
BASE_VMCARD_BLOB='d1a6f3fba93d35054af7d6e05455b557ee5dbd18'
PHASE5F24_VMCARD_BLOB='175265cf2698d17b5f7d0d396a8440cd91ed1bd1'
VMCARD_EXPECTED="$BASE_VMCARD_BLOB"
if [[ -n "${PHASE5F_ALLOWED_VMCARD_BLOB:-}" ]]; then
    case "$PHASE5F_ALLOWED_VMCARD_BLOB" in
        "$PHASE5F24_VMCARD_BLOB") VMCARD_EXPECTED="$PHASE5F24_VMCARD_BLOB" ;;
        *) fail "unrecognized Phase 5F VM-card successor blob requested" ;;
    esac
fi

declare -A BASE_EXPECTED=(
  ["app/integrations/index.php"]="b721ae34b3244aa86dbbcd48f3bde584d58365e2"
  ["app/integrations/registry.php"]="8068e6b8337e926861f65772302742c4d6a6f32a"
  ["app/integrations/vmware/phase5e.css"]="2916636fc01810981bf0d29b16c7ab1002ebc258"
  ["app/integrations/vmware/workspace-result.php"]="408fed89e2b0583013325b08e2959813458c76fe"
  ["functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"]="d26f9ad06ea4136c487b229e004b9a8c6521b863"
  ["app/sections/menu/menu-tools-admin.php"]="c778652ed50fdbd7d5855d4c37d20c2992545c82"
)
for rel in "${!BASE_EXPECTED[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "Phase 5E.2 runtime file missing: $rel"
    [[ "$(hash_file "$TARGET/$rel")" == "${BASE_EXPECTED[$rel]}" ]] || fail "Phase 5E.2 runtime blob mismatch under Phase 5F: $rel"
done

VMCARD_REL='app/integrations/vmware/vm-card-result.php'
[[ -f "$TARGET/$VMCARD_REL" ]] || fail "Phase 5E.2/5F successor VM-card runtime file missing: $VMCARD_REL"
[[ "$(hash_file "$TARGET/$VMCARD_REL")" == "$VMCARD_EXPECTED" ]] || fail "Phase 5E.2 runtime blob mismatch under Phase 5F: $VMCARD_REL"

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail "Phase 5F workspace index missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F_OBSERVABILITY_UI_V1' "$INDEX" || fail "Phase 5F workspace marker missing"
grep -Fq '<script src="app/integrations/vmware/phase5f-observability.js"></script>' "$INDEX" || fail "Phase 5F observability script hook missing"
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F_OBSERVABILITY_UI_V1' "$INDEX")" == "1" ]] || fail "Phase 5F workspace marker count is not exactly one"
awk '/PHPIPAM_CUSTOM_VMWARE_PHASE5F_OBSERVABILITY_UI_V1/{exit} {print}' "$INDEX" > "$TMP_INDEX"
[[ "$(hash_file "$TMP_INDEX")" == "97aeb732cc0a486729936983427c22a4d032efac" ]] || fail "Phase 5E.2 workspace base changed beneath Phase 5F hook"

declare -A EXPECTED=(
  ["app/integrations/vmware/host-card-result.php"]="851ef60f3cc39e03bcc4ad97de50927dad23a398"
  ["app/integrations/vmware/performance-result.php"]="4a5ab086045ca4e8178644c096d0948ae517f075"
  ["app/integrations/vmware/phase5f-observability.js"]="42fd777098dd523c8aecca6b3912e615ba9a0b3e"
  ["functions/classes/class.IntegrationVmwareVcenterDirectTransport.php"]="e6f976342a58da825f07987a3e66162f23d855c7"
  ["functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php"]="2f95eeb6dc8ce07adefd229f8d64a609a1d69f23"
  ["functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php"]="3cd45eda9632063c771bba075330e5a6e3d0559c"
  ["functions/classes/class.IntegrationVmwareVcenterPerformance.php"]="426c8c32632037d2b2ceaac23ef11eb79a06edd2"
)

for rel in "${!EXPECTED[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "Phase 5F runtime file missing: $rel"
    [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F runtime blob mismatch: $rel"
done

echo "Phase 5F exact runtime source: OK (${#EXPECTED[@]})"

for rel in \
  "app/integrations/vmware/host-card-result.php" \
  "app/integrations/vmware/performance-result.php" \
  "functions/classes/class.IntegrationVmwareVcenterDirectTransport.php" \
  "functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php" \
  "functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php" \
  "functions/classes/class.IntegrationVmwareVcenterPerformance.php"; do
    php -l "$TARGET/$rel" >/dev/null || fail "Phase 5F PHP lint failed: $rel"
done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F_HOST_CARD_V1' "$TARGET/app/integrations/vmware/host-card-result.php" || fail "Phase 5F host-card marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F_VM_PERFORMANCE_V1' "$TARGET/app/integrations/vmware/performance-result.php" || fail "Phase 5F VM performance marker missing"
grep -Fq 'host-card-result.php' "$TARGET/app/integrations/vmware/phase5f-observability.js" || fail "Phase 5F host UI hook missing"
grep -Fq 'performance-result.php' "$TARGET/app/integrations/vmware/phase5f-observability.js" || fail "Phase 5F VM performance UI hook missing"
grep -Fq "CURLOPT_PROXY => ''" "$TARGET/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php" || fail "Phase 5F direct no-proxy policy missing"
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$TARGET/functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php" || fail "Phase 5F compatible host PropertyCollector path missing"
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$TARGET/functions/classes/class.IntegrationVmwareVcenterPerformance.php" || fail "Phase 5F compatible perfCounter PropertyCollector path missing"

if grep -REni '\b(ReconfigVM_Task|PowerOnVM_Task|PowerOffVM_Task|ResetVM_Task|ShutdownGuest|RebootGuest|EnterMaintenanceMode_Task|ExitMaintenanceMode_Task|Destroy_Task|CreateVM_Task|RelocateVM_Task|MigrateVM_Task|UpdateOptions|UpdateConfig|CreatePerfInterval|RemovePerfInterval|UpdatePerfInterval)\b' \
    "$TARGET/app/integrations/vmware/host-card-result.php" \
    "$TARGET/app/integrations/vmware/performance-result.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterPerformance.php"; then
    fail "forbidden VMware mutation method in deployed Phase 5F"
fi
if grep -REni '\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)[[:space:]]' \
    "$TARGET/app/integrations/vmware/host-card-result.php" \
    "$TARGET/app/integrations/vmware/performance-result.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php" \
    "$TARGET/functions/classes/class.IntegrationVmwareVcenterPerformance.php"; then
    fail "forbidden SQL mutation verb in deployed Phase 5F"
fi

if command -v node >/dev/null 2>&1; then
    node --check "$TARGET/app/integrations/vmware/phase5f-observability.js" >/dev/null || fail "Phase 5F JavaScript syntax check failed"
fi

echo 'Phase 5F host/performance UI security invariants: OK'
echo 'PHASE5F_UI_VERIFY_OK'
