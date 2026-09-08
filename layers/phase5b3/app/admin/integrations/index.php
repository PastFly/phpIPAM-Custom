<?php

/**
 * phpIPAM Custom — Administration / Integrations, persistent VMware vCenter configuration.
 */

$User->check_user_session();

if (!$User->is_admin(false)) {
    header("Location: " . create_link("error", "403"));
    die();
}

// PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_V1
// PHPIPAM_CUSTOM_VMWARE_VCENTER_TEST_UI_V1
// PHPIPAM_CUSTOM_VMWARE_PERSISTENT_CONFIG_UI_V1
$integrations_registry = require(dirname(__FILE__) . "/../../integrations/registry.php");
if (!is_array($integrations_registry)) {
    $integrations_registry = array();
}

if (!function_exists('phpipam_custom_admin_integrations_h')) {
    function phpipam_custom_admin_integrations_h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
    }
}

$persistentConfigs = array();
$persistentReady = false;
$persistentUnavailable = false;

try {
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
    require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");

    $persistentCipher = IntegrationSecretCipher::from_keyring_file();
    $persistentStore = new IntegrationVmwareVcenterConfigStore($Database, $persistentCipher);
    $persistentConfigs = $persistentStore->list_configs();
    $persistentReady = true;
} catch (Exception $e) {
    // Never render raw keyring/database exception text into Administration HTML.
    $persistentUnavailable = true;
}

$vmwareConfigCsrf = $User->Crypto->csrf_cookie("create", "integration_vmware_vcenter_config");
$vmwareTestCsrf = $User->Crypto->csrf_cookie("create", "integration_vmware_vcenter_test");
?>

