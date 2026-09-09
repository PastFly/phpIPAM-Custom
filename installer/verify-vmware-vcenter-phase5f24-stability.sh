#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f23-chart.sh" "$TARGET"

JS="$TARGET/app/integrations/vmware/phase5f24-stability.js"
CSS="$TARGET/app/integrations/vmware/phase5f24-stability.css"
VMCARD="$TARGET/app/integrations/vmware/vm-card-result.php"
INDEX="$TARGET/app/integrations/vmware/index.php"

[[ -f "$JS" && -f "$CSS" && -f "$VMCARD" && -f "$INDEX" ]] || fail 'Phase 5F.2.4 runtime file missing'
[[ "$(hash_file "$JS")" == '92b39cf47234300d58ec4ced1af05c8150eb0529' ]] || fail 'Phase 5F.2.4 JS blob mismatch'
[[ "$(hash_file "$CSS")" == 'ba06c5c25be02148c25f22dedb0c67e04c820633' ]] || fail 'Phase 5F.2.4 CSS blob mismatch'

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F24_OPTIONAL_GUEST_NETWORK_V1' "$VMCARD" || fail 'optional guest-network marker missing'
grep -Fq "array('status' => 'unavailable', 'interfaces' => array(), 'ip_count' => 0)" "$VMCARD" || fail 'guest-network fallback missing'
grep -Fq 'catch (Exception $guestNetworkError)' "$VMCARD" || fail 'guest-network optional boundary missing'
php -l "$VMCARD" >/dev/null

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5F24_STABILITY_V1' "$INDEX" || fail 'Phase 5F.2.4 workspace hook missing'
grep -Fq 'phase5f24-stability.css' "$INDEX" || fail 'Phase 5F.2.4 CSS hook missing'
grep -Fq 'phase5f24-stability.js' "$INDEX" || fail 'Phase 5F.2.4 JS hook missing'

grep -Fq 'vmCardSequence' "$JS" || fail 'latest-request guard missing'
grep -Fq "status === 'abort'" "$JS" || fail 'abort suppression missing'
grep -Fq 'phase5f24-tls-tooltip' "$JS" || fail 'TLS tooltip rebind missing'
grep -Fq 'max-width: 360px' "$CSS" || fail 'TLS tooltip width constraint missing'

! grep -Eiq 'PowerOnVM|PowerOffVM|ReconfigVM|Destroy_Task|MigrateVM|RelocateVM|CreateVM|UpdateNetwork|RefreshNetworkSystem|INSERT[[:space:]]+INTO|UPDATE[[:space:]]+custom_|DELETE[[:space:]]+FROM' "$JS" "$CSS" "$VMCARD" || fail 'forbidden mutation token detected in Phase 5F.2.4 runtime'

echo 'Phase 5F.2.4 VM-card stability/TLS presentation invariants: OK'
echo 'PHASE5F24_STABILITY_VERIFY_OK'
