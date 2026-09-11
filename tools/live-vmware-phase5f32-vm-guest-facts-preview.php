<?php

/**
 * phpIPAM Custom — Phase 5F.3.2 production VM guest facts preview.
 * READ ONLY. One or more explicit VM ids, no persistence or mutation.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5F.3.2 preview is CLI-only.\n");
    exit(2);
}

$repoRoot = dirname(__DIR__);
$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$vmIds = array();
for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if (strpos($arg, '--vm-id=') === 0) {
        $value = trim(substr($arg, strlen('--vm-id=')));
        if (!preg_match('/^vm-[0-9]+$/', $value)) { fwrite(STDERR, "ERROR: invalid --vm-id value.\n"); exit(2); }
        $vmIds[] = $value;
        continue;
    }
    if ($connectorId === null && preg_match('/^[1-9][0-9]*$/', $arg)) { $connectorId = (int)$arg; continue; }
    fwrite(STDERR, "ERROR: unknown or duplicate argument: {$arg}\n"); exit(2);
}
if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) { fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n"); exit(2); }
if (count($vmIds) < 1 || count($vmIds) > 8) { fwrite(STDERR, "ERROR: pass 1..8 explicit --vm-id values.\n"); exit(2); }

function phase5f32_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}
function phase5f32_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) if (!empty($config['enabled'])) $enabled[] = $config;
    if (count($enabled) === 0) throw new RuntimeException('No enabled VMware connector');
    if (count($enabled) > 1) throw new RuntimeException('More than one enabled VMware connector; pass connector-id explicitly');
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1))) === false) throw new RuntimeException('Enabled VMware connector has invalid id');
    return (int)$id;
}

$runtime = null;
$originalCwd = getcwd();
try {
    $_SERVER['DOCUMENT_ROOT'] = $target;
    chdir($target . '/functions');
    require_once($target . '/functions/functions.php');
    if ($originalCwd !== false) chdir($originalCwd);

    require_once($target . '/functions/classes/class.IntegrationVmwareVcenter.php');
    require_once($target . '/functions/classes/class.IntegrationSecretCipher.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterConfigStore.php');
    require_once($repoRoot . '/layers/phase5f/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php');
    require_once($repoRoot . '/layers/phase5f/functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php');
    require_once($repoRoot . '/layers/phase5f32/functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f32_autoselect_connector($Store);
    $runtime = $Store->load_runtime_config($connectorId, true);

    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(4194304);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);
    $Reader = new IntegrationVmwareVcenterVmGuestFacts($FaultAwareTransport, 8, 20, 4194304);

    fwrite(STDERR, "PHASE5F32_PREVIEW_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, "PHASE5F32_PREVIEW_TLS=" . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F32_PREVIEW_TRANSPORT=DIRECT_NO_PROXY\n");
    fwrite(STDERR, "PHASE5F32_PREVIEW_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F32_PREVIEW_PHPIPAM_MUTATIONS=0\n");
    fwrite(STDERR, "PHASE5F32_PREVIEW_VCENTER_MUTATIONS=0\n");

    $rows = array();
    foreach ($vmIds as $vmId) {
        fwrite(STDERR, "PHASE5F32_PREVIEW_VM_ID={$vmId}\n");
        $facts = $Reader->read_facts($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
        $rows[] = $facts;
        fwrite(STDERR, "PHASE5F32_PREVIEW_VM_NAME=" . $facts['name'] . "\n");
        fwrite(STDERR, "PHASE5F32_PREVIEW_MEMORY_MB=" . ($facts['memory_mb'] === null ? 'NONE' : $facts['memory_mb']) . "\n");
        fwrite(STDERR, "PHASE5F32_PREVIEW_CONFIG_GUEST_FULL_NAME=" . ($facts['config_guest_full_name'] === null ? 'NONE' : $facts['config_guest_full_name']) . "\n");
        fwrite(STDERR, "PHASE5F32_PREVIEW_GUEST_GUEST_FULL_NAME=" . ($facts['guest_guest_full_name'] === null ? 'NONE' : $facts['guest_guest_full_name']) . "\n");
    }

    $payload = array(
        'ok' => true,
        'provider' => 'vmware_vcenter',
        'mode' => 'phase5f32_vm_guest_facts_read_only_preview',
        'connector_id' => $connectorId,
        'verify_tls' => (bool)$runtime['verify_tls'],
        'transport' => 'direct_no_proxy',
        'vms' => $rows,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    );
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to encode preview result');
    print $json . "\n";
    fwrite(STDERR, "PHASE5F32_PREVIEW_RESULT=READ_ONLY_COMPATIBILITY_OK\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F32_PREVIEW_RESULT=FAILED\n");
    fwrite(STDERR, "ERROR_CLASS=" . get_class($e) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE=" . preg_replace('/[\r\n]+/', ' ', substr($e->getMessage(), 0, 500)) . "\n");
    exit(1);
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f32_zero_secret($runtime['password']);
    if ($originalCwd !== false) @chdir($originalCwd);
}
