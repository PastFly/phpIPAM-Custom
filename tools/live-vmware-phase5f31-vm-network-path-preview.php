<?php

/**
 * phpIPAM Custom — Phase 5F.3.1 production VM network path preview.
 * READ ONLY. One explicit VM only. No persistence, phpIPAM mutation or vCenter
 * inventory mutation. Session create/delete and QueryNetworkHint are read-side
 * lifecycle/inspection operations only.
 *
 * Usage:
 *   php tools/live-vmware-phase5f31-vm-network-path-preview.php /var/www/html \
 *       --vm-id=vm-123 [connector-id]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5F.3.1 preview is CLI-only.\n");
    exit(2);
}

$repoRoot = dirname(__DIR__);
$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$vmId = null;

for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if (strpos($arg, '--vm-id=') === 0) {
        $value = trim(substr($arg, strlen('--vm-id=')));
        if (!preg_match('/^vm-[0-9]+$/', $value)) {
            fwrite(STDERR, "ERROR: invalid --vm-id value.\n");
            exit(2);
        }
        $vmId = $value;
        continue;
    }
    if ($connectorId === null && preg_match('/^[1-9][0-9]*$/', $arg)) {
        $connectorId = (int)$arg;
        continue;
    }
    fwrite(STDERR, "ERROR: unknown or duplicate argument: {$arg}\n");
    exit(2);
}

if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) {
    fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n");
    exit(2);
}
if ($vmId === null) {
    fwrite(STDERR, "ERROR: --vm-id is required; the preview never guesses a production VM.\n");
    exit(2);
}

function phase5f31_preview_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f31_preview_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) if (!empty($config['enabled'])) $enabled[] = $config;
    if (count($enabled) === 0) throw new RuntimeException('No enabled VMware connector');
    if (count($enabled) > 1) throw new RuntimeException('More than one enabled VMware connector; pass connector-id explicitly');
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) throw new RuntimeException('Enabled VMware connector has invalid id');
    return (int)$id;
}

function phase5f31_preview_category($e)
{
    $m = strtolower((string)$e->getMessage());
    if (strpos($m, 'certificate') !== false || strpos($m, 'ssl') !== false || strpos($m, 'tls') !== false) return 'tls';
    if (strpos($m, 'timeout') !== false || strpos($m, 'timed out') !== false) return 'timeout';
    if (strpos($m, 'authentication') !== false || strpos($m, 'http 401') !== false) return 'authentication';
    if (strpos($m, 'forbidden') !== false || strpos($m, 'permission') !== false || strpos($m, 'http 403') !== false) return 'authorization';
    if (strpos($m, 'network') !== false || strpos($m, 'curl') !== false || strpos($m, 'resolve') !== false || strpos($m, 'connect') !== false) return 'network';
    if (strpos($m, 'xml') !== false || strpos($m, 'json') !== false || strpos($m, 'response') !== false || strpos($m, 'not returned') !== false) return 'invalid_response';
    if ($e instanceof InvalidArgumentException || strpos($m, 'connector') !== false || strpos($m, 'credential') !== false) return 'configuration';
    return 'unknown';
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
    require_once($repoRoot . '/layers/phase5f31/functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f31_preview_autoselect_connector($Store);
    $runtime = $Store->load_runtime_config($connectorId, true);

    $EndpointPolicy = new IntegrationVmwareVcenter();
    $endpoint = $EndpointPolicy->normalize_endpoint($runtime['endpoint']);
    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(8388608);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);

    fwrite(STDERR, "PHASE5F31_PREVIEW_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_VM_ID={$vmId}\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_TLS=" . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_REST_TRANSPORT=DIRECT_NO_PROXY\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_SOAP_TRANSPORT=DIRECT_NO_PROXY\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_PHPIPAM_MUTATIONS=0\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_VCENTER_MUTATIONS=0\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_CURRENT_UPLINK_GUESSING=0\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_STAGE=VM_NETWORK_PATH\n");

    $Reader = new IntegrationVmwareVcenterVmNetworkPath($FaultAwareTransport, 8, 25, 4194304, 64, 128, 128, 512, 128);
    $result = $Reader->read_path($endpoint, $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);

    fwrite(STDERR, "PHASE5F31_PREVIEW_HOST_ID=" . (isset($result['host_external_id']) ? $result['host_external_id'] : 'NONE') . "\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_NICS=" . (isset($result['nic_count']) ? (int)$result['nic_count'] : 0) . "\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_RESOLVED_NICS=" . (isset($result['resolved_nic_count']) ? (int)$result['resolved_nic_count'] : 0) . "\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_STANDARD_NICS=" . (isset($result['standard_nic_count']) ? (int)$result['standard_nic_count'] : 0) . "\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_DISTRIBUTED_NICS=" . (isset($result['distributed_nic_count']) ? (int)$result['distributed_nic_count'] : 0) . "\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_UPLINK_ROWS=" . (isset($result['uplink_row_count']) ? (int)$result['uplink_row_count'] : 0) . "\n");
    fwrite(STDERR, "PHASE5F31_PREVIEW_CURRENT_UPLINK_PROVEN=" . (isset($result['current_uplink_proven_count']) ? (int)$result['current_uplink_proven_count'] : 0) . "\n");

    $payload = array(
        'ok' => true,
        'provider' => 'vmware_vcenter',
        'mode' => 'phase5f31_vm_network_path_read_only_preview',
        'connector_id' => $connectorId,
        'verify_tls' => (bool)$runtime['verify_tls'],
        'vm_network_path' => $result,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    );
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to encode Phase 5F.3.1 preview result');
    print $json . "\n";
    fwrite(STDERR, "PHASE5F31_PREVIEW_RESULT=READ_ONLY_COMPATIBILITY_OK\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F31_PREVIEW_RESULT=FAILED\n");
    fwrite(STDERR, "ERROR_CATEGORY=" . phase5f31_preview_category($e) . "\n");
    fwrite(STDERR, "ERROR_CLASS=" . get_class($e) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE_SHA256=" . hash('sha256', (string)$e->getMessage()) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE=" . preg_replace('/[\r\n]+/', ' ', substr($e->getMessage(), 0, 500)) . "\n");
    exit(1);
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f31_preview_zero_secret($runtime['password']);
    if ($originalCwd !== false) @chdir($originalCwd);
}
