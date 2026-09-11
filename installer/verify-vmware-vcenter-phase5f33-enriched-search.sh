#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-/var/www/html}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5f32-vm-guest-facts.sh" "$TARGET"
echo 'PHASE5F33_BASE_PHASE5F32_OK=1'

declare -A EXPECTED=(
  ["app/integrations/vmware/enriched-search-result.php"]="2307929a9ce7b006d29aa80631b41f808a4ec7a2"
  ["app/integrations/vmware/phase5f33-enriched-search.js"]="85d9eddd9c5e0fb592cfe915f6b6d9516e282ab6"
  ["functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php"]="c68a0a6cddf3acd11cb5141c83cf6c4a83ce56fc"
  ["functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php"]="b5dfe0e67259261f2dc42b8a61fc4c3c8a202992"
  ["functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php"]="65f0e22e0778ad3cf05f4ac009d2a229102b8d5b"
)
for rel in "${!EXPECTED[@]}"; do
  [[ -f "$TARGET/$rel" ]] || fail "Phase 5F.3.3 runtime target missing: $rel"
  [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5F.3.3 runtime blob mismatch: $rel"
done
echo 'Phase 5F.3.3 exact runtime source: OK (5)'

INDEX="$TARGET/app/integrations/vmware/index.php"
[[ -f "$INDEX" ]] || fail 'VMware index.php missing'
[[ "$(grep -Fc 'PHPIPAM_CUSTOM_VMWARE_PHASE5F33_ENRICHED_SEARCH_UI_V1' "$INDEX")" == "1" ]] || fail 'Phase 5F.3.3 index hook count mismatch'
[[ "$(grep -Fc 'app/integrations/vmware/phase5f33-enriched-search.js' "$INDEX")" == "1" ]] || fail 'Phase 5F.3.3 JS hook count mismatch'

php -l "$TARGET/app/integrations/vmware/enriched-search-result.php" >/dev/null
php -l "$TARGET/functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php" >/dev/null
php -l "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php" >/dev/null
php -l "$TARGET/functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$TARGET/app/integrations/vmware/phase5f33-enriched-search.js" >/dev/null; fi

ENDPOINT="$TARGET/app/integrations/vmware/enriched-search-result.php"
FACTS="$TARGET/functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php"
TAGS="$TARGET/functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php"
JS="$TARGET/app/integrations/vmware/phase5f33-enriched-search.js"

grep -Fq "is_admin(false)" "$ENDPOINT" || fail 'Phase 5F.3.3 admin guard missing'
grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" "$ENDPOINT" || fail 'Phase 5F.3.3 CSRF guard missing'
grep -Fq "'persistent_inventory_written' => false" "$ENDPOINT" || fail 'Phase 5F.3.3 persistence invariant missing'
grep -Fq "'phpipam_objects_modified' => false" "$ENDPOINT" || fail 'Phase 5F.3.3 phpIPAM mutation invariant missing'
grep -Fq "'vcenter_objects_modified' => false" "$ENDPOINT" || fail 'Phase 5F.3.3 vCenter mutation invariant missing'
grep -Fq '<RetrieveProperties xmlns="urn:vim25">' "$FACTS" || fail 'Phase 5F.3.3 RetrieveProperties compatibility missing'
grep -Fq 'list-attached-objects-on-tags' "$TAGS" || fail 'Phase 5F.3.3 bulk tag association path missing'
grep -Fq 'CACHE_TTL_MS = 300000' "$JS" || fail 'Phase 5F.3.3 browser cache TTL missing'
grep -Fq 'enriched-search-result.php' "$JS" || fail 'Phase 5F.3.3 endpoint binding missing'
grep -Fq '.off('\''input.phase5f1'\'')' "$JS" || fail 'Phase 5F.3.3 legacy per-keystroke search suppression missing'
if grep -Fq 'localStorage' "$JS"; then fail 'Phase 5F.3.3 enriched index must not persist in localStorage'; fi
if grep -Eq '<(Update|Add|Remove|Destroy|Reconfig|Power|Migrate|Relocate|Refresh)' "$FACTS"; then fail 'Phase 5F.3.3 forbidden vCenter mutation operation in facts reader'; fi
if grep -Eq 'action=(attach|detach)|/attach|/detach|/create|/update|/delete' "$TAGS"; then fail 'Phase 5F.3.3 forbidden tagging mutation path'; fi

echo 'Phase 5F.3.3 enriched search read-only/security invariants: OK'
echo 'PHASE5F33_ENRICHED_SEARCH_VERIFY_OK'
