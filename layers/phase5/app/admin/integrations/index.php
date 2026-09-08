<?php

/**
 * phpIPAM Custom — Administration / Integrations, Phase 5A.
 */

$User->check_user_session();

if (!$User->is_admin(false)) {
    header("Location: " . create_link("error", "403"));
    die();
}

// PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_V1
// PHPIPAM_CUSTOM_VMWARE_VCENTER_TEST_UI_V1
$integrations_registry = require(dirname(__FILE__) . "/../../integrations/registry.php");

if (!is_array($integrations_registry)) {
    $integrations_registry = array();
}

function phpipam_custom_admin_integrations_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$vmwareTestCsrf = $User->Crypto->csrf_cookie("create", "integration_vmware_vcenter_test");
?>

<div class="col-xs-12">
    <div class="page-header" style="margin-top:0;">
        <h3 style="margin-top:0;">
            <i class="fa fa-plug"></i>
            Integrations
        </h3>
        <p class="text-muted" style="margin-bottom:0;">
            Конфигурационная поверхность внешних инфраструктурных коннекторов phpIPAM Custom.
        </p>
    </div>

    <div class="alert alert-warning">
        <i class="fa fa-lock"></i>
        Phase 5A: VMware vCenter можно проверить без сохранения конфигурации.
        Endpoint, username, password и vCenter session token <strong>не записываются</strong> в БД или настройки phpIPAM.
        После проверки поле password очищается в браузере.
    </div>

    <div class="panel panel-default" id="vmware-vcenter-test-panel">
        <div class="panel-heading">
            <strong><i class="fa fa-cloud"></i> VMware vCenter — transient connection test</strong>
            <span class="label label-primary pull-right">read-only</span>
        </div>
        <div class="panel-body">
            <form class="form-horizontal" id="vmware-vcenter-test-form" autocomplete="off">
                <input type="hidden" name="csrf_cookie" value="<?php print phpipam_custom_admin_integrations_h($vmwareTestCsrf); ?>">

                <div class="form-group">
                    <label class="col-sm-2 control-label" for="vmware-vcenter-endpoint">Endpoint</label>
                    <div class="col-sm-10">
                        <input
                            type="text"
                            class="form-control input-sm"
                            id="vmware-vcenter-endpoint"
                            name="endpoint"
                            placeholder="https://vcenter.example.local"
                            autocomplete="off"
                            required>
                        <span class="help-block" style="margin-bottom:0;">
                            Только HTTPS base URL. Путь <code>/ui</code> указывать не нужно.
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-2 control-label" for="vmware-vcenter-username">Username</label>
                    <div class="col-sm-4">
                        <input
                            type="text"
                            class="form-control input-sm"
                            id="vmware-vcenter-username"
                            name="username"
                            autocomplete="off"
                            required>
                    </div>

                    <label class="col-sm-2 control-label" for="vmware-vcenter-password">Password</label>
                    <div class="col-sm-4">
                        <input
                            type="password"
                            class="form-control input-sm"
                            id="vmware-vcenter-password"
                            name="password"
                            autocomplete="new-password"
                            required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <div class="col-sm-offset-2 col-sm-10">
                        <input type="hidden" name="verify_tls" value="0">
                        <label style="font-weight:normal; margin-right:15px;">
                            <input type="checkbox" name="verify_tls" value="1" checked>
                            Проверять TLS certificate
                        </label>

                        <button type="submit" class="btn btn-sm btn-primary" id="vmware-vcenter-test-submit">
                            <i class="fa fa-plug"></i> Проверить подключение
                        </button>
                    </div>
                </div>
            </form>

            <div id="vmware-vcenter-test-result" style="margin-top:15px;"></div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>Connector registry</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-condensed" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>Connector</th>
                        <th>Состояние</th>
                        <th>Режим</th>
                        <th>Следующий шаг</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($integrations_registry as $connector) { ?>
                        <tr>
                            <td>
                                <i class="fa <?php print phpipam_custom_admin_integrations_h($connector["icon"]); ?>"></i>
                                <?php print phpipam_custom_admin_integrations_h($connector["name"]); ?>
                            </td>
                            <td><?php print phpipam_custom_admin_integrations_h($connector["state_label"]); ?></td>
                            <td><code><?php print phpipam_custom_admin_integrations_h($connector["mode"]); ?></code></td>
                            <td>
                                <?php if ($connector["id"] === "vmware-vcenter") { ?>
                                    Runtime-проверка подключения; затем отдельное решение по secure storage и scheduled sync.
                                <?php } else { ?>
                                    После стабилизации VMware/vCenter коннектора.
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-info" style="margin-bottom:0;">
        <i class="fa fa-info-circle"></i>
        phpIPAM остаётся authoritative IPAM. Текущий VMware test только аутентифицируется, читает список Datacenter и закрывает сессию; write-back не выполняется.
    </div>
</div>

<script type="text/javascript">
$(document).ready(function () {
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
            $result.html('<div class="alert alert-danger">Не удалось выполнить запрос проверки vCenter.</div>');
        }).always(function () {
            $('#vmware-vcenter-password').val('');
            $button.prop('disabled', false);
        });
    });
});
</script>
