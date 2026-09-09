/* phpIPAM Custom — Phase 5F.2.5 VMware Folder hierarchy.
 * Presentation only. Folder inventory is transient, read-only and cached in the
 * browser for a short interval to avoid repeating the bounded vCenter crawl on
 * every VM search keystroke.
 */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var CACHE_TTL_MS = 300000;
    var cache = {};
    var request = null;
    var requestSequence = 0;

    function configId() {
        return String($('#phase5e-config').val() || '');
    }

    function csrf() {
        return $('#phase5e-workspace-form input[name=csrf_cookie]').val();
    }

    function safeId(value) {
        return String(value || '').replace(/[^A-Za-z0-9_.:-]/g, '_');
    }

    function textOf($node) {
        return String($node.text() || '').toLowerCase().trim();
    }

    function folderStatus(message) {
        var $heading = $('#phase5e-tree-result').closest('.phase5e-pane').find('> .panel-heading');
        $heading.find('.phase5f25-folder-warning').remove();
        if (!message) return;
        $('<span class="phase5f25-folder-warning pull-right" tabindex="0"><i class="fa fa-exclamation-circle"></i></span>')
            .attr('title', message)
            .appendTo($heading);
    }

    function nodeSort(left, right) {
        var a = textOf(left.$link || left.$li);
        var b = textOf(right.$link || right.$li);
        if (a < b) return -1;
        if (a > b) return 1;
        return String(left.id || '').localeCompare(String(right.id || ''));
    }

    function appendSorted(target, rows) {
        rows.sort(nodeSort);
        rows.forEach(function (row) { target.append(row.$li); });
    }

    function makeFolderNode(folder) {
        var id = String(folder.external_id || '');
        var $li = $('<li class="phase5f25-folder"></li>').attr('data-folder-id', id);
        var $label = $('<span class="phase5e-tree-node phase5f25-folder-node" role="button" tabindex="0"></span>')
            .attr('data-folder-id', id)
            .attr('title', 'VMware Folder: ' + String(folder.name || id));
        $('<i class="fa fa-folder-open-o phase5f25-folder-icon"></i>').appendTo($label);
        $label.append(document.createTextNode(' ' + String(folder.name || id)));
        $li.append($label).append('<ul class="nav nav-pills nav-stacked phase5f25-folder-children"></ul>');
        return $li;
    }

    function isClusterHost($hostLi) {
        var clustered = false;
        $hostLi.parents('li').each(function () {
            if ($(this).children('a.phase5e-scope-link[data-scope="cluster"]').length) {
                clustered = true;
                return false;
            }
        });
        return clustered;
    }

    function collectExisting($dcChildren) {
        var clusters = {};
        var standaloneHosts = {};

        $dcChildren.find('a.phase5e-scope-link[data-scope="cluster"]').each(function () {
            var id = String($(this).attr('data-scope-id') || '');
            if (!id || clusters[id]) return;
            var $li = $(this).closest('li');
            clusters[id] = {id: id, $li: $li, $link: $(this)};
        });

        $dcChildren.find('a.phase5e-scope-link[data-scope="host"]').each(function () {
            var id = String($(this).attr('data-scope-id') || '');
            if (!id || standaloneHosts[id]) return;
            var $li = $(this).closest('li');
            if (isClusterHost($li)) return;
            standaloneHosts[id] = {id: id, $li: $li, $link: $(this)};
        });

        Object.keys(clusters).forEach(function (id) { clusters[id].$li.detach(); });
        Object.keys(standaloneHosts).forEach(function (id) { standaloneHosts[id].$li.detach(); });
        return {clusters: clusters, hosts: standaloneHosts};
    }

    function applyFolderAwareFilter() {
        var $tree = $('#phase5e-tree-result .phase5e-tree');
        if (!$tree.length) return;
        var needle = String($('#phase5f1-tree-filter').val() || '').toLowerCase().trim();
        var $items = $tree.find('li');
        $items.show();
        if (!needle) return;

        $items.hide();
        $tree.find('.phase5e-scope-link, .phase5f25-folder-node').each(function () {
            var $match = $(this);
            if (textOf($match).indexOf(needle) === -1) return;
            var $li = $match.closest('li');
            $li.show().parents('li').show();
            if ($match.hasClass('phase5f25-folder-node')) $li.find('li').show();
        });
    }

    function applyInventory(inventory) {
        if (!inventory || !Array.isArray(inventory.folders)) return;
        var folderById = {};
        inventory.folders.forEach(function (folder) {
            if (folder && folder.external_id) folderById[String(folder.external_id)] = folder;
        });
        var hostFolder = inventory.host_folder_map || {};
        var clusterFolder = inventory.cluster_folder_map || {};
        var rootByDc = inventory.root_folder_by_datacenter || {};

        $('#phase5e-tree-result .phase5e-tree > ul > li').each(function () {
            var $dcLi = $(this);
            var $dcLink = $dcLi.children('a.phase5e-scope-link[data-scope="datacenter"]').first();
            if (!$dcLink.length) return;
            var dcId = String($dcLink.attr('data-scope-id') || '');
            var $dcChildren = $dcLi.children('ul.nav').first();
            if (!dcId || !$dcChildren.length) return;

            var existing = collectExisting($dcChildren);
            var folderNodes = {};
            var rows = inventory.folders.filter(function (folder) {
                return folder && String(folder.datacenter_external_id || '') === dcId && Number(folder.depth || 0) > 0;
            });
            rows.sort(function (a, b) {
                var depth = Number(a.depth || 0) - Number(b.depth || 0);
                if (depth) return depth;
                var an = String(a.name || '').toLowerCase();
                var bn = String(b.name || '').toLowerCase();
                if (an < bn) return -1;
                if (an > bn) return 1;
                return String(a.external_id || '').localeCompare(String(b.external_id || ''));
            });

            $dcChildren.empty();
            rows.forEach(function (folder) {
                var id = String(folder.external_id);
                folderNodes[id] = makeFolderNode(folder);
            });
            rows.forEach(function (folder) {
                var id = String(folder.external_id);
                var parentId = String(folder.parent_folder_external_id || '');
                var $target = (parentId && folderNodes[parentId])
                    ? folderNodes[parentId].children('ul').first()
                    : $dcChildren;
                $target.append(folderNodes[id]);
            });

            var rootId = String(rootByDc[dcId] || '');
            var directClusters = [];
            var directHosts = [];
            var unresolved = [];

            Object.keys(existing.clusters).forEach(function (id) {
                var mapped = Object.prototype.hasOwnProperty.call(clusterFolder, id);
                var folderId = mapped ? String(clusterFolder[id] || '') : '';
                var row = existing.clusters[id];
                if (mapped && folderId && folderId !== rootId && folderNodes[folderId]) {
                    folderNodes[folderId].children('ul').first().append(row.$li);
                } else if (mapped && (!folderId || folderId === rootId)) {
                    directClusters.push(row);
                } else {
                    unresolved.push(row);
                }
            });

            Object.keys(existing.hosts).forEach(function (id) {
                var mapped = Object.prototype.hasOwnProperty.call(hostFolder, id);
                var folderId = mapped ? String(hostFolder[id] || '') : '';
                var row = existing.hosts[id];
                if (mapped && folderId && folderId !== rootId && folderNodes[folderId]) {
                    folderNodes[folderId].children('ul').first().append(row.$li);
                } else if (mapped && (!folderId || folderId === rootId)) {
                    directHosts.push(row);
                } else {
                    unresolved.push(row);
                }
            });

            appendSorted($dcChildren, directClusters);
            appendSorted($dcChildren, directHosts);

            if (unresolved.length) {
                var $fallback = $('<li class="phase5f25-folder phase5f25-folder-unresolved"><span class="phase5e-tree-node text-muted"><i class="fa fa-question-circle"></i> Без определённой VMware Folder</span><ul class="nav nav-pills nav-stacked"></ul></li>');
                appendSorted($fallback.children('ul'), unresolved);
                $dcChildren.append($fallback);
            }
        });

        $('#phase5e-tree-result .phase5e-tree').attr('data-phase5f25-folders', '1');
        folderStatus('');
        applyFolderAwareFilter();
    }

    function loadFolders(force) {
        var id = configId();
        if (!id || !csrf()) return;
        var now = Date.now();
        if (!force && cache[id] && now - cache[id].at < CACHE_TTL_MS) {
            applyInventory(cache[id].inventory);
            return;
        }

        if (request && request.readyState !== 4) request.abort();
        var sequence = ++requestSequence;
        request = $.ajax({
            url: 'app/integrations/vmware/folder-result.php',
            type: 'POST',
            dataType: 'json',
            cache: false,
            data: {csrf_cookie: csrf(), config_id: id}
        }).done(function (response) {
            if (sequence !== requestSequence || id !== configId()) return;
            if (!response || response.ok !== true || !response.inventory) {
                folderStatus('VMware Folder hierarchy недоступна; показано базовое дерево без папок.');
                return;
            }
            cache[id] = {at: Date.now(), inventory: response.inventory};
            applyInventory(response.inventory);
        }).fail(function (xhr, status) {
            if (status === 'abort' || sequence !== requestSequence) return;
            folderStatus('VMware Folder hierarchy недоступна; показано базовое дерево без папок.');
        });
    }

    $(document).on('click.phase5f25 keydown.phase5f25', '.phase5f25-folder-node', function (event) {
        if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        var $li = $(this).closest('li.phase5f25-folder');
        var $children = $li.children('ul').first();
        if (!$children.length) return;
        var hidden = !$children.is(':visible');
        $children.toggle(hidden);
        $(this).find('.phase5f25-folder-icon')
            .toggleClass('fa-folder-open-o', hidden)
            .toggleClass('fa-folder-o', !hidden);
    });

    $(document).on('input.phase5f25', '#phase5f1-tree-filter', applyFolderAwareFilter);
    $(document).on('click.phase5f25', '#phase5f1-tree-filter-clear', function () {
        window.setTimeout(applyFolderAwareFilter, 0);
    });

    $('#phase5e-config').on('change.phase5f25', function () {
        requestSequence++;
        if (request && request.readyState !== 4) request.abort();
        folderStatus('');
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('workspace-result.php') !== -1 && xhr.status >= 200 && xhr.status < 300) {
            loadFolders(false);
        }
    });
})(window.jQuery);
