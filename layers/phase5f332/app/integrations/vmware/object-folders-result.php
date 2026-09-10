<?php
/** Phase 5F.3.3.2: authenticated, transient VM/datastore folder projections. */
require_once(__DIR__ . '/../../../functions/functions.php');
disable_php_errors();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');

function phase5f332_reply($payload, $status)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

$Database = new Database_PDO();
$User = new User($Database);
$User->check_user_session();
if (!$User->is_admin(false)) { phase5f332_reply(array('ok' => false, 'error' => 'administrative_privileges_required'), 403); return; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { phase5f332_reply(array('ok' => false, 'error' => 'post_required'), 405); return; }
$csrf = isset($POST->csrf_cookie) && is_string($POST->csrf_cookie) ? $POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie('validate', 'integration_vmware_vcenter_explorer', $csrf) === false) {
    phase5f332_reply(array('ok' => false, 'error' => 'invalid_csrf'), 403); return;
}
$id = isset($POST->config_id) && is_scalar($POST->config_id) ? (string)$POST->config_id : '';
$mode = isset($POST->mode) && is_string($POST->mode) ? $POST->mode : 'graph';
if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || !in_array($mode, array('graph', 'datastores'), true)) {
    phase5f332_reply(array('ok' => false, 'error' => 'invalid_request'), 400); return;
}

$runtime = null; $response = null; $status = 200;
try {
    foreach (array('IntegrationVmwareVcenter', 'IntegrationSecretCipher', 'IntegrationVmwareVcenterConfigStore',
        'IntegrationVmwareVcenterDirectTransport', 'IntegrationVmwareVcenterObjectFolders',
        'IntegrationVmwareVcenterDirectRestTransport', 'IntegrationVmwareVcenterDatastore') as $class) {
        require_once(__DIR__ . '/../../../functions/classes/class.' . $class . '.php');
    }
    $Store = new IntegrationVmwareVcenterConfigStore($Database, IntegrationSecretCipher::from_keyring_file());
    // Enabled connector and saved TLS policy are authoritative. No client-supplied endpoint/credential.
    $runtime = $Store->load_runtime_config((int)$id, true);
    // Do not hold phpIPAM's session lock during bounded vCenter reads.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($mode === 'graph') {
        $Reader = new IntegrationVmwareVcenterObjectFolders(new IntegrationVmwareVcenterDirectTransport(4194304));
        $response = $Reader->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], (bool)$runtime['verify_tls']);
    } else {
        // Optional bulk datastore facts; folder parentage comes only from the proven SOAP graph.
        $Reader = new IntegrationVmwareVcenterDatastore(new IntegrationVmwareVcenterDirectRestTransport());
        $data = $Reader->read_inventory($runtime['endpoint'], $runtime['username'], $runtime['password'], array(), (bool)$runtime['verify_tls']);
        $rows = array();
        foreach ($data['datastores'] as $row) {
            $rows[] = array_intersect_key($row, array_flip(array('external_id', 'name', 'type', 'capacity_bytes', 'free_space_bytes')));
        }
        $response = array('ok' => true, 'datastores' => $rows);
    }
    $response['connector_id'] = (int)$id;
    $response['verify_tls'] = (bool)$runtime['verify_tls'];
    $response['persistent_inventory_written'] = false;
    $response['phpipam_objects_modified'] = false;
    $response['vcenter_objects_modified'] = false;
} catch (Throwable $error) {
    // Never return exception messages, endpoint, credentials, raw XML or response bodies.
    $status = 502;
    $response = array('ok' => false, 'error' => 'folder_read_unavailable');
} finally {
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) {
        if (function_exists('sodium_memzero')) sodium_memzero($runtime['password']);
        else $runtime['password'] = str_repeat("\0", strlen($runtime['password']));
    }
}
phase5f332_reply($response, $status);
