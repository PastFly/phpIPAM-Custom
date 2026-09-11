<?php

/**
 * phpIPAM Custom — Phase 5F.3.2 VM guest/config facts JSON endpoint.
 * On-demand, one explicit VM, read-only and fail-open relative to the primary card.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

header('Content-Type: application/json; charset=UTF-8');

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

function phase5f32_json($payload, $status = 200)
{
    http_response_code((int)$status);
    print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function phase5f32_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f32_error_category($error)
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

function phase5f32_clean_optional($value)
{
    if ($value === null) return null;
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

if (!$User->is_admin(false)) phase5f32_json(array('ok' => false, 'error' => 'administrative_privileges_required'), 403);

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    phase5f32_json(array('ok' => false, 'error' => 'invalid_csrf'), 403);
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5F32_VM_GUEST_FACTS_V1
// PHPIPAM_CUSTOM_VMWARE_PHASE5F32_READ_ONLY_NO_PERSISTENCE_V1

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    phase5f32_json(array('ok' => false, 'error' => 'invalid_connector_id'), 400);
}
$configId = (int)$configId;

$vmId = isset($POST->vm_id) ? trim((string)$POST->vm_id) : '';
if (!preg_match('/^vm-[0-9]+$/', $vmId)) phase5f32_json(array('ok' => false, 'error' => 'invalid_vm_id'), 400);

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterVmGuestFacts.php");

$runtime = null;
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(8388608);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);
    $Reader = new IntegrationVmwareVcenterVmGuestFacts($FaultAwareTransport, 8, 20, 4194304);
    $facts = $Reader->read_facts($runtime['endpoint'], $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);

    $runtimeFull = phase5f32_clean_optional(isset($facts['guest_guest_full_name']) ? $facts['guest_guest_full_name'] : null);
    $configFull = phase5f32_clean_optional(isset($facts['config_guest_full_name']) ? $facts['config_guest_full_name'] : null);
    $runtimeId = phase5f32_clean_optional(isset($facts['guest_guest_id']) ? $facts['guest_guest_id'] : null);
    $configIdValue = phase5f32_clean_optional(isset($facts['config_guest_id']) ? $facts['config_guest_id'] : null);

    $friendly = $runtimeFull;
    $friendlySource = 'guest.guestFullName';
    if ($friendly === null) {
        $friendly = $configFull;
        $friendlySource = 'config.guestFullName';
    }
    if ($friendly === null) {
        $friendly = $runtimeId;
        $friendlySource = 'guest.guestId';
    }
    if ($friendly === null) {
        $friendly = $configIdValue;
        $friendlySource = 'config.guestId';
    }

    phase5f32_json(array(
        'ok' => true,
        'vm_external_id' => $vmId,
        'name' => isset($facts['name']) ? $facts['name'] : null,
        'friendly_guest_os' => $friendly,
        'friendly_guest_os_source' => $friendlySource,
        'guest_guest_id' => $runtimeId,
        'config_guest_id' => $configIdValue,
        'guest_guest_full_name' => $runtimeFull,
        'config_guest_full_name' => $configFull,
        'memory_mb' => isset($facts['memory_mb']) ? $facts['memory_mb'] : null,
        'num_cpu' => isset($facts['num_cpu']) ? $facts['num_cpu'] : null,
        'hardware_version' => isset($facts['hardware_version']) ? $facts['hardware_version'] : null,
        'property_method' => 'RetrieveProperties',
        'verify_tls' => !empty($runtime['verify_tls']),
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    ));
} catch (Exception $e) {
    phase5f32_json(array(
        'ok' => false,
        'error' => 'vm_guest_facts_unavailable',
        'category' => phase5f32_error_category($e),
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    ), 502);
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f32_zero_secret($runtime['password']);
}
