#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f31-vm-network-path.sh" "$TARGET"
echo 'PHASE5F32_BASE_PHASE5F31_OK=1'

declare -A EXPECTED=(
  ["app/integrations/vmware/vm-guest-facts-result.php"]="644a74ff9d0b2828117c63845dd79ef2ff006a94"
  ["app/integrations/vmware/phase5f32-vm-guest-facts.js"]="f7117000fec1f20c0f68c95325f8af49a7924e05"
  ["functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php"]="24a1f1293366abca6b0e10bc71aa2270651ba1c5"
)

for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.3.2 runtime source missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3.2 runtime blob mismatch: $rel"
done
echo 'Phase 5F.3.2 exact runtime source: OK (3)'

CLASS="$TARGET/functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php"
ENDPOINT="$TARGET/app/integrations/vmware/vm-guest-facts-result.php"
JS="$TARGET/app/integrations/vmware/phase5f32-vm-guest-facts.js"
INDEX="$TARGET/app/integrations/vmware/index.php"

php -l "$CLASS" >/dev/null
php -l "$ENDPOINT" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$JS" >/dev/null; fi

grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$CLASS" || fail 'Phase 5F.3.2 RetrieveProperties invariant missing'
grep -Fq "'guest.guestFullName'" "$CLASS" || fail 'Phase 5F.3.2 guest friendly-name property missing'
grep -Fq "'config.hardware.memoryMB'" "$CLASS" || fail 'Phase 5F.3.2 memory property missing'
grep -Fq "'persistent_inventory_written' => false" "$CLASS" || fail 'Phase 5F.3.2 persistence invariant missing'
grep -Fq "'phpipam_objects_modified' => false" "$CLASS" || fail 'Phase 5F.3.2 phpIPAM mutation invariant missing'
grep -Fq "'vcenter_objects_modified' => false" "$CLASS" || fail 'Phase 5F.3.2 vCenter mutation invariant missing'
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F32_VM_GUEST_FACTS_V1' "$ENDPOINT" || fail 'Phase 5F.3.2 endpoint marker missing'
grep -Fq "friendly_guest_os_source" "$ENDPOINT" || fail 'Phase 5F.3.2 friendly guest selection invariant missing'
grep -Fq "renderedVmId() !== vmId" "$JS" || fail 'Phase 5F.3.2 stale-card guard missing'
grep -Fq "setTextFact('RAM GiB'" "$JS" || fail 'Phase 5F.3.2 RAM UI patch missing'
grep -Fq "setGuestOsFact(payload)" "$JS" || fail 'Phase 5F.3.2 Guest OS UI patch missing'
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|Refresh)' "$CLASS"; then
  fail 'Phase 5F.3.2 runtime contains forbidden vCenter mutation operation'
fi

[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F32_VM_GUEST_FACTS_UI_V1' "$INDEX")" == "1" ]] || fail 'Phase 5F.3.2 index hook count mismatch'
grep -Fq '<script src="app/integrations/vmware/phase5f32-vm-guest-facts.js"></script>' "$INDEX" || fail 'Phase 5F.3.2 JS hook missing'

echo 'Phase 5F.3.2 VM guest fact fidelity/security invariants: OK'
echo 'PHASE5F32_VM_GUEST_FACTS_VERIFY_OK'
