/* Phase 5F.3.3.1: additive native/header search provider. No persistent storage. */
(function (win, $) {
    'use strict';
    function lower(v) { return String(v == null ? '' : v).toLowerCase(); }
    function mac(v) {
        v = lower(v).trim();
        return /^(?:[0-9a-f]{12}|(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}|(?:[0-9a-f]{4}\.){2}[0-9a-f]{4})$/.test(v) ? v.replace(/[:.-]/g, '') : '';
    }
    function matches(row, query) {
        var needle = lower(query).trim();
        if (!needle || !row || !/^vm-[0-9]+$/.test(String(row.vm_external_id || ''))) return false;
        var parts = [row.search_blob, row.name, row.vm_external_id, row.friendly_guest_os, row.hardware_version,
            row.guest_hostname, row.primary_ip, row.host_name, row.cluster_name, row.datacenter_name,
            row.num_cpu, row.memory_gib];
        ['mac_addresses', 'network_names', 'ip_addresses'].forEach(function (key) {
            if (Array.isArray(row[key])) parts = parts.concat(row[key]);
        });
        if (Array.isArray(row.tags)) row.tags.forEach(function (tag) {
            if (tag) parts = parts.concat([tag.name, tag.category_name, tag.description, tag.category_description]);
        });
        var text = lower(parts.filter(function (p) { return p != null; }).join('\n'));
        // Ordered literal substrings support native '*' wildcards without regex injection.
        var offset = 0, ok = needle.split('*').every(function (part) {
            if (!part) return true;
            var at = text.indexOf(part, offset);
            if (at < 0) return false;
            offset = at + part.length;
            return true;
        });
        if (ok) return true;
        var normalized = mac(needle);
        return !!normalized && Array.isArray(row.mac_addresses) && row.mac_addresses.some(function (v) { return mac(v) === normalized; });
    }
    function fragment(connector, vm) {
        return /^[1-9][0-9]*$/.test(String(connector)) && /^vm-[0-9]+$/.test(String(vm))
            ? '#vmware-connector=' + connector + '&vmware-vm=' + vm : null;
    }
    if (typeof module === 'object' && module.exports) module.exports = {matches: matches, mac: mac, fragment: fragment};
    if (!$ || !win.document) return;
    $(function () {
        var $root = $('#phase5f331-global-search');
        if (!$root.length || $root.attr('data-bound') === '1') return;
        $root.attr('data-bound', '1');
        var query = String($root.attr('data-query') || '').trim();
        if (!query || query.length > 256) return;
        var base;
        try {
            base = new URL($root.attr('data-workspace-url'), win.location.href);
            if (base.origin !== win.location.origin) return;
            base.hash = '';
        } catch (e) { return; }
        var $select = $('#phase5f331-connector'), $retry = $('#phase5f331-retry');
        var $status = $('#phase5f331-status'), $result = $('#phase5f331-results');
        var cache = Object.create(null), busy = false, found = [], notices = [], page = 0;
        var TTL = 300000, PAGE_SIZE = 50;
        function selected() {
            var ids = [];
            $select.find('option[data-connector]').each(function () {
                var id = String($(this).val());
                if (/^[1-9][0-9]*$/.test(id) && ($select.val() === 'all' || id === $select.val())) ids.push({id: id, name: $(this).text()});
            });
            return ids.slice(0, 5);
        }
        function cell($tr, value) { return $('<td>').text(value == null || value === '' ? '—' : String(value)).appendTo($tr); }
        function render() {
            found.sort(function (a, b) { return lower(a.row.name).localeCompare(lower(b.row.name)) || a.id.localeCompare(b.id) || a.row.vm_external_id.localeCompare(b.row.vm_external_id); });
            page = Math.max(0, Math.min(page, Math.max(0, Math.ceil(found.length / PAGE_SIZE) - 1)));
            $result.empty();
            $status.text((busy ? 'Чтение VMware… ' : '') + 'Найдено VM: ' + found.length + (notices.length ? ' · ' + notices.join(' · ') : ''));
            var $table = $('<table class="table table-hover table-condensed">');
            var $head = $('<tr>').appendTo($('<thead>').appendTo($table));
            ['VM', 'Подключение', 'IP', 'Guest OS', 'ESXi', 'Теги'].forEach(function (s) { $('<th>').text(s).appendTo($head); });
            var $body = $('<tbody>').appendTo($table);
            found.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE).forEach(function (entry) {
                var row = entry.row, $tr = $('<tr>').appendTo($body), $vm = $('<td>').appendTo($tr);
                $('<a>').attr('href', base.href + fragment(entry.id, row.vm_external_id)).text(row.name || row.vm_external_id).appendTo($vm);
                $('<div class="small text-muted">').text(row.vm_external_id).appendTo($vm);
                cell($tr, entry.name); cell($tr, row.primary_ip); cell($tr, row.friendly_guest_os); cell($tr, row.host_name);
                cell($tr, Array.isArray(row.tags) ? row.tags.filter(Boolean).map(function (t) { return t.name || ''; }).join(', ') : '');
            });
            if (!found.length) cell($('<tr>').appendTo($body), busy ? 'Подготавливается индекс…' : 'Совпадений в доступных данных VMware нет.').attr('colspan', 6);
            $('<div class="table-responsive">').append($table).appendTo($result);
            if (found.length > PAGE_SIZE) {
                var $nav = $('<div>').appendTo($result);
                $('<button type="button" class="btn btn-default btn-sm">Назад</button>').prop('disabled', page === 0).on('click', function () { page--; render(); }).appendTo($nav);
                $('<span class="small">').text(' Страница ' + (page + 1) + ' / ' + Math.ceil(found.length / PAGE_SIZE) + ' ').appendTo($nav);
                $('<button type="button" class="btn btn-default btn-sm">Далее</button>').prop('disabled', (page + 1) * PAGE_SIZE >= found.length).on('click', function () { page++; render(); }).appendTo($nav);
            }
        }
        function accept(connection, data) {
            if (!data || data.ok !== true || String(data.connector_id) !== connection.id || !Array.isArray(data.rows) || data.rows.length > 1000) {
                notices.push(connection.name + ': поиск недоступен'); return false;
            }
            if (data.tag_status !== 'ok') notices.push(connection.name + ': теги недоступны, поиск по ним неполный');
            data.rows.forEach(function (row) { if (matches(row, query)) found.push({id: connection.id, name: connection.name, row: row}); });
            return true;
        }
        function run(force) {
            if (busy) return;
            busy = true; found = []; notices = []; page = 0;
            $select.prop('disabled', true); $retry.prop('disabled', true);
            var connections = selected(), index = 0;
            function next() {
                render();
                if (index >= connections.length) {
                    busy = false; $select.prop('disabled', false); $retry.prop('disabled', false); render(); return;
                }
                var connection = connections[index++], cached = cache[connection.id];
                if (!force && cached && Date.now() - cached.at < TTL) { accept(connection, cached.data); next(); return; }
                $.ajax({url: 'app/tools/search/vmware-global-result.php', type: 'POST', dataType: 'json', global: false, timeout: 90000,
                    data: {config_id: connection.id, csrf_cookie: $root.attr('data-csrf')}})
                    .done(function (data) { if (accept(connection, data)) cache[connection.id] = {at: Date.now(), data: data}; })
                    .fail(function () { notices.push(connection.name + ': подключение недоступно'); })
                    .always(next);
            }
            next();
        }
        $select.on('change.phase5f331', function () { run(false); });
        $retry.on('click.phase5f331', function () { run(true); });
        // Only search submissions/page entry or explicit connector/retry actions load data.
        // The native header/form handlers, their permissions and results are untouched.
        run(false);
    });
})(typeof window !== 'undefined' ? window : {}, typeof window !== 'undefined' ? window.jQuery : null);
