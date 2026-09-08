<?php

/**
 * phpIPAM Custom — Integrations landing page.
 */

$User->check_user_session();

// PHPIPAM_CUSTOM_INTEGRATIONS_PAGE_V1
$integrations_registry = require(dirname(__FILE__) . "/registry.php");

if (!is_array($integrations_registry)) {
    $integrations_registry = array();
}

function phpipam_custom_integrations_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function phpipam_custom_integrations_badge($state)
{
    if ($state === "next") {
        return "label label-primary";
    }

    if ($state === "ready") {
        return "label label-success";
    }

    if ($state === "error") {
        return "label label-danger";
    }

    return "label label-default";
}
?>

<div class="col-xs-12">
    <div class="page-header" style="margin-top:0;">
        <h3 style="margin-top:0;">
            <i class="fa fa-plug"></i>
            Интеграции
        </h3>
        <p class="text-muted" style="margin-bottom:0;">
            Внешние инфраструктурные источники и сверка их данных с phpIPAM.
            На этом этапе раздел работает только как безопасный read-only каркас — подключений и секретов в нём ещё нет.
        </p>
    </div>

    <?php if ($User->is_admin(false)) { ?>
        <div class="alert alert-info">
            <i class="fa fa-cog"></i>
            Настройки коннекторов будут находиться в
            <a href="<?php print phpipam_custom_integrations_h(create_link("administration", "integrations")); ?>">
                Administration → Integrations
            </a>.
            Первый коннектор — VMware vCenter.
        </div>
    <?php } ?>

    <div class="row">
        <?php foreach ($integrations_registry as $connector) { ?>
            <div class="col-lg-4 col-md-6 col-sm-6 col-xs-12">
                <div class="panel panel-default" style="min-height:260px;">
                    <div class="panel-heading">
                        <strong>
                            <i class="fa <?php print phpipam_custom_integrations_h($connector["icon"]); ?>"></i>
                            <?php print phpipam_custom_integrations_h($connector["name"]); ?>
                        </strong>
                        <span class="<?php print phpipam_custom_integrations_h(phpipam_custom_integrations_badge($connector["state"])); ?> pull-right">
                            <?php print phpipam_custom_integrations_h($connector["state_label"]); ?>
                        </span>
                    </div>
                    <div class="panel-body">
                        <p><?php print phpipam_custom_integrations_h($connector["summary"]); ?></p>

                        <p class="text-muted" style="margin-bottom:6px;">
                            <strong>Режим:</strong>
                            <?php print phpipam_custom_integrations_h($connector["mode"]); ?>
                        </p>

                        <ul class="small" style="padding-left:18px; margin-bottom:0;">
                            <?php foreach ($connector["capabilities"] as $capability) { ?>
                                <li><?php print phpipam_custom_integrations_h($capability); ?></li>
                            <?php } ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
