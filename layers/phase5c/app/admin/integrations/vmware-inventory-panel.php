<?php

/**
 * phpIPAM Custom — Phase 5C inventory/reconciliation panel.
 *
 * Included from the existing Administration → Integrations page. It exposes
 * only enabled saved VMware connectors and never renders credentials.
 */

if (!isset($User) || !is_object($User) || !$User->is_admin(false)) {
    return;
}

// PHPIPAM_CUSTOM_VMWARE_PHASE5C_INVENTORY_PANEL_V1

$phase5cInventoryCsrf = $User->Crypto->csrf_cookie("create", "integration_vmware_vcenter_inventory");
$phase5cInventoryConfigs = array();
if (isset($persistentReady) && $persistentReady && isset($persistentConfigs) && is_array($persistentConfigs)) {
    foreach ($persistentConfigs as $config) {
        if (!empty($config['enabled']) && !empty($config['has_credential'])) {
            $phase5cInventoryConfigs[] = $config;
        }
    }
}
?>

<div class="panel panel-default" id="vmware-phase5c-inventory-panel" style="margin-top:20px;">
    <div class="panel-heading">
        <strong><i class="fa fa-eye"></i> VMware vCenter — read-only inventory / reconciliation</strong>
        <span class="label label-primary pull-right">Phase 5C · no persistence</span>
    </div>
    <div class="panel-body">
        <div class="alert alert-info">
            Этот просмотр выполняет живые GET-only запросы к vCenter и read-only SELECT к phpIPAM.
            Результаты не сохраняются. Автоматическое создание/изменение Devices, IP, Racks или Locations не выполняется.
        </div>

        <?php if (count($phase5cInventoryConfigs) === 0) { ?>
            <div class="alert alert-warning" style="margin-bottom:0;">
                Нет enabled VMware connector с сохранённым encrypted credential.
            </div>
        <?php } else { ?>
            <form class="form-horizontal" id="vmware-phase5c-inventory-form" autocomplete="off">
                <input type="hidden" name="csrf_cookie" value="<?php print phpipam_custom_admin_integrations_h($phase5cInventoryCsrf); ?>">
                <input type="hidden" name="allow_insecure_tls" id="vmware-phase5c-insecure-value" value="0">

                <div class="form-group">
                    <label class="col-sm-2 control-label" for="vmware-phase5c-config">Connector</label>
                    <div class="col-sm-4">
                        <select class="form-control input-sm" id="vmware-phase5c-config" name="config_id" required>
                            <?php foreach ($phase5cInventoryConfigs as $config) { ?>
                                <option
                                    value="<?php print (int)$config['id']; ?>"
                                    data-verify-tls="<?php print !empty($config['verify_tls']) ? '1' : '0'; ?>">
                                    <?php print phpipam_custom_admin_integrations_h($config['name']); ?>
                                    — <?php print !empty($config['verify_tls']) ? 'TLS VERIFY' : 'TLS INSECURE'; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <label class="col-sm-2 control-label" for="vmware-phase5c-mode">View</label>
                    <div class="col-sm-4">
                        <select class="form-control input-sm" id="vmware-phase5c-mode" name="mode">
                            <option value="core">Inventory: Datacenters / Clusters / ESXi / VMs</option>
                            <option value="reconcile">Advisory reconciliation with phpIPAM</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="vmware-phase5c-guest-row" style="display:none;">
                    <label class="col-sm-2 control-label" for="vmware-phase5c-guest-limit">Guest network sample</label>
                    <div class="col-sm-4">
                        <select class="form-control input-sm" id="vmware-phase5c-guest-limit" name="guest_limit">
                            <option value="0">Не читать guest NIC/IP</option>
                            <option value="5">5 VMs</option>
                            <option value="25">25 VMs</option>
                            <option value="100">100 VMs</option>
                            <option value="250">250 VMs — maximum interactive</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <p class="form-control-static text-muted">
                            Network reconciliation является выборкой; отсутствие IP в sample не означает отсутствие адреса в vCenter.
                        </p>
                    </div>
                </div>

                <div class="form-group" id="vmware-phase5c-insecure-row" style="display:none;">
                    <div class="col-sm-offset-2 col-sm-10">
                        <div class="alert alert-warning" style="margin-bottom:0; padding:8px 12px;">
                            <label style="font-weight:normal; margin-bottom:0;">
                                <input type="checkbox" id="vmware-phase5c-insecure-ack">
                                Я явно разрешаю этот ручной read-only inventory с отключённой TLS verification.
                            </label>
                            <br><small>Это не разрешает scheduler. Для scheduled inventory по-прежнему требуется исправить CA trust и включить TLS verification.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <div class="col-sm-offset-2 col-sm-10">
                        <button type="submit" class="btn btn-sm btn-primary" id="vmware-phase5c-run">
                            <i class="fa fa-eye"></i> Получить inventory
                        </button>
                        <span class="text-muted" style="margin-left:10px;">Ничего не сохраняется.</span>
                    </div>
                </div>
            </form>

            <div id="vmware-phase5c-inventory-result" style="margin-top:15px;"></div>
        <?php } ?>
    </div>
</div>

<?php if (count($phase5cInventoryConfigs) > 0) { ?>
<script type="text/javascript">
$(document).ready(function () {
    function phase5cSyncInventoryControls() {
        var mode = $('#vmware-phase5c-mode').val();
        var verifyTls = $('#vmware-phase5c-config option:selected').attr('data-verify-tls') === '1';
        var insecureAck = $('#vmware-phase5c-insecure-ack').is(':checked');

        $('#vmware-phase5c-guest-row').toggle(mode === 'reconcile');
        $('#vmware-phase5c-insecure-row').toggle(!verifyTls);
        if (verifyTls) {
            $('#vmware-phase5c-insecure-ack').prop('checked', false);
            insecureAck = false;
        }
        $('#vmware-phase5c-insecure-value').val(insecureAck ? '1' : '0');
    }

    $('#vmware-phase5c-config, #vmware-phase5c-mode, #vmware-phase5c-insecure-ack').on('change', phase5cSyncInventoryControls);
    phase5cSyncInventoryControls();

    $('#vmware-phase5c-inventory-form').on('submit', function (event) {
        event.preventDefault();
        phase5cSyncInventoryControls();

        var verifyTls = $('#vmware-phase5c-config option:selected').attr('data-verify-tls') === '1';
        if (!verifyTls && !$('#vmware-phase5c-insecure-ack').is(':checked')) {
            $('#vmware-phase5c-inventory-result').html('<div class="alert alert-warning">Для INSECURE connector требуется явное подтверждение ручного запуска.</div>');
            return;
        }

        var $button = $('#vmware-phase5c-run');
        var $result = $('#vmware-phase5c-inventory-result');
        $button.prop('disabled', true);
        $result.html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Чтение vCenter inventory...</div>');

        $.ajax({
            url: 'app/admin/integrations/vmware-inventory-result.php',
            type: 'POST',
            data: $('#vmware-phase5c-inventory-form').serialize(),
            cache: false
        }).done(function (response) {
            $result.html(response);
        }).fail(function () {
            $result.html('<div class="alert alert-danger">Inventory request failed. Sensitive server details were not rendered.</div>');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });
});
</script>
<?php } ?>
