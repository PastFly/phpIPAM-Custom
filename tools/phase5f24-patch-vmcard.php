<?php

/**
 * Deterministic staged patcher for Phase 5F.2.4 VM-card optional guest-network enrichment.
 * This helper edits only the file path explicitly supplied by the caller.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: CLI only.\n");
    exit(2);
}
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php phase5f24-patch-vmcard.php <vm-card-result.php>\n");
    exit(2);
}

$path = (string)$argv[1];
if ($path === '' || !is_file($path) || !is_readable($path) || !is_writable($path)) {
    fwrite(STDERR, "ERROR: readable/writable VM-card file required.\n");
    exit(2);
}

$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "ERROR: unable to read VM-card source.\n");
    exit(2);
}

$marker = 'PHPIPAM_CUSTOM_VMWARE_PHASE5F24_OPTIONAL_GUEST_NETWORK_V1';
if (strpos($source, $marker) !== false) {
    fwrite(STDERR, "ERROR: Phase 5F.2.4 marker already present.\n");
    exit(2);
}

$needle = <<<'PHP'
    $Guest = new IntegrationVmwareVcenterGuestNetwork();
    $guest = $Guest->read_vm_interfaces($runtime['endpoint'], $runtime['username'], $runtime['password'], array($vmId), $runtime['verify_tls']);
    $guestVm = count($guest['vms']) > 0 ? $guest['vms'][0] : array('status' => 'unknown', 'interfaces' => array(), 'ip_count' => 0);
PHP;

$replacement = <<<'PHP'
    // PHPIPAM_CUSTOM_VMWARE_PHASE5F24_OPTIONAL_GUEST_NETWORK_V1
    // Guest networking is optional enrichment. A malformed/unsupported VMware
    // guest-network payload must not hide otherwise valid VM identity, storage,
    // placement, Notes or performance data.
    $guestVm = array('status' => 'unavailable', 'interfaces' => array(), 'ip_count' => 0);
    try {
        $Guest = new IntegrationVmwareVcenterGuestNetwork();
        $guest = $Guest->read_vm_interfaces($runtime['endpoint'], $runtime['username'], $runtime['password'], array($vmId), $runtime['verify_tls']);
        $guestVm = count($guest['vms']) > 0 ? $guest['vms'][0] : array('status' => 'unknown', 'interfaces' => array(), 'ip_count' => 0);
    } catch (Exception $guestNetworkError) {
        $guestVm = array('status' => 'unavailable', 'interfaces' => array(), 'ip_count' => 0);
    }
PHP;

$count = substr_count($source, $needle);
if ($count !== 1) {
    fwrite(STDERR, "ERROR: expected VM-card guest-network block was not found exactly once.\n");
    exit(3);
}

$patched = str_replace($needle, $replacement, $source, $replaced);
if ($replaced !== 1 || strpos($patched, $marker) === false) {
    fwrite(STDERR, "ERROR: deterministic VM-card patch failed.\n");
    exit(3);
}
if (file_put_contents($path, $patched) === false) {
    fwrite(STDERR, "ERROR: unable to write patched VM-card source.\n");
    exit(3);
}

fwrite(STDERR, "PHASE5F24_VMCARD_PATCH_OK\n");
