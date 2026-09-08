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
)
for file in "${php_files[@]}"; do php -l "$file" >/dev/null; done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_V1' layers/phase5e/app/integrations/vmware/index.php
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_RESULT_V1' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E_VM_CARD_RESULT_V1' layers/phase5e/app/integrations/vmware/vm-card-result.php

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

grep -Fq 'persistent_inventory_written=false' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'phpipam_objects_modified=false' layers/phase5e/app/integrations/vmware/workspace-result.php
grep -Fq 'vcenter_objects_modified=false' layers/phase5e/app/integrations/vmware/workspace-result.php

if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' layers/phase5e/app/integrations/vmware/*.php; then
  echo 'unexpected phpIPAM write API in Phase 5E' >&2; exit 1
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