<div class="col-xs-12">
    <div class="page-header" style="margin-top:0;">
        <h3 style="margin-top:0;">
            <i class="fa fa-plug"></i>
            Integrations
        </h3>
        <p class="text-muted" style="margin-bottom:0;">
            Управление внешними инфраструктурными коннекторами phpIPAM Custom.
        </p>
    </div>

    <?php if ($persistentReady) { ?>
        <div class="alert alert-success">
            <i class="fa fa-lock"></i>
            Persistent VMware configuration storage доступно. Пароли хранятся только в зашифрованном envelope AES-256-GCM;
            master key находится вне web-root. Пароль никогда не отображается обратно в форме.
        </div>

        <div class="panel panel-default" id="vmware-vcenter-persistent-panel">
            <div class="panel-heading">
                <strong><i class="fa fa-cloud"></i> VMware vCenter — сохранённые подключения</strong>
                <span class="label label-primary pull-right">read-only</span>
            </div>
            <div class="panel-body">
                <form class="form-horizontal" id="vmware-persistent-config-form" autocomplete="off">
                    <input type="hidden" name="csrf_cookie" value="<?php print phpipam_custom_admin_integrations_h($vmwareConfigCsrf); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="config_id" id="vmware-config-id" value="">
                    <input type="hidden" name="verify_tls" id="vmware-config-verify-tls-value" value="1">
                    <input type="hidden" name="enabled" id="vmware-config-enabled-value" value="0">
                    <input type="hidden" name="allow_insecure_tls" id="vmware-config-insecure-ack-value" value="0">

                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="vmware-config-name">Название</label>
                        <div class="col-sm-4">
                            <input type="text" class="form-control input-sm" id="vmware-config-name" name="name" maxlength="128" required>
                        </div>

                        <label class="col-sm-2 control-label" for="vmware-config-endpoint">Endpoint</label>
                        <div class="col-sm-4">
                            <input type="text" class="form-control input-sm" id="vmware-config-endpoint" name="endpoint" maxlength="255" placeholder="https://vcenter.example.local" autocomplete="off" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="vmware-config-username">Username</label>
                        <div class="col-sm-4">
                            <input type="text" class="form-control input-sm" id="vmware-config-username" name="username" maxlength="255" autocomplete="off" required>
                        </div>

                        <label class="col-sm-2 control-label" for="vmware-config-password">Password</label>
                        <div class="col-sm-4">
                            <input type="password" class="form-control input-sm" id="vmware-config-password" name="password" maxlength="4096" autocomplete="new-password">
                            <span class="help-block" style="margin-bottom:0;">
                                При создании обязателен. При редактировании пустое поле сохраняет текущий encrypted credential.
                            </span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:8px;">
                        <div class="col-sm-offset-2 col-sm-10">
                            <label style="font-weight:normal; margin-right:20px;">
                                <input type="checkbox" id="vmware-config-verify-tls" checked>
                                Проверять TLS certificate
                            </label>
                            <label style="font-weight:normal; margin-right:20px;">
                                <input type="checkbox" id="vmware-config-enabled">
                                Connector enabled
                            </label>
                        </div>
                    </div>

                    <div class="form-group" id="vmware-config-insecure-ack-row" style="display:none; margin-bottom:8px;">
                        <div class="col-sm-offset-2 col-sm-10">
                            <div class="alert alert-warning" style="margin-bottom:0; padding:8px 12px;">
                                <label style="font-weight:normal; margin-bottom:0;">
                                    <input type="checkbox" id="vmware-config-insecure-ack">
                                    Я явно подтверждаю сохранение конфигурации с отключённой TLS verification.
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <div class="col-sm-offset-2 col-sm-10">
                            <button type="submit" class="btn btn-sm btn-primary" id="vmware-config-save-submit">
                                <i class="fa fa-save"></i> Сохранить
                            </button>
                            <button type="button" class="btn btn-sm btn-default" id="vmware-config-reset">
                                Новое подключение
                            </button>
                            <span class="text-muted" style="margin-left:10px;">
                                Рекомендуется сначала сохранить отключённым, выполнить Test, затем включить.
                            </span>
                        </div>
                    </div>
                </form>

                <div id="vmware-config-action-result" style="margin-top:15px;"></div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-condensed" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th>Название</th>
                            <th>Endpoint</th>
                            <th>Username</th>
                            <th>Enabled</th>
                            <th>TLS</th>
                            <th>Credential</th>
                            <th>Последняя проверка</th>
                            <th style="min-width:280px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($persistentConfigs) === 0) { ?>
                            <tr>
                                <td colspan="8" class="text-muted">Сохранённых vCenter подключений пока нет.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($persistentConfigs as $config) { ?>
                                <?php
                                    $configId = (int)$config['id'];
                                    $enabled = !empty($config['enabled']);
                                    $verifyTls = !empty($config['verify_tls']);
                                    $hasCredential = !empty($config['has_credential']);
                                    $needsRotation = !empty($config['credential_needs_rotation']);
                                ?>
                                <tr>
                                    <td><?php print phpipam_custom_admin_integrations_h($config['name']); ?></td>
                                    <td><code><?php print phpipam_custom_admin_integrations_h($config['endpoint']); ?></code></td>
                                    <td><?php print phpipam_custom_admin_integrations_h($config['username']); ?></td>
                                    <td>
                                        <span class="label <?php print $enabled ? 'label-success' : 'label-default'; ?>">
                                            <?php print $enabled ? 'ON' : 'OFF'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="label <?php print $verifyTls ? 'label-success' : 'label-warning'; ?>">
                                            <?php print $verifyTls ? 'VERIFY' : 'INSECURE'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($hasCredential) { ?>
                                            <span class="label label-success">encrypted</span>
                                            <?php if ($needsRotation) { ?><span class="label label-warning">rotation</span><?php } ?>
                                        <?php } else { ?>
                                            <span class="label label-danger">missing</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($config['last_test_at'])) { ?>
                                            <?php print phpipam_custom_admin_integrations_h($config['last_test_at']); ?>
                                            <?php if (!empty($config['last_error_code'])) { ?>
                                                <br><span class="text-danger">error: <?php print phpipam_custom_admin_integrations_h($config['last_error_code']); ?></span>
                                            <?php } elseif (!empty($config['last_success_at'])) { ?>
                                                <br><span class="text-success">success</span>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <span class="text-muted">не выполнялась</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-xs btn-default vmware-config-edit"
                                            data-id="<?php print $configId; ?>"
                                            data-name="<?php print phpipam_custom_admin_integrations_h($config['name']); ?>"
                                            data-endpoint="<?php print phpipam_custom_admin_integrations_h($config['endpoint']); ?>"
                                            data-username="<?php print phpipam_custom_admin_integrations_h($config['username']); ?>"
                                            data-enabled="<?php print $enabled ? '1' : '0'; ?>"
                                            data-verify-tls="<?php print $verifyTls ? '1' : '0'; ?>">
                                            <i class="fa fa-pencil"></i> Edit
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-xs btn-primary vmware-config-action"
                                            data-action="test"
                                            data-id="<?php print $configId; ?>"
                                            <?php print $hasCredential ? '' : 'disabled'; ?>>
                                            <i class="fa fa-plug"></i> Test
                                        </button>

                                        <?php if ($needsRotation) { ?>
                                            <button type="button" class="btn btn-xs btn-warning vmware-config-action" data-action="rotate" data-id="<?php print $configId; ?>">
                                                Rotate key
                                            </button>
                                        <?php } ?>

                                        <?php if ($hasCredential) { ?>
                                            <button type="button" class="btn btn-xs btn-warning vmware-config-action" data-action="delete_credential" data-id="<?php print $configId; ?>">
                                                Delete credential
                                            </button>
                                        <?php } ?>

                                        <button type="button" class="btn btn-xs btn-danger vmware-config-action" data-action="delete_config" data-id="<?php print $configId; ?>">
                                            Delete config
                                        </button>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php } elseif ($persistentUnavailable) { ?>
        <div class="alert alert-warning">
            <i class="fa fa-warning"></i>
            Persistent VMware configuration storage сейчас недоступно. Секретные детали ошибки намеренно не выводятся.
            Проверьте Phase 5B.3 foundation verifier на сервере.
        </div>
    <?php } ?>

    <div class="panel panel-default" id="vmware-vcenter-test-panel">
        <div class="panel-heading">
            <strong><i class="fa fa-wrench"></i> VMware vCenter — transient diagnostic test</strong>
            <span class="label label-default pull-right">не сохраняется</span>
        </div>
        <div class="panel-body">
            <form class="form-horizontal" id="vmware-vcenter-test-form" autocomplete="off">
                <input type="hidden" name="csrf_cookie" value="<?php print phpipam_custom_admin_integrations_h($vmwareTestCsrf); ?>">

                <div class="form-group">
                    <label class="col-sm-2 control-label" for="vmware-vcenter-endpoint">Endpoint</label>
                    <div class="col-sm-10">
                        <input type="text" class="form-control input-sm" id="vmware-vcenter-endpoint" name="endpoint" placeholder="https://vcenter.example.local" autocomplete="off" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label" for="vmware-vcenter-username">Username</label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control input-sm" id="vmware-vcenter-username" name="username" autocomplete="off" required>
                    </div>
                    <label class="col-sm-2 control-label" for="vmware-vcenter-password">Password</label>
                    <div class="col-sm-4">
                        <input type="password" class="form-control input-sm" id="vmware-vcenter-password" name="password" autocomplete="new-password" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <div class="col-sm-offset-2 col-sm-10">
                        <input type="hidden" name="verify_tls" value="0">
                        <label style="font-weight:normal; margin-right:15px;">
                            <input type="checkbox" name="verify_tls" value="1" checked>
                            Проверять TLS certificate
                        </label>
                        <button type="submit" class="btn btn-sm btn-default" id="vmware-vcenter-test-submit">
                            <i class="fa fa-plug"></i> One-time test
                        </button>
                    </div>
                </div>
            </form>
            <div id="vmware-vcenter-test-result" style="margin-top:15px;"></div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><strong>Connector registry</strong></div>
        <div class="table-responsive">
            <table class="table table-hover table-condensed" style="margin-bottom:0;">
                <thead><tr><th>Connector</th><th>Состояние</th><th>Режим</th><th>Следующий шаг</th></tr></thead>
                <tbody>
                    <?php foreach ($integrations_registry as $connector) { ?>
                        <tr>
                            <td><i class="fa <?php print phpipam_custom_admin_integrations_h($connector['icon']); ?>"></i> <?php print phpipam_custom_admin_integrations_h($connector['name']); ?></td>
                            <td><?php print phpipam_custom_admin_integrations_h($connector['state_label']); ?></td>
                            <td><code><?php print phpipam_custom_admin_integrations_h($connector['mode']); ?></code></td>
                            <td><?php print $connector['id'] === 'vmware-vcenter' ? 'Persistent secure config; затем read-only inventory/reconciliation.' : 'После стабилизации VMware/vCenter коннектора.'; ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-info" style="margin-bottom:0;">
        <i class="fa fa-info-circle"></i>
        phpIPAM остаётся authoritative IPAM. VMware используется как read-only observation source; write-back в vCenter и автоматическое изменение IPAM-объектов не выполняются.
    </div>
</div>

<script type="text/javascript">
$(document).ready(function () {
    function syncPersistentFlags() {
        var verifyTls = $('#vmware-config-verify-tls').is(':checked');
        var enabled = $('#vmware-config-enabled').is(':checked');
        var insecureAck = $('#vmware-config-insecure-ack').is(':checked');
        $('#vmware-config-verify-tls-value').val(verifyTls ? '1' : '0');
        $('#vmware-config-enabled-value').val(enabled ? '1' : '0');
        $('#vmware-config-insecure-ack-value').val(insecureAck ? '1' : '0');
        $('#vmware-config-insecure-ack-row').toggle(!verifyTls);
        if (verifyTls) {
            $('#vmware-config-insecure-ack').prop('checked', false);
            $('#vmware-config-insecure-ack-value').val('0');
        }
    }

    function resetPersistentForm() {
        $('#vmware-config-id').val('');
        $('#vmware-config-name').val('');
        $('#vmware-config-endpoint').val('');
        $('#vmware-config-username').val('');
        $('#vmware-config-password').val('');
        $('#vmware-config-verify-tls').prop('checked', true);
        $('#vmware-config-enabled').prop('checked', false);
        $('#vmware-config-insecure-ack').prop('checked', false);
        syncPersistentFlags();
    }

    $('#vmware-config-verify-tls, #vmware-config-enabled, #vmware-config-insecure-ack').on('change', syncPersistentFlags);
    syncPersistentFlags();

    $('#vmware-config-reset').on('click', function () {
        resetPersistentForm();
        $('#vmware-config-action-result').empty();
    });

    $('.vmware-config-edit').on('click', function () {
        var $button = $(this);
        $('#vmware-config-id').val($button.attr('data-id'));
        $('#vmware-config-name').val($button.attr('data-name'));
        $('#vmware-config-endpoint').val($button.attr('data-endpoint'));
        $('#vmware-config-username').val($button.attr('data-username'));
        $('#vmware-config-password').val('');
        $('#vmware-config-enabled').prop('checked', $button.attr('data-enabled') === '1');
        $('#vmware-config-verify-tls').prop('checked', $button.attr('data-verify-tls') === '1');
        $('#vmware-config-insecure-ack').prop('checked', false);
        syncPersistentFlags();
        $('html, body').animate({scrollTop: $('#vmware-vcenter-persistent-panel').offset().top - 20}, 150);
    });

    $('#vmware-persistent-config-form').on('submit', function (event) {
        event.preventDefault();
        syncPersistentFlags();
        var $form = $(this);
        var $button = $('#vmware-config-save-submit');
        var $result = $('#vmware-config-action-result');

        if ($('#vmware-config-id').val() === '' && $('#vmware-config-password').val() === '') {
            $result.html('<div class="alert alert-danger">Для нового подключения требуется password.</div>');
            return;
        }

        $button.prop('disabled', true);
        $result.html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Сохранение...</div>');
        $.ajax({
            url: 'app/admin/integrations/vmware-config-action.php',
            type: 'POST',
            data: $form.serialize(),
            cache: false
        }).done(function (response) {
            $result.html(response);
            $('#vmware-config-password').val('');
            if (String(response).indexOf('data-phase5b3-refresh="1"') !== -1) {
                window.setTimeout(function () { window.location.reload(); }, 700);
            }
        }).fail(function () {
            $result.html('<div class="alert alert-danger">Не удалось выполнить запрос управления vCenter configuration.</div>');
            $('#vmware-config-password').val('');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });

    $('.vmware-config-action').on('click', function () {
        var $button = $(this);
        var action = $button.attr('data-action');
        var id = $button.attr('data-id');
        var $result = $('#vmware-config-action-result');
        var confirmText = null;

        if (action === 'delete_credential') {
            confirmText = 'Удалить сохранённый encrypted credential? Connector будет автоматически отключён.';
        } else if (action === 'delete_config') {
            confirmText = 'Удалить конфигурацию vCenter полностью, включая encrypted credential?';
        } else if (action === 'rotate') {
            confirmText = 'Перешифровать credential текущим active key?';
        }
        if (confirmText !== null && !window.confirm(confirmText)) {
            return;
        }

        $button.prop('disabled', true);
        $result.html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Выполнение операции...</div>');
        $.ajax({
            url: 'app/admin/integrations/vmware-config-action.php',
            type: 'POST',
            data: {
                csrf_cookie: <?php print json_encode((string)$vmwareConfigCsrf); ?>,
                action: action,
                config_id: id
            },
            cache: false
        }).done(function (response) {
            $result.html(response);
            if (String(response).indexOf('data-phase5b3-refresh="1"') !== -1) {
                window.setTimeout(function () { window.location.reload(); }, action === 'test' ? 1200 : 700);
            }
        }).fail(function () {
            $result.html('<div class="alert alert-danger">Операция vCenter configuration завершилась ошибкой.</div>');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });

    $('#vmware-vcenter-test-form').on('submit', function (event) {
        event.preventDefault();
        var $form = $(this);
        var $button = $('#vmware-vcenter-test-submit');
        var $result = $('#vmware-vcenter-test-result');
        $button.prop('disabled', true);
        $result.html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Проверка vCenter...</div>');
        $.ajax({
            url: 'app/admin/integrations/vmware-test-result.php',
            type: 'POST',
            data: $form.serialize(),
            cache: false
        }).done(function (response) {
            $result.html(response);
        }).fail(function () {
            $result.html('<div class="alert alert-danger">Не удалось выполнить transient vCenter test.</div>');
        }).always(function () {
            $('#vmware-vcenter-password').val('');
            $button.prop('disabled', false);
        });
    });
});
</script>
