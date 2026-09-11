#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
echo '=== PHASE 5F.3.2 VM GUEST FACTS UI LIVE PREFLIGHT ==='
echo 'phpIPAM Custom Phase 5F.3.2 preflight (READ ONLY)'
echo "target=$TARGET"

bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f31-vm-network-path.sh" "$TARGET"
echo 'PHASE5F32_BASE_PHASE5F31_OK=1'

for rel in \
  app/integrations/vmware/vm-guest-facts-result.php \
  app/integrations/vmware/phase5f32-vm-guest-facts.js \
  functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php; do
  if [[ -e "$TARGET/$rel" ]]; then
    fail "Phase 5F.3.2 target already exists before owner approval: $rel"
  fi
  echo "PHASE5F32_ABSENT=$rel"
done

CLASS="$REPO_ROOT/layers/phase5f32/functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php"
ENDPOINT="$REPO_ROOT/layers/phase5f32/app/integrations/vmware/vm-guest-facts-result.php"
JS="$REPO_ROOT/layers/phase5f32/app/integrations/vmware/phase5f32-vm-guest-facts.js"

grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$CLASS" || fail 'Phase 5F.3.2 legacy RetrieveProperties reader missing'
grep -Fq "'guest.guestFullName'" "$CLASS" || fail 'Phase 5F.3.2 guest friendly-name property missing'
grep -Fq "'config.hardware.memoryMB'" "$CLASS" || fail 'Phase 5F.3.2 configured memory property missing'
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F32_VM_GUEST_FACTS_V1' "$ENDPOINT" || fail 'Phase 5F.3.2 endpoint marker missing'
grep -Fq "friendly_guest_os_source" "$ENDPOINT" || fail 'Phase 5F.3.2 friendly guest OS source selection missing'
grep -Fq "setTextFact('RAM GiB'" "$JS" || fail 'Phase 5F.3.2 RAM fidelity patch missing'
grep -Fq "setGuestOsFact(payload)" "$JS" || fail 'Phase 5F.3.2 Guest OS fidelity patch missing'
grep -Fq "renderedVmId() !== vmId" "$JS" || fail 'Phase 5F.3.2 stale-card guard missing'
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|Refresh)' "$CLASS"; then
  fail 'Phase 5F.3.2 candidate contains forbidden vCenter mutation operation'
fi

php -l "$CLASS" >/dev/null
php -l "$ENDPOINT" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$JS" >/dev/null; fi

echo 'PHASE5F32_SOURCE_ABSENT=1'
echo 'PREFLIGHT_WRITE_OPERATIONS=0'
echo 'PREFLIGHT_VCENTER_REQUESTS=0'
echo 'PREFLIGHT_RESULT=READY_FOR_PHASE5F32_VM_GUEST_FACTS_OWNER_APPROVAL'
