/* phpIPAM Custom — Phase 5F.1 UX refinements.
 * Presentation/interaction only: auto-load, debounced search, tree filter,
 * GiB->TiB formatting and optional storage performance fragment.
 */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var searchTimer = null;
    var storageRequest = null;
    var lastHostId = null;

    function submitWorkspace() {
        $('#phase5e-page').val('1');
        $('#phase5e-workspace-form').trigger('submit');
    }

    function scheduleWorkspace(delay) {
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(submitWorkspace, delay || 350);
    }

    function installTreeFilter() {
        if ($('#phase5f1-tree-filter').length) return;
        var html = '<div class="form-group" style="margin:8px 8px 4px;">'
            + '<div class="input-group input-group-sm">'
            + '<span class="input-group-addon"><i class="fa fa-filter"></i></span>'
            + '<input type="text" class="form-control" id="phase5f1-tree-filter" maxlength="128" placeholder="Фильтр Datacenter / Cluster / ESXi">'
            + '<span class="input-group-btn"><button type="button" class="btn btn-default" id="phase5f1-tree-filter-clear" title="Очистить"><i class="fa fa-times"></i></button></span>'
            + '</div></div>';
        $(html).insertBefore('#phase5e-tree-result');
    }

    function applyTreeFilter() {
        var $tree = $('#phase5e-tree-result .phase5e-tree');
        if (!$tree.length) return;
        var needle = String($('#phase5f1-tree-filter').val() || '').toLowerCase().trim();
        var $items = $tree.find('li');
        $items.show();
        if (!needle) return;
        $items.hide();
        $tree.find('.phase5e-scope-link').each(function () {
            if (String($(this).text() || '').toLowerCase().indexOf(needle) === -1) return;
            $(this).closest('li').show().parents('li').show();
        });
    }

    function convertGiBToTiB(root) {
        var node = root && root.nodeType ? root : document;
        if (!document.createTreeWalker || !window.NodeFilter) return;
        var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null, false);
        var textNodes = [], current;
        while ((current = walker.nextNode())) textNodes.push(current);
        var regex = /([0-9][0-9 ]*(?:[.,][0-9]+)?)\s+GiB\b/g;
        textNodes.forEach(function (textNode) {
            if (!textNode.nodeValue || textNode.parentNode && /^(SCRIPT|STYLE)$/.test(textNode.parentNode.nodeName)) return;
            textNode.nodeValue = textNode.nodeValue.replace(regex, function (all, raw) {
                var gib = parseFloat(String(raw).replace(/ /g, '').replace(',', '.'));
                if (!isFinite(gib) || gib <= 1000) return all;
                return (gib / 1024).toFixed(2) + ' TiB';
            });
        });
    }

    function commonStorageData() {
        return {
            csrf_cookie: $('#phase5e-workspace-form input[name=csrf_cookie]').val(),
            config_id: $('#phase5e-config').val(),
            max_samples: 60
        };
    }

    function loadStorage(entityType, entityId) {
        if (!/^(host|vm)$/.test(String(entityType || ''))) return;
        if (entityType === 'host' && !/^host-[0-9]+$/.test(String(entityId || ''))) return;
        if (entityType === 'vm' && !/^vm-[0-9]+$/.test(String(entityId || ''))) return;
        if (storageRequest && storageRequest.readyState !== 4) storageRequest.abort();

        var data = commonStorageData();
        data.entity_type = entityType;
        data.entity_id = entityId;
        $('#phase5e-detail-result .phase5f1-storage-performance').remove();

        storageRequest = $.ajax({
            url: 'app/integrations/vmware/storage-performance-result.php',
            type: 'POST',
            data: data,
            cache: false
        }).done(function (response) {
            $('#phase5e-detail-result .phase5f1-storage-performance').remove();
            $('#phase5e-detail-result').append(response);
            convertGiBToTiB(document.getElementById('phase5e-detail-result'));
        });
    }

    installTreeFilter();
    $('#phase5e-search').after('<div class="help-block small" style="margin-bottom:0;">Поиск применяется автоматически.</div>');

    $('#phase5e-search').on('input.phase5f1', function () { scheduleWorkspace(450); });
    $('#phase5e-page-size').on('change.phase5f1', function () { scheduleWorkspace(50); });
    $('#phase5e-config').on('change.phase5f1', function () { scheduleWorkspace(80); });
    $(document).on('input.phase5f1', '#phase5f1-tree-filter', applyTreeFilter);
    $(document).on('click.phase5f1', '#phase5f1-tree-filter-clear', function () { $('#phase5f1-tree-filter').val(''); applyTreeFilter(); });

    $(document).on('click.phase5f1', '.phase5e-scope-link[data-scope="host"]', function () {
        lastHostId = $(this).attr('data-scope-id');
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('workspace-result.php') !== -1 && xhr.status >= 200 && xhr.status < 300) {
            applyTreeFilter();
            convertGiBToTiB(document.getElementById('phase5e-list-result'));
        }
        if (url.indexOf('vm-card-result.php') !== -1 && xhr.status >= 200 && xhr.status < 300) {
            convertGiBToTiB(document.getElementById('phase5e-detail-result'));
            var vmId = $('.phase5e-vm-row.active .phase5e-vm-link').attr('data-vm-id');
            if (vmId) loadStorage('vm', vmId);
        }
        if (url.indexOf('host-card-result.php') !== -1 && xhr.status >= 200 && xhr.status < 300) {
            convertGiBToTiB(document.getElementById('phase5e-detail-result'));
            if (lastHostId) loadStorage('host', lastHostId);
        }
    });

    // Initial inventory load is automatic. No periodic polling is introduced:
    // full topology/datastore reads remain explicit on page open/filter changes.
    window.setTimeout(function () {
        if ($('#phase5e-workspace-form').length && !String($('#phase5e-runtime-summary').text() || '').trim()) submitWorkspace();
    }, 120);
})(window.jQuery);
