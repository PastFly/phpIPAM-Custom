<?php

$root = dirname(__DIR__);
$index = file_get_contents($root . '/layers/phase5b3/app/admin/integrations/index.php');
$action = file_get_contents($root . '/layers/phase5b3/app/admin/integrations/vmware-config-action.php');
$installer = file_get_contents($root . '/installer/install-vmware-vcenter-phase5b3-ui.sh');
$verifier = file_get_contents($root . '/installer/verify-vmware-vcenter-phase5b3-ui.sh');

$passed = 0;
$failed = 0;

function check_ui($condition, $label)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo "FAIL: {$label}\n";
    }
}

foreach (array('index' => $index, 'action' => $action, 'installer' => $installer, 'verifier' => $verifier) as $name => $content) {
    check_ui(is_string($content) && $content !== '', $name . ' source is readable');
}

check_ui(strpos($index, 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_UI_V1') !== false, 'persistent Administration UI marker present');
check_ui(strpos($index, '$User->check_user_session();') !== false, 'Administration page checks session');
check_ui(strpos($index, 'is_admin(false)') !== false, 'Administration page requires administrator');
check_ui(strpos($index, 'IntegrationSecretCipher::from_keyring_file()') !== false, 'Administration page loads external encrypted keyring');
check_ui(strpos($index, 'IntegrationVmwareVcenterConfigStore') !== false, 'Administration page reads connector metadata through config store');
check_ui(strpos($index, 'secret_envelope') === false && strpos($index, 'secret_key_id') === false, 'Administration page never references secret database fields');
check_ui(strpos($index, 'name="password"') !== false && strpos($index, 'autocomplete="new-password"') !== false, 'password input is write-only browser field');
check_ui(strpos($index, "$('#vmware-config-password').val('');") !== false, 'persistent password field cleared after AJAX operation');
check_ui(strpos($index, "$('#vmware-vcenter-password').val('');") !== false, 'transient password field clear behavior preserved');
check_ui(strpos($index, 'id="vmware-config-verify-tls" checked') !== false, 'TLS verification defaults enabled in UI');
check_ui(strpos($index, 'allow_insecure_tls') !== false && strpos($index, 'явно подтверждаю') !== false, 'insecure TLS persistence requires visible explicit acknowledgement');
check_ui(strpos($index, 'пустое поле сохраняет текущий encrypted credential') !== false, 'blank-password retain semantics explained');
check_ui(strpos($index, 'Delete credential') !== false, 'explicit credential deletion action exposed');
check_ui(strpos($index, 'Delete config') !== false, 'explicit connector deletion action exposed');
check_ui(strpos($index, 'Rotate key') !== false, 'credential key rotation action available when required');
check_ui(strpos($index, 'VMware используется как read-only observation source') !== false, 'read-only authority boundary shown in UI');

check_ui(strpos($action, 'PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_ACTION_V1') !== false, 'persistent action endpoint marker present');
check_ui(strpos($action, '$User->check_user_session();') !== false, 'action endpoint checks session');
check_ui(strpos($action, 'is_admin(false)') !== false, 'action endpoint requires administrator');
check_ui(strpos($action, 'csrf_cookie("validate", "integration_vmware_vcenter_config"') !== false, 'action endpoint validates dedicated CSRF');
check_ui(strpos($action, "array('save', 'test', 'delete_credential', 'delete_config', 'rotate')") !== false, 'action endpoint uses explicit operation allowlist');
check_ui(strpos($action, 'IntegrationSecretCipher::from_keyring_file()') !== false, 'action endpoint opens external keyring with permission enforcement');
check_ui(strpos($action, 'create_config($input, $actorId)') !== false, 'create operation uses encrypted config store');
check_ui(strpos($action, 'update_config($idRaw, $input, $actorId)') !== false, 'update operation uses encrypted config store');
check_ui(strpos($action, 'load_runtime_config($id, false)') !== false, 'stored test decrypts credential only at runtime');
check_ui(strpos($action, 'test_connection(') !== false, 'stored test invokes read-only VMware connection test');
check_ui(strpos($action, 'mark_test_success($id)') !== false, 'successful stored test records health metadata');
check_ui(strpos($action, 'mark_test_failure($id, $category)') !== false, 'failed stored test persists only sanitized category');
check_ui(strpos($action, 'delete_credential($id, $actorId)') !== false, 'credential deletion uses store safety semantics');
check_ui(strpos($action, 'rotate_credential($id, $actorId)') !== false, 'credential rotation uses authenticated re-encryption path');
check_ui(strpos($action, 'IntegrationVmwareVcenterConfigStore::PROVIDER') !== false && strpos($action, "'provider'") !== false, 'full config delete is provider scoped');
check_ui(strpos($action, 'phpipam_custom_vmware_zero_secret') !== false && strpos($action, 'sodium_memzero') !== false, 'plaintext secret buffers are explicitly cleared when possible');
check_ui(strpos($action, 'Sensitive exception details were suppressed.') !== false, 'generic exception response suppresses sensitive detail');
check_ui(!preg_match('/phpipam_custom_vmware_action_response\([^;]*getMessage\s*\(/s', $action), 'raw exception message is never rendered by action response');
check_ui(strpos($action, 'secret_envelope') === false && strpos($action, 'secret_key_id') === false, 'action endpoint never directly handles secret envelope columns');
check_ui(!preg_match('#/(api|rest)/[^\"\']*(create|update|patch|delete)#i', $action), 'action endpoint contains no direct vCenter write API path');

foreach (array('tls', 'timeout', 'authentication', 'authorization', 'network', 'http', 'invalid_response', 'configuration', 'unknown') as $category) {
    check_ui(strpos($action, "'{$category}'") !== false, 'sanitized error category available: ' . $category);
}

check_ui(strpos($installer, 'verify-vmware-vcenter-phase5b3-foundation.sh') !== false, 'UI installer requires verified Phase 5B.3 foundation');
check_ui(strpos($installer, 'c5a7d8b4b3248e6eb561e9cb7c5af0b82d59e3d4') !== false, 'UI installer pins exact Phase 5A Administration index baseline');
check_ui(strpos($installer, 'b3ba819ca80e96a89edcafcc67d76c5d7cb2c9c0') !== false, 'UI installer pins transient test endpoint sentinel');
check_ui(strpos($installer, 'CREATE TABLE') === false && strpos($installer, 'DROP TABLE') === false, 'UI installer has no schema mutation');
check_ui(strpos($installer, '/etc/phpipam-custom/integrations.key') === false, 'UI installer does not create or rewrite key material');
check_ui(strpos($installer, 'PHASE5B3_UI_INSTALL_OK') !== false, 'UI installer has explicit success marker');
check_ui(strpos($installer, 'PHASE5B3_UI_ROLLBACK_OK') !== false, 'UI installer has source-only rollback marker');

check_ui(strpos($verifier, 'verify-vmware-vcenter-phase5b3-foundation.sh') !== false, 'UI verifier re-validates secure foundation');
check_ui(strpos($verifier, 'secret_envelope|secret_key_id') !== false, 'UI verifier rejects secret-field rendering');
check_ui(strpos($verifier, 'PHASE5B3_UI_VERIFY_OK') !== false, 'UI verifier has explicit success marker');

echo "\nPHASE5B3_ADMIN_UI_REGRESSION: passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
