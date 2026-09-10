/* Phase 5F.3.3.2: independent inventory domains. Existing HOST/search/card code is not replaced. */
(function (win, $) {
    'use strict';
    var MAX = 4096, TTL = 300000;
    function text(v) { return String(v == null ? '' : v); }
    function lower(v) { return text(v).toLowerCase(); }
    function graph(data, domain) {
        if (!data || !Array.isArray(data.nodes) || !Array.isArray(data.objects) ||
            data.nodes.length + data.objects.length > MAX) throw new Error('Invalid folder graph');
        var nodes = Object.create(null), children = Object.create(null), roots = [], seen = Object.create(null);
        data.nodes.forEach(function (n) {
            var types = domain === 'vm' ? ['Folder', 'VirtualApp'] : ['Folder', 'StoragePod'];
            if (!n || types.indexOf(n.object_type) < 0 || n.key !== n.object_type + ':' + n.external_id ||
                nodes[n.key] || text(n.key).length > 256) throw new Error('Invalid folder identity');
            nodes[n.key] = n; children[n.key] = [];
        });
        data.nodes.forEach(function (n) {
            if (n.implicit_root && n.parent_key === null) roots.push(n);
            else {
                if (!nodes[n.parent_key] || nodes[n.parent_key].datacenter_external_id !== n.datacenter_external_id) throw new Error('Invalid folder parent');
                children[n.parent_key].push(n);
            }
            var cursor = n, steps = 0;
            while (cursor && !cursor.implicit_root) {
                if (++steps > 32) throw new Error('Folder cycle/depth');
                cursor = nodes[cursor.parent_key];
            }
            if (!cursor) throw new Error('Unrooted folder');
        });
        function sort(a, b) { return text(a.name).localeCompare(text(b.name)) || text(a.key).localeCompare(text(b.key)); }
        Object.keys(children).forEach(function (key) { children[key].sort(sort); });
        var objects = data.objects.map(function (o) {
            if (!o || !nodes[o.parent_key] || seen[o.external_id] ||
                o.object_type !== (domain === 'vm' ? 'VirtualMachine' : 'Datastore')) throw new Error('Invalid folder object');
            seen[o.external_id] = true; return o;
        });
        var unresolved = Array.isArray(data.unresolved_objects) ? data.unresolved_objects : [];
        if (objects.length + unresolved.length > MAX) throw new Error('Too many folder objects');
        unresolved.forEach(function (o) {
            if (!o || !o.external_id || seen[o.external_id]) throw new Error('Invalid unresolved object');
            seen[o.external_id] = true;
            objects.push({external_id: o.external_id, name: o.name, parent_key: null, unresolved: true});
        });
        return {nodes: nodes, children: children, roots: roots, objects: objects,
            unresolvedContainers: Array.isArray(data.unresolved_containers) ? data.unresolved_containers : []};
    }
    function under(model, object, key, descendants) {
        if (!key) return true;
        if (key === '@unresolved') return !!object.unresolved;
        var cursor = object.parent_key, steps = 0;
        while (cursor && steps++ < 33) {
            if (cursor === key) return true;
            if (!descendants) return false;
            cursor = model.nodes[cursor] ? model.nodes[cursor].parent_key : null;
        }
        return false;
    }
    function path(model, key, dcs) {
        var out = [], steps = 0;
        while (model.nodes[key] && steps++ < 33) {
            var node = model.nodes[key];
            out.unshift(node.implicit_root ? (dcs[node.datacenter_external_id] || node.datacenter_external_id) : node.name);
            key = node.parent_key;
        }
        return out.join(' / ');
    }
    function collapse(raw, model) {
        var out = Object.create(null), values;
        try { values = JSON.parse(raw); } catch (ignored) { return out; }
        if (!values || typeof values !== 'object' || Array.isArray(values) || Object.keys(values).length > 512) return out;
        Object.keys(values).forEach(function (key) { if (values[key] === true && model.nodes[key]) out[key] = true; });
        return out;
    }
    function size(bytes) {
        if (bytes == null || !isFinite(Number(bytes)) || Number(bytes) < 0) return '—';
        var gib = Number(bytes) / 1073741824;
        return gib > 1000 ? (gib / 1024).toFixed(2) + ' TiB' : gib.toFixed(2) + ' GiB';
    }
    function storageKey(base, connector, domain) {
        return 'phpipam:5f332:collapsed:' + encodeURIComponent(base) + ':' + connector + ':' + domain;
    }
    if (typeof module === 'object' && module.exports) module.exports = {graph: graph, under: under, path: path, collapse: collapse, size: size, storageKey: storageKey};
    if (!$ || !win.document) return;
    $(function () {
        var $root = $('#vmware-phase5e'), $form = $('#phase5e-workspace-form');
        if (!$root.length || !$form.length || $root.attr('data-folders-bound')) return;
        $root.attr('data-folders-bound', '1');
        var $hostTree = $('#phase5e-tree-result'), $hostList = $('#phase5e-list-result');
        var $tree = $('<div class="phase5f332-pane" id="phase5f332-tree">').insertAfter($hostTree);
        var $list = $('<div class="phase5f332-pane" id="phase5f332-list">').insertAfter($hostList);
        var $vmTab = $('<li><a href="#" id="phase5f332-vm-tab"><i class="fa fa-folder-open-o"></i> Папки ВМ</a></li>');
        $vmTab.insertAfter($('.phase5e-view-link[data-view="vms"]').parent());
        var mode = null, nonce = 0, page = 0, selectedKey = '', descendants = true, objectQuery = '', treeQuery = '';
        var model = null, dcs = Object.create(null), folded = Object.create(null), entries = Object.create(null);
        var graphError = false, metadataError = false, metadataPending = false, tagsPartial = false, metadata = Object.create(null), flat = false;
        var base = text($('base').attr('href') || $('.iebase').text() || '/');
        var detailRequests = [];
        $.ajaxPrefilter(function (options, originalOptions, xhr) {
            if (!/(^|\/)(?:vm-card|host-card|performance|storage-performance|vm-annotation|vm-guest-facts|host-network|vm-network-path)-result\.php(?:\?|$)/.test(text(options.url))) return;
            detailRequests.push(xhr);
            xhr.always(function () { detailRequests = detailRequests.filter(function (r) { return r !== xhr; }); });
        });
        function cancelDetails() {
            var pending = detailRequests.slice(); detailRequests = [];
            pending.forEach(function (xhr) { try { xhr.abort(); } catch (ignored) {} });
        }
        function id() { return text($('#phase5e-config').val()); }
        function csrf() { return $form.find('input[name="csrf_cookie"]').val(); }
        function key(kind, connector) { return kind + ':' + connector; }
        function load(kind, force) {
            var connector = id(), k = key(kind, connector), entry = entries[k];
            if (entry && entry.flight) return entry.flight;
            if (!force && entry && entry.data && Date.now() - entry.at < TTL) return $.Deferred().resolve(entry.data).promise();
            entry = entries[k] = {at: 0};
            var gate = $.Deferred(); entry.flight = gate.promise();
            var url = kind === 'vm' ? 'app/integrations/vmware/enriched-search-result.php' : 'app/integrations/vmware/object-folders-result.php';
            $.ajax({url: url, type: 'POST', dataType: 'json', cache: false, global: false, timeout: 90000,
                data: {csrf_cookie: csrf(), config_id: connector, mode: kind === 'graph' ? 'graph' : 'datastores'}})
                .done(function (data) {
                    if (!data || data.ok !== true || text(data.connector_id) !== connector) { gate.reject(); return; }
                    entry.data = data; entry.at = Date.now(); gate.resolve(data);
                }).fail(function () { gate.reject(); }).always(function () { entry.flight = null; });
            return gate.promise();
        }
        // Reuse a completed normal-workspace search index without changing its code or its cache.
        $(document).ajaxSuccess(function (event, xhr, settings) {
            if (!/(^|\/)enriched-search-result\.php(?:\?|$)/.test(text(settings && settings.url))) return;
            var data = xhr.responseJSON;
            if (!data || data.ok !== true || !Array.isArray(data.rows) || data.rows.length > 1000) return;
            var params = new URLSearchParams(typeof settings.data === 'string' ? settings.data : $.param(settings.data || {}));
            if (text(data.connector_id) !== params.get('config_id')) return;
            var k = key('vm', text(data.connector_id));
            if (!entries[k] || !entries[k].flight) entries[k] = {data: data, at: Date.now()};
        });
        function remember() {
            try {
                var keys = Object.keys(folded).slice(-512), value = Object.create(null);
                keys.forEach(function (k) { if (folded[k]) value[k] = true; });
                win.localStorage.setItem(storageKey(base, id(), mode), JSON.stringify(value));
            } catch (ignored) { /* Disabled/full storage cannot break navigation. */ }
        }
        function restore() {
            var raw = '';
            try { raw = win.localStorage.getItem(storageKey(base, id(), mode)) || ''; } catch (ignored) {}
            folded = raw.length <= 131072 ? collapse(raw, model) : Object.create(null);
        }
        function sync() {
            $root.toggleClass('phase5f332-active', !!mode);
            $vmTab.toggleClass('active', mode === 'vm');
            if (mode) {
                $('.phase5e-view-link').parent().removeClass('active');
                if (mode === 'datastore') $('.phase5e-view-link[data-view="datastores"]').parent().addClass('active');
                $('#phase5e-center-title').text(mode === 'vm' ? 'Виртуальные машины по папкам' : 'Хранилища по папкам');
            }
        }
        function leave() {
            if (!mode) return;
            mode = null; nonce++; $root.removeClass('phase5f332-active'); $vmTab.removeClass('active');
            $('#phase5e-center-title').text('VMware');
        }
        function button(label, action) { return $('<button type="button" class="btn btn-default btn-xs">').text(label).on('click', action); }
        function notice(target, value) { $('<div class="small text-muted phase5f332-note">').text(value).appendTo(target); }
        function rowCell(row, value) { return $('<td>').text(value == null || value === '' ? '—' : text(value)).appendTo(row); }
        function breadcrumb(keyValue) { return keyValue === '@unresolved' ? 'Неподтверждённое размещение' : path(model, keyValue, dcs); }
        function select(keyValue) {
            selectedKey = keyValue; page = 0; renderTree(); renderList();
        }
        function renderTree() {
            $tree.empty();
            if (!model) { notice($tree, graphError ? 'Папки недоступны. Обычная инфраструктура сохранена.' : 'Чтение папок…'); return; }
            var $controls = $('<div class="phase5f332-controls">').appendTo($tree);
            var $input = $('<input type="search" class="form-control input-sm" maxlength="256" aria-label="Фильтр папок" placeholder="Фильтр папок">').val(treeQuery).appendTo($controls);
            // Render only the tree contents during typing to retain focus/caret.
            $input.on('input', function () { treeQuery = $(this).val(); renderTreeNodes($nodes); });
            button('×', function () { treeQuery = ''; renderTree(); }).attr('aria-label', 'Очистить фильтр папок').appendTo($controls);
            button('Все объекты', function () { select(''); }).appendTo($tree);
            var $nodes = $('<div class="phase5f332-tree">').appendTo($tree); renderTreeNodes($nodes);
            if (model.unresolvedContainers.length) {
                notice($tree, 'Контейнеры с неподтверждённой связью (без выдуманного размещения):');
                model.unresolvedContainers.forEach(function (n) { notice($tree, text(n.name) + ' · ' + text(n.key)); });
            }
        }
        function renderTreeNodes($target) {
            $target.empty();
            var needle = lower(treeQuery).trim();
            function subtreeMatches(n) {
                return lower(breadcrumb(n.key)).indexOf(needle) >= 0 || model.children[n.key].some(subtreeMatches);
            }
            function add(n, $ul) {
                if (needle && !subtreeMatches(n)) return;
                var $li = $('<li>').appendTo($ul), $row = $('<div class="phase5f332-node">').appendTo($li);
                var children = model.children[n.key], closed = !needle && !!folded[n.key];
                button(closed ? '▸' : '▾', function () {
                    if (folded[n.key]) delete folded[n.key]; else folded[n.key] = true;
                    remember(); renderTreeNodes($target);
                }).addClass('phase5f332-toggle').prop('disabled', children.length === 0)
                    .attr({'aria-label': 'Свернуть / развернуть ' + text(n.name), 'aria-expanded': String(!closed)}).appendTo($row);
                var title = n.implicit_root ? (dcs[n.datacenter_external_id] || n.datacenter_external_id) : n.name;
                var $link = $('<a href="#">').attr('aria-current', String(selectedKey === n.key)).on('click', function (e) { e.preventDefault(); select(n.key); }).appendTo($row);
                $('<i>').addClass('fa ' + (n.implicit_root ? 'fa-building-o' : n.kind === 'vapp' ? 'fa-cubes' : n.kind === 'datastore_cluster' ? 'fa-database' : 'fa-folder-o')).appendTo($link);
                $link.append(win.document.createTextNode(' ' + text(title) + ' '));
                $('<span class="badge">').text(n.subtree_object_count).appendTo($link);
                if (!closed) { var $child = $('<ul>').appendTo($li); children.forEach(function (v) { add(v, $child); }); }
            }
            var $ul = $('<ul>').appendTo($target);
            model.roots.slice().sort(function (a, b) { return text(dcs[a.datacenter_external_id]).localeCompare(text(dcs[b.datacenter_external_id])); }).forEach(function (n) { add(n, $ul); });
            var unknown = model.objects.filter(function (o) { return o.unresolved; }).length;
            if (unknown) $('<a href="#">').text('Неподтверждённое размещение (' + unknown + ')').on('click', function (e) { e.preventDefault(); select('@unresolved'); }).appendTo($target);
        }
        function renderList() {
            var focused = $list.find('input[type=search]')[0], resume = focused && focused === win.document.activeElement;
            var caret = resume ? focused.selectionStart : null;
            $list.empty();
            var $controls = $('<div class="phase5f332-controls">').appendTo($list);
            var $input = $('<input type="search" class="form-control input-sm" maxlength="256" placeholder="Поиск в выбранной папке" aria-label="Поиск в выбранной папке">').val(objectQuery).appendTo($controls);
            var $recursive = $('<label>').appendTo($controls);
            $('<input type="checkbox">').prop('checked', descendants).on('change', function () { descendants = this.checked; page = 0; renderRows($rows); }).appendTo($recursive);
            $recursive.append(win.document.createTextNode(' С вложенными'));
            button('Обновить', function () { open(mode, true); }).appendTo($controls);
            if (mode === 'datastore') button('Плоский список', function () {
                leave(); flat = true; $('.phase5e-view-link[data-view="datastores"]').trigger('click'); flat = false;
            }).appendTo($controls);
            notice($list, selectedKey ? breadcrumb(selectedKey) : 'Все доступные объекты');
            if (graphError) notice($list, 'Папки временно недоступны. Повтори чтение или вернись к обычному списку.');
            var $rows = $('<div>').appendTo($list);
            $input.on('input', function () { objectQuery = $(this).val(); page = 0; renderRows($rows); });
            renderRows($rows);
            if (resume) { $input[0].focus(); if (caret != null) $input[0].setSelectionRange(caret, caret); }
        }
        function renderRows($target) {
            $target.empty();
            if (!model) { notice($target, graphError ? 'Не удалось прочитать дерево.' : 'Чтение дерева…'); return; }
            if (metadataPending) notice($target, 'Дополнительные характеристики загружаются; названия и папки уже доступны.');
            if (metadataError) notice($target, 'Дополнительные характеристики недоступны. Поиск ограничен именем, ID и папкой; пустые поля не означают нулевые значения.');
            if (tagsPartial && mode === 'vm') notice($target, 'Теги/категории недоступны; поиск по ним может быть неполным.');
            var needle = lower(objectQuery).trim();
            var rows = model.objects.filter(function (o) {
                if (!under(model, o, selectedKey, descendants)) return false;
                var m = metadata[o.external_id] || {};
                return !needle || lower([o.name, o.external_id, breadcrumb(o.parent_key), m.search_blob, m.type].join('\n')).indexOf(needle) >= 0;
            }).sort(function (a, b) { return text(a.name).localeCompare(text(b.name)) || text(a.external_id).localeCompare(text(b.external_id)); });
            var pageSize = Number($('#phase5e-page-size').val()) || 50; pageSize = Math.max(1, Math.min(100, pageSize));
            page = Math.max(0, Math.min(page, Math.max(0, Math.ceil(rows.length / pageSize) - 1)));
            notice($target, 'Найдено: ' + rows.length + ' · показано: ' + Math.min(pageSize, rows.length - page * pageSize));
            var $table = $('<table class="table table-condensed table-hover phase5f332-table">'), $head = $('<tr>').appendTo($('<thead>').appendTo($table));
            var columns = mode === 'vm' ? ['ВМ', 'Тип', 'Power', 'CPU', 'RAM', 'Guest OS', 'ESXi', 'Папка'] : ['Хранилище', 'Тип', 'Ёмкость', 'Свободно', 'Папка'];
            columns.forEach(function (v) { $('<th>').text(v).appendTo($head); });
            var $body = $('<tbody>').appendTo($table);
            rows.slice(page * pageSize, (page + 1) * pageSize).forEach(function (o) {
                var m = metadata[o.external_id] || {}, $row = $('<tr>').appendTo($body), $name = $('<td>').appendTo($row);
                if (mode === 'vm' && /^vm-[0-9]+$/.test(o.external_id)) {
                    $row.addClass('phase5e-vm-row').attr('data-vm-id', o.external_id);
                    $('<a href="#" class="phase5e-vm-link">').attr('data-vm-id', o.external_id).text(o.name).appendTo($name);
                } else if (mode === 'datastore') {
                    $('<a href="#">').text(o.name).on('click', function (e) { e.preventDefault(); datastoreCard(o, m); }).appendTo($name);
                } else $name.text(o.name);
                $('<div class="small text-muted">').text(o.external_id).appendTo($name);
                if (mode === 'vm') {
                    rowCell($row, o.unresolved ? null : (o.is_template ? 'Шаблон' : 'ВМ'));
                    rowCell($row, m.power_state); rowCell($row, m.num_cpu); rowCell($row, m.memory_gib == null ? null : size(Number(m.memory_gib) * 1073741824));
                    rowCell($row, m.friendly_guest_os); rowCell($row, m.host_name);
                } else {
                    rowCell($row, m.type); rowCell($row, size(m.capacity_bytes)); rowCell($row, size(m.free_space_bytes));
                }
                rowCell($row, o.unresolved ? 'Не подтверждена' : breadcrumb(o.parent_key));
            });
            if (!rows.length) rowCell($('<tr>').appendTo($body), 'В этой папке нет объектов по текущему фильтру.').attr('colspan', columns.length);
            $('<div class="table-responsive">').append($table).appendTo($target);
            if (rows.length > pageSize) {
                var $nav = $('<div class="phase5f332-controls">').appendTo($target);
                button('Назад', function () { page--; renderRows($target); }).prop('disabled', !page).appendTo($nav);
                $('<span>').text((page + 1) + ' / ' + Math.ceil(rows.length / pageSize)).appendTo($nav);
                button('Далее', function () { page++; renderRows($target); }).prop('disabled', (page + 1) * pageSize >= rows.length).appendTo($nav);
            }
        }
        function datastoreCard(o, m) {
            cancelDetails();
            var $detail = $('#phase5e-detail-result').empty();
            $('<h4>').text(o.name).appendTo($detail);
            var $body = $('<tbody>').appendTo($('<table class="table table-condensed">').appendTo($detail));
            [['Datastore ID', o.external_id], ['Тип', m.type], ['Ёмкость', size(m.capacity_bytes)], ['Свободно', size(m.free_space_bytes)], ['Папка', breadcrumb(o.parent_key)]].forEach(function (p) {
                var $tr = $('<tr>').appendTo($body); $('<th>').text(p[0]).appendTo($tr); rowCell($tr, p[1]);
            });
            notice($detail, 'Read-only · параметры из последнего чтения. Неполученные значения обозначены —.');
        }
        function enrich(current, connector, ticket, force) {
            metadataPending = true; metadataError = false; renderList();
            load(current === 'vm' ? 'vm' : 'datastores', force).done(function (data) {
                if (ticket !== nonce || current !== mode || connector !== id()) return;
                tagsPartial = current === 'vm' && data.tag_status !== 'ok';
                var rows = current === 'vm' ? data.rows : data.datastores;
                if (!Array.isArray(rows) || rows.length > MAX) { metadataError = true; return; }
                rows.forEach(function (r) { if (r) metadata[current === 'vm' ? r.vm_external_id : r.external_id] = r; });
            }).fail(function () { if (ticket === nonce) metadataError = true; }).always(function () {
                if (ticket !== nonce) return;
                metadataPending = false; renderList();
            });
        }
        function open(domain, force) {
            if (domain !== 'vm' && domain !== 'datastore') return;
            var previous = mode, previousSelection = selectedKey;
            mode = domain; var ticket = ++nonce, connector = id();
            selectedKey = previous === domain ? previousSelection : ''; page = 0; objectQuery = ''; treeQuery = '';
            model = null; metadata = Object.create(null); graphError = false; metadataError = false; metadataPending = false; tagsPartial = false;
            cancelDetails();
            $('#phase5e-detail-result').empty(); sync(); renderTree(); renderList();
            load('graph', force).done(function (data) {
                if (ticket !== nonce || connector !== id()) return;
                try {
                    if (!data.domains || !Array.isArray(data.datacenters)) throw new Error('Invalid payload');
                    model = graph(data.domains[domain], domain); dcs = Object.create(null);
                    data.datacenters.forEach(function (dc) { dcs[dc.external_id] = dc.name; });
                    if (selectedKey && selectedKey !== '@unresolved' && !model.nodes[selectedKey]) selectedKey = '';
                    restore(); renderTree(); renderList();
                    if (data.partial) notice($tree, 'Иерархия частичная; неподтверждённые связи не угадываются.');
                    enrich(domain, connector, ticket, force);
                } catch (ignored) { model = null; graphError = true; renderTree(); renderList(); }
            }).fail(function () { if (ticket !== nonce) return; graphError = true; renderTree(); renderList(); });
        }
        $vmTab.find('a').on('click', function (e) { e.preventDefault(); open('vm', false); });
        $('.phase5e-view-link[data-view="datastores"]').on('click.phase5f332', function (e) {
            if (flat) return;
            e.preventDefault(); e.stopImmediatePropagation(); open('datastore', false);
        });
        // Capture before ancestor delegated handlers; never hijack the normal VM-card event.
        win.document.addEventListener('click', function (e) {
            var $target = $(e.target).closest('.phase5e-view-link, .phase5e-scope-link, .phase5e-datastore-link');
            if ($target.length && $target.attr('data-view') !== 'datastores') leave();
        }, true);
        win.document.addEventListener('input', function (e) {
            if ($(e.target).is('#phase5e-search, #phase5f2-vm-search')) leave();
        }, true);
        $form[0].addEventListener('submit', function (e) {
            if (!mode) return;
            e.preventDefault(); e.stopImmediatePropagation(); open(mode, true);
        }, true);
        $('#phase5e-page-size')[0].addEventListener('change', function (e) {
            if (!mode) return;
            e.stopImmediatePropagation(); page = 0; renderList();
        }, true);
        $('#phase5e-config').on('change.phase5f332', function () { if (mode) { selectedKey = ''; open(mode, false); } });
        $(document).ajaxComplete(function () { if (mode) sync(); });
    });
})(typeof window !== 'undefined' ? window : {}, typeof window !== 'undefined' ? window.jQuery : null);
