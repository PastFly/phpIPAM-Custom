/* phpIPAM Custom — Phase 5F VMware observability UI glue.
 * Adds on-demand ESXi details and VM performance to the existing Phase 5E
 * workspace without changing topology/list behavior or persisting samples.
 */
(function ($) {
    'use strict';

    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var vmPerformanceRequest = null;
    var hostRequest = null;

    function commonData() {
        return {
            csrf_cookie: $('#phase5e-workspace-form input[name=csrf_cookie]').val(),
            config_id: $('#phase5e-config').val(),
            max_samples: 60
        };
    }

    function loadHost(hostId) {
        if (!/^host-[0-9]+$/.test(String(hostId || ''))) return;
        if (hostRequest && hostRequest.readyState !== 4) hostRequest.abort();

        var data = commonData();
        data.host_id = hostId;
        $('#phase5e-detail-result').html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> Чтение ESXi hardware / performance...</div>');

        hostRequest = $.ajax({
            url: 'app/integrations/vmware/host-card-result.php',
            type: 'POST',
            data: data,
            cache: false
        }).done(function (response) {
            $('#phase5e-detail-result').html(response);
        }).fail(function (xhr, status) {
            if (status === 'abort') return;
            $('#phase5e-detail-result').html('<div class="alert alert-danger">ESXi observability request failed. Sensitive server details were not rendered.</div>');
        });
    }

    function loadVmPerformance(vmId) {
        if (!/^vm-[0-9]+$/.test(String(vmId || ''))) return;
        if (vmPerformanceRequest && vmPerformanceRequest.readyState !== 4) vmPerformanceRequest.abort();

        var data = commonData();
        data.vm_id = vmId;

        vmPerformanceRequest = $.ajax({
            url: 'app/integrations/vmware/performance-result.php',
            type: 'POST',
            data: data,
            cache: false
        }).done(function (response) {
            if ($('.phase5e-vm-row.active .phase5e-vm-link').attr('data-vm-id') !== vmId) return;
            $('#phase5e-detail-result .phase5f-vm-performance').remove();
            $('#phase5e-detail-result').append(response);
        }).fail(function (xhr, status) {
            if (status === 'abort') return;
            if ($('.phase5e-vm-row.active .phase5e-vm-link').attr('data-vm-id') !== vmId) return;
            $('#phase5e-detail-result .phase5f-vm-performance').remove();
            $('#phase5e-detail-result').append('<div class="alert alert-warning phase5f-vm-performance">VM performance недоступен. Основная карточка VM остаётся доступной.</div>');
        });
    }

    $(document).on('click.phase5f', '.phase5e-scope-link', function () {
        var scope = $(this).attr('data-scope');
        var scopeId = $(this).attr('data-scope-id');
        $('.phase5e-vm-row').removeClass('active');
        if (scope === 'host') {
            loadHost(scopeId);
        } else {
            if (hostRequest && hostRequest.readyState !== 4) hostRequest.abort();
            $('#phase5e-detail-result').html('<div class="text-muted phase5e-detail-empty">Выберите ESXi Host или VM для просмотра деталей и real-time performance.</div>');
        }
    });

    // Existing Phase 5E code owns the VM-card AJAX call. ajaxComplete runs after
    // its .done() handler has placed the card into the detail pane, so Phase 5F
    // can append performance without racing or replacing the trusted VM card.
    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('app/integrations/vmware/vm-card-result.php') === -1) return;
        if (xhr.status < 200 || xhr.status >= 300) return;
        var vmId = $('.phase5e-vm-row.active .phase5e-vm-link').attr('data-vm-id');
        if (vmId) loadVmPerformance(vmId);
    });

    $('#phase5e-config').on('change.phase5f', function () {
        if (vmPerformanceRequest && vmPerformanceRequest.readyState !== 4) vmPerformanceRequest.abort();
        if (hostRequest && hostRequest.readyState !== 4) hostRequest.abort();
    });
})(window.jQuery);
