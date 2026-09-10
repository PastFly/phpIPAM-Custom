<?php
/** All authorization runs BEFORE even a fresh shared snapshot is read. */
if (!defined('PHPIPAM_VMWARE_CACHE_OPERATION')) { http_response_code(403); return; }
$application = dirname(__DIR__, 4);
require_once($application . '/functions/functions.php');
disable_php_errors();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
function phase5f34_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
function phase5f34_string($post, string $key, string $default = ''): string
{
    $value = $post->$key ?? $default;
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('invalid_request');
    $value = (string)$value;
    if (strlen($value) > 1024 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new InvalidArgumentException('invalid_request');
    return $value;
}
$Database = new Database_PDO(); $User = new User($Database);
$User->check_user_session();
if (!$User->is_admin(false)) { phase5f34_reply(['ok' => false, 'error' => 'administrative_privileges_required'], 403); return; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { phase5f34_reply(['ok' => false, 'error' => 'post_required'], 405); return; }
$csrf = isset($POST->csrf_cookie) && is_string($POST->csrf_cookie) ? $POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    phase5f34_reply(['ok' => false, 'error' => 'invalid_csrf'], 403); return;
}
$runtime = null; $service = null; $response = null; $status = 200;
try {
    $id = phase5f34_string($POST, 'config_id');
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) throw new InvalidArgumentException('invalid_connector');
    $forceRaw = phase5f34_string($POST, 'force_refresh', '0');
    if (!in_array($forceRaw, ['0', '1'], true)) throw new InvalidArgumentException('invalid_request');
    $force = $forceRaw === '1'; $op = PHPIPAM_VMWARE_CACHE_OPERATION;
    if (!in_array($op, ['workspace', 'host_folders', 'object_folders', 'search', 'refresh', 'status'], true)) throw new InvalidArgumentException('invalid_request');
    $mode = phase5f34_string($POST, 'mode', 'graph');
    if ($op === 'object_folders' && !in_array($mode, ['graph', 'datastores'], true)) throw new InvalidArgumentException('invalid_request');
    $view = phase5f34_string($POST, 'view', 'overview');
    $scope = phase5f34_string($POST, 'scope'); $scopeId = phase5f34_string($POST, 'scope_id');
    $search = trim(phase5f34_string($POST, 'search')); $page = phase5f34_string($POST, 'page', '1');
    $pageSize = phase5f34_string($POST, 'page_size', '50');
    if ($op === 'workspace' && (!in_array($view, ['overview', 'vms', 'datastores'], true) ||
        !in_array($scope, ['', 'datacenter', 'cluster', 'host'], true) || strlen($search) > 256 ||
        !preg_match('/^[1-9][0-9]{0,7}$/D', $page) || !preg_match('/^[1-9][0-9]{0,2}$/D', $pageSize) || (int)$pageSize > 100)) throw new InvalidArgumentException('invalid_request');
    if ($scope === '') $scopeId = '';
    $domain = $op === 'refresh' ? phase5f34_string($POST, 'domain') :
        ($op === 'workspace' ? 'core' : ($op === 'object_folders' && $mode === 'datastores' ? 'core' : $op));
    if ($op !== 'status' && !in_array($domain, ['core', 'host_folders', 'object_folders', 'search'], true)) throw new InvalidArgumentException('invalid_request');
    foreach (['IntegrationVmwareVcenter', 'IntegrationSecretCipher', 'IntegrationVmwareVcenterConfigStore',
        'IntegrationVmwareSnapshotCache', 'IntegrationVmwareSnapshotService', 'IntegrationVmwareVcenterDirectTransport',
        'IntegrationVmwareVcenterFaultAwareTransport', 'IntegrationVmwareVcenterDirectRestTransport',
        'IntegrationVmwareVcenterTopology', 'IntegrationVmwareVcenterDatastore', 'IntegrationVmwareVcenterExplorer',
        'IntegrationVmwareVcenterFolderInventory', 'IntegrationVmwareVcenterObjectFolders',
        'IntegrationVmwareVcenterVmSearchFacts', 'IntegrationVmwareVcenterVmTagIndex'] as $class) {
        require_once($application . '/functions/classes/class.' . $class . '.php');
    }
    $store = new IntegrationVmwareVcenterConfigStore($Database, IntegrationSecretCipher::from_keyring_file());
    $runtime = $store->load_runtime_config((int)$id, true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    define('PHPIPAM_VMWARE_CACHE_GUARDED', true);
    require_once(__DIR__ . '/renderers.php'); require_once(__DIR__ . '/search-provider.php');
    $service = IntegrationVmwareSnapshotService::fromRuntime($application, (int)$id, $runtime);
    if ($op === 'status') $response = $service->status();
    else {
        $entry = $service->read($domain, $op === 'refresh', $force); $payload = $entry['payload'];
        if ($op === 'refresh') $response = ['ok' => true]; // maintenance never replaces a selected view
        elseif ($op === 'workspace') {
            $topology = $payload['topology'];
            $projection = (new IntegrationVmwareVcenterExplorer(100))->build($topology, $payload['datastores']);
            if ($scope !== '') {
                $map = $scope === 'datacenter' ? $projection['datacenters'] : ($scope === 'host' ? $projection['hosts'] : $projection['clusters']);
                if (!isset($map[$scopeId])) throw new InvalidArgumentException('unknown_scope_id');
            }
            $content = $view === 'overview' ? phase5e_result_render_overview($projection, $topology) :
                ($view === 'datastores' ? phase5e_result_render_datastores($projection, $scope, $scopeId, $search, (int)$page, (int)$pageSize) :
                phase5e_result_render_vms($projection, $scope, $scopeId, $search, (int)$page, (int)$pageSize));
            $summary = '<div class="small text-muted">VMware Explorer · connector #' . (int)$id . ' · read-only';
            if (empty($runtime['verify_tls'])) $summary .= ' · <span class="text-warning" title="TLS verification отключена в настройках подключения; unattended scheduler запрещён.">⚠ TLS</span>';
            $summary .= ' · локальные снимки кэша: ' . ($service->enabled() ? 'включены' : 'выключены') . '</div>';
            $response = ['ok' => true, 'tree_html' => phase5e_result_render_tree($projection, $scope, $scopeId), 'content_html' => $content, 'summary_html' => $summary];
        } elseif ($op === 'host_folders') $response = ['ok' => true, 'inventory' => $payload];
        elseif ($op === 'object_folders' && $mode === 'datastores') {
            $response = ['ok' => true, 'datastores' => array_map(static fn(array $r) => array_intersect_key($r,
                array_flip(['external_id', 'name', 'type', 'capacity_bytes', 'free_space_bytes'])), $payload['datastores']['datastores'])];
        } else $response = $payload;
        $response['cache'] = $entry['cache']; $response['cache_enabled'] = $service->enabled();
        $response['cache_snapshot_written'] = (bool)($entry['cache']['cache_snapshot_written'] ?? false);
        $response['sapi'] = PHP_SAPI;
    }
    $response['connector_id'] = (int)$id; $response['verify_tls'] = (bool)$runtime['verify_tls'];
    $response['persistent_inventory_written'] = false; // business inventory only; cache_snapshot_written is explicit
    $response['inventory_db_written'] = false; $response['phpipam_objects_modified'] = false; $response['vcenter_objects_modified'] = false;
} catch (Throwable $error) {
    $known = ['snapshot_refresh_busy', 'snapshot_backoff', 'snapshot_access_denied', 'snapshot_disabled'];
    $category = in_array($error->getMessage(), $known, true) ? $error->getMessage() : ($error instanceof InvalidArgumentException ? 'invalid_request' : 'cache_read_unavailable');
    $status = $error instanceof InvalidArgumentException ? 400 : ($category === 'snapshot_access_denied' ? 403 : 503);
    if ($category === 'snapshot_refresh_busy') header('Retry-After: 1');
    $response = ['ok' => false, 'error' => $category, 'retry_after_seconds' => $category === 'snapshot_refresh_busy' ? 1 : 30];
} finally {
    unset($service);
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password']) && function_exists('sodium_memzero')) sodium_memzero($runtime['password']);
}
phase5f34_reply($response, $status);
