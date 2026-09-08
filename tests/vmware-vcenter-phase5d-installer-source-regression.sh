#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALLER="$ROOT/installer/install-vmware-vcenter-phase5d-ui.sh"
VERIFIER="$ROOT/installer/verify-vmware-vcenter-phase5d-ui.sh"
PREFLIGHT="$ROOT/tools/live-vmware-phase5d-ui-preflight.sh"

fail() { echo "FAIL $*" >&2; exit 1; }
pass() { echo "PASS $*"; }

bash -n "$INSTALLER" && pass installer-shell-syntax
bash -n "$VERIFIER" && pass verifier-shell-syntax
bash -n "$PREFLIGHT" && pass preflight-shell-syntax

extract_const() {
    local file="$1" name="$2"
    sed -n "s/^${name}=\"\([0-9a-f]\{40\}\)\"$/\1/p" "$file"
}

check_pin() {
    local name="$1" rel="$2" expected actual verifier_expected
    expected="$(extract_const "$INSTALLER" "$name")"
    [[ -n "$expected" ]] || fail "missing installer pin $name"
    actual="$(git -C "$ROOT" hash-object "$ROOT/$rel")"
    [[ "$expected" == "$actual" ]] || fail "$name installer pin drift: expected=$expected actual=$actual"
    verifier_expected="$(extract_const "$VERIFIER" "$name")"
    [[ "$verifier_expected" == "$actual" ]] || fail "$name verifier pin drift: expected=$verifier_expected actual=$actual"
    pass "$name-source-pin"
}

check_pin PHASE5D_APPEND_BLOB layers/phase5d/app/admin/integrations/index-phase5d-append.php
check_pin TOPOLOGY_BLOB layers/phase5d/functions/classes/class.IntegrationVmwareVcenterTopology.php
check_pin DATASTORE_BLOB layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastore.php
check_pin RESOLVER_BLOB layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php
check_pin VMDETAIL_BLOB layers/phase5d/functions/classes/class.IntegrationVmwareVcenterVmDetail.php
check_pin METADATA_BLOB layers/phase5d/functions/classes/class.IntegrationVmwareVcenterMetadata.php
check_pin EXPLORER_BLOB layers/phase5d/functions/classes/class.IntegrationVmwareVcenterExplorer.php
check_pin PANEL_BLOB layers/phase5d/app/admin/integrations/vmware-explorer-panel.php
check_pin RESULT_BLOB layers/phase5d/app/admin/integrations/vmware-explorer-result.php
check_pin CARD_BLOB layers/phase5d/app/admin/integrations/vmware-vm-card-result.php

for script in "$INSTALLER" "$VERIFIER" "$PREFLIGHT"; do
    if grep -Eq 'CREATE TABLE|ALTER TABLE|DROP TABLE|INSERT INTO|UPDATE `|DELETE FROM' "$script"; then
        fail "unexpected DB mutation statement in $(basename "$script")"
    fi
done
pass no-db-schema-or-data-mutation-in-phase5d-deployment-scripts

grep -Fq 'PREFLIGHT_WRITE_OPERATIONS=0' "$PREFLIGHT" || fail preflight-write-zero-marker
grep -Fq 'PREFLIGHT_VCENTER_REQUESTS=0' "$PREFLIGHT" || fail preflight-vcenter-zero-marker
pass preflight-readonly-markers

grep -Fq 'verify-vmware-vcenter-phase5c-ui.sh' "$PREFLIGHT" || fail phase5c-exact-precondition
grep -Fq 'verify-vmware-vcenter-phase5d-ui.sh' "$INSTALLER" || fail installer-post-verifier
pass fail-closed-verifier-chain

echo "PHASE5D_INSTALLER_SOURCE_REGRESSION_OK"
