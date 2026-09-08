<?php

/**
 * Phase 5D live-preview safety regression.
 * Static repository contract only; no live connector or network calls.
 */

$root = dirname(__DIR__);
$path = $root . '/tools/live-vmware-phase5d-preview.php';
$source = file_get_contents($path);
$passed = 0;
$failed = 0;

function p5d_preview_check($name, $condition)
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

p5d_preview_check('source-cli-only', strpos($source, "PHP_SAPI !== 'cli'") !== false);
p5d_preview_check('source-autoselect-single-enabled', strpos($source, 'phase5d_preview_autoselect_connector') !== false && strpos($source, 'AMBIGUOUS_CONNECTOR_SELECTION') !== false);
p5d_preview_check('source-insecure-tls-explicit-gate', strpos($source, '--allow-insecure-tls') !== false && strpos($source, 'PREVIEW_BLOCKED=INSECURE_TLS') !== false);
p5d_preview_check('source-topology-client', strpos($source, 'IntegrationVmwareVcenterTopology') !== false);
p5d_preview_check('source-datastore-client', strpos($source, 'IntegrationVmwareVcenterDatastore') !== false);
p5d_preview_check('source-datastore-resolver', strpos($source, 'IntegrationVmwareVcenterDatastoreResolver') !== false);
p5d_preview_check('source-on-demand-vm-detail', strpos($source, '--sample-vm-detail') !== false && strpos($source, 'IntegrationVmwareVcenterVmDetail') !== false);
p5d_preview_check('source-tags-explicit-option', strpos($source, '--include-tags') !== false && strpos($source, 'IntegrationVmwareVcenterMetadata') !== false);
p5d_preview_check('source-no-db-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
p5d_preview_check('source-no-object-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);
p5d_preview_check('source-no-persistence-flags', strpos($source, "'persistent_inventory_written' => false") !== false && strpos($source, "'phpipam_objects_modified' => false") !== false && strpos($source, "'vcenter_objects_modified' => false") !== false);
p5d_preview_check('source-zero-secret', strpos($source, 'phase5d_preview_zero_secret') !== false && strpos($source, 'sodium_memzero') !== false);
p5d_preview_check('source-suppresses-sensitive-exception', strpos($source, 'Sensitive exception details were suppressed') !== false);
p5d_preview_check('source-summary-result-marker', strpos($source, 'PHASE5D_LIVE_PREVIEW_OK') !== false);
p5d_preview_check('source-no-scheduler', strpos($source, 'cron') === false && strpos($source, 'systemd') === false);

print "\nPhase 5D live preview safety regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
