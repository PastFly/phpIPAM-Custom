<?php

/**
 * phpIPAM Custom — persistent VMware vCenter configuration actions.
 *
 * Secret handling rules:
 * - administrator session + dedicated CSRF are mandatory;
 * - passwords are accepted only by POST and never rendered/logged;
 * - database stores only AES-256-GCM envelopes;
 * - stored-credential tests persist only allowlisted error categories;
 * - vCenter remains read-only.
 */

require_once(dirname(__FILE__) . "/../../../functions/functions.php");
disable_php_errors();

$Database = new Database_PDO;
$User = new User($Database);
$Result = new Result();

$User->check_user_session();

if (!$User->is_admin(false)) {
    $Result->show("danger", _("Administrative privileges required"), true, true, false, true);
}

$csrf = isset($POST->csrf_cookie) ? (string)$POST->csrf_cookie : '';
if ($User->Crypto->csrf_cookie("validate", "integration_vmware_vcenter_config", $csrf) === false) {
    $Result->show("danger", _("Invalid CSRF cookie"), true, true, false, true);
}

// PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_ACTION_V1

function phpipam_custom_vmware_action_response($type, $message, $refresh = false)
{
    $allowed = array('success', 'danger', 'warning', 'info');
    $type = in_array((string)$type, $allowed, true) ? (string)$type : 'danger';
    $safe = htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8');
    $refreshAttr = $refresh ? ' data-phase5b3-refresh="1"' : '';
    print '<div class="alert alert-' . $type . '"' . $refreshAttr . '>' . $safe . '</div>';
    exit;
}

function phpipam_custom_vmware_post_bool($post, $field)
{
    $raw = isset($post->{$field}) ? (string)$post->{$field} : '';
    if ($raw !== '0' && $raw !== '1') {
        throw new InvalidArgumentException('Invalid boolean field');
    }
    return $raw === '1';
}

