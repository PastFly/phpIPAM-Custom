<?php

$root = dirname(__DIR__);
$panelPath = $root . '/layers/phase5c/app/admin/integrations/vmware-inventory-panel.php';
$resultPath = $root . '/layers/phase5c/app/admin/integrations/vmware-inventory-result.php';
$snapshotPath = $root . '/layers/phase5c/functions/classes/class.IntegrationPhpIpamInventorySnapshot.php';

foreach (array($panelPath, $resultPath, $snapshotPath) as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing Phase 5C admin inventory source: $path\n");
        exit(2);
    }
}

$panel = file_get_contents($panelPath);
$result = file_get_contents($resultPath);
$snapshot = file_get_contents($snapshotPath);
$passed = 0;
$failed = 0;

function phase5c_ui_check($name, $condition)
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

phase5c_ui_check('panel-marker', strpos($panel, 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_PANEL_V1') !== false);
phase5c_ui_check('result-marker', strpos($result, 'PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_UI_RESULT_V1') !== false);
phase5c_ui_check('admin-required', strpos($result, '$User->is_admin(false)') !== false);
phase5c_ui_check('dedicated-csrf-create', strpos($panel, 'integration_vmware_vcenter_inventory') !== false);
phase5c_ui_check('dedicated-csrf-validate', strpos($result, 'csrf_cookie("validate", "integration_vmware_vcenter_inventory"') !== false);
phase5c_ui_check('enabled-connectors-only-in-panel', strpos($panel, '!empty($config[\'enabled\'])') !== false && strpos($panel, '!empty($config[\'has_credential\'])') !== false);
phase5c_ui_check('runtime-config-requires-enabled', strpos($result, 'load_runtime_config($configId, true)') !== false);
phase5c_ui_check('insecure-tls-explicit-ack', strpos($result, 'empty($runtime[\'verify_tls\']) && !$allowInsecureTls') !== false && strpos($panel, 'vmware-phase5c-insecure-ack') !== false);
phase5c_ui_check('scheduler-remains-blocked-copy', strpos($panel, 'scheduled inventory') !== false && strpos($result, 'Scheduled sync') !== false);
phase5c_ui_check('interactive-guest-limit-250', strpos($result, '$guestLimit > 250') !== false && strpos($panel, '250 VMs') !== false);
phase5c_ui_check('core-inventory-get-path-used', strpos($result, 'read_core_inventory(') !== false);
phase5c_ui_check('guest-network-get-path-used', strpos($result, 'read_vm_interfaces(') !== false);
phase5c_ui_check('phpipam-projection-used', strpos($result, 'IntegrationPhpIpamInventorySnapshot') !== false && strpos($result, 'read_projection()') !== false);
phase5c_ui_check('reconciler-used', strpos($result, 'IntegrationVmwareVcenterReconciler') !== false && strpos($result, '->reconcile(') !== false);
phase5c_ui_check('partial-network-warning', strpos($result, 'bounded sample') !== false && strpos($result, 'not presented as absence evidence') !== false);
phase5c_ui_check('secret-zeroing', strpos($result, 'phase5c_inventory_zero_secret') !== false);
phase5c_ui_check('sanitized-error-category', strpos($result, 'Sensitive exception details were suppressed') !== false);
phase5c_ui_check('no-password-render-input', strpos($panel, 'name="password"') === false && strpos($panel, 'type="password"') === false);
phase5c_ui_check('runtime-username-used-only-for-api', strpos($result, '$runtime[\'username\']') !== false && strpos($result, 'phase5c_inventory_h($runtime[\'username\'])') === false);
phase5c_ui_check('no-health-metadata-write', strpos($result, 'mark_test_success') === false && strpos($result, 'mark_test_failure') === false);
phase5c_ui_check('result-no-object-modify', strpos($result, 'object_modify') === false && strpos($result, 'modify_object') === false);
phase5c_ui_check('snapshot-select-only', strpos($snapshot, 'SELECT `id`,`hostname` FROM `devices`') !== false && strpos($snapshot, 'SELECT `id`,`ip_addr`,`switch`,`mac` FROM `ipaddresses`') !== false);
phase5c_ui_check('snapshot-no-write-api', strpos($snapshot, 'insertObject(') === false && strpos($snapshot, 'updateObject(') === false && strpos($snapshot, 'deleteRow(') === false);
phase5c_ui_check('panel-declares-no-persistence', strpos($panel, 'no persistence') !== false && strpos($panel, 'Ничего не сохраняется') !== false);

print "\nPhase 5C admin inventory UI regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
