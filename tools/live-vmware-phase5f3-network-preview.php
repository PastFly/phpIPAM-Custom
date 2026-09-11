<?php

/**
 * phpIPAM Custom — Phase 5F.3 production host network hint preview.
 * READ ONLY. Resolves one explicitly named ESXi HostSystem and reads CDP/LLDP
 * hints through HostNetworkSystem.QueryNetworkHint. No persistence or mutation.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5F.3 preview is CLI-only.\n");
    exit(2);
}

$repoRoot = dirname(__DIR__);
$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$hostName = null;

for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if (strpos($arg, '--host-name=') === 0) {
        $value = trim(substr($arg, strlen('--host-name=')));
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x20\x7F]/', $value)) {
            fwrite(STDERR, "ERROR: invalid --host-name value.\n"); exit(2);
        }
        $hostName = $value;
        continue;
    }
    if ($connectorId === null && preg_match('/^[1-9][0-9]*$/', $arg)) {
        $connectorId = (int)$arg;
        continue;
    }
    fwrite(STDERR, "ERROR: unknown or duplicate argument: {$arg}\n"); exit(2);
}

if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) {
    fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n"); exit(2);
}
if ($hostName === null) {
    fwrite(STDERR, "ERROR: --host-name is required; the preview never guesses a production Host.\n"); exit(2);
}

function phase5f3_preview_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f3_preview_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
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
    require_once($repoRoot . '/layers/phase5f/functions/classes/class.IntegrationVmwareVcenterSoapHostResolver.php');
    require_once($repoRoot . '/layers/phase5f3/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f3_preview_autoselect_connector($Store);
    $runtime = $Store->load_runtime_config($connectorId, true);

    $EndpointPolicy = new IntegrationVmwareVcenter();
    $endpoint = $EndpointPolicy->normalize_endpoint($runtime['endpoint']);
    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(4194304);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);

    fwrite(STDERR, "PHASE5F3_PREVIEW_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, "PHASE5F3_PREVIEW_TLS=" . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F3_PREVIEW_TRANSPORT=DIRECT_NO_PROXY\n");
    fwrite(STDERR, "PHASE5F3_PREVIEW_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F3_PREVIEW_VCENTER_MUTATIONS=0\n");

    fwrite(STDERR, "PHASE5F3_PREVIEW_STAGE=HOST_RESOLVE\n");
    $Resolver = new IntegrationVmwareVcenterSoapHostResolver($FaultAwareTransport, 8, 20, 1048576);
    $hostId = $Resolver->resolve_host_id_by_dns_name($endpoint, $runtime['username'], $runtime['password'], $hostName, $runtime['verify_tls']);
    fwrite(STDERR, "PHASE5F3_PREVIEW_HOST_NAME={$hostName}\n");
    fwrite(STDERR, "PHASE5F3_PREVIEW_HOST_ID={$hostId}\n");

    fwrite(STDERR, "PHASE5F3_PREVIEW_STAGE=QUERY_NETWORK_HINT\n");
    $Reader = new IntegrationVmwareVcenterHostNetworkHints($FaultAwareTransport, 8, 20, 2097152, 64);
    $result = $Reader->read_hints($endpoint, $runtime['username'], $runtime['password'], $hostId, $runtime['verify_tls']);

    $payload = array(
        'ok' => true,
        'provider' => 'vmware_vcenter',
        'mode' => 'phase5f3_host_network_read_only_preview',
        'connector_id' => $connectorId,
        'verify_tls' => (bool)$runtime['verify_tls'],
        'transport' => 'direct_no_proxy',
        'host_name' => $hostName,
        'host_external_id' => $hostId,
        'network_hints' => $result,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    );
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to encode preview result');
    print $json . "\n";
    fwrite(STDERR, "PHASE5F3_PREVIEW_RESULT=READ_ONLY_COMPATIBILITY_OK\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F3_PREVIEW_RESULT=FAILED\n");
    fwrite(STDERR, "ERROR_CATEGORY=" . get_class($e) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE=" . preg_replace('/[\r\n]+/', ' ', substr($e->getMessage(), 0, 500)) . "\n");
    exit(1);
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f3_preview_zero_secret($runtime['password']);
    if ($originalCwd !== false) @chdir($originalCwd);
}
