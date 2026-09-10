<?php
/** Phase 5F.3.3.2 compatibility preview: no production source installation. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if ($argc < 2 || $argc > 3 || ($argc === 3 && !preg_match('/^[1-9][0-9]{0,9}$/D', $argv[2]))) {
    fwrite(STDERR, "Usage: php tools/live-vmware-phase5f332-object-folders-preview.php TARGET [CONNECTOR_ID]\n"); exit(2);
}
$target = realpath($argv[1]);
if ($target === false || !is_file($target . '/config.php')) { fwrite(STDERR, "ERROR: valid phpIPAM target required\n"); exit(2); }
$repo = dirname(__DIR__); $cwd = getcwd(); $runtime = null; $exitCode = 0;
try {
    $_SERVER['DOCUMENT_ROOT'] = $target;
    chdir($target . '/functions');
    require_once($target . '/functions/functions.php');
    if ($cwd !== false) chdir($cwd);
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenter.php');
    require_once($target . '/functions/classes/class.IntegrationSecretCipher.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterConfigStore.php');
    require_once($repo . '/layers/phase5f/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php');
    require_once($repo . '/layers/phase5f332/functions/classes/class.IntegrationVmwareVcenterObjectFolders.php');
    $database = new Database_PDO();
    $store = new IntegrationVmwareVcenterConfigStore($database, IntegrationSecretCipher::from_keyring_file());
    $id = $argc === 3 ? (int)$argv[2] : null;
    if ($id === null) {
        $configs = array_values(array_filter($store->list_configs(), static function ($c) { return !empty($c['enabled']) && !empty($c['has_credential']); }));
        if (count($configs) !== 1) throw new RuntimeException('folder_explicit_connector_required');
        $id = (int)$configs[0]['id'];
    }
    $runtime = $store->load_runtime_config($id, true);
    echo "PHASE5F332_PREVIEW_CONNECTOR_ID=$id\n";
    echo 'PHASE5F332_PREVIEW_TLS=' . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . "\n";
    echo "PHASE5F332_PREVIEW_TRANSPORT=DIRECT_NO_PROXY\n";
    echo "PHASE5F332_PREVIEW_SOURCE_INSTALLS=0\nPHASE5F332_PREVIEW_PERSISTENCE=0\nPHASE5F332_PREVIEW_PHPIPAM_MUTATIONS=0\nPHASE5F332_PREVIEW_VCENTER_MUTATIONS=0\n";
    $reader = new IntegrationVmwareVcenterObjectFolders(new IntegrationVmwareVcenterDirectTransport(4194304));
    $inventory = $reader->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], (bool)$runtime['verify_tls']);
    $report = array('ok' => true, 'provider' => 'vmware_vcenter', 'connector_id' => $id, 'mode' => 'phase5f332_vm_datastore_folders_read_only_preview', 'datacenters' => $inventory['datacenters'], 'domains' => array());
    foreach ($inventory['domains'] as $domain => $data) {
        $counts = array('folder' => 0, 'vapp' => 0, 'datastore_cluster' => 0);
        foreach ($data['nodes'] as $node) $counts[$node['kind']]++;
        $prefix = 'PHASE5F332_' . strtoupper($domain);
        echo $prefix . '_ROOTS=' . $data['root_count'] . "\n";
        echo $prefix . '_FOLDERS=' . $counts['folder'] . "\n";
        echo $prefix . '_VAPPS=' . $counts['vapp'] . "\n";
        echo $prefix . '_DATASTORE_CLUSTERS=' . $counts['datastore_cluster'] . "\n";
        echo $prefix . '_OBJECTS_RESOLVED=' . $data['resolved_object_count'] . "\n";
        echo $prefix . '_OBJECTS_UNRESOLVED=' . $data['unresolved_object_count'] . "\n";
        echo $prefix . '_CONTAINERS_UNRESOLVED=' . $data['unresolved_container_count'] . "\n";
        $report['domains'][$domain] = array(
            'counts' => $counts, 'root_count' => $data['root_count'],
            'resolved_object_count' => $data['resolved_object_count'], 'unresolved_object_count' => $data['unresolved_object_count'],
            'nodes' => array_slice($data['nodes'], 0, 256), 'nodes_output_truncated' => count($data['nodes']) > 256,
            'sample_objects' => array_slice($data['objects'], 0, 8),
            'sample_unresolved_objects' => array_slice($data['unresolved_objects'], 0, 8),
            'sample_unresolved_containers' => array_slice($data['unresolved_containers'], 0, 8),
            'relation_evidence' => $data['relation_evidence']
        );
    }
    foreach (array('partial', 'missing_object_count', 'unsupported_edge_count', 'request_count', 'duration_ms', 'property_method', 'persistent_inventory_written', 'phpipam_objects_modified', 'vcenter_objects_modified') as $key) $report[$key] = $inventory[$key];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    echo 'PHASE5F332_PREVIEW_REQUESTS=' . $inventory['request_count'] . "\n";
    echo 'PHASE5F332_PREVIEW_RESULT=' . ($inventory['partial'] ? 'READ_ONLY_COMPATIBILITY_PARTIAL' : 'READ_ONLY_COMPATIBILITY_OK') . "\n";
    if ($inventory['partial']) $exitCode = 3;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $category = preg_match('/^folder_[a-z0-9_]+$/D', $message) ? $message : 'folder_preview_failed';
    fwrite(STDERR, "PHASE5F332_PREVIEW_RESULT=FAILED\nERROR_CATEGORY=$category\nERROR_FINGERPRINT=" . hash('sha256', $message) . "\n");
    $exitCode = 1;
} finally {
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) {
        if (function_exists('sodium_memzero')) sodium_memzero($runtime['password']);
        else $runtime['password'] = str_repeat("\0", strlen($runtime['password']));
    }
    if ($cwd !== false) chdir($cwd);
}
exit($exitCode);
