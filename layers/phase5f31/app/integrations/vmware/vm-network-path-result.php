<?php

/**
 * phpIPAM Custom — Phase 5F.3.1 VM -> physical network path fragment.
 * Read-only, on-demand, one explicit VM, fail-open relative to the primary VM card.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

if (!$User->is_admin(false)) {
    http_response_code(403);
    print '<div class="alert alert-danger phase5f31-vm-network-path">Administrative privileges required.</div>';
    exit;
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    http_response_code(403);
    print '<div class="alert alert-danger phase5f31-vm-network-path">Invalid CSRF cookie.</div>';
    exit;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5F31_VM_NETWORK_PATH_V1
// PHPIPAM_CUSTOM_VMWARE_PHASE5F31_READ_ONLY_NO_PERSISTENCE_V1

function phase5f31_path_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5f31_path_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f31_path_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) return 'tls';
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) return 'timeout';
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) return 'authentication';
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) return 'authorization';
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) return 'network';
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) return 'configuration';
    return 'invalid_response';
}

function phase5f31_path_value($row, $key)
{
    return is_array($row) && array_key_exists($key, $row) ? $row[$key] : null;
}

function phase5f31_path_label($role)
{
    $role = strtolower(trim((string)$role));
    if ($role === 'active') return '<span class="label label-success">active</span>';
    if ($role === 'standby') return '<span class="label label-warning">standby</span>';
    if ($role === 'unused') return '<span class="label label-default">unused</span>';
    if ($role === 'dvs_attached') return '<span class="label label-info">dvs attached</span>';
    return '<span class="label label-default">' . phase5f31_path_h($role === '' ? 'associated' : $role) . '</span>';
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    print '<div class="alert alert-danger phase5f31-vm-network-path">Invalid VMware connector id.</div>';
    exit;
}
$configId = (int)$configId;

$vmId = isset($POST->vm_id) ? trim((string)$POST->vm_id) : '';
if (!preg_match('/^vm-[0-9]+$/', $vmId)) {
    print '<div class="alert alert-danger phase5f31-vm-network-path">Invalid VMware VirtualMachine external id.</div>';
    exit;
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterVmNetworkPath.php");

$runtime = null;
ob_start();
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(8388608);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);
    $Reader = new IntegrationVmwareVcenterVmNetworkPath($FaultAwareTransport, 8, 25, 8388608, 64, 128, 128, 512, 128);
    $result = $Reader->read_path($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);

    $nics = isset($result['nics']) && is_array($result['nics']) ? $result['nics'] : array();
    print '<div class="phase5f31-vm-network-path phase5e-detail-section">';
    print '<h5><strong><i class="fa fa-random"></i> Сетевой путь / Physical uplinks</strong></h5>';
    print '<div class="small text-muted phase5f31-path-summary">ESXi: ' . phase5f31_path_h(phase5f31_path_value($result, 'host_name')) . ' <code>' . phase5f31_path_h(phase5f31_path_value($result, 'host_external_id')) . '</code></div>';

    if (count($nics) === 0) {
        print '<div class="text-muted small">vCenter не вернул виртуальные сетевые адаптеры для этой VM.</div>';
    } else {
        foreach ($nics as $nic) {
            $label = phase5f31_path_value($nic, 'label');
            if ($label === null || $label === '') $label = phase5f31_path_value($nic, 'nic_external_id');
            $network = phase5f31_path_value($nic, 'network_name');
            if ($network === null || $network === '') $network = phase5f31_path_value($nic, 'network_external_id');
            $pathType = phase5f31_path_value($nic, 'path_type');
            $switchName = phase5f31_path_value($nic, 'switch_name');
            $teaming = phase5f31_path_value($nic, 'teaming_policy');
            $status = phase5f31_path_value($nic, 'relation_status');
            $uplinks = phase5f31_path_value($nic, 'uplinks');
            if (!is_array($uplinks)) $uplinks = array();

            print '<div class="phase5f31-vnic-block">';
            print '<div class="phase5f31-vnic-head"><strong>' . phase5f31_path_h($label) . '</strong>';
            print ' <code>' . phase5f31_path_h(phase5f31_path_value($nic, 'nic_external_id')) . '</code>';
            if (phase5f31_path_value($nic, 'mac_address') !== null) print ' <span class="text-muted">MAC ' . phase5f31_path_h(phase5f31_path_value($nic, 'mac_address')) . '</span>';
            print '</div>';
            print '<div class="small phase5f31-vnic-meta">Network: <strong>' . phase5f31_path_h($network === null ? '—' : $network) . '</strong> · Path: ' . phase5f31_path_h($pathType === null ? 'unresolved' : $pathType);
            if ($switchName !== null) print ' · Switch: <strong>' . phase5f31_path_h($switchName) . '</strong>';
            if ($teaming !== null) print ' · Teaming: <code>' . phase5f31_path_h($teaming) . '</code>';
            print ' · Relation: <span class="label label-' . ($status === 'resolved' ? 'success' : ($status === 'partial' ? 'warning' : 'default')) . '">' . phase5f31_path_h($status === null ? 'unknown' : $status) . '</span></div>';

            if (count($uplinks) === 0) {
                print '<div class="text-muted small" style="margin-top:4px;">Physical uplink не доказан доступными read-only свойствами; значение не угадывается.</div>';
            } else {
                print '<div class="table-responsive"><table class="table table-condensed phase5f31-path-table"><thead><tr><th>Uplink</th><th>Role</th><th>Discovery</th><th>Device ID</th><th>Port ID</th></tr></thead><tbody>';
                foreach ($uplinks as $uplink) {
                    $adapter = phase5f31_path_value($uplink, 'adapter');
                    $uplinkName = phase5f31_path_value($uplink, 'uplink_name');
                    print '<tr><td><strong>' . phase5f31_path_h($adapter === null ? '—' : $adapter) . '</strong>';
                    if ($uplinkName !== null && $uplinkName !== '') print '<br><small class="text-muted">' . phase5f31_path_h($uplinkName) . '</small>';
                    print '</td><td>' . phase5f31_path_label(phase5f31_path_value($uplink, 'role')) . '</td>';
                    print '<td>' . phase5f31_path_h(phase5f31_path_value($uplink, 'discovery') === null ? '—' : phase5f31_path_value($uplink, 'discovery')) . '</td>';
                    print '<td class="phase5f31-path-value">' . phase5f31_path_h(phase5f31_path_value($uplink, 'device_id') === null ? '—' : phase5f31_path_value($uplink, 'device_id')) . '</td>';
                    print '<td class="phase5f31-path-value">' . phase5f31_path_h(phase5f31_path_value($uplink, 'port_id') === null ? '—' : phase5f31_path_value($uplink, 'port_id')) . '</td></tr>';
                }
                print '</tbody></table></div>';
            }

            if (empty($nic['current_uplink_proven'])) {
                print '<div class="small text-muted phase5f31-current-note">Current uplink не заявляется: показаны только доказанные policy/attachment uplinks.</div>';
            }
            print '</div>';
        }
    }

    print '<div class="text-muted small">RetrieveProperties + QueryNetworkHint · direct/no-proxy · persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div>';
    print '</div>';
} catch (Exception $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(502);
    print '<div class="alert alert-warning phase5f31-vm-network-path">Сетевой путь VM недоступен (' . phase5f31_path_h(phase5f31_path_error_category($e)) . '). Основная карточка VM не затронута.</div>';
    if (is_array($runtime) && array_key_exists('password', $runtime)) phase5f31_path_zero_secret($runtime['password']);
    exit;
}

$output = ob_get_clean();
if (is_array($runtime) && array_key_exists('password', $runtime)) phase5f31_path_zero_secret($runtime['password']);
print $output;
