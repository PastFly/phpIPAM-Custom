<?php

/**
 * phpIPAM Custom — Phase 5E VMware workspace result.
 * Transient read-only topology/datastore projection; no persistence or mutation.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

header('Content-Type: application/json; charset=UTF-8');

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

// PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_RESULT_V1
// PHPIPAM_CUSTOM_VMWARE_PHASE5E1_TLS_SETTINGS_GIB_V1

function phase5e_result_json($payload, $status = 200)
{
    http_response_code((int)$status);
    print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$User->is_admin(false)) {
    phase5e_result_json(array('ok' => false, 'error' => 'administrative_privileges_required'), 403);
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    phase5e_result_json(array('ok' => false, 'error' => 'invalid_csrf'), 403);
}

function phase5e_result_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5e_result_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5e_result_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) return 'tls';
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) return 'timeout';
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) return 'authentication';
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) return 'authorization';
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) return 'network';
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'safety limit') !== false || strpos($message, 'unknown external id') !== false) return 'invalid_response';
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) return 'configuration';
    return 'unknown';
}

function phase5e_result_name_sort(&$rows)
{
    usort($rows, function ($left, $right) {
        $a = isset($left['name']) ? (string)$left['name'] : '';
        $b = isset($right['name']) ? (string)$right['name'] : '';
        $cmp = strcasecmp($a, $b);
        if ($cmp !== 0) return $cmp;
        return strcmp((string)$left['external_id'], (string)$right['external_id']);
    });
}

function phase5e_result_scope_link($scope, $id, $name, $icon, $badge = null, $selected = false)
{
    $class = 'phase5e-tree-node phase5e-scope-link';
    $liClass = $selected ? ' class="active"' : '';
    $html = '<li' . $liClass . '><a href="#" class="' . $class . '" data-scope="' . phase5e_result_h($scope) . '" data-scope-id="' . phase5e_result_h($id) . '">';
    $html .= '<i class="fa ' . phase5e_result_h($icon) . '"></i> ' . phase5e_result_h($name);
    if ($badge !== null) $html .= ' <span class="badge">' . (int)$badge . '</span>';
    $html .= '</a>';
    return $html;
}

function phase5e_result_render_tree($projection, $scope, $scopeId)
{
    $vmByHost = array();
    $hostsByCluster = array();
    $hostsByDcStandalone = array();
    $clustersByDc = array();

    foreach ($projection['vms'] as $vm) {
        $hostId = isset($vm['host_external_id']) ? $vm['host_external_id'] : null;
        if ($hostId !== null) {
            if (!isset($vmByHost[$hostId])) $vmByHost[$hostId] = 0;
            $vmByHost[$hostId]++;
        }
    }

    foreach ($projection['clusters'] as $cluster) {
        $dcId = isset($cluster['datacenter_external_id']) ? $cluster['datacenter_external_id'] : null;
        if ($dcId !== null) {
            if (!isset($clustersByDc[$dcId])) $clustersByDc[$dcId] = array();
            $clustersByDc[$dcId][] = $cluster;
        }
    }
    foreach ($clustersByDc as &$clusterRows) phase5e_result_name_sort($clusterRows);
    unset($clusterRows);

    foreach ($projection['hosts'] as $host) {
        $dcId = isset($host['datacenter_external_id']) ? $host['datacenter_external_id'] : null;
        $clusterId = isset($host['cluster_external_id']) ? $host['cluster_external_id'] : null;
        if ($clusterId !== null) {
            if (!isset($hostsByCluster[$clusterId])) $hostsByCluster[$clusterId] = array();
            $hostsByCluster[$clusterId][] = $host;
        } elseif ($dcId !== null) {
            if (!isset($hostsByDcStandalone[$dcId])) $hostsByDcStandalone[$dcId] = array();
            $hostsByDcStandalone[$dcId][] = $host;
        }
    }
    foreach ($hostsByCluster as &$hostRows) phase5e_result_name_sort($hostRows);
    unset($hostRows);
    foreach ($hostsByDcStandalone as &$hostRows) phase5e_result_name_sort($hostRows);
    unset($hostRows);

    $datacenters = array_values($projection['datacenters']);
    phase5e_result_name_sort($datacenters);

    $html = '<div class="phase5e-tree"><ul class="nav nav-pills nav-stacked">';
    foreach ($datacenters as $dc) {
        $dcId = $dc['external_id'];
        $dcVmCount = 0;
        foreach ($projection['vms'] as $vm) {
            if (isset($vm['datacenter_external_id']) && $vm['datacenter_external_id'] === $dcId) $dcVmCount++;
        }
        $html .= phase5e_result_scope_link('datacenter', $dcId, $dc['name'], 'fa-building-o', $dcVmCount, $scope === 'datacenter' && $scopeId === $dcId);
        $html .= '<ul class="nav nav-pills nav-stacked">';

        if (isset($clustersByDc[$dcId])) {
            foreach ($clustersByDc[$dcId] as $cluster) {
                $clusterId = $cluster['external_id'];
                $clusterVmCount = 0;
                foreach ($projection['vms'] as $vm) {
                    if (isset($vm['cluster_external_id']) && $vm['cluster_external_id'] === $clusterId) $clusterVmCount++;
                }
                $html .= phase5e_result_scope_link('cluster', $clusterId, $cluster['name'], 'fa-cubes', $clusterVmCount, $scope === 'cluster' && $scopeId === $clusterId);
                $html .= '<ul class="nav nav-pills nav-stacked">';
                if (isset($hostsByCluster[$clusterId])) {
                    foreach ($hostsByCluster[$clusterId] as $host) {
                        $hostId = $host['external_id'];
                        $html .= phase5e_result_scope_link('host', $hostId, $host['name'], 'fa-server', isset($vmByHost[$hostId]) ? $vmByHost[$hostId] : 0, $scope === 'host' && $scopeId === $hostId);
                        $html .= '</li>';
                    }
                }
                $html .= '</ul></li>';
            }
        }

        if (isset($hostsByDcStandalone[$dcId]) && count($hostsByDcStandalone[$dcId]) > 0) {
            $html .= '<li><span class="phase5e-tree-node text-muted"><i class="fa fa-unlink"></i> Standalone / unknown cluster</span><ul class="nav nav-pills nav-stacked">';
            foreach ($hostsByDcStandalone[$dcId] as $host) {
                $hostId = $host['external_id'];
                $html .= phase5e_result_scope_link('host', $hostId, $host['name'], 'fa-server', isset($vmByHost[$hostId]) ? $vmByHost[$hostId] : 0, $scope === 'host' && $scopeId === $hostId);
                $html .= '</li>';
            }
            $html .= '</ul></li>';
        }

        $html .= '</ul></li>';
    }
    $html .= '</ul></div>';
    return $html;
}

function phase5e_result_scope_dc_id($projection, $scope, $scopeId)
{
    if ($scope === 'datacenter') return $scopeId;
    if ($scope === 'cluster' && isset($projection['clusters'][$scopeId])) {
        return isset($projection['clusters'][$scopeId]['datacenter_external_id']) ? $projection['clusters'][$scopeId]['datacenter_external_id'] : null;
    }
    if ($scope === 'host' && isset($projection['hosts'][$scopeId])) {
        return isset($projection['hosts'][$scopeId]['datacenter_external_id']) ? $projection['hosts'][$scopeId]['datacenter_external_id'] : null;
    }
    return null;
}

function phase5e_result_render_breadcrumb($projection, $scope, $scopeId)
{
    $parts = array();
    if ($scope === 'datacenter' && isset($projection['datacenters'][$scopeId])) {
        $parts[] = array('scope' => 'datacenter', 'id' => $scopeId, 'name' => $projection['datacenters'][$scopeId]['name']);
    } elseif ($scope === 'cluster' && isset($projection['clusters'][$scopeId])) {
        $cluster = $projection['clusters'][$scopeId];
        $dcId = isset($cluster['datacenter_external_id']) ? $cluster['datacenter_external_id'] : null;
        if ($dcId !== null && isset($projection['datacenters'][$dcId])) $parts[] = array('scope' => 'datacenter', 'id' => $dcId, 'name' => $projection['datacenters'][$dcId]['name']);
        $parts[] = array('scope' => 'cluster', 'id' => $scopeId, 'name' => $cluster['name']);
    } elseif ($scope === 'host' && isset($projection['hosts'][$scopeId])) {
        $host = $projection['hosts'][$scopeId];
        $dcId = isset($host['datacenter_external_id']) ? $host['datacenter_external_id'] : null;
        $clusterId = isset($host['cluster_external_id']) ? $host['cluster_external_id'] : null;
        if ($dcId !== null && isset($projection['datacenters'][$dcId])) $parts[] = array('scope' => 'datacenter', 'id' => $dcId, 'name' => $projection['datacenters'][$dcId]['name']);
        if ($clusterId !== null && isset($projection['clusters'][$clusterId])) $parts[] = array('scope' => 'cluster', 'id' => $clusterId, 'name' => $projection['clusters'][$clusterId]['name']);
        $parts[] = array('scope' => 'host', 'id' => $scopeId, 'name' => $host['name']);
    }

    if (count($parts) === 0) return '';
    $html = '<ol class="breadcrumb phase5e-breadcrumb">';
    foreach ($parts as $index => $part) {
        $isLast = $index === count($parts) - 1;
        if ($isLast) {
            $html .= '<li class="active">' . phase5e_result_h($part['name']) . '</li>';
        } else {
            $html .= '<li><a href="#" class="phase5e-scope-link" data-scope="' . phase5e_result_h($part['scope']) . '" data-scope-id="' . phase5e_result_h($part['id']) . '">' . phase5e_result_h($part['name']) . '</a></li>';
        }
    }
    $html .= '</ol>';
    return $html;
}

function phase5e_result_render_metrics($projection)
{
    $metrics = array(
        array('label' => 'Datacenters', 'value' => $projection['counts']['datacenters'], 'icon' => 'fa-building-o'),
        array('label' => 'Clusters', 'value' => $projection['counts']['clusters'], 'icon' => 'fa-cubes'),
        array('label' => 'ESXi Hosts', 'value' => $projection['counts']['hosts'], 'icon' => 'fa-server'),
        array('label' => 'VMs', 'value' => $projection['counts']['vms'], 'icon' => 'fa-cube'),
        array('label' => 'Datastores', 'value' => $projection['counts']['datastores'], 'icon' => 'fa-database')
    );
    $html = '<div class="row">';
    foreach ($metrics as $metric) {
        $html .= '<div class="col-lg-2 col-md-4 col-sm-4 col-xs-6"><div class="panel panel-default"><div class="panel-body">';
        $html .= '<div class="text-muted"><i class="fa ' . phase5e_result_h($metric['icon']) . '"></i> ' . phase5e_result_h($metric['label']) . '</div>';
        $html .= '<strong style="font-size:20px;">' . (int)$metric['value'] . '</strong>';
        $html .= '</div></div></div>';
    }
    $html .= '</div>';
    return $html;
}

function phase5e_result_render_overview($projection, $topology)
{
    $html = phase5e_result_render_metrics($projection);
    if ((int)$topology['unresolved_host_cluster_count'] > 0 || (int)$topology['unresolved_vm_cluster_count'] > 0) {
        $html .= '<div class="alert alert-info"><strong>Standalone / not cluster-assigned:</strong> hosts=' . (int)$topology['unresolved_host_cluster_count'] . ', VMs=' . (int)$topology['unresolved_vm_cluster_count'] . '. Placement по именам не угадывается.</div>';
    }
    $html .= '<div class="table-responsive"><table class="table table-hover table-condensed phase5e-table"><thead><tr><th>Datacenter</th><th>External ID</th><th>Clusters</th><th>Hosts</th><th>VMs</th><th>Datastores</th></tr></thead><tbody>';
    foreach ($projection['overview'] as $row) {
        $html .= '<tr><td><a href="#" class="phase5e-scope-link" data-scope="datacenter" data-scope-id="' . phase5e_result_h($row['external_id']) . '"><strong>' . phase5e_result_h($row['name']) . '</strong></a></td>';
        $html .= '<td><code class="phase5e-id">' . phase5e_result_h($row['external_id']) . '</code></td>';
        $html .= '<td>' . (int)$row['cluster_count'] . '</td><td>' . (int)$row['host_count'] . '</td><td>' . (int)$row['vm_count'] . '</td><td>' . (int)$row['datastore_count'] . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function phase5e_result_memory_gib($value)
{
    return $value === null ? '—' : number_format(((float)$value) / 1024, 2, '.', ' ');
}

function phase5e_result_render_scope_cell($scope, $id, $name)
{
    if ($id === null || $name === null) return '—';
    return '<a href="#" class="phase5e-scope-link" data-scope="' . phase5e_result_h($scope) . '" data-scope-id="' . phase5e_result_h($id) . '">' . phase5e_result_h($name) . '</a>';
}

function phase5e_result_render_pagination($page, $pageCount)
{
    if ($pageCount <= 1) return '';
    $start = max(1, $page - 3);
    $end = min($pageCount, $page + 3);
    $html = '<div class="text-center"><ul class="pagination pagination-sm">';
    for ($p = $start; $p <= $end; $p++) {
        $class = $p === $page ? ' class="active"' : '';
        $html .= '<li' . $class . '><a href="#" class="phase5e-page-link" data-page="' . (int)$p . '">' . (int)$p . '</a></li>';
    }
    $html .= '</ul></div>';
    return $html;
}

function phase5e_result_render_vms($projection, $scope, $scopeId, $search, $page, $pageSize)
{
    $Explorer = new IntegrationVmwareVcenterExplorer(100);
    $vmPage = $Explorer->list_vms($projection, array(
        'scope' => $scope,
        'scope_id' => $scopeId,
        'search' => $search,
        'page' => $page,
        'page_size' => $pageSize
    ));

    $html = phase5e_result_render_breadcrumb($projection, $scope, $scopeId);
    $html .= '<div class="text-muted" style="margin-bottom:8px;">Найдено VM: ' . (int)$vmPage['total'] . '</div>';
    $html .= '<div class="table-responsive"><table class="table table-hover table-condensed phase5e-table"><thead><tr><th>VM</th><th>Power</th><th>CPU</th><th>Memory GiB</th><th>Datacenter</th><th>Cluster</th><th>ESXi Host</th></tr></thead><tbody>';
    foreach ($vmPage['items'] as $vm) {
        $power = $vm['power_state'] === null ? '—' : $vm['power_state'];
        $powerClass = $power === 'powered_on' ? 'label label-success' : 'label label-default';
        $html .= '<tr class="phase5e-vm-row">';
        $html .= '<td><a href="#" class="phase5e-vm-link" data-vm-id="' . phase5e_result_h($vm['external_id']) . '"><strong>' . phase5e_result_h($vm['name']) . '</strong></a><br><code class="phase5e-id">' . phase5e_result_h($vm['external_id']) . '</code></td>';
        $html .= '<td><span class="' . $powerClass . '">' . phase5e_result_h($power) . '</span></td>';
        $html .= '<td>' . ($vm['cpu_count'] === null ? '—' : (int)$vm['cpu_count']) . '</td>';
        $html .= '<td>' . phase5e_result_h(phase5e_result_memory_gib($vm['memory_size_mib'])) . '</td>';
        $html .= '<td>' . phase5e_result_render_scope_cell('datacenter', $vm['datacenter_external_id'], $vm['datacenter_name']) . '</td>';
        if ($vm['cluster_external_id'] === null) $html .= '<td><span class="text-muted">Standalone / unknown</span></td>';
        else $html .= '<td>' . phase5e_result_render_scope_cell('cluster', $vm['cluster_external_id'], $vm['cluster_name']) . '</td>';
        $html .= '<td>' . phase5e_result_render_scope_cell('host', $vm['host_external_id'], $vm['host_name']) . '</td>';
        $html .= '</tr>';
    }
    if (count($vmPage['items']) === 0) $html .= '<tr><td colspan="7" class="text-muted">VM по текущему фильтру не найдены.</td></tr>';
    $html .= '</tbody></table></div>';
    $html .= phase5e_result_render_pagination($vmPage['page'], $vmPage['page_count']);
    return $html;
}

function phase5e_result_gib($bytes)
{
    if ($bytes === null) return '—';
    return number_format(((float)$bytes) / 1073741824, 2, '.', ' ') . ' GiB';
}

function phase5e_result_render_datastores($projection, $scope, $scopeId, $search, $page, $pageSize)
{
    $filterDc = phase5e_result_scope_dc_id($projection, $scope, $scopeId);
    $needle = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
    $rows = array();
    foreach ($projection['datastores'] as $row) {
        $dcId = isset($row['datacenter_external_id']) ? $row['datacenter_external_id'] : null;
        if ($filterDc !== null && $dcId !== $filterDc) continue;
        if ($search !== '') {
            $haystack = (string)$row['name'] . ' ' . (string)$row['external_id'];
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            if (strpos($haystack, $needle) === false) continue;
        }
        $rows[] = $row;
    }
    phase5e_result_name_sort($rows);
    $total = count($rows);
    $pageCount = $total === 0 ? 0 : (int)ceil($total / $pageSize);
    $offset = ($page - 1) * $pageSize;
    $items = $offset < $total ? array_slice($rows, $offset, $pageSize) : array();

    $html = phase5e_result_render_breadcrumb($projection, $scope, $scopeId);
    $html .= '<div class="text-muted" style="margin-bottom:8px;">Найдено datastore: ' . (int)$total . '</div>';
    $html .= '<div class="table-responsive"><table class="table table-hover table-condensed phase5e-table"><thead><tr><th>Datastore</th><th>External ID</th><th>Type</th><th>Capacity</th><th>Free</th><th>Datacenter</th></tr></thead><tbody>';
    foreach ($items as $row) {
        $dcId = isset($row['datacenter_external_id']) ? $row['datacenter_external_id'] : null;
        $dcName = $dcId !== null && isset($projection['datacenters'][$dcId]) ? $projection['datacenters'][$dcId]['name'] : null;
        $html .= '<tr><td><a href="#" class="phase5e-datastore-link" data-datastore-id="' . phase5e_result_h($row['external_id']) . '"><strong>' . phase5e_result_h($row['name']) . '</strong></a></td>';
        $html .= '<td><code class="phase5e-id">' . phase5e_result_h($row['external_id']) . '</code></td>';
        $html .= '<td>' . phase5e_result_h(isset($row['type']) && $row['type'] !== null ? $row['type'] : '—') . '</td>';
        $html .= '<td>' . phase5e_result_h(phase5e_result_gib(isset($row['capacity_bytes']) ? $row['capacity_bytes'] : null)) . '</td>';
        $html .= '<td>' . phase5e_result_h(phase5e_result_gib(isset($row['free_space_bytes']) ? $row['free_space_bytes'] : null)) . '</td>';
        $html .= '<td>' . phase5e_result_render_scope_cell('datacenter', $dcId, $dcName) . '</td></tr>';
    }
    if (count($items) === 0) $html .= '<tr><td colspan="6" class="text-muted">Datastore по текущему фильтру не найдены.</td></tr>';
    $html .= '</tbody></table></div>';
    $html .= phase5e_result_render_pagination($page, $pageCount);
    return $html;
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) phase5e_result_json(array('ok' => false, 'error' => 'invalid_connector_id'), 400);
$configId = (int)$configId;

$view = isset($POST->view) ? trim((string)$POST->view) : 'overview';
if (!in_array($view, array('overview', 'vms', 'datastores'), true)) phase5e_result_json(array('ok' => false, 'error' => 'unsupported_view'), 400);

$scope = isset($POST->scope) ? trim((string)$POST->scope) : '';
$scopeId = isset($POST->scope_id) ? trim((string)$POST->scope_id) : '';
if ($scope !== '' && !in_array($scope, array('datacenter', 'cluster', 'host'), true)) phase5e_result_json(array('ok' => false, 'error' => 'unsupported_scope'), 400);
if ($scope === '') $scopeId = '';
if ($scopeId !== '' && (strlen($scopeId) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $scopeId))) phase5e_result_json(array('ok' => false, 'error' => 'invalid_scope_id'), 400);

$search = isset($POST->search) ? trim((string)$POST->search) : '';
if (strlen($search) > 256 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $search)) phase5e_result_json(array('ok' => false, 'error' => 'invalid_search'), 400);

$pageRaw = isset($POST->page) ? trim((string)$POST->page) : '1';
$pageSizeRaw = isset($POST->page_size) ? trim((string)$POST->page_size) : '50';
if (!preg_match('/^[1-9][0-9]*$/', $pageRaw) || !preg_match('/^[1-9][0-9]*$/', $pageSizeRaw)) phase5e_result_json(array('ok' => false, 'error' => 'invalid_pagination'), 400);
$page = (int)$pageRaw;
$pageSize = (int)$pageSizeRaw;
if ($pageSize > 100) phase5e_result_json(array('ok' => false, 'error' => 'page_size_too_large'), 400);

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterTopology.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDatastore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterExplorer.php");

$runtime = null;
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    $Topology = new IntegrationVmwareVcenterTopology();
    $topology = $Topology->read_topology($runtime['endpoint'], $runtime['username'], $runtime['password'], $runtime['verify_tls']);
    $dcIds = array();
    foreach ($topology['datacenters'] as $dc) $dcIds[] = $dc['external_id'];

    $Datastore = new IntegrationVmwareVcenterDatastore();
    $datastoreInventory = $Datastore->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], $dcIds, $runtime['verify_tls']);
    $Explorer = new IntegrationVmwareVcenterExplorer(100);
    $projection = $Explorer->build($topology, $datastoreInventory);

    if ($scope !== '') {
        $map = $scope === 'datacenter' ? $projection['datacenters'] : ($scope === 'cluster' ? $projection['clusters'] : $projection['hosts']);
        if (!isset($map[$scopeId])) phase5e_result_json(array('ok' => false, 'error' => 'unknown_scope_id'), 400);
    }

    $treeHtml = phase5e_result_render_tree($projection, $scope, $scopeId);
    if ($view === 'overview') $contentHtml = phase5e_result_render_overview($projection, $topology);
    elseif ($view === 'datastores') $contentHtml = phase5e_result_render_datastores($projection, $scope, $scopeId, $search, $page, $pageSize);
    else $contentHtml = phase5e_result_render_vms($projection, $scope, $scopeId, $search, $page, $pageSize);

    $summaryClass = !empty($runtime['verify_tls']) ? 'alert alert-info' : 'alert alert-warning';
    $summaryHtml = '<div class="' . $summaryClass . '"><strong>VMware Explorer:</strong> connector #' . (int)$configId . '; API=modern; TLS=' . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . '. Результат существует только в памяти текущего запроса.';
    if (empty($runtime['verify_tls'])) $summaryHtml .= ' TLS mode взят из сохранённых настроек connector; scheduler запрещён.';
    $summaryHtml .= '</div>';
    $summaryHtml .= '<div class="text-muted phase5e-safety-note">persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div>';

    phase5e_result_zero_secret($runtime['password']);
    $runtime['password'] = '';

    phase5e_result_json(array(
        'ok' => true,
        'tree_html' => $treeHtml,
        'content_html' => $contentHtml,
        'summary_html' => $summaryHtml,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    ));
} catch (Exception $e) {
    if (is_array($runtime) && isset($runtime['password'])) phase5e_result_zero_secret($runtime['password']);
    phase5e_result_json(array('ok' => false, 'error' => phase5e_result_error_category($e)), 500);
}
