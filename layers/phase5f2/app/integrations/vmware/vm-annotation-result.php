<?php

/**
 * phpIPAM Custom — Phase 5F.2 on-demand vSphere VM Notes fragment.
 * Reads only VirtualMachine.config.annotation through the bounded SOAP reader.
 */
require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$User->check_user_session();

if (!$User->is_admin(false)) {
    http_response_code(403);
    print '<div class="alert alert-danger phase5f2-vm-annotation">Administrative privileges required.</div>';
    exit;
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    http_response_code(403);
    print '<div class="alert alert-danger phase5f2-vm-annotation">Invalid CSRF cookie.</div>';
    exit;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5F2_VM_ANNOTATION_V1

function phase5f2_annotation_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function phase5f2_annotation_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f2_annotation_error_category($error)
{
    $message = strtolower((string)$error->getMessage());
    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) return 'tls';
    if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) return 'timeout';
    if (strpos($message, 'authentication') !== false || strpos($message, 'http 401') !== false) return 'authentication';
    if (strpos($message, 'forbidden') !== false || strpos($message, 'permission') !== false || strpos($message, 'http 403') !== false) return 'authorization';
    if (strpos($message, 'network') !== false || strpos($message, 'curl') !== false || strpos($message, 'connect') !== false) return 'network';
    if (strpos($message, 'soap') !== false || strpos($message, 'annotation') !== false || strpos($message, 'response') !== false) return 'invalid_response';
    if ($error instanceof InvalidArgumentException || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) return 'configuration';
    return 'unknown';
}

$configId = isset($POST->config_id) ? (string)$POST->config_id : '';
if (filter_var($configId, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
    print '<div class="alert alert-danger phase5f2-vm-annotation">Invalid VMware connector id.</div>';
    exit;
}
$configId = (int)$configId;

$vmId = isset($POST->vm_id) ? trim((string)$POST->vm_id) : '';
if (!preg_match('/^vm-[0-9]+$/', $vmId)) {
    print '<div class="alert alert-danger phase5f2-vm-annotation">Invalid VMware VM external id.</div>';
    exit;
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterDirectTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php");

$runtime = null;
ob_start();
try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    $runtime = $Store->load_runtime_config($configId, true);

    $EndpointPolicy = new IntegrationVmwareVcenter();
    $endpoint = $EndpointPolicy->normalize_endpoint($runtime['endpoint']);
    $DirectTransport = new IntegrationVmwareVcenterDirectTransport(2097152);
    $FaultAwareTransport = new IntegrationVmwareVcenterFaultAwareTransport($DirectTransport);
    $Reader = new IntegrationVmwareVcenterVmAnnotation($FaultAwareTransport, 8, 20, 1048576, 65535);
    $result = $Reader->read_annotation($endpoint, $runtime['username'], $runtime['password'], $vmId, $runtime['verify_tls']);

    $annotation = isset($result['annotation']) ? (string)$result['annotation'] : '';
    print '<div class="phase5f2-vm-annotation phase5e-detail-section">';
    print '<h5><strong><i class="fa fa-sticky-note-o"></i> Описание / Notes</strong></h5>';
    if (trim($annotation) === '') {
        print '<div class="text-muted small">Описание в vSphere не задано.</div>';
    } else {
        print '<div class="phase5f2-annotation-text">' . nl2br(phase5f2_annotation_h($annotation), false) . '</div>';
    }
    print '<div class="small text-muted">Источник: VirtualMachine.config.annotation · read-only · не сохраняется.</div>';
    print '</div>';
} catch (Exception $e) {
    ob_clean();
    http_response_code(502);
    print '<div class="alert alert-warning phase5f2-vm-annotation">Описание VM недоступно (' . phase5f2_annotation_h(phase5f2_annotation_error_category($e)) . '). Основная карточка VM остаётся доступной.</div>';
} finally {
    if (is_array($runtime) && isset($runtime['password'])) phase5f2_annotation_zero_secret($runtime['password']);
}

print ob_get_clean();
