#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
BASE="af1245a1888d967ba07961cf52ddd013fa61ed0e"

php_files=(
  layers/phase5e/app/integrations/index.php
  layers/phase5e/app/integrations/registry.php
  layers/phase5e/app/integrations/vmware/index.php
  layers/phase5e/app/integrations/vmware/workspace-result.php
  layers/phase5e/app/integrations/vmware/vm-card-result.php
  layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php
  layers/phase5e1/app/sections/menu/menu-tools-admin.php
)
for file in "${php_files[@]}"; do php -l "$file" >/dev/null; done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_V1' layers/phase5e/app/integrations/vmware/index.php
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_RESULT_V1' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_VM_CARD_RESULT_V1' layers/phase5e/app/integrations/vmware/vm-card-result.php

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_TLS_FROM_CONNECTOR_SETTINGS_V1' layers/phase5e/app/integrations/vmware/index.php
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_TLS_SETTINGS_GIB_V1' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_GUEST_OS_IP_LINKS_TLS_SETTINGS_V1' layers/phase5e/app/integrations/vmware/vm-card-result.php
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_GLOBAL_NAV_PHASE5E1_V1' layers/phase5e1/app/sections/menu/menu-tools-admin.php

vmware_client_line="$(grep -nF 'class.IntegrationVmwareVcenter.php' layers/phase5e/app/integrations/vmware/index.php | head -n1 | cut -d: -f1)"
config_store_line="$(grep -nF 'class.IntegrationVmwareVcenterConfigStore.php' layers/phase5e/app/integrations/vmware/index.php | head -n1 | cut -d: -f1)"
[[ -n "$vmware_client_line" && -n "$config_store_line" && "$vmware_client_line" -lt "$config_store_line" ]] || {
  echo 'VMware client must be loaded before ConfigStore in dedicated Phase 5E workspace' >&2; exit 1
}

grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq "csrf_cookie('validate', 'integration_vmware_vcenter_explorer'" layers/phase5e/app/integrations/vmware/vm-card-result.php
grep -Fq 'phase5e-scope-link' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'phase5e-vm-link' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'phase5e-datastore-link' layers/phase5e/app/integrations/vmware/vm-card-result.php

# Owner feedback: saved connector TLS setting is authoritative; no extra checkbox.
for file in layers/phase5e/app/integrations/vmware/index.php layers/phase5e/app/integrations/vmware/workspace-result.php layers/phase5e/app/integrations/vmware/vm-card-result.php; do
  ! grep -Fq 'allow_insecure_tls' "$file" || { echo "legacy per-request TLS acknowledgement remains in $file" >&2; exit 1; }
  ! grep -Fq 'tls_acknowledgement_required' "$file" || { echo "legacy TLS gate remains in $file" >&2; exit 1; }
done
! grep -Fq 'phase5e-insecure-ack' layers/phase5e/app/integrations/vmware/index.php || { echo 'legacy TLS checkbox remains' >&2; exit 1; }
grep -Fq 'scheduler остаётся запрещённым' layers/phase5e/app/integrations/vmware/vm-card-result.php

# Owner feedback: human guest OS, GiB units, exact native IP click-through.
grep -Fq "phase5e_vmcard_value(\$guestIdentity, 'guest_os')" layers/phase5e/app/integrations/vmware/vm-card-result.php
grep -Fq "'RAM GiB'" layers/phase5e/app/integrations/vmware/vm-card-result.php
grep -Fq 'Memory GiB' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'class IntegrationPhpIpamAddressLinkResolver' layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php
grep -Fq "create_link('subnets', \$sectionId, \$subnetId, 'address-details', \$addressId)" layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php
grep -Fq 'exact_unique_ip' layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php
grep -Fq 'LIMIT 2' layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php

grep -Fq 'persistent_inventory_written=false' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'phpipam_objects_modified=false' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'vcenter_objects_modified=false' layers/phase5e/app/integrations/vmware/workspace-result.php

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' layers/phase5e/app/integrations/vmware/*.php layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php; then
  echo 'unexpected phpIPAM write API in Phase 5E' >&2; exit 1
fi
if grep -Eqi '\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)[[:space:]]' layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php; then
  echo 'unexpected SQL write verb in Phase 5E IP resolver' >&2; exit 1
fi
if grep -Eq "'PUT'|'PATCH'|action=(attach|detach)" layers/phase5e/app/integrations/vmware/*.php; then
  echo 'unexpected VMware mutation surface in Phase 5E' >&2; exit 1
fi

# Theme isolation: custom CSS controls layout only; phpIPAM/Bootstrap owns colors.
if grep -Eqi '(^|[;{[:space:]])(color|background|background-color)[[:space:]]*:' layers/phase5e/app/integrations/vmware/phase5e.css; then
  echo 'Phase 5E CSS must not hardcode foreground/background theme colors' >&2; exit 1
fi
if grep -Eq '#[0-9A-Fa-f]{3,8}([^0-9A-Za-z_-]|$)' layers/phase5e/app/integrations/vmware/phase5e.css; then
  echo 'Phase 5E CSS must not hardcode hex theme colors' >&2; exit 1
fi

# Phase 5D implementation is the protected working reference for this stacked branch.
git diff --quiet "$BASE" -- layers/phase5d || { echo 'Phase 5D layer changed in Phase 5E branch' >&2; exit 1; }
git diff --quiet "$BASE" -- migrations || { echo 'unexpected migration change in Phase 5E branch' >&2; exit 1; }

echo 'PHASE5E_UX_REGRESSION_OK'
