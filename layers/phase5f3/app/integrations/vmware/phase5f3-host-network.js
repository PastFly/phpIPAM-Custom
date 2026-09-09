/* phpIPAM Custom — Phase 5F.3 ESXi physical adapter peer UI glue. */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var request = null;
    var currentHostId = null;

    function commonData() {
        return {
            csrf_cookie: $('#phase5e-workspace-form input[name=csrf_cookie]').val(),
            config_id: $('#phase5e-config').val()
        };
    }

    function abortRequest() {
        if (request && request.readyState !== 4) request.abort();
        request = null;
    }

    function loadNetworkHints(hostId) {
        hostId = String(hostId || '');
        if (!/^host-[0-9]+$/.test(hostId)) return;
        abortRequest();

        var data = commonData();
        data.host_id = hostId;
        $('#phase5e-detail-result .phase5f3-host-network').remove();
        $('#phase5e-detail-result').append('<div class="phase5f3-host-network text-muted small"><i class="fa fa-spinner fa-spin"></i> Чтение CDP/LLDP network hints...</div>');

        request = $.ajax({
            url: 'app/integrations/vmware/host-network-result.php',
            type: 'POST',
            data: data,
            cache: false
        }).done(function (response) {
            if (currentHostId !== hostId) return;
            if (!$('#phase5e-detail-result .phase5f-host-card').length) return;
            $('#phase5e-detail-result .phase5f3-host-network').remove();
            $('#phase5e-detail-result').append(response);
        }).fail(function (xhr, status) {
            if (status === 'abort' || currentHostId !== hostId) return;
            if (!$('#phase5e-detail-result .phase5f-host-card').length) return;
            $('#phase5e-detail-result .phase5f3-host-network').remove();
            $('#phase5e-detail-result').append('<div class="text-muted small phase5f3-host-network">CDP/LLDP данные физических адаптеров недоступны. Основная карточка ESXi не затронута.</div>');
        });
    }

    $(document).on('click.phase5f3', '.phase5e-scope-link', function () {
        if ($(this).attr('data-scope') === 'host') {
            currentHostId = String($(this).attr('data-scope-id') || '');
        } else {
            currentHostId = null;
            abortRequest();
        }
    });

    $(document).on('click.phase5f3', '.phase5e-vm-link', function () {
        currentHostId = null;
        abortRequest();
    });

    // The existing Phase 5F handler owns the primary Host card. Only after it
    // has completed successfully do we append optional CDP/LLDP peer details.
    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('app/integrations/vmware/host-card-result.php') === -1) return;
        if (xhr.status < 200 || xhr.status >= 300) return;
        if (currentHostId) window.setTimeout(function () { loadNetworkHints(currentHostId); }, 0);
    });

    $('#phase5e-config').on('change.phase5f3', function () {
        currentHostId = null;
        abortRequest();
    });
})(window.jQuery);
