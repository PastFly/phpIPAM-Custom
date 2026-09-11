/* VM-card transport only. Preserve the legacy URL for every completion listener. */
(function (win, $) {
    'use strict';
    if (!$ || !win.document.getElementById('vmware-phase5e') || $.phase5f35Bound) return;
    $.phase5f35Bound = true;
    var original = 'app/integrations/vmware/vm-card-result.php';
    $.ajaxPrefilter(function (options) {
        if (options.phase5f35Inner || String(options.type || 'GET').toUpperCase() !== 'POST' || options.async === false) return;
        var parsed, expected, data;
        try {
            parsed = new URL(options.url, win.document.baseURI);
            expected = new URL(original, win.document.baseURI);
            data = new URLSearchParams(typeof options.data === 'string' ? options.data : $.param(options.data || {}));
        } catch (ignored) { return; }
        if (parsed.origin !== win.location.origin || expected.origin !== win.location.origin || parsed.pathname !== expected.pathname) return;
        if (!/^[1-9][0-9]{0,9}$/.test(data.get('config_id') || '') ||
            !/^vm-[0-9]{1,20}$/.test(data.get('vm_id') || '') || !data.get('csrf_cookie')) return;
        parsed.pathname = parsed.pathname.slice(0, parsed.pathname.lastIndexOf('/') + 1) + 'phase5f35-vm-card-result.php';
        options.phase5f35Target = parsed.href;
        if (!options.timeout) options.timeout = 90000;
        // Do NOT rewrite options.url: Notes, charts, guest facts, folder cancellation
        // and latest-request-wins code must observe the original request identity.
    });
    $.ajaxTransport('+*', function (options) {
        if (!options.phase5f35Target || options.phase5f35Inner) return;
        var inner = null, retry = null, aborted = false, attempts = 0;
        return {
            send: function (headers, complete) {
                function finish(xhr) {
                    if (aborted) return;
                    if (xhr.status === 503 && xhr.getResponseHeader('X-Phpipam-VM-Context-Error') === 'snapshot_refresh_busy' && attempts < 8) {
                        retry = win.setTimeout(send, Math.min(2000, 300 * attempts));
                        return;
                    }
                    complete(xhr.status, xhr.statusText, {text: xhr.responseText || ''}, xhr.getAllResponseHeaders());
                }
                function send() {
                    if (aborted) return;
                    attempts++;
                    inner = $.ajax({url: options.phase5f35Target, type: 'POST', data: options.data,
                        dataType: 'text', processData: false, contentType: options.contentType,
                        headers: headers, xhrFields: options.xhrFields, global: false,
                        timeout: Math.min(options.timeout || 90000, 90000), phase5f35Inner: true});
                    inner.done(function (body, status, xhr) { finish(xhr); }).fail(function (xhr) { finish(xhr); });
                }
                send();
            },
            abort: function () {
                aborted = true;
                if (retry !== null) win.clearTimeout(retry);
                if (inner) inner.abort();
            }
        };
    });
})(window, window.jQuery);
