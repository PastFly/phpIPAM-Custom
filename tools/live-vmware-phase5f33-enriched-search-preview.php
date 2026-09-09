<?php

/**
 * phpIPAM Custom — Phase 5F.3.3 enriched VM search compatibility preview.
 * READ ONLY. Builds a bounded transient index from topology + bulk VM facts +
 * bulk tag/category associations. Nothing is persisted.
 *
 * Usage:
 *   php tools/live-vmware-phase5f33-enriched-search-preview.php /var/www/html [connector-id] [--query=text ...]
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "ERROR: CLI only.\n"); exit(2); }

$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
$queries = array();
for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if (strpos($arg, '--query=') === 0) {
        $value = trim(substr($arg, strlen('--query=')));
        if ($value === '' || strlen($value) > 256 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) { fwrite(STDERR, "ERROR: invalid --query.\n"); exit(2); }
        $queries[] = $value;
        continue;
    }
    if ($connectorId === null && preg_match('/^[1-9][0-9]*$/', $arg)) { $connectorId = (int)$arg; continue; }
    fwrite(STDERR, "ERROR: unknown or duplicate argument: {$arg}\n"); exit(2);
}
if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) { fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n"); exit(2); }
if (count($queries) === 0) {
    $queries = array('00:50:56:aa:7f:6b', 'Debian GNU/Linux 11');
}
if (count($queries) > 8) { fwrite(STDERR, "ERROR: too many --query values.\n"); exit(2); }

function phase5f33_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f33_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) if (!empty($config['enabled'])) $enabled[] = $config;
    if (count($enabled) === 0) throw new RuntimeException('No enabled VMware connector');
    if (count($enabled) > 1) throw new RuntimeException('More than one enabled VMware connector; pass connector-id explicitly');
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) throw new RuntimeException('Enabled VMware connector has invalid id');
    return (int)$id;
}

function phase5f33_lower($value)
{
    $value = (string)$value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function phase5f33_category($e)
{
    $m = strtolower((string)$e->getMessage());
    if (strpos($m, 'bulk_tag_api_unsupported') !== false || strpos($m, 'bulk_tag_association_unsupported') !== false) return 'unsupported';
    if (strpos($m, 'certificate') !== false || strpos($m, 'ssl') !== false || strpos($m, 'tls') !== false) return 'tls';
    if (strpos($m, 'timeout') !== false || strpos($m, 'timed out') !== false) return 'timeout';
    if (strpos($m, 'authentication') !== false || strpos($m, 'http 401') !== false) return 'authentication';
    if (strpos($m, 'forbidden') !== false || strpos($m, 'permission') !== false || strpos($m, 'http 403') !== false) return 'authorization';
    if (strpos($m, 'network') !== false || strpos($m, 'curl') !== false || strpos($m, 'resolve') !== false || strpos($m, 'connect') !== false) return 'network';
    if ($e instanceof InvalidArgumentException || strpos($m, 'connector') !== false || strpos($m, 'credential') !== false) return 'configuration';
    return 'invalid_response';
}

function phase5f33_index_by_id(array $rows)
{
    $out = array();
    foreach ($rows as $row) if (is_array($row) && isset($row['external_id'])) $out[(string)$row['external_id']] = $row;
    return $out;
}

function phase5f33_add(&$parts, $value)
{
    if ($value === null || $value === '') return;
    if (is_array($value)) { foreach ($value as $item) phase5f33_add($parts, $item); return; }
    $parts[] = (string)$value;
}

function phase5f33_search_blob(array $fact, array $topVm, array $dcMap, array $clusterMap, array $hostMap, array $tags)
{
    $parts = array();
    foreach (array('vm_external_id','name','annotation','config_guest_id','config_guest_full_name','guest_guest_id','guest_guest_full_name','guest_guest_family','friendly_guest_os','guest_hostname','primary_ip','hardware_version','bios_uuid','instance_uuid') as $field) {
        if (isset($fact[$field])) phase5f33_add($parts, $fact[$field]);
    }
    foreach (array('mac_addresses','network_names','ip_addresses') as $field) if (isset($fact[$field])) phase5f33_add($parts, $fact[$field]);

    $hostId = isset($fact['host_external_id']) ? $fact['host_external_id'] : (isset($topVm['host_external_id']) ? $topVm['host_external_id'] : null);
    $clusterId = isset($topVm['cluster_external_id']) ? $topVm['cluster_external_id'] : null;
    $dcId = isset($topVm['datacenter_external_id']) ? $topVm['datacenter_external_id'] : null;
    phase5f33_add($parts, $hostId);
    if ($hostId !== null && isset($hostMap[$hostId]['name'])) phase5f33_add($parts, $hostMap[$hostId]['name']);
    phase5f33_add($parts, $clusterId);
    if ($clusterId !== null && isset($clusterMap[$clusterId]['name'])) phase5f33_add($parts, $clusterMap[$clusterId]['name']);
    phase5f33_add($parts, $dcId);
    if ($dcId !== null && isset($dcMap[$dcId]['name'])) phase5f33_add($parts, $dcMap[$dcId]['name']);

    foreach ($tags as $tag) {
        if (!is_array($tag)) continue;
        if (isset($tag['name'])) phase5f33_add($parts, $tag['name']);
        if (isset($tag['category_name'])) phase5f33_add($parts, $tag['category_name']);
        if (isset($tag['description'])) phase5f33_add($parts, $tag['description']);
    }
    return phase5f33_lower(implode("\n", $parts));
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
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php');

    require_once(__DIR__ . '/../layers/phase5f33/functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php');
    require_once(__DIR__ . '/../layers/phase5f33/functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php');
    require_once(__DIR__ . '/../layers/phase5f33/functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f33_autoselect_connector($Store);
    $runtime = $Store->load_runtime_config($connectorId, true);

    fwrite(STDERR, "PHASE5F33_PREVIEW_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_TLS=" . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_REST_TRANSPORT=DIRECT_NO_PROXY\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_SOAP_TRANSPORT=DIRECT_NO_PROXY\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_PHPIPAM_MUTATIONS=0\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_VCENTER_MUTATIONS=0\n");

    fwrite(STDERR, "PHASE5F33_PREVIEW_STAGE=TOPOLOGY\n");
    $RestTransport = new IntegrationVmwareVcenterDirectRestTransport(8388608);
    $Topology = new IntegrationVmwareVcenterTopology($RestTransport, 8, 30, 8388608, 10000, 800);
    $topology = $Topology->read_topology($runtime['endpoint'], $runtime['username'], $runtime['password'], $runtime['verify_tls']);
    $vmIds = array();
    foreach ($topology['vms'] as $vm) if (isset($vm['external_id'])) $vmIds[] = $vm['external_id'];
    if (count($vmIds) === 0 || count($vmIds) > 1000) throw new RuntimeException('Topology VM count is outside Phase 5F.3.3 safety bounds');
    fwrite(STDERR, "PHASE5F33_PREVIEW_VMS=" . count($vmIds) . "\n");

    fwrite(STDERR, "PHASE5F33_PREVIEW_STAGE=VM_FACTS\n");
    $DirectSoap = new IntegrationVmwareVcenterDirectTransport(8388608);
    $FaultAware = new IntegrationVmwareVcenterFaultAwareTransport($DirectSoap);
    $FactsReader = new IntegrationVmwareVcenterVmSearchFacts($FaultAware, 8, 30, 8388608, 1000, 20);
    $facts = $FactsReader->read_facts($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmIds, $runtime['verify_tls']);
    fwrite(STDERR, "PHASE5F33_PREVIEW_FACT_ROWS=" . (int)$facts['vm_count'] . "\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_FACT_SOAP_REQUESTS=" . (int)$facts['soap_request_count'] . "\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_FACT_DURATION_MS=" . (int)$facts['duration_ms'] . "\n");

    $tagStatus = 'OK';
    $tagIndex = array('tag_count' => 0, 'category_count' => 0, 'vm_with_tags_count' => 0, 'vm_tag_association_count' => 0, 'request_count' => 0, 'duration_ms' => 0, 'vm_tags' => array());
    fwrite(STDERR, "PHASE5F33_PREVIEW_STAGE=TAGS\n");
    try {
        $TagReader = new IntegrationVmwareVcenterVmTagIndex(8, 25, 4194304, 500, 800, 50);
        $tagIndex = $TagReader->read_index($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmIds, $runtime['verify_tls']);
    } catch (Exception $tagError) {
        $tagStatus = strtoupper(phase5f33_category($tagError));
        fwrite(STDERR, "PHASE5F33_PREVIEW_TAG_ERROR_CATEGORY={$tagStatus}\n");
        fwrite(STDERR, "PHASE5F33_PREVIEW_TAG_ERROR_SHA256=" . hash('sha256', (string)$tagError->getMessage()) . "\n");
    }
    fwrite(STDERR, "PHASE5F33_PREVIEW_TAG_STATUS={$tagStatus}\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_TAGS=" . (int)$tagIndex['tag_count'] . "\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_TAG_CATEGORIES=" . (int)$tagIndex['category_count'] . "\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_VMS_WITH_TAGS=" . (int)$tagIndex['vm_with_tags_count'] . "\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_VM_TAG_ASSOCIATIONS=" . (int)$tagIndex['vm_tag_association_count'] . "\n");
    fwrite(STDERR, "PHASE5F33_PREVIEW_TAG_REQUESTS=" . (int)$tagIndex['request_count'] . "\n");

    $topVms = phase5f33_index_by_id($topology['vms']);
    $dcMap = phase5f33_index_by_id($topology['datacenters']);
    $clusterMap = phase5f33_index_by_id($topology['clusters']);
    $hostMap = phase5f33_index_by_id($topology['hosts']);

    $querySummary = array();
    foreach ($queries as $query) {
        $needle = phase5f33_lower($query);
        $matches = array();
        foreach ($facts['rows'] as $vmId => $fact) {
            $tags = isset($tagIndex['vm_tags'][$vmId]) && is_array($tagIndex['vm_tags'][$vmId]) ? $tagIndex['vm_tags'][$vmId] : array();
            $topVm = isset($topVms[$vmId]) ? $topVms[$vmId] : array();
            if (strpos(phase5f33_search_blob($fact, $topVm, $dcMap, $clusterMap, $hostMap, $tags), $needle) === false) continue;
            $matches[] = array('vm_external_id' => $vmId, 'name' => isset($fact['name']) ? $fact['name'] : null, 'tag_count' => count($tags));
            if (count($matches) >= 10) break;
        }
        $querySummary[] = array('query' => $query, 'shown_matches' => $matches, 'shown_match_count' => count($matches));
        fwrite(STDERR, "PHASE5F33_PREVIEW_QUERY_SHA256=" . hash('sha256', $query) . " MATCHES_SHOWN=" . count($matches) . "\n");
    }

    $samples = array();
    foreach (array('vm-11151011','vm-11147212','vm-20') as $sampleId) {
        if (!isset($facts['rows'][$sampleId])) continue;
        $row = $facts['rows'][$sampleId];
        $samples[] = array(
            'vm_external_id' => $sampleId,
            'name' => isset($row['name']) ? $row['name'] : null,
            'friendly_guest_os' => isset($row['friendly_guest_os']) ? $row['friendly_guest_os'] : null,
            'annotation_present' => !empty($row['annotation']),
            'mac_addresses' => isset($row['mac_addresses']) ? $row['mac_addresses'] : array(),
            'network_names' => isset($row['network_names']) ? $row['network_names'] : array(),
            'tag_count' => isset($tagIndex['vm_tags'][$sampleId]) ? count($tagIndex['vm_tags'][$sampleId]) : 0
        );
    }

    $payload = array(
        'ok' => $tagStatus === 'OK',
        'provider' => 'vmware_vcenter',
        'mode' => 'phase5f33_enriched_search_read_only_preview',
        'connector_id' => $connectorId,
        'verify_tls' => !empty($runtime['verify_tls']),
        'vm_count' => count($vmIds),
        'fact_count' => (int)$facts['vm_count'],
        'fact_soap_request_count' => (int)$facts['soap_request_count'],
        'tag_status' => $tagStatus,
        'tag_count' => (int)$tagIndex['tag_count'],
        'category_count' => (int)$tagIndex['category_count'],
        'vm_with_tags_count' => (int)$tagIndex['vm_with_tags_count'],
        'vm_tag_association_count' => (int)$tagIndex['vm_tag_association_count'],
        'tag_request_count' => (int)$tagIndex['request_count'],
        'queries' => $querySummary,
        'samples' => $samples,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    );
    print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    if ($tagStatus !== 'OK') {
        fwrite(STDERR, "PHASE5F33_PREVIEW_RESULT=CORE_COMPATIBILITY_OK_TAGS_PENDING\n");
        exit(3);
    }
    fwrite(STDERR, "PHASE5F33_PREVIEW_RESULT=READ_ONLY_COMPATIBILITY_OK\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F33_PREVIEW_RESULT=FAILED\n");
    fwrite(STDERR, "ERROR_CATEGORY=" . phase5f33_category($e) . "\n");
    fwrite(STDERR, "ERROR_CLASS=" . get_class($e) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE_SHA256=" . hash('sha256', (string)$e->getMessage()) . "\n");
    exit(1);
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f33_zero_secret($runtime['password']);
    if ($originalCwd !== false) @chdir($originalCwd);
}
