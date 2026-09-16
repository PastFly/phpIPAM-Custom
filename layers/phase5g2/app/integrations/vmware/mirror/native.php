<?php
/** Called only by generated, fixed-operation native route wrappers. Never imports a collector. */
if (!defined('PHPIPAM_VMWARE_MIRROR_OPERATION')) { http_response_code(403); return; }
$application = dirname(__DIR__, 4);
require_once($application . '/functions/functions.php');
disable_php_errors();
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Phpipam-Inventory-Vcenter-Requests: 0');
$operation = PHPIPAM_VMWARE_MIRROR_OPERATION;
$json = in_array($operation, ['workspace', 'host_folders', 'object_folders', 'search', 'status', 'guest_facts'], true);
header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=UTF-8');
$reply = static function ($body, int $status = 200) use ($json): void {
    http_response_code($status);
    print $json ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : $body;
};
$fail = static function (string $code, int $status) use ($reply, $json): void {
    $reply($json ? ['ok' => false, 'error' => $code, 'vcenter_requests' => 0] :
        '<div class="alert alert-warning">Локальный инвентарь недоступен (' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . ').</div>', $status);
};
$Database = new Database_PDO(); $User = new User($Database); $User->check_user_session();
if (!$User->is_admin(false)) { $fail('administrative_privileges_required', 403); return; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $fail('post_required', 405); return; }
$csrf = $POST->csrf_cookie ?? '';
if (!is_string($csrf) || $User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    $fail('invalid_csrf', 403); return;
}
$start = hrtime(true);
try {
    $input = is_object($POST) ? get_object_vars($POST) : [];
    $id = $input['config_id'] ?? '';
    if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$id)) throw new InvalidArgumentException('invalid_request');
    foreach (['IntegrationVmwareSnapshotService', 'IntegrationVmwareInventoryStore', 'IntegrationVmwareInventoryContext',
        'IntegrationVmwareInventoryReader', 'IntegrationVmwareInventoryNative', 'IntegrationVmwareVcenterExplorer',
        'IntegrationPhpIpamAddressLinkResolver', 'IntegrationVmwareInventoryTelemetryStore',
        'IntegrationVmwareRosterReconciler', 'IntegrationVmwareFastStore', 'IntegrationVmwareFastReader',
        'IntegrationVmwareFastMetricCompat', 'IntegrationVmwareFastOverlayReader', 'IntegrationVmwareInventoryQuerySelector',
        'IntegrationVmwareFastReaderConfig', 'IntegrationVmwareFastOverlayFactory', 'IntegrationVmwareFastReaderConfigLoader'] as $class) {
        require_once($application . '/functions/classes/class.' . $class . '.php');
    }
    $context = new IntegrationVmwareInventoryContext($Database, $application, (int)$id);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    define('PHPIPAM_VMWARE_MIRROR_GUARDED', true);
    require_once(__DIR__ . '/native-views.php');
    $reader = new IntegrationVmwareInventoryReader($context->store(), [$context, 'current'], null, $context->telemetry());
    $fastConfig = IntegrationVmwareFastReaderConfigLoader::load();
    $reader = (new IntegrationVmwareFastOverlayFactory($context, $fastConfig, (int)$id))->selector($reader);
    $links = null;
    if ($operation === 'vm') {
        try { $links = new IntegrationPhpIpamAddressLinkResolver($Database); } catch (Throwable $ignored) { /* Optional native links must not hide the saved card. */ }
    }
    $result = (new IntegrationVmwareInventoryNative($reader, $links))->response($operation, $input);
    if (!$context->current()) throw new RuntimeException('mirror_access_denied');
    if ($json) $result['body']['connector_id'] = (int)$id;
    header('Server-Timing: local_inventory;dur=' . number_format((hrtime(true) - $start) / 1000000, 2, '.', ''));
    $reply($result['body'], $result['status']);
} catch (Throwable $error) {
    $code = $error->getMessage();
    $status = $error instanceof InvalidArgumentException ? 400 : 503;
    if (in_array($code, ['mirror_access_denied', 'mirror_connector_unavailable'], true)) $status = 403;
    if ($code === 'mirror_object_not_found') $status = 404;
    $fail([400 => 'invalid_request', 403 => 'access_denied', 404 => 'object_not_found', 503 => 'local_inventory_unavailable'][$status], $status);
}
