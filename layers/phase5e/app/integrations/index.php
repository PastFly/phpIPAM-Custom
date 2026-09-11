<?php

/**
 * phpIPAM Custom — Integrations product area, Phase 5E.
 */

$User->check_user_session();

// PHPIPAM_CUSTOM_INTEGRATIONS_PAGE_PHASE5E_V1
$phase5eSection = isset($GET->section) ? trim((string)$GET->section) : '';
if ($phase5eSection === 'vmware') {
    include(dirname(__FILE__) . '/vmware/index.php');
    return;
}

$integrations_registry = require(dirname(__FILE__) . "/registry.php");
if (!is_array($integrations_registry)) {
    $integrations_registry = array();
}

if (!function_exists('phpipam_custom_integrations_h')) {
    function phpipam_custom_integrations_h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists('phpipam_custom_integrations_badge')) {
    function phpipam_custom_integrations_badge($state)
    {
        if ($state === 'ready') return 'label label-success';
        if ($state === 'next') return 'label label-primary';
        if ($state === 'error') return 'label label-danger';
        return 'label label-default';
    }
}
?>

<div class="col-xs-12">
    <div class="page-header" style="margin-top:0;">
        <h3 style="margin-top:0;"><i class="fa fa-plug"></i> Интеграции</h3>
        <p class="text-muted" style="margin-bottom:0;">
            Внешние инфраструктурные источники и сверка их данных с phpIPAM.
        </p>
    </div>

    <?php if ($User->is_admin(false)) { ?>
        <div class="alert alert-info">
            <i class="fa fa-cog"></i>
            Credentials и настройки подключений остаются в
            <a href="<?php print phpipam_custom_integrations_h(create_link('administration', 'integrations')); ?>">Administration → Integrations</a>.
            Операционные read-only представления находятся здесь.
        </div>
    <?php } ?>

    <div class="row">
        <?php foreach ($integrations_registry as $connector) { ?>
            <?php $isVmware = isset($connector['id']) && $connector['id'] === 'vmware-vcenter'; ?>
            <div class="col-lg-4 col-md-6 col-sm-6 col-xs-12">
                <div class="panel panel-default" style="min-height:260px;">
                    <div class="panel-heading">
                        <strong>
                            <i class="fa <?php print phpipam_custom_integrations_h($connector['icon']); ?>"></i>
                            <?php print phpipam_custom_integrations_h($connector['name']); ?>
                        </strong>
                        <span class="<?php print phpipam_custom_integrations_h(phpipam_custom_integrations_badge($connector['state'])); ?> pull-right">
                            <?php print phpipam_custom_integrations_h($connector['state_label']); ?>
                        </span>
                    </div>
                    <div class="panel-body">
                        <p><?php print phpipam_custom_integrations_h($connector['summary']); ?></p>
                        <p class="text-muted" style="margin-bottom:6px;"><strong>Режим:</strong> <?php print phpipam_custom_integrations_h($connector['mode']); ?></p>
                        <ul class="small" style="padding-left:18px;">
                            <?php foreach ($connector['capabilities'] as $capability) { ?>
                                <li><?php print phpipam_custom_integrations_h($capability); ?></li>
                            <?php } ?>
                        </ul>

                        <?php if ($isVmware && $User->is_admin(false)) { ?>
                            <a class="btn btn-sm btn-primary" id="phase5e-open-vmware" href="<?php print phpipam_custom_integrations_h(create_link('integrations', 'vmware')); ?>">
                                <i class="fa fa-sitemap"></i> Открыть VMware vCenter
                            </a>
                        <?php } elseif ($isVmware) { ?>
                            <span class="text-muted">Текущий Explorer пока доступен администраторам.</span>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
