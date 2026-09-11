<?php

/**
 * phpIPAM Custom — Phase 5D administrator-only VMware Explorer result.
 * Live read-only topology/datastore inventory; no result persistence.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

if (!$User->is_admin(false)) {
    http_response_code(403);
    print '<div class="alert alert-danger">Administrative privileges required.</div>';
    exit;
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie("validate", "integration_vmware_vcenter_explorer", $csrf) === false) {
    http_response_code(403);
    print '<div class="alert alert-danger">Invalid CSRF cookie.</div>';
    exit;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_RESULT_V1

function phase5d_explorer_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5d_explorer_zero_secret(&$value)
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

function phase5d_explorer_error_category($error)
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
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) {
        return 'network';
    }
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'safety limit') !== false || strpos($message, 'unknown external id') !== false) {
        return 'invalid_response';
    }
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) {
        return 'configuration';
    }
    return 'unknown';
}

function phase5d_explorer_post_bool($post, $field)
{
    $value = isset($post->{$field}) ? (string)$post->{$field} : '0';
    if ($value !== '0' && $value !== '1') {
        throw new InvalidArgumentException('Invalid boolean field');
    }
    return $value === '1';
}

function phase5d_explorer_bytes_mib($value)
{
    return $value === null ? '—' : number_format((int)$value, 0, '.', ' ');
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    print '<div class="alert alert-danger">Invalid VMware connector id.</div>';
    exit;
}
$configId = (int)$configId;

$view = isset($POST->view) ? trim((string)$POST->view) : 'overview';
if (!in_array($view, array('overview', 'vms'), true)) {
    print '<div class="alert alert-danger">Unsupported Explorer view.</div>';
    exit;
}

$scope = isset($POST->scope) ? trim((string)$POST->scope) : '';
$scopeId = isset($POST->scope_id) ? trim((string)$POST->scope_id) : '';
$search = isset($POST->search) ? trim((string)$POST->search) : '';
$pageRaw = isset($POST->page) ? trim((string)$POST->page) : '1';
$pageSizeRaw = isset($POST->page_size) ? trim((string)$POST->page_size) : '50';
if (!preg_match('/^[1-9][0-9]*$/', $pageRaw) || !preg_match('/^[1-9][0-9]*$/', $pageSizeRaw)) {
    print '<div class="alert alert-danger">Invalid Explorer pagination.</div>';
    exit;
}
$page = (int)$pageRaw;
$pageSize = (int)$pageSizeRaw;
if ($pageSize > 100) {
    print '<div class="alert alert-danger">Explorer page size may not exceed 100.</div>';
    exit;
}

try {
    $allowInsecureTls = phase5d_explorer_post_bool($POST, 'allow_insecure_tls');
} catch (Exception $e) {
    print '<div class="alert alert-danger">Invalid TLS acknowledgement field.</div>';
    exit;
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterTopology.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDatastore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterExplorer.php");

$runtime = null;
ob_start();
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    if (empty($runtime['verify_tls']) && !$allowInsecureTls) {
        print '<div class="alert alert-warning"><strong>Explorer blocked:</strong> сохранённый connector использует отключённую TLS verification. Для ручного read-only запуска требуется явное подтверждение.</div>';
    } else {
        $Topology = new IntegrationVmwareVcenterTopology();
        $topology = $Topology->read_topology($runtime['endpoint'], $runtime['username'], $runtime['password'], $runtime['verify_tls']);

        $dcIds = array();
        foreach ($topology['datacenters'] as $dc) {
            $dcIds[] = $dc['external_id'];
        }
        $Datastore = new IntegrationVmwareVcenterDatastore();
        $datastoreInventory = $Datastore->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], $dcIds, $runtime['verify_tls']);

        $Explorer = new IntegrationVmwareVcenterExplorer(100);
        $projection = $Explorer->build($topology, $datastoreInventory);

        print '<div class="alert alert-info"><strong>VMware Explorer:</strong> connector #' . (int)$configId . '; API=modern; TLS=' . (!empty($runtime['verify_tls']) ? 'VERIFY' : 'INSECURE') . '. Результат существует только в памяти текущего запроса.</div>';
        print '<div class="row">';
        $metrics = array('Datacenters' => $projection['counts']['datacenters'], 'Clusters' => $projection['counts']['clusters'], 'ESXi Hosts' => $projection['counts']['hosts'], 'VMs' => $projection['counts']['vms'], 'Datastores' => $projection['counts']['datastores']);
        foreach ($metrics as $label => $value) {
            print '<div class="col-sm-2"><div class="well well-sm" style="margin-bottom:10px;"><strong>' . phase5d_explorer_h($label) . '</strong><br><span style="font-size:22px;">' . (int)$value . '</span></div></div>';
        }
        print '</div>';

        if ((int)$topology['unresolved_host_cluster_count'] > 0 || (int)$topology['unresolved_vm_cluster_count'] > 0) {
            print '<div class="alert alert-default" style="border:1px solid #777;">';
            print '<strong>Standalone / not cluster-assigned:</strong> hosts=' . (int)$topology['unresolved_host_cluster_count'] . ', VMs=' . (int)$topology['unresolved_vm_cluster_count'] . '. ';
            print 'Это не считается ошибкой, если Datacenter/Host placement определён. Unknown relations не угадываются.';
            print '</div>';
        }

        if ($view === 'overview') {
            print '<h4><i class="fa fa-building-o"></i> Datacenters</h4>';
            print '<div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>Datacenter</th><th>External ID</th><th>Clusters</th><th>Hosts</th><th>VMs</th><th>Datastores</th><th></th></tr></thead><tbody>';
            foreach ($projection['overview'] as $row) {
                print '<tr>';
                print '<td><strong>' . phase5d_explorer_h($row['name']) . '</strong></td>';
                print '<td><code>' . phase5d_explorer_h($row['external_id']) . '</code></td>';
                print '<td>' . (int)$row['cluster_count'] . '</td><td>' . (int)$row['host_count'] . '</td><td>' . (int)$row['vm_count'] . '</td><td>' . (int)$row['datastore_count'] . '</td>';
                print '<td><button type="button" class="btn btn-xs btn-default phase5d-scope-link" data-scope="datacenter" data-scope-id="' . phase5d_explorer_h($row['external_id']) . '"><i class="fa fa-list"></i> VMs</button></td>';
                print '</tr>';
            }
            print '</tbody></table></div>';
        } else {
            $filters = array('scope' => $scope, 'scope_id' => $scopeId, 'search' => $search, 'page' => $page, 'page_size' => $pageSize);
            $vmPage = $Explorer->list_vms($projection, $filters);

            print '<h4><i class="fa fa-server"></i> Virtual Machines <small>' . (int)$vmPage['total'] . ' found</small></h4>';
            if ($scope !== '' && $scopeId !== '') {
                print '<div class="text-muted" style="margin-bottom:8px;">Scope: ' . phase5d_explorer_h($scope) . ' / <code>' . phase5d_explorer_h($scopeId) . '</code></div>';
            }
            print '<div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>VM</th><th>Power</th><th>CPU</th><th>Memory MiB</th><th>Datacenter</th><th>Cluster</th><th>ESXi Host</th><th></th></tr></thead><tbody>';
            foreach ($vmPage['items'] as $vm) {
                print '<tr>';
                print '<td><strong>' . phase5d_explorer_h($vm['name']) . '</strong><br><code>' . phase5d_explorer_h($vm['external_id']) . '</code></td>';
                print '<td>' . phase5d_explorer_h($vm['power_state'] === null ? '—' : $vm['power_state']) . '</td>';
                print '<td>' . ($vm['cpu_count'] === null ? '—' : (int)$vm['cpu_count']) . '</td>';
                print '<td>' . phase5d_explorer_bytes_mib($vm['memory_size_mib']) . '</td>';
                print '<td>' . phase5d_explorer_h($vm['datacenter_name'] === null ? '—' : $vm['datacenter_name']) . '</td>';
                print '<td>' . phase5d_explorer_h($vm['cluster_name'] === null ? 'Standalone / unknown' : $vm['cluster_name']) . '</td>';
                print '<td>' . phase5d_explorer_h($vm['host_name'] === null ? '—' : $vm['host_name']) . '</td>';
                print '<td><button type="button" class="btn btn-xs btn-primary phase5d-vm-card-link" data-vm-id="' . phase5d_explorer_h($vm['external_id']) . '"><i class="fa fa-info-circle"></i> VM card</button></td>';
                print '</tr>';
            }
            if (count($vmPage['items']) === 0) {
                print '<tr><td colspan="8" class="text-muted">No VMs match the current filter.</td></tr>';
            }
            print '</tbody></table></div>';

            if ($vmPage['page_count'] > 1) {
                print '<div class="text-center"><ul class="pagination pagination-sm" style="margin:5px 0;">';
                $start = max(1, $vmPage['page'] - 3);
                $end = min($vmPage['page_count'], $vmPage['page'] + 3);
                for ($p = $start; $p <= $end; $p++) {
                    $class = $p === $vmPage['page'] ? ' class="active"' : '';
                    print '<li' . $class . '><a href="#" class="phase5d-page-link" data-page="' . (int)$p . '">' . (int)$p . '</a></li>';
                }
                print '</ul></div>';
            }
        }

        print '<div class="text-muted" style="margin-top:8px;">persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div>';
    }
} catch (Exception $e) {
    http_response_code(500);
    print '<div class="alert alert-danger"><strong>Explorer failed:</strong> ' . phase5d_explorer_h(phase5d_explorer_error_category($e)) . '. Sensitive exception details were suppressed.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) {
        phase5d_explorer_zero_secret($runtime['password']);
    }
}

$output = ob_get_clean();
print $output;
