<?php

/**
 * phpIPAM Custom — Phase 5F production vCenter compatibility preview.
 *
 * READ ONLY. Uses the deployed encrypted connector, existing authoritative REST
 * topology for stable Host IDs, and repository Phase 5F SOAP collectors for one
 * explicitly named ESXi host plus optional one VM performance series.
 *
 * Nothing is written to phpIPAM or vCenter and no performance data is persisted.
 *
 * Usage:
 *   php tools/live-vmware-phase5f-observability-preview.php /var/www/html \
 *       --host-name=esxi-1-04.rbank.local [connector-id] [--vm-id=vm-123] [--max-samples=12]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5F preview is CLI-only.\n");
    exit(2);
}

$repoRoot = dirname(__DIR__);
$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$hostName = null;
$vmId = null;
$maxSamples = 12;

for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if (strpos($arg, '--host-name=') === 0) {
        $value = trim(substr($arg, strlen('--host-name=')));
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x20\x7F]/', $value)) {
            fwrite(STDERR, "ERROR: invalid --host-name value.\n");
            exit(2);
        }
        $hostName = $value;
        continue;
    }
    if (strpos($arg, '--vm-id=') === 0) {
        $value = trim(substr($arg, strlen('--vm-id=')));
        if (!preg_match('/^vm-[0-9]+$/', $value)) {
            fwrite(STDERR, "ERROR: --vm-id must be a VMware stable id such as vm-123.\n");
            exit(2);
        }
        $vmId = $value;
        continue;
    }
    if (strpos($arg, '--max-samples=') === 0) {
        $value = substr($arg, strlen('--max-samples='));
        if (!preg_match('/^[0-9]+$/', $value) || (int)$value < 2 || (int)$value > 60) {
            fwrite(STDERR, "ERROR: --max-samples must be between 2 and 60 for the live preview.\n");
            exit(2);
        }
        $maxSamples = (int)$value;
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
if ($hostName === null) {
    fwrite(STDERR, "ERROR: --host-name is required; the preview never guesses a production Host.\n");
    exit(2);
}

function phase5f_preview_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f_preview_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) if (!empty($config['enabled'])) $enabled[] = $config;
    if (count($enabled) === 0) throw new RuntimeException('No enabled VMware connector');
    if (count($enabled) > 1) throw new RuntimeException('More than one enabled VMware connector; pass connector-id explicitly');
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1))) === false) throw new RuntimeException('Enabled VMware connector has invalid id');
    return (int)$id;
}

function phase5f_preview_exact_host(array $hosts, $name)
{
    $matches = array();
    foreach ($hosts as $host) {
        if (isset($host['name']) && strcasecmp((string)$host['name'], (string)$name) === 0) $matches[] = $host;
    }
    if (count($matches) !== 1) throw new RuntimeException('Host name must resolve to exactly one topology Host; matches=' . count($matches));
    return $matches[0];
}

function phase5f_preview_json($value)
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to encode preview result');
    return $json;
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
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterTopology.php');
    require_once($repoRoot . '/layers/phase5f/functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php');
    require_once($repoRoot . '/layers/phase5f/functions/classes/class.IntegrationVmwareVcenterPerformance.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f_preview_autoselect_connector($Store);
    $runtime = $Store->load_runtime_config($connectorId, true);

    // Reuse the mature Phase 5A endpoint policy, including literal loopback/link-local blocking.
    $EndpointPolicy = new IntegrationVmwareVcenter();
    $endpoint = $EndpointPolicy->normalize_endpoint($runtime['endpoint']);

    fwrite(STDERR, "PHASE5F_PREVIEW_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, "PHASE5F_PREVIEW_TLS=" . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F_PREVIEW_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F_PREVIEW_VCENTER_MUTATIONS=0\n");

    $Topology = new IntegrationVmwareVcenterTopology(null, 8, 20, 2097152, 10000, 500);
    $topology = $Topology->read_topology($endpoint, $runtime['username'], $runtime['password'], $runtime['verify_tls']);
    $host = phase5f_preview_exact_host($topology['hosts'], $hostName);
    $hostId = isset($host['external_id']) ? (string)$host['external_id'] : '';
    if (!preg_match('/^host-[0-9]+$/', $hostId)) throw new RuntimeException('Resolved Host has unexpected stable id');

    fwrite(STDERR, "PHASE5F_PREVIEW_HOST_NAME=" . $hostName . "\n");
    fwrite(STDERR, "PHASE5F_PREVIEW_HOST_ID=" . $hostId . "\n");

    $HostReader = new IntegrationVmwareVcenterSoapReadOnly(null, 8, 20, 4194304);
    $hostSummary = $HostReader->read_host_summary($endpoint, $runtime['username'], $runtime['password'], $hostId, $runtime['verify_tls']);

    $Performance = new IntegrationVmwareVcenterPerformance(null, 8, 25, 6291456);
    $hostPerformance = $Performance->read_realtime_percent_series($endpoint, $runtime['username'], $runtime['password'], 'host', $hostId, $maxSamples, $runtime['verify_tls']);

    $result = array(
        'ok' => true,
        'provider' => 'vmware_vcenter',
        'mode' => 'phase5f_read_only_compatibility_preview',
        'connector_id' => $connectorId,
        'verify_tls' => (bool)$runtime['verify_tls'],
        'topology_counts' => array(
            'datacenters' => $topology['datacenter_count'],
            'clusters' => $topology['cluster_count'],
            'hosts' => $topology['host_count'],
            'vms' => $topology['vm_count']
        ),
        'host' => array(
            'topology' => $host,
            'summary' => $hostSummary,
            'performance' => $hostPerformance
        ),
        'vm_performance' => null,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    );

    if ($vmId !== null) {
        $knownVm = false;
        foreach ($topology['vms'] as $vm) {
            if (isset($vm['external_id']) && (string)$vm['external_id'] === $vmId) { $knownVm = true; break; }
        }
        if (!$knownVm) throw new RuntimeException('Requested VM stable id is not present in the authoritative topology');
        $result['vm_performance'] = $Performance->read_realtime_percent_series($endpoint, $runtime['username'], $runtime['password'], 'vm', $vmId, $maxSamples, $runtime['verify_tls']);
    }

    print phase5f_preview_json($result) . "\n";
    fwrite(STDERR, "PHASE5F_PREVIEW_RESULT=READ_ONLY_COMPATIBILITY_OK\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F_PREVIEW_RESULT=FAILED\n");
    fwrite(STDERR, "ERROR_CATEGORY=" . get_class($e) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE=" . preg_replace('/[\r\n]+/', ' ', substr($e->getMessage(), 0, 500)) . "\n");
    exit(1);
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f_preview_zero_secret($runtime['password']);
    if ($originalCwd !== false) @chdir($originalCwd);
}
