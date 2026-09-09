<?php

/**
 * phpIPAM Custom — Phase 5F.2.5 transient VMware HOST-folder result.
 * Read-only; no phpIPAM/vCenter inventory mutation or persistence.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

header('Content-Type: application/json; charset=UTF-8');

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

// PHPIPAM_CUSTOM_VMWARE_PHASE5F25_FOLDER_RESULT_V1

function phase5f25_result_json($payload, $status = 200)
{
    http_response_code((int)$status);
    print json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function phase5f25_result_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f25_result_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) return 'tls';
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) return 'timeout';
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) return 'authentication';
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) return 'authorization';
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'resolve') !== false || strpos($message, 'connect') !== false) return 'network';
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'safety limit') !== false || strpos($message, 'cycle') !== false) return 'invalid_response';
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) return 'configuration';
    return 'unknown';
}

if (!$User->is_admin(false)) {
    phase5f25_result_json(array('ok' => false, 'error' => 'administrative_privileges_required'), 403);
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    phase5f25_result_json(array('ok' => false, 'error' => 'invalid_csrf'), 403);
}

$configId = isset($POST->config_id) ? filter_var($POST->config_id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) : false;
if ($configId === false) {
    phase5f25_result_json(array('ok' => false, 'error' => 'invalid_connector'), 400);
}

$runtime = null;
try {
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFolderInventory.php");

    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config((int)$configId, true);

    $Collector = new IntegrationVmwareVcenterFolderInventory();
    $inventory = $Collector->read_inventory(
        $runtime['endpoint'],
        $runtime['username'],
        $runtime['password'],
        !empty($runtime['verify_tls'])
    );

    phase5f25_result_zero_secret($runtime['password']);
    phase5f25_result_json(array(
        'ok' => true,
        'inventory' => $inventory,
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    ));
} catch (Exception $e) {
    if (is_array($runtime) && isset($runtime['password'])) phase5f25_result_zero_secret($runtime['password']);
    phase5f25_result_json(array(
        'ok' => false,
        'error' => 'folder_inventory_unavailable',
        'category' => phase5f25_result_error_category($e),
        'persistent_inventory_written' => false,
        'phpipam_objects_modified' => false,
        'vcenter_objects_modified' => false
    ), 502);
}
