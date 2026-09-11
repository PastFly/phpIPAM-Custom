/* phpIPAM Custom — Phase 5F.2.6 VMware Folder UI state persistence.
 * Presentation only. Stores collapsed VMware Folder IDs per connector in browser
 * localStorage. No server-side persistence and no vCenter mutation.
 */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var STORAGE_PREFIX = 'phpipam.vmware.folder-state.v1:';
    var MAX_FOLDER_IDS = 512;
    var observer = null;
    var applyTimer = null;

    function configId() {
        return String($('#phase5e-config').val() || '').trim();
    }

    function storageKey(id) {
        return STORAGE_PREFIX + String(id || '');
    }

    function validFolderId(value) {
        return /^[A-Za-z0-9_.:-]{1,128}$/.test(String(value || ''));
    }

    function readCollapsed(id) {
        var result = {};
        if (!id) return result;
        try {
            var raw = window.localStorage.getItem(storageKey(id));
            if (!raw) return result;
            var parsed = JSON.parse(raw);
            var rows = parsed && Array.isArray(parsed.collapsed) ? parsed.collapsed : [];
            rows.slice(0, MAX_FOLDER_IDS).forEach(function (folderId) {
                folderId = String(folderId || '');
                if (validFolderId(folderId)) result[folderId] = true;
            });
        } catch (error) {
            return {};
        }
        return result;
    }

    function writeCollapsed(id, collapsed) {
        if (!id) return;
        var rows = Object.keys(collapsed || {}).filter(validFolderId).sort().slice(0, MAX_FOLDER_IDS);
        try {
            window.localStorage.setItem(storageKey(id), JSON.stringify({version: 1, collapsed: rows}));
        } catch (error) {
            /* Browser storage may be blocked; Folder UI remains fully functional. */
        }
    }

    function setFolderVisualState($li, collapsed) {
        var $children = $li.children('ul').first();
        var $icon = $li.children('.phase5f25-folder-node').find('.phase5f25-folder-icon').first();
        if (!$children.length) return;
        $children.toggle(!collapsed);
        if ($icon.length) {
            $icon.toggleClass('fa-folder-open-o', !collapsed)
                .toggleClass('fa-folder-o', collapsed);
        }
    }

    function applySavedState() {
        var id = configId();
        if (!id) return;
        var collapsed = readCollapsed(id);
        $('#phase5e-tree-result li.phase5f25-folder[data-folder-id]').each(function () {
            var $li = $(this);
            var folderId = String($li.attr('data-folder-id') || '');
            if (!validFolderId(folderId)) return;
            setFolderVisualState($li, collapsed[folderId] === true);
        });
        $('#phase5e-tree-result .phase5e-tree').attr('data-phase5f26-folder-state', '1');
    }

    function scheduleApply() {
        if (applyTimer !== null) window.clearTimeout(applyTimer);
        applyTimer = window.setTimeout(function () {
            applyTimer = null;
            applySavedState();
        }, 0);
    }

    function persistFolder($node) {
        var id = configId();
        if (!id) return;
        var $li = $node.closest('li.phase5f25-folder[data-folder-id]');
        if (!$li.length) return;
        var folderId = String($li.attr('data-folder-id') || '');
        if (!validFolderId(folderId)) return;
        var $children = $li.children('ul').first();
        if (!$children.length) return;

        var collapsed = readCollapsed(id);
        if ($children.is(':visible')) delete collapsed[folderId];
        else collapsed[folderId] = true;
        writeCollapsed(id, collapsed);
    }

    $(document).on('click.phase5f26 keydown.phase5f26', '.phase5f25-folder-node', function (event) {
        if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
        var $node = $(this);
        window.setTimeout(function () { persistFolder($node); }, 0);
    });

    $('#phase5e-config').on('change.phase5f26', function () {
        scheduleApply();
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('folder-result.php') !== -1 || url.indexOf('workspace-result.php') !== -1) {
            scheduleApply();
        }
    });

    var target = document.getElementById('phase5e-tree-result');
    if (target && window.MutationObserver) {
        observer = new MutationObserver(function (mutations) {
            var shouldApply = false;
            mutations.some(function (mutation) {
                if (!mutation.addedNodes || !mutation.addedNodes.length) return false;
                for (var i = 0; i < mutation.addedNodes.length; i++) {
                    var node = mutation.addedNodes[i];
                    if (node.nodeType !== 1) continue;
                    if ($(node).is('.phase5f25-folder') || $(node).find('.phase5f25-folder').length) {
                        shouldApply = true;
                        return true;
                    }
                }
                return false;
            });
            if (shouldApply) scheduleApply();
        });
        observer.observe(target, {childList: true, subtree: true});
    }

    scheduleApply();
})(window.jQuery);
