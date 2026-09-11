<?php

/**
 * phpIPAM Custom — Phase 5F.3 ESXi physical adapter peer details.
 * Read-only, on-demand, one selected HostSystem, non-persistent.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

if (!$User->is_admin(false)) {
    http_response_code(403);
    print '<div class="alert alert-danger phase5f3-host-network">Administrative privileges required.</div>';
    exit;
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    http_response_code(403);
    print '<div class="alert alert-danger phase5f3-host-network">Invalid CSRF cookie.</div>';
    exit;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5F3_HOST_NETWORK_HINTS_V1
// PHPIPAM_CUSTOM_VMWARE_PHASE5F3_READ_ONLY_NO_PERSISTENCE_V1

function phase5f3_net_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5f3_net_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f3_net_error_category($error)
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

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    print '<div class="alert alert-danger phase5f3-host-network">Invalid VMware connector id.</div>';
    exit;
}
$configId = (int)$configId;

$hostId = isset($POST->host_id) ? trim((string)$POST->host_id) : '';
if (!preg_match('/^host-[0-9]+$/', $hostId)) {
    print '<div class="alert alert-danger phase5f3-host-network">Invalid VMware HostSystem external id.</div>';
    exit;
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php");

$runtime = null;
ob_start();
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    $EndpointPolicy = new IntegrationVmwareVcenter();
    $endpoint = $EndpointPolicy->normalize_endpoint($runtime['endpoint']);
    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(4194304);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);
    $Reader = new IntegrationVmwareVcenterHostNetworkHints($FaultAwareTransport, 8, 20, 2097152, 64);
    $result = $Reader->read_hints($endpoint, $runtime['username'], $runtime['password'], $hostId, $runtime['verify_tls']);

    $hints = isset($result['hints']) && is_array($result['hints']) ? $result['hints'] : array();
    print '<div class="phase5f3-host-network">';
    print '<h5><strong><i class="fa fa-exchange"></i> Физические сетевые адаптеры</strong></h5>';

    if (count($hints) === 0) {
        print '<div class="text-muted small">vCenter не вернул CDP/LLDP network hints для физических адаптеров этого ESXi.</div>';
    } else {
        print '<div class="table-responsive"><table class="table table-condensed table-striped phase5f3-network-table">';
        print '<thead><tr><th>Адаптер</th><th>Discovery</th><th>Device ID</th><th>Port ID</th></tr></thead><tbody>';
        foreach ($hints as $hint) {
            $adapter = isset($hint['adapter']) ? $hint['adapter'] : null;
            $protocol = isset($hint['protocol']) ? $hint['protocol'] : null;
            $deviceId = isset($hint['device_id']) ? $hint['device_id'] : null;
            $portId = isset($hint['port_id']) ? $hint['port_id'] : null;

            print '<tr>';
            print '<td><strong>' . phase5f3_net_h($adapter === null ? '—' : $adapter) . '</strong></td>';
            print '<td>' . phase5f3_net_h($protocol === null ? '—' : $protocol) . '</td>';
            print '<td class="phase5f3-network-value">' . phase5f3_net_h($deviceId === null ? '—' : $deviceId) . '</td>';
            print '<td class="phase5f3-network-value">' . phase5f3_net_h($portId === null ? '—' : $portId) . '</td>';
            print '</tr>';
        }
        print '</tbody></table></div>';
        print '<div class="text-muted small">CDP: Device ID / Port ID. LLDP: Chassis ID отображается в колонке Device ID, Port ID — без переименования.</div>';
    }

    print '<div class="text-muted small" style="margin-top:4px;">QueryNetworkHint · System.Read · persistent_inventory_written=false · phpipam_objects_modified=false · vcenter_objects_modified=false</div>';
    print '</div>';
} catch (Exception $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(502);
    print '<div class="alert alert-warning phase5f3-host-network">Информация о физических адаптерах недоступна (' . phase5f3_net_h(phase5f3_net_error_category($e)) . '). Основная карточка ESXi остаётся доступной.</div>';
    if (is_array($runtime) && array_key_exists('password', $runtime)) phase5f3_net_zero_secret($runtime['password']);
    exit;
}

$output = ob_get_clean();
if (is_array($runtime) && array_key_exists('password', $runtime)) phase5f3_net_zero_secret($runtime['password']);
print $output;
