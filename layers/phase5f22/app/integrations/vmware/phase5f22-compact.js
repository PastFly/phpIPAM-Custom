/* phpIPAM Custom — Phase 5F.2.2 compact toolbar/TLS presentation. */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    function compactTlsIcons() {
        $('#vmware-phase5e .phase5f21-tls-indicator, #vmware-phase5e .phase5f21-detail-tls').each(function () {
            var $node = $(this);
            $node.addClass('phase5f22-icon-only');
            $node.html('<i class="fa fa-exclamation-circle" aria-hidden="true"></i><span class="sr-only">TLS warning</span>');
        });
    }

    function compactRuntimeSummary() {
        var $summary = $('#phase5e-runtime-summary .phase5f21-runtime-summary').first();
        if (!$summary.length) return;
        var connectorId = String($('#phase5e-config').val() || '');
        $summary.empty().append(document.createTextNode('VMware Explorer · connector #' + connectorId + ' · API=modern · read-only'));
    }

    function compactToolbarSpacing() {
        $('#phase5e-insecure-row').hide();
        $('#phase5e-client-warning').filter(':empty').hide();
    }

    function refresh() {
        compactToolbarSpacing();
        compactTlsIcons();
        compactRuntimeSummary();
    }

    refresh();
    $(document).ajaxComplete(function () { window.setTimeout(refresh, 0); });
    $('#phase5e-config').on('change.phase5f22', function () { window.setTimeout(refresh, 0); });
})(window.jQuery);
