<?php

/**
 * phpIPAM Custom — Phase 5D live read-only topology/datastore/VM-detail preview.
 *
 * Runs from repository checkout against an already deployed encrypted Phase 5B
 * connector. Nothing is written to phpIPAM or vCenter and no inventory is
 * persisted.
 *
 * Usage:
 *   php tools/live-vmware-phase5d-preview.php /var/www/html [connector-id]
 *       [--allow-insecure-tls] [--sample-vm-detail] [--vm-id=vm-123]
 *       [--include-tags]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5D preview is CLI-only.\n");
    exit(2);
}

$repoRoot = dirname(__DIR__);
$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$allowInsecureTls = false;
$sampleVmDetail = false;
$vmId = null;
$includeTags = false;

for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if ($arg === '--allow-insecure-tls') {
        $allowInsecureTls = true;
        continue;
    }
    if ($arg === '--sample-vm-detail') {
        $sampleVmDetail = true;
        continue;
    }
    if ($arg === '--include-tags') {
        $includeTags = true;
        continue;
    }
    if (strpos($arg, '--vm-id=') === 0) {
        $value = trim(substr($arg, strlen('--vm-id=')));
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
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
    fwrite(STDERR, "ERROR: unknown or duplicate argument: " . $arg . "\n");
    exit(2);
}

if ($vmId !== null && $sampleVmDetail) {
    fwrite(STDERR, "ERROR: use either --vm-id or --sample-vm-detail, not both.\n");
    exit(2);
}
if ($includeTags && $vmId === null && !$sampleVmDetail) {
    fwrite(STDERR, "ERROR: --include-tags requires --vm-id or --sample-vm-detail.\n");
    exit(2);
}
if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) {
    fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n");
    exit(2);
}

function phase5d_preview_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') {
        return;
    }
    if (function_exists('sodium_memzero')) {
        @sodium_memzero($value);
    } else {
        $value = str_repeat("\0", strlen($value));
    }
}

function phase5d_preview_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) {
        return 'tls';
    }
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) {
        return 'timeout';
    }
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) {
        return 'authentication';
    }
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) {
        return 'authorization';
    }
    if (strpos($message, 'network') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) {
        return 'network';
    }
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'safety limit') !== false || strpos($message, 'conflicting') !== false) {
        return 'invalid_response';
    }
    if (strpos($message, 'connector') !== false || strpos($message, 'credential') !== false || $error instanceof InvalidArgumentException) {
        return 'configuration';
    }
    return 'unknown';
}

function phase5d_preview_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) {
        if (!empty($config['enabled'])) {
            $enabled[] = $config;
        }
    }
    if (count($enabled) === 0) {
        fwrite(STDERR, "PREVIEW_BLOCKED=NO_ENABLED_CONNECTOR\n");
        exit(3);
    }
    if (count($enabled) > 1) {
        fwrite(STDERR, "PREVIEW_BLOCKED=AMBIGUOUS_CONNECTOR_SELECTION\n");
        exit(3);
    }
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
        throw new RuntimeException('The enabled VMware connector has an invalid id');
    }
    return (int)$id;
}

function phase5d_preview_find_vm(array $vms, $vmId)
{
    foreach ($vms as $vm) {
        if (isset($vm['external_id']) && (string)$vm['external_id'] === (string)$vmId) {
            return $vm;
        }
    }
    return null;
}

function phase5d_preview_vm_summary(array $detail)
{
    $vm = $detail['vm'];
    $disks = array();
    foreach ($vm['disks'] as $disk) {
        $disks[] = array(
            'disk_external_id' => isset($disk['external_id']) ? $disk['external_id'] : null,
            'label' => isset($disk['label']) ? $disk['label'] : null,
            'capacity_bytes' => isset($disk['capacity_bytes']) ? $disk['capacity_bytes'] : null,
            'observed_datastore_name' => isset($disk['observed_datastore_name']) ? $disk['observed_datastore_name'] : null,
            'datastore_external_id' => isset($disk['datastore_external_id']) ? $disk['datastore_external_id'] : null,
            'datastore_name' => isset($disk['datastore_name']) ? $disk['datastore_name'] : null,
            'datastore_relation_status' => isset($disk['datastore_relation_status']) ? $disk['datastore_relation_status'] : null,
            'datastore_relation_evidence' => isset($disk['datastore_relation_evidence']) ? $disk['datastore_relation_evidence'] : null
        );
    }

    return array(
        'external_id' => isset($vm['external_id']) ? $vm['external_id'] : null,
        'name' => isset($vm['name']) ? $vm['name'] : null,
        'power_state' => isset($vm['power_state']) ? $vm['power_state'] : null,
        'guest_os' => isset($vm['guest_os']) ? $vm['guest_os'] : null,
        'cpu_count' => isset($vm['cpu_count']) ? $vm['cpu_count'] : null,
        'memory_size_mib' => isset($vm['memory_size_mib']) ? $vm['memory_size_mib'] : null,
        'bios_uuid' => isset($vm['identity']['bios_uuid']) ? $vm['identity']['bios_uuid'] : null,
        'instance_uuid' => isset($vm['identity']['instance_uuid']) ? $vm['identity']['instance_uuid'] : null,
        'guest_hostname' => isset($detail['guest_identity']['host_name']) ? $detail['guest_identity']['host_name'] : null,
        'guest_primary_ip' => isset($detail['guest_identity']['primary_ip']) ? $detail['guest_identity']['primary_ip'] : null,
        'tools_status' => isset($detail['tools']['status']) ? $detail['tools']['status'] : null,
        'tools_version' => isset($detail['tools']['version']) ? $detail['tools']['version'] : null,
        'nic_count' => isset($vm['nic_count']) ? $vm['nic_count'] : 0,
        'disk_count' => isset($vm['disk_count']) ? $vm['disk_count'] : count($disks),
        'disks' => $disks,
        'datastore_resolution' => isset($detail['datastore_resolution']) ? $detail['datastore_resolution'] : null
    );
}

$runtime = null;
$originalCwd = getcwd();
try {
    $_SERVER['DOCUMENT_ROOT'] = $target;
    chdir($target . '/functions');
    require_once($target . '/functions/functions.php');
    if ($originalCwd !== false) {
        chdir($originalCwd);
    }

    require_once($target . '/functions/classes/class.IntegrationVmwareVcenter.php');
    require_once($target . '/functions/classes/class.IntegrationSecretCipher.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterConfigStore.php');
    require_once($repoRoot . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterTopology.php');
    require_once($repoRoot . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastore.php');
    require_once($repoRoot . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php');
    require_once($repoRoot . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterVmDetail.php');
    require_once($repoRoot . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterMetadata.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);

    if ($connectorId === null) {
        $connectorId = phase5d_preview_autoselect_connector($Store);
        fwrite(STDERR, "PREVIEW_CONNECTOR_AUTOSELECTED=" . $connectorId . "\n");
    }

    $runtime = $Store->load_runtime_config($connectorId, true);
    if (empty($runtime['verify_tls']) && !$allowInsecureTls) {
        fwrite(STDERR, "PREVIEW_BLOCKED=INSECURE_TLS\n");
        fwrite(STDERR, "Use --allow-insecure-tls only for this manual read-only preview. Scheduled inventory remains forbidden.\n");
        phase5d_preview_zero_secret($runtime['password']);
        exit(3);
    }

    $Topology = new IntegrationVmwareVcenterTopology();
    $topology = $Topology->read_topology($runtime['endpoint'], $runtime['username'], $runtime['password'], $runtime['verify_tls']);

    $datacenterIds = array();
    foreach ($topology['datacenters'] as $datacenter) {
        $datacenterIds[] = $datacenter['external_id'];
    }

    $Datastore = new IntegrationVmwareVcenterDatastore();
    $datastoreInventory = $Datastore->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], $datacenterIds, $runtime['verify_tls']);

    $summary = array(
        'result' => 'PHASE5D_LIVE_PREVIEW_OK',
        'connector_id' => $connectorId,
        'api_family' => 'modern',
        'verify_tls' => !empty($runtime['verify_tls']),
        'datacenter_count' => (int)$topology['datacenter_count'],
        'cluster_count' => (int)$topology['cluster_count'],
        'host_count' => (int)$topology['host_count'],
        'vm_count' => (int)$topology['vm_count'],
        'datastore_count' => (int)$datastoreInventory['datastore_count'],
        'unresolved_cluster_parent_count' => (int)$topology['unresolved_cluster_parent_count'],
        'unresolved_host_cluster_count' => (int)$topology['unresolved_host_cluster_count'],
        'unresolved_host_datacenter_count' => (int)$topology['unresolved_host_datacenter_count'],
        'unresolved_vm_host_count' => (int)$topology['unresolved_vm_host_count'],
        'unresolved_vm_cluster_count' => (int)$topology['unresolved_vm_cluster_count'],
        'unresolved_vm_datacenter_count' => (int)$topology['unresolved_vm_datacenter_count'],
        'unresolved_datastore_datacenter_count' => (int)$datastoreInventory['unresolved_datacenter_count'],
        'vm_detail' => null,
        'vm_tags' => null,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    );

    if ($sampleVmDetail && count($topology['vms']) > 0) {
        $sortedVms = $topology['vms'];
        usort($sortedVms, function ($a, $b) {
            return strcmp((string)$a['external_id'], (string)$b['external_id']);
        });
        $vmId = $sortedVms[0]['external_id'];
    }

    if ($vmId !== null) {
        $placement = phase5d_preview_find_vm($topology['vms'], $vmId);
        if ($placement === null) {
            throw new RuntimeException('Requested VM external id was not present in topology inventory');
        }

        $VmDetail = new IntegrationVmwareVcenterVmDetail();
        $detail = $VmDetail->read_detail($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
        $Resolver = new IntegrationVmwareVcenterDatastoreResolver();
        $detail = $Resolver->resolve_vm_detail($detail, $datastoreInventory['datastores'], isset($placement['datacenter_external_id']) ? $placement['datacenter_external_id'] : null);
        $summary['vm_detail'] = phase5d_preview_vm_summary($detail);
        $summary['vm_detail']['placement'] = array(
            'datacenter_external_id' => isset($placement['datacenter_external_id']) ? $placement['datacenter_external_id'] : null,
            'cluster_external_id' => isset($placement['cluster_external_id']) ? $placement['cluster_external_id'] : null,
            'host_external_id' => isset($placement['host_external_id']) ? $placement['host_external_id'] : null
        );

        if ($includeTags) {
            $Metadata = new IntegrationVmwareVcenterMetadata();
            $tags = $Metadata->read_vm_tags($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);
            $summary['vm_tags'] = array(
                'tag_count' => $tags['tag_count'],
                'category_count' => $tags['category_count'],
                'tags' => $tags['tags']
            );
        }
    }

    phase5d_preview_zero_secret($runtime['password']);
    print json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Exception $e) {
    if (is_array($runtime) && isset($runtime['password'])) {
        phase5d_preview_zero_secret($runtime['password']);
    }
    if ($originalCwd !== false) {
        @chdir($originalCwd);
    }
    fwrite(STDERR, "PHASE5D_LIVE_PREVIEW_FAILED=" . phase5d_preview_error_category($e) . "\n");
    fwrite(STDERR, "Sensitive exception details were suppressed.\n");
    exit(1);
}
