/* phpIPAM Custom — Phase 5F.3.1 VM -> physical network path UI glue. */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var request = null;
    var currentVmId = null;
    var sequence = 0;

    function commonData() {
        return {
            csrf_cookie: $('#phase5e-workspace-form input[name=csrf_cookie]').val(),
            config_id: $('#phase5e-config').val()
        };
    }

    function abortRequest() {
        sequence++;
        if (request && request.readyState !== 4) {
            try { request.abort(); } catch (ignored) {}
        }
        request = null;
    }

    function selectedVmId() {
        var id = currentVmId;
        if (!id) id = $('.phase5e-vm-row.active .phase5e-vm-link').attr('data-vm-id');
        id = String(id || '');
        return /^vm-[0-9]+$/.test(id) ? id : null;
    }

    function loadNetworkPath(vmId) {
        vmId = String(vmId || '');
        if (!/^vm-[0-9]+$/.test(vmId)) return;
        abortRequest();
        currentVmId = vmId;
        var mySequence = ++sequence;
        var data = commonData();
        data.vm_id = vmId;

        $('#phase5e-detail-result .phase5f31-vm-network-path').remove();
        $('#phase5e-detail-result').append('<div class="phase5f31-vm-network-path text-muted small"><i class="fa fa-spinner fa-spin"></i> Чтение сетевого пути VM...</div>');

        request = $.ajax({
            url: 'app/integrations/vmware/vm-network-path-result.php',
            type: 'POST',
            data: data,
            cache: false
        }).done(function (response) {
            if (mySequence !== sequence || currentVmId !== vmId) return;
            if (!$('#phase5e-detail-result .phase5f-vm-card, #phase5e-detail-result .phase5e-detail-section').length) return;
            $('#phase5e-detail-result .phase5f31-vm-network-path').remove();
            $('#phase5e-detail-result').append(response);
        }).fail(function (xhr, status) {
            if (status === 'abort' || mySequence !== sequence || currentVmId !== vmId) return;
            if (!$('#phase5e-detail-result .phase5f-vm-card, #phase5e-detail-result .phase5e-detail-section').length) return;
            $('#phase5e-detail-result .phase5f31-vm-network-path').remove();
            $('#phase5e-detail-result').append('<div class="text-muted small phase5f31-vm-network-path">Сетевой путь VM недоступен. Основная карточка VM не затронута.</div>');
        });
    }

    $(document).on('click.phase5f31', '.phase5e-vm-link', function () {
        currentVmId = String($(this).attr('data-vm-id') || '');
        abortRequest();
        currentVmId = /^vm-[0-9]+$/.test(currentVmId) ? currentVmId : null;
    });

    $(document).on('click.phase5f31', '.phase5e-scope-link', function () {
        currentVmId = null;
        abortRequest();
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('app/integrations/vmware/vm-card-result.php') === -1) return;
        if (xhr.status < 200 || xhr.status >= 300) return;
        var vmId = selectedVmId();
        if (vmId) window.setTimeout(function () { loadNetworkPath(vmId); }, 0);
    });

    $('#phase5e-config').on('change.phase5f31', function () {
        currentVmId = null;
        abortRequest();
    });
})(window.jQuery);
