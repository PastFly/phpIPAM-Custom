<?php

/**
 * phpIPAM Custom — Administration / Integrations.
 */

$User->check_user_session();

if (!$User->is_admin(false)) {
    header("Location: " . create_link("error", "403"));
    die();
}

// PHPIPAM_CUSTOM_ADMIN_INTEGRATIONS_V1
$integrations_registry = require(dirname(__FILE__) . "/../../integrations/registry.php");

if (!is_array($integrations_registry)) {
    $integrations_registry = array();
}

function phpipam_custom_admin_integrations_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}
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
        На текущем foundation-этапе здесь не сохраняются endpoint, логины, пароли, API tokens или другие секреты.
        Поля подключения появятся вместе с конкретным VMware/vCenter storage contract и отдельной проверкой безопасности.
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
                                    Подготовить безопасное хранение конфигурации и read-only vCenter connection test.
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
        phpIPAM остаётся authoritative IPAM. Интеграции сначала только наблюдают и сверяют внешнее состояние; write-back не включён.
    </div>
</div>
