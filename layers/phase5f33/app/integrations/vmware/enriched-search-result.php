<?php

/**
 * phpIPAM Custom — Phase 5F.3.3 enriched global VM search index endpoint.
 * Builds a bounded transient, read-only search index. Nothing is persisted.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();
header('Content-Type: application/json; charset=UTF-8');

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

function phase5f33_ui_json($payload, $status = 200)
{
    http_response_code((int)$status);
    print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function phase5f33_ui_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f33_ui_lower($value)
{
    $value = (string)$value;
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function phase5f33_ui_category($e)
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

function phase5f33_ui_index_by_id(array $rows)
{
    $out = array();
    foreach ($rows as $row) if (is_array($row) && isset($row['external_id'])) $out[(string)$row['external_id']] = $row;
    return $out;
}

function phase5f33_ui_add(&$parts, $value)
{
    if ($value === null || $value === '') return;
    if (is_array($value)) { foreach ($value as $item) phase5f33_ui_add($parts, $item); return; }
    $parts[] = (string)$value;
}

function phase5f33_ui_search_blob(array $fact, array $topVm, array $dcMap, array $clusterMap, array $hostMap, array $tags)
{
    $parts = array();
    foreach (array('vm_external_id','name','annotation','config_guest_id','config_guest_full_name','guest_guest_id','guest_guest_full_name','guest_guest_family','friendly_guest_os','guest_hostname','primary_ip','hardware_version','bios_uuid','instance_uuid') as $field) {
        if (isset($fact[$field])) phase5f33_ui_add($parts, $fact[$field]);
    }
    foreach (array('mac_addresses','network_names','ip_addresses') as $field) if (isset($fact[$field])) phase5f33_ui_add($parts, $fact[$field]);

    $hostId = isset($fact['host_external_id']) ? $fact['host_external_id'] : (isset($topVm['host_external_id']) ? $topVm['host_external_id'] : null);
    $clusterId = isset($topVm['cluster_external_id']) ? $topVm['cluster_external_id'] : null;
    $dcId = isset($topVm['datacenter_external_id']) ? $topVm['datacenter_external_id'] : null;
    phase5f33_ui_add($parts, $hostId);
    if ($hostId !== null && isset($hostMap[$hostId]['name'])) phase5f33_ui_add($parts, $hostMap[$hostId]['name']);
    phase5f33_ui_add($parts, $clusterId);
    if ($clusterId !== null && isset($clusterMap[$clusterId]['name'])) phase5f33_ui_add($parts, $clusterMap[$clusterId]['name']);
    phase5f33_ui_add($parts, $dcId);
    if ($dcId !== null && isset($dcMap[$dcId]['name'])) phase5f33_ui_add($parts, $dcMap[$dcId]['name']);

    foreach ($tags as $tag) {
        if (!is_array($tag)) continue;
        foreach (array('name','category_name','description','category_description') as $field) if (isset($tag[$field])) phase5f33_ui_add($parts, $tag[$field]);
    }
    return phase5f33_ui_lower(implode("\n", $parts));
}

if (!$User->is_admin(false)) phase5f33_ui_json(array('ok' => false, 'error' => 'administrative_privileges_required'), 403);
$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) phase5f33_ui_json(array('ok' => false, 'error' => 'invalid_csrf'), 403);
$configId = isset($POST->config_id) ? filter_var($POST->config_id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) : false;
if ($configId === false) phase5f33_ui_json(array('ok' => false, 'error' => 'invalid_connector'), 400);

$runtime = null;
try {
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterTopology.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectTransport.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectRestTransport.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterVmSearchFacts.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterVmTagIndex.php");

    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config((int)$configId, true);

    $RestTransport = new IntegrationVmwareVcenterDirectRestTransport(8388608);
    $Topology = new IntegrationVmwareVcenterTopology($RestTransport, 8, 30, 8388608, 10000, 800);
    $topology = $Topology->read_topology($runtime['endpoint'], $runtime['username'], $runtime['password'], !empty($runtime['verify_tls']));

    $vmIds = array();
    foreach ($topology['vms'] as $vm) if (isset($vm['external_id'])) $vmIds[] = (string)$vm['external_id'];
    if (count($vmIds) === 0 || count($vmIds) > 1000) throw new RuntimeException('Topology VM count is outside Phase 5F.3.3 safety bounds');

    $DirectSoap = new IntegrationVmwareVcenterDirectTransport(8388608);
    $FaultAware = new IntegrationVmwareVcenterFaultAwareTransport($DirectSoap);
    $FactsReader = new IntegrationVmwareVcenterVmSearchFacts($FaultAware, 8, 30, 8388608, 1000, 20);
    $facts = $FactsReader->read_facts($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmIds, !empty($runtime['verify_tls']));

    $tagStatus = 'ok';
    $tagIndex = array('tag_count' => 0, 'category_count' => 0, 'vm_with_tags_count' => 0, 'vm_tag_association_count' => 0, 'request_count' => 0, 'duration_ms' => 0, 'vm_tags' => array());
    try {
        $TagReader = new IntegrationVmwareVcenterVmTagIndex(8, 25, 4194304, 500, 800, 50);
        $tagIndex = $TagReader->read_index($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmIds, !empty($runtime['verify_tls']));
    } catch (Exception $tagError) {
        $tagStatus = phase5f33_ui_category($tagError);
    }

    $topVms = phase5f33_ui_index_by_id($topology['vms']);
    $dcMap = phase5f33_ui_index_by_id($topology['datacenters']);
    $clusterMap = phase5f33_ui_index_by_id($topology['clusters']);
    $hostMap = phase5f33_ui_index_by_id($topology['hosts']);
    $rows = array();

    foreach ($facts['rows'] as $vmId => $fact) {
        $topVm = isset($topVms[$vmId]) ? $topVms[$vmId] : array();
        $tags = isset($tagIndex['vm_tags'][$vmId]) && is_array($tagIndex['vm_tags'][$vmId]) ? $tagIndex['vm_tags'][$vmId] : array();
        $hostId = isset($fact['host_external_id']) ? $fact['host_external_id'] : (isset($topVm['host_external_id']) ? $topVm['host_external_id'] : null);
        $clusterId = isset($topVm['cluster_external_id']) ? $topVm['cluster_external_id'] : null;
        $dcId = isset($topVm['datacenter_external_id']) ? $topVm['datacenter_external_id'] : null;
        $memoryMb = isset($fact['memory_mb']) ? (int)$fact['memory_mb'] : 0;
        $rows[] = array(
            'vm_external_id' => (string)$vmId,
            'name' => isset($fact['name']) ? $fact['name'] : (isset($topVm['name']) ? $topVm['name'] : $vmId),
            'power_state' => isset($topVm['power_state']) ? $topVm['power_state'] : null,
            'num_cpu' => isset($fact['num_cpu']) ? $fact['num_cpu'] : null,
            'memory_gib' => $memoryMb > 0 ? round($memoryMb / 1024, 2) : null,
            'friendly_guest_os' => isset($fact['friendly_guest_os']) ? $fact['friendly_guest_os'] : null,
            'guest_hostname' => isset($fact['guest_hostname']) ? $fact['guest_hostname'] : null,
            'primary_ip' => isset($fact['primary_ip']) ? $fact['primary_ip'] : null,
            'hardware_version' => isset($fact['hardware_version']) ? $fact['hardware_version'] : null,
            'datacenter_external_id' => $dcId,
            'datacenter_name' => ($dcId !== null && isset($dcMap[$dcId]['name'])) ? $dcMap[$dcId]['name'] : null,
            'cluster_external_id' => $clusterId,
            'cluster_name' => ($clusterId !== null && isset($clusterMap[$clusterId]['name'])) ? $clusterMap[$clusterId]['name'] : null,
            'host_external_id' => $hostId,
            'host_name' => ($hostId !== null && isset($hostMap[$hostId]['name'])) ? $hostMap[$hostId]['name'] : null,
            'mac_addresses' => isset($fact['mac_addresses']) ? $fact['mac_addresses'] : array(),
            'network_names' => isset($fact['network_names']) ? $fact['network_names'] : array(),
            'ip_addresses' => isset($fact['ip_addresses']) ? $fact['ip_addresses'] : array(),
            'tags' => $tags,
            'search_blob' => phase5f33_ui_search_blob($fact, $topVm, $dcMap, $clusterMap, $hostMap, $tags)
        );
    }

    usort($rows, function ($a, $b) {
        $cmp = strcasecmp((string)$a['name'], (string)$b['name']);
        return $cmp !== 0 ? $cmp : strcmp((string)$a['vm_external_id'], (string)$b['vm_external_id']);
    });

    phase5f33_ui_zero_secret($runtime['password']);
    phase5f33_ui_json(array(
        'ok' => true,
        'provider' => 'vmware_vcenter',
        'mode' => 'phase5f33_enriched_search_transient_index',
        'connector_id' => (int)$configId,
        'verify_tls' => !empty($runtime['verify_tls']),
        'rows' => $rows,
        'row_count' => count($rows),
        'tag_status' => $tagStatus,
        'tag_count' => isset($tagIndex['tag_count']) ? (int)$tagIndex['tag_count'] : 0,
        'category_count' => isset($tagIndex['category_count']) ? (int)$tagIndex['category_count'] : 0,
        'fact_soap_request_count' => isset($facts['soap_request_count']) ? (int)$facts['soap_request_count'] : 0,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    ));
} catch (Exception $e) {
    if (is_array($runtime) && isset($runtime['password'])) phase5f33_ui_zero_secret($runtime['password']);
    phase5f33_ui_json(array(
        'ok' => false,
        'error' => 'enriched_search_unavailable',
        'category' => phase5f33_ui_category($e),
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    ), 502);
}
