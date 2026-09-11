<?php

/**
 * phpIPAM Custom — Phase 5E dedicated VMware vCenter workspace.
 * UI/navigation only; Phase 5D collectors and safety contracts remain authoritative.
 */

if (!isset($User) || !is_object($User)) {
    die('Invalid application context');
}
$User->check_user_session();
if (!$User->is_admin(false)) {
    header("Location: " . create_link("error", "403"));
    die();
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5E_WORKSPACE_V1
// PHPIPAM_CUSTOM_VMWARE_PHASE5E1_TLS_FROM_CONNECTOR_SETTINGS_V1
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenter.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationSecretCipher.php");
require_once(dirname(__FILE__) . "/../../../functions/classes/class.IntegrationVmwareVcenterConfigStore.php");

function phase5e_workspace_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$phase5eConfigs = array();
$phase5eStorageReady = false;
try {
    $phase5eCipher = IntegrationSecretCipher::from_keyring_file();
    $phase5eStore = new IntegrationVmwareVcenterConfigStore($Database, $phase5eCipher);
    foreach ($phase5eStore->list_configs() as $phase5eConfig) {
        if (!empty($phase5eConfig['enabled']) && !empty($phase5eConfig['has_credential'])) {
            $phase5eConfigs[] = $phase5eConfig;
        }
    }
    $phase5eStorageReady = true;
} catch (Exception $e) {
    $phase5eStorageReady = false;
}

$phase5eCsrf = $User->Crypto->csrf_cookie('create', 'integration_vmware_vcenter_explorer');
?>
<link rel="stylesheet" href="app/integrations/vmware/phase5e.css">

<div class="col-xs-12" id="vmware-phase5e">
    <div class="page-header phase5e-page-header">
        <h3><i class="fa fa-cloud"></i> VMware vCenter</h3>
        <p class="text-muted">
            Read-only обзор виртуальной инфраструктуры. Навигация использует stable vCenter IDs; inventory существует только в памяти текущего запроса.
        </p>
    </div>

    <ol class="breadcrumb phase5e-breadcrumb">
        <li><a href="<?php print phase5e_workspace_h(create_link('integrations')); ?>"><i class="fa fa-plug"></i> Интеграции</a></li>
        <li class="active">VMware vCenter</li>
    </ol>

    <ul class="nav nav-tabs phase5e-nav" role="tablist">
        <li class="active"><a href="#" class="phase5e-view-link" data-view="overview"><i class="fa fa-dashboard"></i> Обзор</a></li>
        <li><a href="#" class="phase5e-view-link" data-view="vms"><i class="fa fa-sitemap"></i> Инфраструктура / ВМ</a></li>
        <li><a href="#" class="phase5e-view-link" data-view="datastores"><i class="fa fa-database"></i> Хранилища</a></li>
        <li><a href="<?php print phase5e_workspace_h(create_link('administration', 'integrations')); ?>"><i class="fa fa-exchange"></i> Сопоставление</a></li>
        <li class="pull-right"><a href="<?php print phase5e_workspace_h(create_link('administration', 'integrations')); ?>"><i class="fa fa-cog"></i> Настройки</a></li>
    </ul>

    <?php if (!$phase5eStorageReady) { ?>
        <div class="alert alert-warning">
            <i class="fa fa-warning"></i> Persistent VMware configuration storage недоступно. Секретные детали ошибки намеренно скрыты.
        </div>
    <?php } elseif (count($phase5eConfigs) === 0) { ?>
        <div class="alert alert-warning">
            Нет enabled VMware connector с сохранённым encrypted credential.
            <a href="<?php print phase5e_workspace_h(create_link('administration', 'integrations')); ?>">Открыть настройки</a>.
        </div>
    <?php } else { ?>
        <form id="phase5e-workspace-form" class="phase5e-toolbar" autocomplete="off">
            <input type="hidden" name="csrf_cookie" value="<?php print phase5e_workspace_h($phase5eCsrf); ?>">
            <input type="hidden" name="view" id="phase5e-view" value="overview">
            <input type="hidden" name="scope" id="phase5e-scope" value="">
            <input type="hidden" name="scope_id" id="phase5e-scope-id" value="">
            <input type="hidden" name="page" id="phase5e-page" value="1">

            <div class="panel panel-default">
                <div class="panel-body">
                    <div class="row">
                        <div class="col-lg-4 col-md-5 col-sm-6">
                            <div class="form-group">
                                <label for="phase5e-config">Connector</label>
                                <select class="form-control input-sm" id="phase5e-config" name="config_id" required>
                                    <?php foreach ($phase5eConfigs as $phase5eConfig) { ?>
                                        <option value="<?php print (int)$phase5eConfig['id']; ?>" data-verify-tls="<?php print !empty($phase5eConfig['verify_tls']) ? '1' : '0'; ?>">
                                            <?php print phase5e_workspace_h($phase5eConfig['name']); ?> — <?php print !empty($phase5eConfig['verify_tls']) ? 'TLS VERIFY' : 'TLS INSECURE'; ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-4 col-sm-6">
                            <div class="form-group">
                                <label for="phase5e-search">Поиск</label>
                                <input class="form-control input-sm" type="text" id="phase5e-search" name="search" maxlength="256" placeholder="VM, external ID, datastore...">
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-3 col-sm-6">
                            <div class="form-group">
                                <label for="phase5e-page-size">На странице</label>
                                <select class="form-control input-sm" id="phase5e-page-size" name="page_size">
                                    <option value="25">25</option>
                                    <option value="50" selected>50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-12 col-sm-6">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-sm btn-primary form-control" id="phase5e-refresh"><i class="fa fa-refresh"></i> Обновить</button>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning" id="phase5e-insecure-row" style="display:none; margin-bottom:0;">
                        <i class="fa fa-warning"></i>
                        TLS verification отключена в сохранённых настройках этого connector. Ручной read-only Explorer использует эту настройку без дополнительного подтверждения.
                        <div class="small">Scheduler по-прежнему запрещён, пока CA trust не исправлен и TLS verification не включена.</div>
                    </div>
                </div>
            </div>
        </form>

        <div id="phase5e-client-warning"></div>
        <div id="phase5e-runtime-summary"></div>

        <div class="row phase5e-workspace-row">
            <div class="col-lg-3 col-md-4 col-xs-12 phase5e-workspace-col">
                <div class="panel panel-default phase5e-pane">
                    <div class="panel-heading"><strong><i class="fa fa-sitemap"></i> Инфраструктура</strong></div>
                    <div class="phase5e-pane-body" id="phase5e-tree-result">
                        <div class="text-muted phase5e-detail-empty">Запустите Explorer, чтобы загрузить дерево Datacenter → Cluster → ESXi.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 col-md-8 col-xs-12 phase5e-workspace-col">
                <div class="panel panel-default phase5e-pane">
                    <div class="panel-heading">
                        <strong><i class="fa fa-list"></i> <span id="phase5e-center-title">Обзор</span></strong>
                        <button type="button" class="btn btn-xs btn-default pull-right" id="phase5e-clear-scope" style="display:none;"><i class="fa fa-times"></i> Сбросить scope</button>
                    </div>
                    <div class="phase5e-pane-body" id="phase5e-list-result">
                        <div class="text-muted phase5e-detail-empty">Результаты появятся здесь.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-12 col-xs-12 phase5e-workspace-col">
                <div class="panel panel-default phase5e-pane">
                    <div class="panel-heading"><strong><i class="fa fa-info-circle"></i> Детали</strong></div>
                    <div class="phase5e-pane-body" id="phase5e-detail-result">
                        <div class="text-muted phase5e-detail-empty">Щёлкните по имени VM, чтобы открыть карточку без перехода вниз страницы.</div>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<?php if ($phase5eStorageReady && count($phase5eConfigs) > 0) { ?>
<script type="text/javascript">
$(document).ready(function () {
    function phase5eSyncTlsNotice() {
        var verifyTls = $('#phase5e-config option:selected').attr('data-verify-tls') === '1';
        $('#phase5e-insecure-row').toggle(!verifyTls);
    }

    function phase5eSetView(view) {
        $('#phase5e-view').val(view);
        $('#phase5e-page').val('1');
        $('.phase5e-view-link').parent().removeClass('active');
        $('.phase5e-view-link[data-view="' + view + '"]').parent().addClass('active');
        var titles = {overview: 'Обзор', vms: 'Виртуальные машины', datastores: 'Хранилища'};
        $('#phase5e-center-title').text(titles[view] || 'VMware');
    }

    function phase5eRunWorkspace() {
        $('#phase5e-client-warning').empty();
        phase5eSyncTlsNotice();
        var $button = $('#phase5e-refresh');
        $button.prop('disabled', true);
        $('#phase5e-list-result').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Чтение topology/datastore inventory...</div>');
        $.ajax({
            url: 'app/integrations/vmware/workspace-result.php',
            type: 'POST',
            dataType: 'json',
            data: $('#phase5e-workspace-form').serialize(),
            cache: false
        }).done(function (response) {
            if (!response || response.ok !== true) {
                $('#phase5e-list-result').html('<div class="alert alert-danger">Explorer response invalid.</div>');
                return;
            }
            $('#phase5e-tree-result').html(response.tree_html || '');
            $('#phase5e-list-result').html(response.content_html || '');
            $('#phase5e-runtime-summary').html(response.summary_html || '');
            $('#phase5e-clear-scope').toggle($('#phase5e-scope').val() !== '');
        }).fail(function () {
            $('#phase5e-list-result').html('<div class="alert alert-danger">Explorer request failed. Sensitive server details were not rendered.</div>');
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    function phase5eLoadVm(vmId) {
        phase5eSyncTlsNotice();
        $('#phase5e-detail-result').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Чтение VM card...</div>');
        $.ajax({
            url: 'app/integrations/vmware/vm-card-result.php',
            type: 'POST',
            data: {
                csrf_cookie: $('#phase5e-workspace-form input[name=csrf_cookie]').val(),
                config_id: $('#phase5e-config').val(),
                vm_id: vmId,
                include_tags: '1'
            },
            cache: false
        }).done(function (response) {
            $('#phase5e-detail-result').html(response);
        }).fail(function () {
            $('#phase5e-detail-result').html('<div class="alert alert-danger">VM card request failed. Sensitive server details were not rendered.</div>');
        });
    }

    $('#phase5e-config').on('change', function () {
        $('#phase5e-scope, #phase5e-scope-id').val('');
        $('#phase5e-page').val('1');
        $('#phase5e-detail-result').html('<div class="text-muted phase5e-detail-empty">Выберите VM после загрузки нового connector.</div>');
        phase5eSyncTlsNotice();
    });

    $('#phase5e-search, #phase5e-page-size').on('change', function () { $('#phase5e-page').val('1'); });

    $('#phase5e-workspace-form').on('submit', function (event) {
        event.preventDefault();
        $('#phase5e-page').val('1');
        phase5eRunWorkspace();
    });

    $(document).on('click', '.phase5e-view-link', function (event) {
        event.preventDefault();
        phase5eSetView($(this).attr('data-view'));
        phase5eRunWorkspace();
    });

    $(document).on('click', '.phase5e-scope-link', function (event) {
        event.preventDefault();
        $('#phase5e-scope').val($(this).attr('data-scope'));
        $('#phase5e-scope-id').val($(this).attr('data-scope-id'));
        phase5eSetView('vms');
        phase5eRunWorkspace();
    });

    $(document).on('click', '.phase5e-vm-link', function (event) {
        event.preventDefault();
        $('.phase5e-vm-row').removeClass('active');
        $(this).closest('.phase5e-vm-row').addClass('active');
        phase5eLoadVm($(this).attr('data-vm-id'));
    });

    $(document).on('click', '.phase5e-datastore-link', function (event) {
        event.preventDefault();
        $('#phase5e-search').val($(this).attr('data-datastore-id') || $(this).text());
        phase5eSetView('datastores');
        phase5eRunWorkspace();
    });

    $(document).on('click', '.phase5e-page-link', function (event) {
        event.preventDefault();
        $('#phase5e-page').val($(this).attr('data-page'));
        phase5eRunWorkspace();
    });

    $('#phase5e-clear-scope').on('click', function () {
        $('#phase5e-scope, #phase5e-scope-id').val('');
        $('#phase5e-page').val('1');
        phase5eRunWorkspace();
    });

    phase5eSyncTlsNotice();
});
</script>
<?php } ?>
