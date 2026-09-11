/* Additive server-cache routing. No business data is saved in browser storage. */
(function (win, $) {
    'use strict';
    if (!$ || !win.document || win.phpipamSnapshotBound) return;
    win.phpipamSnapshotBound = true;
    var routes = {
        'app/integrations/vmware/workspace-result.php': 'core',
        'app/integrations/vmware/folder-result.php': 'host_folders',
        'app/integrations/vmware/object-folders-result.php': 'object_folders',
        'app/integrations/vmware/enriched-search-result.php': 'search',
        'app/tools/search/vmware-global-result.php': 'search'
    };
    var entries = Object.create(null), forceUntil = 0, queue = [], flight = null, stopped = false;
    var interval = 60000, last = Object.create(null);
    function active() { return !stopped && win.document.visibilityState !== 'hidden'; }
    function allowed(id) {
        var $w = $('#phase5e-config'), $n = $('#phase5f331-connector');
        if ($w.length) return String($w.val()) === id;
        if (!$n.length) return false;
        return String($n.val()) === id || ($n.val() === 'all' && $n.find('option').filter(function () { return String(this.value) === id; }).length > 0);
    }
    function key(id, domain) { return id + ':' + domain; }
    function params(options) { return new URLSearchParams(typeof options.data === 'string' ? options.data : $.param(options.data || {})); }
    function target(url) {
        var parsed;
        try { parsed = new URL(url, win.document.baseURI); } catch (e) { return null; }
        if (parsed.origin !== win.location.origin) return null;
        var found = null;
        Object.keys(routes).some(function (suffix) {
            var expected = new URL(suffix, win.document.baseURI);
            if (expected.origin !== win.location.origin || parsed.pathname !== expected.pathname) return false;
            var at = parsed.pathname.lastIndexOf('/');
            parsed.pathname = parsed.pathname.slice(0, at + 1) + 'cache/' + parsed.pathname.slice(at + 1);
            found = {url: parsed.href, domain: routes[suffix]}; return true;
        });
        return found;
    }
    function indicator(id, cache) {
        if (!allowed(id)) return;
        var $where = $('#phase5e-workspace-form');
        if (!$where.length) $where = $('#phase5f331-global-search .panel-heading').first();
        if (!$where.length) return;
        var $label = $('#phase5f34-cache-indicator');
        if (!$label.length) $label = $('<div id="phase5f34-cache-indicator" class="small text-muted" role="status">').insertAfter($where);
        var age = cache.age_seconds == null ? '' : ' · возраст ' + Math.max(0, Math.floor(cache.age_seconds)) + ' с';
        var label = {fresh: 'готов', stale: 'устарел, обновление отдельно', refreshed: 'обновлён', refreshing: 'обновляется',
            disabled: 'не включён для этого PHP', incomplete_uncached: 'ответ неполный, не сохранён', refresh_failed: 'обновление не удалось',
            backoff: 'пауза после ошибки', uncached: 'не сохранён'}[cache.state] || 'статус недоступен';
        $label.text('Кэш сервера #' + id + ': ' + label + age)
            .attr('title', 'Обновление снимка не перерисовывает открытый список. Карточки и графики загружаются отдельно.');
    }
    function accept(id, token, data) {
        if (!allowed(id) || !data || data.ok !== true || String(data.connector_id) !== id || !data.cache) return;
        var c = data.cache, k = key(id, c.domain);
        if (['core', 'host_folders', 'object_folders', 'search'].indexOf(c.domain) < 0) return;
        entries[k] = {id: id, token: token, cache: c, at: Date.now(), enabled: data.cache_enabled === true};
        indicator(id, c);
        // Do not retain the pre-cache page's claim that inventory exists only in request memory.
        $('#vmware-phase5e .phase5e-page-header > p.text-muted').text(data.cache_enabled === true
            ? 'Read-only обзор. Инфраструктура, папки и поиск используют зашифрованные снимки на сервере; карточки и графики загружаются отдельно.'
            : 'Read-only обзор. Серверный кэш не активен; данные запрашиваются при обращении.');
        if (c.refresh_required && c.retry_after_seconds === 0) enqueue(k);
    }
    function enqueue(k) {
        var entry = entries[k];
        if (!active() || !entry || !entry.enabled || !allowed(entry.id) || Date.now() - (last[k] || 0) < interval || queue.indexOf(k) !== -1) return;
        queue.push(k); pump();
    }
    function pump() {
        if (!active() || flight || !queue.length) return;
        var k = queue.shift(), entry = entries[k];
        if (!entry || !entry.enabled || !allowed(entry.id)) { pump(); return; }
        last[k] = Date.now();
        flight = $.ajax({url: 'app/integrations/vmware/cache/refresh.php', type: 'POST', dataType: 'json', global: false,
            timeout: 90000, phase5f34Inner: true, data: {config_id: entry.id, csrf_cookie: entry.token, domain: entry.cache.domain}});
        flight.done(function (data) { accept(entry.id, entry.token, data); })
            .fail(function (xhr) { if (xhr.status === 401 || xhr.status === 403) { stopped = true; queue = []; } })
            .always(function () { flight = null; win.setTimeout(pump, 20); });
    }
    $.ajaxPrefilter(function (options, original, xhr) {
        if (options.phase5f34Inner || String(options.type || 'GET').toUpperCase() !== 'POST') return;
        var match = target(options.url);
        if (!match) return;
        var data = params(options), id = data.get('config_id'), token = data.get('csrf_cookie');
        if (!/^[1-9][0-9]{0,9}$/.test(id || '') || !token) return;
        options.url = match.url;
        options.phase5f34Managed = true;
        if (!options.timeout) options.timeout = 90000;
        if (Date.now() < forceUntil) data.set('force_refresh', '1');
        options.data = data.toString();
        xhr.done(function (response) { accept(id, token, response); });
    });
    // Cold-lock collisions only: bounded retries, outer deadline, abort propagation.
    $.ajaxTransport('+*', function (options) {
        if (!options.phase5f34Managed || options.phase5f34Inner || options.async === false) return;
        var current = null, timer = null, aborted = false, attempts = 0;
        return {
            send: function (headers, complete) {
                function finish(xhr) {
                    if (aborted) return;
                    var body = xhr.responseText || '', data = null;
                    try { data = JSON.parse(body); } catch (ignored) {}
                    if (xhr.status === 503 && data && data.error === 'snapshot_refresh_busy' && attempts < 8) {
                        timer = win.setTimeout(send, Math.min(2000, 300 * attempts)); return;
                    }
                    complete(xhr.status, xhr.statusText, {text: body}, xhr.getAllResponseHeaders());
                }
                function send() {
                    if (aborted) return;
                    attempts++;
                    current = $.ajax({url: options.url, type: options.type, data: options.data, dataType: 'text', processData: false,
                        contentType: options.contentType, headers: headers, xhrFields: options.xhrFields,
                        timeout: Math.min(options.timeout || 90000, 90000), global: false, phase5f34Inner: true});
                    current.done(function (body, status, xhr) { finish(xhr); }).fail(function (xhr) { finish(xhr); });
                }
                send();
            },
            abort: function () { aborted = true; if (timer) win.clearTimeout(timer); if (current) current.abort(); }
        };
    });
    win.document.addEventListener('click', function (event) {
        var button = $(event.target).closest('button');
        if (button.is('#phase5e-refresh, #phase5f331-retry') ||
            (button.closest('#phase5f332-list').length && button.text().trim() === 'Обновить')) {
            forceUntil = Date.now() + 1500; stopped = false;
        }
    }, true);
    var timer = win.setInterval(function () {
        if (!active()) return;
        Object.keys(entries).forEach(function (k) {
            var e = entries[k], age = Number(e.cache.age_seconds) + (Date.now() - e.at) / 1000;
            if (allowed(e.id) && e.enabled && isFinite(age) && age >= Number(e.cache.soft_ttl_seconds)) enqueue(k);
        });
    }, interval);
    win.document.addEventListener('visibilitychange', function () {
        if (!active()) { queue = []; if (flight) flight.abort(); }
        else pump();
    });
    win.addEventListener('pagehide', function () { stopped = true; queue = []; win.clearInterval(timer); if (flight) flight.abort(); });
})(window, window.jQuery);
