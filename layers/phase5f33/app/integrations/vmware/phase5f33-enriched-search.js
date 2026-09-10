/* phpIPAM Custom — Phase 5F.3.3 enriched global VM search.
 * One bounded transient index load per connector/TTL; filtering is browser-local.
 * No per-keystroke vCenter calls and no persistent browser storage.
 */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var CACHE_TTL_MS = 300000;
    var cache = {};
    var request = null;
    var requestSequence = 0;
    var searchTimer = null;
    var activeNeedle = '';

    function connectorId() { return String($('#phase5e-config').val() || ''); }
    function csrf() { return $('#phase5e-workspace-form input[name=csrf_cookie]').val(); }
    function lower(value) {
        value = String(value == null ? '' : value);
        try { return value.toLocaleLowerCase('ru-RU'); } catch (e) { return value.toLowerCase(); }
    }
    function esc(value) { return $('<div>').text(String(value == null ? '' : value)).html(); }

    function setSearchValues(value) {
        value = String(value || '');
        if ($('#phase5e-search').val() !== value) $('#phase5e-search').val(value);
        if ($('#phase5f2-vm-search').length && $('#phase5f2-vm-search').val() !== value) $('#phase5f2-vm-search').val(value);
    }

    function setVmViewForGlobalSearch() {
        $('#phase5e-view').val('vms');
        $('#phase5e-page').val('1');
        $('#phase5e-scope, #phase5e-scope-id').val('');
        $('#phase5e-clear-scope').hide();
        $('.phase5e-view-link').parent().removeClass('active');
        $('.phase5e-view-link[data-view="vms"]').parent().addClass('active');
        $('#phase5e-center-title').text('Виртуальные машины');
        $('#phase5f2-vm-search-wrap').show();
    }

    function legacyWorkspaceSubmit() {
        $('#phase5e-page').val('1');
        $('#phase5e-workspace-form').trigger('submit');
    }

    function statusHtml(message, kind) {
        var cls = kind === 'error' ? 'text-danger' : (kind === 'loading' ? 'text-muted' : 'text-muted');
        return '<div class="phase5f33-search-status ' + cls + '">' + esc(message) + '</div>';
    }

    function powerHtml(value) {
        var raw = String(value || '').toLowerCase();
        if (raw === 'powered_on' || raw === 'poweredon') return '<span class="label label-success">powered_on</span>';
        if (raw === 'powered_off' || raw === 'poweredoff') return '<span class="label label-default">powered_off</span>';
        if (raw === 'suspended') return '<span class="label label-warning">suspended</span>';
        return raw ? esc(raw) : '—';
    }

    function renderMatches(rows, query, meta) {
        var needle = lower(query).trim();
        if (!needle) return;
        var matches = [];
        rows.forEach(function (row) {
            if (!row || lower(row.search_blob).indexOf(needle) === -1) return;
            matches.push(row);
        });

        var pageSize = parseInt($('#phase5e-page-size').val(), 10);
        if (!isFinite(pageSize) || pageSize < 1) pageSize = 50;
        pageSize = Math.min(200, pageSize);
        var shown = matches.slice(0, pageSize);
        var html = statusHtml('Найдено VM: ' + matches.length + (matches.length > shown.length ? ' · показано: ' + shown.length : '') + (meta && meta.tag_status !== 'ok' ? ' · Tags/Categories временно недоступны' : ''), 'ok');

        html += '<div class="table-responsive"><table class="table table-hover table-condensed phase5e-table phase5f33-search-table">';
        html += '<thead><tr><th>VM</th><th>Power</th><th>CPU</th><th>Memory GiB</th><th>Guest OS</th><th>Datacenter</th><th>Cluster</th><th>ESXi Host</th></tr></thead><tbody>';
        if (!shown.length) {
            html += '<tr><td colspan="8" class="text-muted">Совпадений нет.</td></tr>';
        } else {
            shown.forEach(function (row) {
                var vmId = String(row.vm_external_id || '');
                html += '<tr class="phase5e-vm-row" data-vm-id="' + esc(vmId) + '">';
                html += '<td><a href="#" class="phase5e-vm-link" data-vm-id="' + esc(vmId) + '"><strong>' + esc(row.name || vmId) + '</strong></a><br><code class="phase5e-id">' + esc(vmId) + '</code></td>';
                html += '<td>' + powerHtml(row.power_state) + '</td>';
                html += '<td>' + (row.num_cpu == null ? '—' : esc(row.num_cpu)) + '</td>';
                html += '<td>' + (row.memory_gib == null ? '—' : Number(row.memory_gib).toFixed(2)) + '</td>';
                html += '<td>' + esc(row.friendly_guest_os || '—') + '</td>';
                html += '<td>' + esc(row.datacenter_name || '—') + '</td>';
                html += '<td>' + esc(row.cluster_name || '—') + '</td>';
                html += '<td>' + esc(row.host_name || '—') + '</td>';
                html += '</tr>';
            });
        }
        html += '</tbody></table></div>';
        $('#phase5e-list-result').html(html).attr('data-phase5f33-enriched-search', '1');
    }

    function renderLoading() {
        $('#phase5e-list-result').html(statusHtml('Подготавливается расширенный индекс VM…', 'loading')).attr('data-phase5f33-enriched-search', '1');
    }

    function renderError() {
        $('#phase5e-list-result').html(statusHtml('Расширенный поиск временно недоступен. Повтори поиск или используй обновление страницы.', 'error')).attr('data-phase5f33-enriched-search', '1');
    }

    function loadIndex(force, callback) {
        var id = connectorId();
        if (!id || !csrf()) { if (callback) callback(null); return; }
        var now = Date.now();
        if (!force && cache[id] && now - cache[id].at < CACHE_TTL_MS) {
            if (callback) callback(cache[id]);
            return;
        }
        if (request && request.readyState !== 4) request.abort();
        var sequence = ++requestSequence;
        request = $.ajax({
            url: 'app/integrations/vmware/enriched-search-result.php',
            type: 'POST',
            dataType: 'json',
            cache: false,
            data: {csrf_cookie: csrf(), config_id: id}
        }).done(function (response) {
            if (sequence !== requestSequence || id !== connectorId()) return;
            if (!response || response.ok !== true || !Array.isArray(response.rows)) {
                if (callback) callback(null);
                return;
            }
            cache[id] = {at: Date.now(), rows: response.rows, meta: response};
            if (callback) callback(cache[id]);
        }).fail(function (xhr, status) {
            if (status === 'abort' || sequence !== requestSequence) return;
            if (callback) callback(null);
        });
    }

    function runSearch(value) {
        value = String(value || '');
        setSearchValues(value);
        activeNeedle = value;
        var needle = value.trim();
        if (!needle) {
            activeNeedle = '';
            legacyWorkspaceSubmit();
            return;
        }
        setVmViewForGlobalSearch();
        renderLoading();
        loadIndex(false, function (entry) {
            if (value !== activeNeedle) return;
            if (!entry) { renderError(); return; }
            renderMatches(entry.rows, value, entry.meta);
        });
    }

    function scheduleSearch(value, delay) {
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () { runSearch(value); }, delay || 220);
    }

    // Replace the legacy name/id-only per-keystroke workspace submissions.
    $('#phase5e-search').off('input.phase5f1').off('input.phase5f2 change.phase5f2');
    $(document).off('input.phase5f2', '#phase5f2-vm-search');
    $(document).off('click.phase5f2', '#phase5f2-vm-search-clear');

    $('#phase5e-search').on('input.phase5f33', function () { scheduleSearch($(this).val(), 220); });
    $(document).on('input.phase5f33', '#phase5f2-vm-search', function () { scheduleSearch($(this).val(), 220); });
    $(document).on('click.phase5f33', '#phase5f2-vm-search-clear', function () {
        setSearchValues('');
        runSearch('');
    });

    $('#phase5e-page-size').on('change.phase5f33', function () {
        if (!String(activeNeedle || '').trim()) return;
        var entry = cache[connectorId()];
        if (entry) renderMatches(entry.rows, activeNeedle, entry.meta);
    });

    $('#phase5e-config').on('change.phase5f33', function () {
        requestSequence++;
        if (request && request.readyState !== 4) request.abort();
        activeNeedle = '';
    });

    // Warm the transient index after the normal workspace has rendered. This is
    // asynchronous and never blocks topology rendering.
    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('workspace-result.php') !== -1 && xhr.status >= 200 && xhr.status < 300 && !String(activeNeedle || '').trim()) {
            window.setTimeout(function () { loadIndex(false, function () {}); }, 150);
        }
    });
})(window.jQuery);
