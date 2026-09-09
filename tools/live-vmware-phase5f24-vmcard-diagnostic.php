<?php

/**
 * phpIPAM Custom — Phase 5F.2.4 VM card staged production diagnostic.
 * READ ONLY. Replays the existing VM-card backend pipeline for one explicit VM
 * and reports the stage/category only. No persistence or mutation.
 *
 * Usage:
 *   php tools/live-vmware-phase5f24-vmcard-diagnostic.php /var/www/html \
 *       --vm-id=vm-11151011 [connector-id]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5F.2.4 diagnostic is CLI-only.\n");
    exit(2);
}

$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$vmId = null;
for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if (strpos($arg, '--vm-id=') === 0) {
        $value = trim(substr($arg, strlen('--vm-id=')));
        if (!preg_match('/^vm-[0-9]+$/', $value)) {
            fwrite(STDERR, "ERROR: invalid --vm-id value.\n"); exit(2);
        }
        $vmId = $value;
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
if ($vmId === null) {
    fwrite(STDERR, "ERROR: --vm-id is required; the diagnostic never guesses a production VM.\n"); exit(2);
}

function phase5f24_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f24_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) if (!empty($config['enabled'])) $enabled[] = $config;
    if (count($enabled) === 0) throw new RuntimeException('No enabled VMware connector');
    if (count($enabled) > 1) throw new RuntimeException('More than one enabled VMware connector; pass connector-id explicitly');
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) throw new RuntimeException('Enabled VMware connector has invalid id');
    return (int)$id;
}

function phase5f24_category($e)
{
    $m = strtolower((string)$e->getMessage());
    if (strpos($m, 'certificate') !== false || strpos($m, 'ssl') !== false || strpos($m, 'tls') !== false) return 'tls';
    if (strpos($m, 'timeout') !== false || strpos($m, 'timed out') !== false) return 'timeout';
    if (strpos($m, 'authentication') !== false || strpos($m, 'http 401') !== false) return 'authentication';
    if (strpos($m, 'forbidden') !== false || strpos($m, 'permission') !== false || strpos($m, 'http 403') !== false) return 'authorization';
    if (strpos($m, 'network') !== false || strpos($m, 'curl') !== false || strpos($m, 'resolve') !== false || strpos($m, 'connect') !== false) return 'network';
    if (strpos($m, 'json') !== false || strpos($m, 'response') !== false || strpos($m, 'safety limit') !== false || strpos($m, 'not found') !== false) return 'invalid_response';
    if ($e instanceof InvalidArgumentException || strpos($m, 'connector') !== false || strpos($m, 'credential') !== false) return 'configuration';
    return 'unknown';
}

$runtime = null;
$stage = 'BOOTSTRAP';
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
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterDatastore.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterVmDetail.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterMetadata.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterExplorer.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f24_autoselect_connector($Store);
    $runtime = $Store->load_runtime_config($connectorId, true);

    fwrite(STDERR, "PHASE5F24_DIAG_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, "PHASE5F24_DIAG_VM_ID={$vmId}\n");
    fwrite(STDERR, "PHASE5F24_DIAG_TLS=" . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F24_DIAG_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F24_DIAG_VCENTER_MUTATIONS=0\n");

    $stage = 'TOPOLOGY';
    fwrite(STDERR, "PHASE5F24_DIAG_STAGE={$stage}\n");
    $Topology = new IntegrationVmwareVcenterTopology();
    $topology = $Topology->read_topology($runtime['endpoint'], $runtime['username'], $runtime['password'], $runtime['verify_tls']);
    fwrite(STDERR, "PHASE5F24_DIAG_TOPOLOGY_DCS=" . count($topology['datacenters']) . "\n");
    fwrite(STDERR, "PHASE5F24_DIAG_TOPOLOGY_VMS=" . count($topology['vms']) . "\n");

    $stage = 'DATASTORE';
    fwrite(STDERR, "PHASE5F24_DIAG_STAGE={$stage}\n");
    $dcIds = array();
    foreach ($topology['datacenters'] as $dc) $dcIds[] = $dc['external_id'];
    $Datastore = new IntegrationVmwareVcenterDatastore();
    $datastoreInventory = $Datastore->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], $dcIds, $runtime['verify_tls']);
    fwrite(STDERR, "PHASE5F24_DIAG_DATASTORES=" . count($datastoreInventory['datastores']) . "\n");

    $stage = 'VM_CONTEXT';
    fwrite(STDERR, "PHASE5F24_DIAG_STAGE={$stage}\n");
    $Explorer = new IntegrationVmwareVcenterExplorer(100);
    $projection = $Explorer->build($topology, $datastoreInventory);
    $context = $Explorer->vm_context($projection, $vmId);
    fwrite(STDERR, "PHASE5F24_DIAG_CONTEXT_HOST=" . (isset($context['host_external_id']) ? $context['host_external_id'] : 'NONE') . "\n");

    $stage = 'VM_DETAIL';
    fwrite(STDERR, "PHASE5F24_DIAG_STAGE={$stage}\n");
    $VmDetail = new IntegrationVmwareVcenterVmDetail();
    $detail = $VmDetail->read_detail($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
    $Resolver = new IntegrationVmwareVcenterDatastoreResolver();
    $detail = $Resolver->resolve_vm_detail($detail, $datastoreInventory['datastores'], $context['datacenter_external_id']);
    $vm = isset($detail['vm']) ? $detail['vm'] : array();
    fwrite(STDERR, "PHASE5F24_DIAG_VM_NAME_PRESENT=" . (!empty($vm['name']) ? '1' : '0') . "\n");
    fwrite(STDERR, "PHASE5F24_DIAG_VM_DISKS=" . (isset($vm['disks']) && is_array($vm['disks']) ? count($vm['disks']) : 0) . "\n");

    $stage = 'GUEST_NETWORK';
    fwrite(STDERR, "PHASE5F24_DIAG_STAGE={$stage}\n");
    $Guest = new IntegrationVmwareVcenterGuestNetwork();
    $guest = $Guest->read_vm_interfaces($runtime['endpoint'], $runtime['username'], $runtime['password'], array($vmId), $runtime['verify_tls']);
    fwrite(STDERR, "PHASE5F24_DIAG_GUEST_VMS=" . (isset($guest['vms']) && is_array($guest['vms']) ? count($guest['vms']) : 0) . "\n");

    $stage = 'TAGS';
    fwrite(STDERR, "PHASE5F24_DIAG_STAGE={$stage}\n");
    $Metadata = new IntegrationVmwareVcenterMetadata();
    $metadata = $Metadata->read_vm_tags($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
    fwrite(STDERR, "PHASE5F24_DIAG_TAGS=" . (isset($metadata['tag_count']) ? (int)$metadata['tag_count'] : 0) . "\n");

    fwrite(STDERR, "PHASE5F24_DIAG_RESULT=READ_ONLY_PIPELINE_OK\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F24_DIAG_RESULT=FAILED\n");
    fwrite(STDERR, "PHASE5F24_DIAG_FAILED_STAGE={$stage}\n");
    fwrite(STDERR, "ERROR_CATEGORY=" . phase5f24_category($e) . "\n");
    fwrite(STDERR, "ERROR_CLASS=" . get_class($e) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE_SHA256=" . hash('sha256', (string)$e->getMessage()) . "\n");
    exit(1);
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f24_zero_secret($runtime['password']);
    if ($originalCwd !== false) @chdir($originalCwd);
}