function phpipam_custom_vmware_error_category($error)
{
    $message = strtolower((string)$error->getMessage());

    if (strpos($message, 'certificate') !== false || strpos($message, 'ssl') !== false || strpos($message, 'tls') !== false) {
        return 'tls';
    }
    if (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false) {
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
    if (strpos($message, 'http ') !== false) {
        return 'http';
    }
    if (strpos($message, 'json') !== false || strpos($message, 'response') !== false || strpos($message, 'session token') !== false || strpos($message, 'safety limit') !== false) {
        return 'invalid_response';
    }
    if ($error instanceof InvalidArgumentException || strpos($message, 'config') !== false || strpos($message, 'connector') !== false || strpos($message, 'credential') !== false) {
        return 'configuration';
    }
    return 'unknown';
}

function phpipam_custom_vmware_zero_secret(&$value)
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

$action = isset($POST->action) ? trim((string)$POST->action) : '';
$allowedActions = array('save', 'test', 'delete_credential', 'delete_config', 'rotate');
if (!in_array($action, $allowedActions, true)) {
    phpipam_custom_vmware_action_response('danger', 'Unsupported VMware configuration action.');
}

require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");

try {
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
} catch (Exception $e) {
    phpipam_custom_vmware_action_response('danger', 'Persistent VMware configuration storage is unavailable. Run the Phase 5B.3 verifier.');
}

$actorId = isset($User->user->id) ? (int)$User->user->id : 0;
if ($actorId <= 0) {
    phpipam_custom_vmware_action_response('danger', 'Unable to resolve the authenticated administrator id.');
}

try {
    if ($action === 'save') {
        $verifyTls = phpipam_custom_vmware_post_bool($POST, 'verify_tls');
        $enabled = phpipam_custom_vmware_post_bool($POST, 'enabled');
        $allowInsecure = phpipam_custom_vmware_post_bool($POST, 'allow_insecure_tls');
        $password = isset($POST->password) ? (string)$POST->password : '';

        $input = array(
            'name' => isset($POST->name) ? (string)$POST->name : '',
            'endpoint' => isset($POST->endpoint) ? (string)$POST->endpoint : '',
            'username' => isset($POST->username) ? (string)$POST->username : '',
            'password' => $password,
            'verify_tls' => $verifyTls,
            'enabled' => $enabled,
            'allow_insecure_tls' => $allowInsecure
        );

        $idRaw = isset($POST->config_id) ? trim((string)$POST->config_id) : '';
        try {
            if ($idRaw === '') {
                $saved = $Store->create_config($input, $actorId);
            } else {
                $saved = $Store->update_config($idRaw, $input, $actorId);
            }
        } finally {
            phpipam_custom_vmware_zero_secret($password);
            $input['password'] = '';
        }

        phpipam_custom_vmware_action_response('success', 'VMware configuration saved. Credential is stored only as an encrypted envelope.', true);
    }

    $id = isset($POST->config_id) ? (string)$POST->config_id : '';

    if ($action === 'test') {
        $runtime = null;
        try {
            $runtime = $Store->load_runtime_config($id, false);
            $Vmware = new IntegrationVmwareVcenter();
            $test = $Vmware->test_connection(
                $runtime['endpoint'],
                $runtime['username'],
                $runtime['password'],
                $runtime['verify_tls']
            );
            $Store->mark_test_success($id);

            $family = in_array((string)$test['api_family'], array('modern', 'legacy'), true) ? (string)$test['api_family'] : 'unknown';
            $count = max(0, (int)$test['datacenter_count']);
            $duration = max(0, (int)$test['duration_ms']);
            $tlsText = !empty($test['verify_tls']) ? 'enabled' : 'DISABLED';

            phpipam_custom_vmware_zero_secret($runtime['password']);
            phpipam_custom_vmware_action_response(
                'success',
                'Stored vCenter connection OK. API=' . $family . '; Datacenters=' . $count . '; TLS verification=' . $tlsText . '; duration=' . $duration . ' ms.',
                true
            );
        } catch (Exception $e) {
            if (is_array($runtime) && isset($runtime['password'])) {
                phpipam_custom_vmware_zero_secret($runtime['password']);
            }
            $category = phpipam_custom_vmware_error_category($e);
            try {
                $Store->mark_test_failure($id, $category);
            } catch (Exception $ignored) {
                // Never replace the primary sanitized result with a metadata-write failure.
            }
            phpipam_custom_vmware_action_response('danger', 'Stored vCenter connection failed. Sanitized category: ' . $category . '.', true);
        }
    }

    if ($action === 'delete_credential') {
        $Store->delete_credential($id, $actorId);
        phpipam_custom_vmware_action_response('success', 'Stored credential deleted. Connector was automatically disabled.', true);
    }

    if ($action === 'rotate') {
        $Store->rotate_credential($id, $actorId);
        phpipam_custom_vmware_action_response('success', 'Stored credential was re-encrypted with the current active key.', true);
    }

    if ($action === 'delete_config') {
        // Resolve the row through the provider-scoped store first, then delete by
        // id + provider so an unrelated future integration row cannot be removed.
        $Store->get_config($id);
        if (!method_exists($Database, 'deleteRow')) {
            throw new RuntimeException('Database delete operation unavailable');
        }
        $Database->deleteRow(
            IntegrationVmwareVcenterConfigStore::TABLE,
            'id',
            (int)$id,
            'provider',
            IntegrationVmwareVcenterConfigStore::PROVIDER
        );
        phpipam_custom_vmware_action_response('success', 'VMware connector configuration deleted.', true);
    }
} catch (InvalidArgumentException $e) {
    phpipam_custom_vmware_action_response('danger', 'Configuration validation failed. Check required fields, HTTPS endpoint, duplicate endpoint, credential state and TLS acknowledgement.');
} catch (Exception $e) {
    phpipam_custom_vmware_action_response('danger', 'VMware configuration operation failed. Sensitive exception details were suppressed.');
}

phpipam_custom_vmware_action_response('danger', 'VMware configuration action did not complete.');
