/* phpIPAM Custom — Phase 5F.2.1 layout/TLS hotfix. */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var tlsText = 'TLS verification отключена для этого connector. Read-only Explorer использует сохранённую настройку. Scheduler остаётся запрещённым, пока CA trust не исправлен и TLS verification не включена.';

    function installPaneRoles() {
        var $row = $('#vmware-phase5e .phase5e-workspace-row.phase5f2-resizable');
        if (!$row.length) return;
        var $cols = $row.children('.phase5e-workspace-col');
        if ($cols.length !== 3) return;
        $cols.removeClass('phase5f21-pane-left phase5f21-pane-center phase5f21-pane-right');
        $cols.eq(0).addClass('phase5f21-pane-left');
        $cols.eq(1).addClass('phase5f21-pane-center');
        $cols.eq(2).addClass('phase5f21-pane-right');
    }

    function enhanceTooltip($node) {
        if (!$node || !$node.length) return;
        if ($.fn && typeof $.fn.tooltip === 'function') {
            try {
                $node.tooltip({container: 'body', placement: 'bottom'});
            } catch (e) {}
        }
    }

    function ensureConnectorTlsIndicator() {
        var $label = $('label[for="phase5e-config"]');
        if (!$label.length) return;
        var $indicator = $('#phase5f21-tls-indicator');
        if (!$indicator.length) {
            $indicator = $('<span id="phase5f21-tls-indicator" class="phase5f21-tls-indicator text-warning" tabindex="0"><i class="fa fa-exclamation-triangle"></i> TLS</span>');
            $indicator.attr('title', tlsText);
            $label.append($indicator);
            enhanceTooltip($indicator);
        }
        var insecure = $('#phase5e-config option:selected').attr('data-verify-tls') !== '1';
        $indicator.toggle(insecure);
    }

    function compactRuntimeSummary() {
        var $root = $('#phase5e-runtime-summary');
        if (!$root.length) return;
        var $alert = $root.children('.alert').first();
        if (!$alert.length || $alert.hasClass('phase5f21-processed')) return;

        var insecure = $('#phase5e-config option:selected').attr('data-verify-tls') !== '1';
        var connectorId = String($('#phase5e-config').val() || '');
        var $line = $('<div class="phase5f21-runtime-summary small text-muted phase5f21-processed"></div>');
        $line.append(document.createTextNode('VMware Explorer · connector #' + connectorId + ' · API=modern · '));
        if (insecure) {
            var $tls = $('<span class="phase5f21-tls-indicator text-warning" tabindex="0"><i class="fa fa-exclamation-triangle"></i> TLS INSECURE</span>');
            $tls.attr('title', tlsText);
            $line.append($tls);
            enhanceTooltip($tls);
        } else {
            $line.append($('<span class="text-success"><i class="fa fa-lock"></i> TLS VERIFY</span>'));
        }
        $line.append(document.createTextNode(' · read-only'));
        $alert.replaceWith($line);
    }

    function isTlsWarning(text) {
        text = String(text || '').toLowerCase();
        return text.indexOf('tls verification') !== -1 || text.indexOf('tls insecure') !== -1 || text.indexOf('tls verification отключена') !== -1;
    }

    function compactDetailTlsWarnings() {
        $('#phase5e-detail-result .alert-warning').each(function () {
            var $warning = $(this);
            if ($warning.hasClass('phase5f21-tls-processed')) return;
            var text = $.trim($warning.text().replace(/\s+/g, ' '));
            if (!isTlsWarning(text)) return;
            var $indicator = $('<span class="phase5f21-detail-tls text-warning phase5f21-tls-processed" tabindex="0"><i class="fa fa-exclamation-triangle"></i> TLS</span>');
            $indicator.attr('title', text || tlsText);
            $warning.replaceWith($indicator);
            enhanceTooltip($indicator);
        });
    }

    function refreshHotfix() {
        installPaneRoles();
        ensureConnectorTlsIndicator();
        compactRuntimeSummary();
        compactDetailTlsWarnings();
    }

    refreshHotfix();

    $('#phase5e-config').on('change.phase5f21', function () {
        window.setTimeout(function () {
            ensureConnectorTlsIndicator();
            compactRuntimeSummary();
        }, 0);
    });

    $(document).ajaxComplete(function () {
        window.setTimeout(refreshHotfix, 0);
    });

    $(window).on('resize.phase5f21', function () {
        installPaneRoles();
    });
})(window.jQuery);
