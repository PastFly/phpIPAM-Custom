<?php

/**
 * phpIPAM Custom — Phase 5D VMware Explorer panel.
 * Included from Administration → Integrations after the persistent connector UI.
 */
if (!isset($User) || !is_object($User) || !$User->is_admin(false)) {
    return;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5D_EXPLORER_PANEL_V1

$phase5dExplorerCsrf = $User->Crypto->csrf_cookie("create", "integration_vmware_vcenter_explorer");
$phase5dExplorerConfigs = array();
if (isset($persistentReady) && $persistentReady && isset($persistentConfigs) && is_array($persistentConfigs)) {
    foreach ($persistentConfigs as $config) {
        if (!empty($config['enabled']) && !empty($config['has_credential'])) {
            $phase5dExplorerConfigs[] = $config;
        }
    }
}

function phase5d_explorer_panel_h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<div class="panel panel-default" id="vmware-phase5d-explorer-panel" style="margin-top:20px;">
    <div class="panel-heading">
        <strong><i class="fa fa-sitemap"></i> VMware Explorer — topology / datastore / VM cards</strong>
        <span class="label label-primary pull-right">Phase 5D · read-only</span>
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            Навигация строится по stable vCenter IDs и поддерживаемым API relation filters. Поиск используется только как фильтр отображения и никогда не определяет placement или identity.
        </div>

        <?php if (count($phase5dExplorerConfigs) === 0) { ?>
            <div class="alert alert-warning" style="margin-bottom:0;">Нет enabled VMware connector с сохранённым encrypted credential.</div>
        <?php } else { ?>
            <form class="form-horizontal" id="vmware-phase5d-explorer-form" autocomplete="off">
                <input type="hidden" name="csrf_cookie" value="<?php print phase5d_explorer_panel_h($phase5dExplorerCsrf); ?>">
                <input type="hidden" name="allow_insecure_tls" id="vmware-phase5d-insecure-value" value="0">
                <input type="hidden" name="page" id="vmware-phase5d-page" value="1">
                <input type="hidden" name="scope" id="vmware-phase5d-scope" value="">
                <input type="hidden" name="scope_id" id="vmware-phase5d-scope-id" value="">

                <div class="form-group">
                    <label class="col-sm-2 control-label" for="vmware-phase5d-config">Connector</label>
                    <div class="col-sm-4">
                        <select class="form-control input-sm" id="vmware-phase5d-config" name="config_id" required>
                            <?php foreach ($phase5dExplorerConfigs as $config) { ?>
                                <option value="<?php print (int)$config['id']; ?>" data-verify-tls="<?php print !empty($config['verify_tls']) ? '1' : '0'; ?>">
                                    <?php print phase5d_explorer_panel_h($config['name']); ?> — <?php print !empty($config['verify_tls']) ? 'TLS VERIFY' : 'TLS INSECURE'; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                    <label class="col-sm-2 control-label" for="vmware-phase5d-view">View</label>
                    <div class="col-sm-4">
                        <select class="form-control input-sm" id="vmware-phase5d-view" name="view">
                            <option value="overview">Datacenters overview</option>
                            <option value="vms">Virtual Machines</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="vmware-phase5d-vm-filter-row" style="display:none;">
                    <label class="col-sm-2 control-label" for="vmware-phase5d-search">Search VM</label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control input-sm" id="vmware-phase5d-search" name="search" maxlength="256" placeholder="Name or VMware external ID">
                    </div>
                    <label class="col-sm-2 control-label" for="vmware-phase5d-page-size">Page size</label>
                    <div class="col-sm-2">
                        <select class="form-control input-sm" id="vmware-phase5d-page-size" name="page_size">
                            <option value="25">25</option>
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <div class="col-sm-2">
                        <button type="button" class="btn btn-sm btn-default" id="vmware-phase5d-clear-scope"><i class="fa fa-times"></i> Clear scope</button>
                    </div>
                </div>

                <div class="form-group" id="vmware-phase5d-insecure-row" style="display:none;">
                    <div class="col-sm-offset-2 col-sm-10">
                        <div class="alert alert-warning" style="margin-bottom:0; padding:8px 12px;">
                            <label style="font-weight:normal; margin-bottom:0;">
                                <input type="checkbox" id="vmware-phase5d-insecure-ack">
                                Я явно разрешаю этот ручной read-only Explorer с отключённой TLS verification.
                            </label>
                            <br><small>Это не разрешает scheduler. Для автоматизации по-прежнему требуется исправить CA trust и включить TLS verification.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <div class="col-sm-offset-2 col-sm-10">
                        <button type="submit" class="btn btn-sm btn-primary" id="vmware-phase5d-run"><i class="fa fa-sitemap"></i> Открыть Explorer</button>
                        <span class="text-muted" style="margin-left:10px;">Inventory не сохраняется.</span>
                    </div>
                </div>
            </form>

            <div id="vmware-phase5d-explorer-result" style="margin-top:15px;"></div>
            <div id="vmware-phase5d-vm-card-result" style="margin-top:15px;"></div>
        <?php } ?>
    </div>
</div>

<?php if (count($phase5dExplorerConfigs) > 0) { ?>
<script type="text/javascript">
$(document).ready(function () {
    function phase5dSyncControls() {
        var verifyTls = $('#vmware-phase5d-config option:selected').attr('data-verify-tls') === '1';
        var insecureAck = $('#vmware-phase5d-insecure-ack').is(':checked');
        var view = $('#vmware-phase5d-view').val();
        $('#vmware-phase5d-vm-filter-row').toggle(view === 'vms');
        $('#vmware-phase5d-insecure-row').toggle(!verifyTls);
        if (verifyTls) {
            $('#vmware-phase5d-insecure-ack').prop('checked', false);
            insecureAck = false;
        }
        $('#vmware-phase5d-insecure-value').val(insecureAck ? '1' : '0');
    }

    function phase5dCanRun() {
        var verifyTls = $('#vmware-phase5d-config option:selected').attr('data-verify-tls') === '1';
        return verifyTls || $('#vmware-phase5d-insecure-ack').is(':checked');
    }

    function phase5dRunExplorer() {
        phase5dSyncControls();
        var $result = $('#vmware-phase5d-explorer-result');
        if (!phase5dCanRun()) {
            $result.html('<div class="alert alert-warning">Для INSECURE connector требуется явное подтверждение ручного запуска.</div>');
            return;
        }
        var $button = $('#vmware-phase5d-run');
        $button.prop('disabled', true);
        $('#vmware-phase5d-vm-card-result').empty();
        $result.html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Чтение topology/datastore inventory...</div>');
        $.ajax({
            url: 'app/admin/integrations/vmware-explorer-result.php',
            type: 'POST',
            data: $('#vmware-phase5d-explorer-form').serialize(),
            cache: false
        }).done(function (response) {
            $result.html(response);
        }).fail(function () {
            $result.html('<div class="alert alert-danger">Explorer request failed. Sensitive server details were not rendered.</div>');
        }).always(function () {
            $button.prop('disabled', false);
        });
    }

    $('#vmware-phase5d-config, #vmware-phase5d-view, #vmware-phase5d-insecure-ack').on('change', function () {
        $('#vmware-phase5d-page').val('1');
        if ($(this).attr('id') === 'vmware-phase5d-view' && $('#vmware-phase5d-view').val() !== 'vms') {
            $('#vmware-phase5d-scope, #vmware-phase5d-scope-id').val('');
        }
        phase5dSyncControls();
    });
    $('#vmware-phase5d-search, #vmware-phase5d-page-size').on('change', function () { $('#vmware-phase5d-page').val('1'); });
    phase5dSyncControls();

    $('#vmware-phase5d-explorer-form').on('submit', function (event) {
        event.preventDefault();
        $('#vmware-phase5d-page').val('1');
        phase5dRunExplorer();
    });

    $('#vmware-phase5d-clear-scope').on('click', function () {
        $('#vmware-phase5d-scope, #vmware-phase5d-scope-id').val('');
        $('#vmware-phase5d-page').val('1');
        phase5dRunExplorer();
    });

    $(document).on('click', '.phase5d-scope-link', function (event) {
        event.preventDefault();
        $('#vmware-phase5d-view').val('vms');
        $('#vmware-phase5d-scope').val($(this).attr('data-scope'));
        $('#vmware-phase5d-scope-id').val($(this).attr('data-scope-id'));
        $('#vmware-phase5d-page').val('1');
        phase5dSyncControls();
        phase5dRunExplorer();
    });

    $(document).on('click', '.phase5d-page-link', function (event) {
        event.preventDefault();
        $('#vmware-phase5d-page').val($(this).attr('data-page'));
        phase5dRunExplorer();
    });

    $(document).on('click', '.phase5d-vm-card-link', function (event) {
        event.preventDefault();
        phase5dSyncControls();
        var $target = $('#vmware-phase5d-vm-card-result');
        if (!phase5dCanRun()) {
            $target.html('<div class="alert alert-warning">Для INSECURE connector требуется явное подтверждение ручного запуска.</div>');
            return;
        }
        var payload = {
            csrf_cookie: $('#vmware-phase5d-explorer-form input[name=csrf_cookie]').val(),
            config_id: $('#vmware-phase5d-config').val(),
            allow_insecure_tls: $('#vmware-phase5d-insecure-value').val(),
            vm_id: $(this).attr('data-vm-id'),
            include_tags: '1'
        };
        $target.html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Чтение VM card...</div>');
        $.ajax({
            url: 'app/admin/integrations/vmware-vm-card-result.php',
            type: 'POST',
            data: payload,
            cache: false
        }).done(function (response) {
            $target.html(response);
            $('html, body').animate({ scrollTop: $target.offset().top - 60 }, 200);
        }).fail(function () {
            $target.html('<div class="alert alert-danger">VM card request failed. Sensitive server details were not rendered.</div>');
        });
    });
});
</script>
<?php } ?>
