<?php
/** Additive API only; existing pages are deliberately NOT rewired by this backend slice. */
$application = dirname(__DIR__, 4);
require_once($application . '/functions/functions.php');
disable_php_errors();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
function phase5g_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    print json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
$Database = new Database_PDO(); $User = new User($Database);
$User->check_user_session();
if (!$User->is_admin(false)) { phase5g_reply(['ok' => false, 'error' => 'administrative_privileges_required'], 403); return; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { phase5g_reply(['ok' => false, 'error' => 'post_required'], 405); return; }
$csrf = $POST->csrf_cookie ?? '';
if (!is_string($csrf) || $User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    phase5g_reply(['ok' => false, 'error' => 'invalid_csrf'], 403); return;
}
try {
    $input = [];
    foreach (['config_id' => '', 'view' => 'status', 'id' => '', 'search' => '', 'page' => '1', 'page_size' => '50'] as $name => $default) {
        $value = $POST->$name ?? $default;
        if ((!is_string($value) && !is_int($value)) || strlen((string)$value) > 256) throw new InvalidArgumentException('invalid_request');
        $input[$name] = (string)$value;
    }
    foreach (['config_id', 'page', 'page_size'] as $key) {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $input[$key])) throw new InvalidArgumentException('invalid_request');
    }
    foreach (['IntegrationVmwareSnapshotService', 'IntegrationVmwareInventoryStore', 'IntegrationVmwareInventoryContext', 'IntegrationVmwareInventoryReader', 'IntegrationVmwareInventoryTelemetryStore'] as $class) {
        require_once($application . '/functions/classes/class.' . $class . '.php');
    }
    $context = new IntegrationVmwareInventoryContext($Database, $application, (int)$input['config_id']);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $reader = new IntegrationVmwareInventoryReader($context->store(), [$context, 'current'], null, $context->telemetry());
    $result = $reader->query($input['view'], $input['id'], $input['search'], (int)$input['page'], (int)$input['page_size']);
    $result['connector_id'] = (int)$input['config_id']; $result['verify_tls'] = true;
    header('X-Phpipam-Inventory-Vcenter-Requests: 0');
    phase5g_reply($result);
} catch (Throwable $error) {
    $message = $error->getMessage();
    $status = $error instanceof InvalidArgumentException ? 400 : 503;
    if (in_array($message, ['mirror_access_denied', 'mirror_connector_unavailable'], true)) $status = 403;
    if ($message === 'mirror_object_not_found') $status = 404;
    phase5g_reply(['ok' => false, 'error' => [400 => 'invalid_request', 403 => 'access_denied',
        404 => 'object_not_found', 503 => 'local_inventory_unavailable'][$status], 'vcenter_requests' => 0], $status);
}
