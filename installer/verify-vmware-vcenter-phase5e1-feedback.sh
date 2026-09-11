#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${1:-/var/www/html}"
fail() { echo "ERROR: $*" >&2; exit 1; }
hash_file() { git hash-object "$1"; }

for cmd in git php grep; do command -v "$cmd" >/dev/null 2>&1 || fail "required command not found: $cmd"; done
[[ -d "$TARGET" ]] || fail "target phpIPAM root not found: $TARGET"

# Phase 5D remains the protected functional backend/fallback.
bash "$REPO_ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh" "$TARGET"

declare -A EXPECTED=(
  ["app/integrations/index.php"]="b721ae34b3244aa86dbbcd48f3bde584d58365e2"
  ["app/integrations/registry.php"]="8068e6b8337e926861f65772302742c4d6a6f32a"
  ["app/integrations/vmware/index.php"]="97aeb732cc0a486729936983427c22a4d032efac"
  ["app/integrations/vmware/phase5e.css"]="2916636fc01810981bf0d29b16c7ab1002ebc258"
  ["app/integrations/vmware/workspace-result.php"]="408fed89e2b0583013325b08e2959813458c76fe"
  ["app/integrations/vmware/vm-card-result.php"]="2d58ab4af53f750d945527e0ac1a404d7973e574"
  ["functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"]="d26f9ad06ea4136c487b229e004b9a8c6521b863"
  ["app/sections/menu/menu-tools-admin.php"]="c778652ed50fdbd7d5855d4c37d20c2992545c82"
)

for rel in "${!EXPECTED[@]}"; do
    [[ -f "$TARGET/$rel" ]] || fail "Phase 5E.1 runtime file missing: $rel"
    [[ "$(hash_file "$TARGET/$rel")" == "${EXPECTED[$rel]}" ]] || fail "Phase 5E.1 runtime blob mismatch: $rel"
done
echo "Phase 5E.1 exact source: OK (${#EXPECTED[@]})"

php_files=(
  app/integrations/index.php
  app/integrations/registry.php
  app/integrations/vmware/index.php
  app/integrations/vmware/workspace-result.php
  app/integrations/vmware/vm-card-result.php
  functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php
  app/sections/menu/menu-tools-admin.php
)
for rel in "${php_files[@]}"; do php -l "$TARGET/$rel" >/dev/null || fail "Phase 5E.1 PHP lint failed: $rel"; done

grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_TLS_FROM_CONNECTOR_SETTINGS_V1' "$TARGET/app/integrations/vmware/index.php" || fail "saved TLS settings workspace marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_TLS_SETTINGS_GIB_V1' "$TARGET/app/integrations/vmware/workspace-result.php" || fail "workspace feedback marker missing"
grep -Fq 'PHPIPAM_CUSTOM_VMWARE_PHASE5E1_GUEST_OS_IP_LINKS_TLS_SETTINGS_V1' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "VM card feedback marker missing"
grep -Fq 'class IntegrationPhpIpamAddressLinkResolver' "$TARGET/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php" || fail "IP resolver class missing"
grep -Fq "create_link('subnets', \$sectionId, \$subnetId, 'address-details', \$addressId)" "$TARGET/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php" || fail "native phpIPAM address route missing"
grep -Fq 'exact_unique_ip' "$TARGET/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php" || fail "exact IP match provenance missing"
grep -Fq 'LIMIT 2' "$TARGET/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php" || fail "duplicate-IP ambiguity bound missing"
grep -Fq 'PHPIPAM_CUSTOM_INTEGRATIONS_GLOBAL_NAV_PHASE5E1_V1' "$TARGET/app/sections/menu/menu-tools-admin.php" || fail "global Integrations navigation marker missing"
grep -Fq 'Memory GiB' "$TARGET/app/integrations/vmware/workspace-result.php" || fail "VM list GiB heading missing"
grep -Fq "'RAM GiB'" "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "VM card GiB row missing"
grep -Fq "phase5e_vmcard_value(\$guestIdentity, 'guest_os')" "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "Guest OS guest-identity preference missing"

for rel in app/integrations/vmware/index.php app/integrations/vmware/workspace-result.php app/integrations/vmware/vm-card-result.php; do
    if grep -Fq 'allow_insecure_tls' "$TARGET/$rel"; then fail "legacy per-request TLS acknowledgement remains in $rel"; fi
    if grep -Fq 'tls_acknowledgement_required' "$TARGET/$rel"; then fail "legacy TLS acknowledgement gate remains in $rel"; fi
done
if grep -Fq 'phase5e-insecure-ack' "$TARGET/app/integrations/vmware/index.php"; then fail "legacy TLS checkbox remains in workspace"; fi

grep -Fq 'scheduler остаётся запрещённым' "$TARGET/app/integrations/vmware/vm-card-result.php" || fail "TLS-INSECURE scheduler warning missing"

# No phpIPAM or vCenter mutations are introduced by the feedback slice.
if grep -Eq 'insertObject\(|updateObject\(|deleteRow\(|object_modify\(|modify_object\(' \
    "$TARGET/app/integrations/vmware/workspace-result.php" \
    "$TARGET/app/integrations/vmware/vm-card-result.php" \
    "$TARGET/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"; then
    fail "unexpected phpIPAM write API in Phase 5E.1 runtime"
fi
if grep -Eqi '\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)[[:space:]]' "$TARGET/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php"; then
    fail "unexpected SQL write verb in Phase 5E.1 IP resolver"
fi
if grep -Eq "'PUT'|'PATCH'|action=(attach|detach)" "$TARGET/app/integrations/vmware/workspace-result.php" "$TARGET/app/integrations/vmware/vm-card-result.php"; then
    fail "unexpected VMware mutation surface in Phase 5E.1 runtime"
fi

# Theme remains owned by phpIPAM/Bootstrap.
if grep -Eqi '(^|[;{[:space:]])(color|background|background-color)[[:space:]]*:' "$TARGET/app/integrations/vmware/phase5e.css"; then
    fail "Phase 5E CSS hardcodes theme foreground/background"
fi
if grep -Eq '#[0-9A-Fa-f]{3,8}([^0-9A-Za-z_-]|$)' "$TARGET/app/integrations/vmware/phase5e.css"; then
    fail "Phase 5E CSS hardcodes hex theme colors"
fi

echo "Phase 5E.1 UX/security invariants: OK"
echo "PHASE5E1_FEEDBACK_VERIFY_OK"
