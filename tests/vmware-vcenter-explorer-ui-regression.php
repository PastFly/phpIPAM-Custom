<?php

/**
 * Phase 5D.5 Explorer Administration UI static/security regression.
 */
$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function explorer_ui_check($name, $condition)
{
    global $passed, $failed;
    if ($condition) {
        print "PASS $name\n";
        $passed++;
    } else {
        print "FAIL $name\n";
        $failed++;
    }
}

$panelPath = $root . '/layers/phase5d/app/admin/integrations/vmware-explorer-panel.php';
$resultPath = $root . '/layers/phase5d/app/admin/integrations/vmware-explorer-result.php';
$cardPath = $root . '/layers/phase5d/app/admin/integrations/vmware-vm-card-result.php';
$appendPath = $root . '/layers/phase5d/app/admin/integrations/index-phase5d-append.php';

$panel = file_get_contents($panelPath);
$result = file_get_contents($resultPath);
$card = file_get_contents($cardPath);
$append = file_get_contents($appendPath);

explorer_ui_check('panel-marker', strpos($panel, 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_PANEL_V1') !== false);
explorer_ui_check('panel-admin-only', strpos($panel, 'is_admin(false)') !== false);
explorer_ui_check('panel-csrf', strpos($panel, 'integration_vmware_vcenter_explorer') !== false && strpos($panel, 'csrf_cookie') !== false);
explorer_ui_check('panel-insecure-ack', strpos($panel, 'vmware-phase5d-insecure-ack') !== false && strpos($panel, 'allow_insecure_tls') !== false);
explorer_ui_check('panel-pagination', strpos($panel, 'page_size') !== false && strpos($panel, 'phase5d-page-link') !== false);
explorer_ui_check('panel-dc-drilldown', strpos($panel, 'phase5d-scope-link') !== false && strpos($panel, 'scope_id') !== false);
explorer_ui_check('panel-vm-card-ajax', strpos($panel, 'vmware-vm-card-result.php') !== false && strpos($panel, 'phase5d-vm-card-link') !== false);
explorer_ui_check('panel-no-password-field', strpos($panel, 'type="password"') === false && strpos($panel, 'name="password"') === false);
explorer_ui_check('panel-no-persistence-language', strpos($panel, 'Inventory не сохраняется') !== false);

explorer_ui_check('result-marker', strpos($result, 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_RESULT_V1') !== false);
explorer_ui_check('result-session-admin', strpos($result, 'check_user_session') !== false && strpos($result, 'is_admin(false)') !== false);
explorer_ui_check('result-csrf-validate', strpos($result, 'csrf_cookie("validate", "integration_vmware_vcenter_explorer"') !== false);
explorer_ui_check('result-loads-encrypted-config', strpos($result, 'IntegrationSecretCipher::from_keyring_file') !== false && strpos($result, 'load_runtime_config') !== false);
explorer_ui_check('result-authoritative-topology', strpos($result, 'IntegrationVmwareVcenterTopology') !== false);
explorer_ui_check('result-datastores', strpos($result, 'IntegrationVmwareVcenterDatastore') !== false);
explorer_ui_check('result-explorer-model', strpos($result, 'IntegrationVmwareVcenterExplorer') !== false);
explorer_ui_check('result-page-bound', strpos($result, '$pageSize > 100') !== false);
explorer_ui_check('result-sanitized-error', strpos($result, 'Sensitive exception details were suppressed') !== false && strpos($result, '$e->getMessage()') !== false);
explorer_ui_check('result-zero-secret', strpos($result, 'phase5d_explorer_zero_secret') !== false);
explorer_ui_check('result-no-db-write', strpos($result, 'INSERT INTO') === false && strpos($result, 'UPDATE ') === false && strpos($result, 'DELETE FROM') === false);
explorer_ui_check('result-no-object-write', strpos($result, 'object_modify') === false && strpos($result, 'modify_object') === false);
explorer_ui_check('result-no-password-render', strpos($result, "print $runtime['password']") === false);
explorer_ui_check('result-no-raw-exception-render', strpos($result, "print $e->getMessage()") === false);
explorer_ui_check('result-declares-no-persistence', strpos($result, 'persistent_inventory_written=false') !== false && strpos($result, 'phpipam_objects_modified=false') !== false && strpos($result, 'vcenter_objects_modified=false') !== false);

explorer_ui_check('card-marker', strpos($card, 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_VM_CARD_RESULT_V1') !== false);
explorer_ui_check('card-session-admin', strpos($card, 'check_user_session') !== false && strpos($card, 'is_admin(false)') !== false);
explorer_ui_check('card-csrf', strpos($card, 'csrf_cookie("validate", "integration_vmware_vcenter_explorer"') !== false);
explorer_ui_check('card-one-vm-detail', strpos($card, 'IntegrationVmwareVcenterVmDetail') !== false && strpos($card, 'read_detail') !== false);
explorer_ui_check('card-datastore-resolver', strpos($card, 'IntegrationVmwareVcenterDatastoreResolver') !== false);
explorer_ui_check('card-guest-network', strpos($card, 'IntegrationVmwareVcenterGuestNetwork') !== false && strpos($card, 'read_vm_interfaces') !== false);
explorer_ui_check('card-tags', strpos($card, 'IntegrationVmwareVcenterMetadata') !== false && strpos($card, 'read_vm_tags') !== false);
explorer_ui_check('card-provenance-warning', strpos($card, 'correlation-only') !== false && strpos($card, 'native VMware stable-ID relation') !== false);
explorer_ui_check('card-no-db-write', strpos($card, 'INSERT INTO') === false && strpos($card, 'UPDATE ') === false && strpos($card, 'DELETE FROM') === false);
explorer_ui_check('card-no-vcenter-write-words', strpos($card, 'power_on') === false && strpos($card, 'reconfigure') === false && strpos($card, 'migrate') === false);
explorer_ui_check('card-zero-secret', strpos($card, 'phase5d_vmcard_zero_secret') !== false);
explorer_ui_check('card-no-raw-exception-render', strpos($card, "print $e->getMessage()") === false && strpos($card, 'Sensitive exception details were suppressed') !== false);
explorer_ui_check('card-declares-no-persistence', strpos($card, 'persistent_inventory_written=false') !== false && strpos($card, 'phpipam_objects_modified=false') !== false && strpos($card, 'vcenter_objects_modified=false') !== false);

explorer_ui_check('append-marker', strpos($append, 'PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_INDEX_APPEND_V1') !== false);
explorer_ui_check('append-panel', strpos($append, "vmware-explorer-panel.php") !== false);

print "\nPhase 5D.5 Explorer UI regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
