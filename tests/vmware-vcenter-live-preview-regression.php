<?php

/**
 * Phase 5C live preview source/security regression.
 *
 * Static contract only: CI must never use a real stored credential or vCenter.
 */

$root = dirname(__DIR__);
$path = $root . '/tools/live-vmware-phase5c-preview.php';
if (!is_file($path)) {
    fwrite(STDERR, "Missing Phase 5C live preview tool.\n");
    exit(2);
}
$source = file_get_contents($path);
$passed = 0;
$failed = 0;

function preview_check($name, $condition)
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

preview_check('cli-only', strpos($source, "PHP_SAPI !== 'cli'") !== false);
preview_check('persistent-runtime-config-used', strpos($source, 'load_runtime_config($connectorId, true)') !== false);
preview_check('requires-enabled-connector', strpos($source, 'load_runtime_config($connectorId, true)') !== false);
preview_check('default-blocks-insecure-tls', strpos($source, 'PREVIEW_BLOCKED=INSECURE_TLS') !== false);
preview_check('insecure-requires-explicit-flag', strpos($source, '--allow-insecure-tls') !== false);
preview_check('guest-limit-bounded', strpos($source, '$guestLimit > 250') !== false);
preview_check('core-inventory-read', strpos($source, 'read_core_inventory(') !== false);
preview_check('guest-network-read', strpos($source, 'read_vm_interfaces(') !== false);
preview_check('summary-no-credential', strpos($source, "'password' =>") === false && strpos($source, "'username' =>") === false);
preview_check('secret-zeroing', strpos($source, 'phase5c_preview_zero_secret') !== false);
preview_check('sanitized-errors', strpos($source, 'Sensitive exception details were suppressed.') !== false);
preview_check('no-health-write', strpos($source, 'mark_test_success') === false && strpos($source, 'mark_test_failure') === false);
preview_check('no-sql-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
preview_check('no-object-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);
preview_check('declares-no-persistence', strpos($source, "'persistent_inventory_written' => false") !== false);
preview_check('declares-no-phpipam-mutation', strpos($source, "'phpipam_objects_modified' => false") !== false);
preview_check('declares-no-vcenter-mutation', strpos($source, "'vcenter_objects_modified' => false") !== false);

print "\nPhase 5C live preview regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
