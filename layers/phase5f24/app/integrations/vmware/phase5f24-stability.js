/* phpIPAM Custom — Phase 5F.2.4 VM-card stability / TLS tooltip hotfix. */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;
    if ($.phase5f24VmCardGuardInstalled) return;
    $.phase5f24VmCardGuardInstalled = true;

    var originalAjax = $.ajax;
    var vmCardSequence = 0;
    var activeVmCardRequest = null;

    function isVmCardRequest(first, second) {
        var options = typeof first === 'string' ? (second || {}) : (first || {});
        var url = typeof first === 'string' ? first : options.url;
        return String(url || '').indexOf('app/integrations/vmware/vm-card-result.php') !== -1;
    }

    function safeServerErrorHtml(xhr) {
        var text = String(xhr && xhr.responseText ? xhr.responseText : '');
        if (text.length < 1 || text.length > 2048) return null;
        if (/<script\b|<iframe\b|<object\b|<embed\b/i.test(text)) return null;
        if (text.indexOf('<div class="alert alert-danger"><strong>VM card failed:</strong>') === -1) return null;
        return text;
    }

    // Wrap only the legacy Phase 5E VM-card request. Old/stale requests are
    // aborted at the transport layer but their proxy Deferred is intentionally
    // left unresolved, so the legacy generic .fail() handler cannot overwrite a
    // newer card with an abort/stale error.
    $.ajax = function (first, second) {
        if (!isVmCardRequest(first, second)) {
            return originalAjax.apply($, arguments);
        }

        var args = arguments;
        var sequence = ++vmCardSequence;
        if (activeVmCardRequest && activeVmCardRequest.readyState !== 4) {
            try { activeVmCardRequest.abort(); } catch (ignored) {}
        }

        var real = originalAjax.apply($, args);
        activeVmCardRequest = real;
        var gate = $.Deferred();

        real.done(function () {
            if (sequence !== vmCardSequence) return;
            gate.resolveWith(this, arguments);
        });

        real.fail(function (xhr, status) {
            if (sequence !== vmCardSequence || status === 'abort') return;
            var safe = safeServerErrorHtml(xhr);
            if (safe !== null) {
                gate.resolveWith(this, [safe, 'success', xhr]);
                return;
            }
            gate.rejectWith(this, arguments);
        });

        var proxy = gate.promise();
        proxy.abort = function () {
            try { real.abort(); } catch (ignored) {}
            return proxy;
        };
        return proxy;
    };

    function compactTlsTooltip($node) {
        if (!$node || !$node.length || !($.fn && typeof $.fn.tooltip === 'function')) return;
        try { $node.tooltip('destroy'); } catch (ignored) {}
        try {
            $node.tooltip({
                container: 'body',
                placement: 'bottom',
                trigger: 'hover focus',
                html: false,
                template: '<div class="tooltip phase5f24-tls-tooltip" role="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner"></div></div>'
            });
        } catch (ignored2) {}
    }

    function refreshTlsTooltips() {
        compactTlsTooltip($('#phase5f21-tls-indicator'));
        $('#phase5e-detail-result .phase5f21-detail-tls').each(function () {
            compactTlsTooltip($(this));
        });
    }

    refreshTlsTooltips();
    $(document).ajaxComplete(function () {
        window.setTimeout(refreshTlsTooltips, 0);
    });
})(window.jQuery);
