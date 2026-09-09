/* phpIPAM Custom — Phase 5F.2 UX refinements.
 * Dedicated VM search, on-demand vSphere Notes and resizable three-pane layout.
 */
(function ($) {
    'use strict';
    if (!$ || !document.getElementById('vmware-phase5e')) return;

    var vmSearchTimer = null;
    var annotationRequest = null;
    var lastAnnotationVmId = null;
    var layoutKey = 'phpipam.vmware.phase5f2.layout.v1';
    var defaultLeft = 18;
    var defaultRight = 34;

    function submitWorkspace(delay) {
        if (vmSearchTimer) window.clearTimeout(vmSearchTimer);
        vmSearchTimer = window.setTimeout(function () {
            $('#phase5e-page').val('1');
            $('#phase5e-workspace-form').trigger('submit');
        }, delay || 0);
    }

    function setVmView() {
        $('#phase5e-view').val('vms');
        $('#phase5e-page').val('1');
        $('.phase5e-view-link').parent().removeClass('active');
        $('.phase5e-view-link[data-view="vms"]').parent().addClass('active');
        $('#phase5e-center-title').text('Виртуальные машины');
    }

    function installVmSearch() {
        if ($('#phase5f2-vm-search').length) return;
        var html = '<div class="phase5f2-vm-search-bar" id="phase5f2-vm-search-wrap" style="display:none;">'
            + '<div class="form-group"><label for="phase5f2-vm-search">Поиск VM по всей инфраструктуре</label>'
            + '<div class="input-group input-group-sm">'
            + '<span class="input-group-addon"><i class="fa fa-search"></i></span>'
            + '<input type="text" class="form-control" id="phase5f2-vm-search" maxlength="256" placeholder="Имя VM или vm-12345">'
            + '<span class="input-group-btn"><button type="button" class="btn btn-default" id="phase5f2-vm-search-clear" title="Очистить"><i class="fa fa-times"></i></button></span>'
            + '</div><div class="help-block small" style="margin-bottom:0;">Поиск выполняется автоматически и временно снимает текущий scope, чтобы найти VM на любом ESXi.</div></div></div>';
        $('#phase5e-list-result').before(html);
    }

    function syncVmSearchVisibility() {
        var isVmView = String($('#phase5e-view').val() || '') === 'vms';
        $('#phase5f2-vm-search-wrap').toggle(isVmView);
        if (isVmView) {
            var topValue = String($('#phase5e-search').val() || '');
            if ($('#phase5f2-vm-search').val() !== topValue) $('#phase5f2-vm-search').val(topValue);
        }
    }

    function applyGlobalVmSearch(value) {
        value = String(value || '');
        $('#phase5e-search').val(value);
        setVmView();
        if (value.trim() !== '') {
            $('#phase5e-scope, #phase5e-scope-id').val('');
            $('#phase5e-clear-scope').hide();
        }
        submitWorkspace(450);
    }

    function commonVmData(vmId) {
        return {
            csrf_cookie: $('#phase5e-workspace-form input[name=csrf_cookie]').val(),
            config_id: $('#phase5e-config').val(),
            vm_id: vmId
        };
    }

    function loadVmAnnotation(vmId) {
        vmId = String(vmId || '');
        if (!/^vm-[0-9]+$/.test(vmId)) return;
        if (annotationRequest && annotationRequest.readyState !== 4) annotationRequest.abort();
        lastAnnotationVmId = vmId;
        $('#phase5e-detail-result .phase5f2-vm-annotation').remove();

        annotationRequest = $.ajax({
            url: 'app/integrations/vmware/vm-annotation-result.php',
            type: 'POST',
            data: commonVmData(vmId),
            cache: false
        }).done(function (response) {
            if (lastAnnotationVmId !== vmId) return;
            $('#phase5e-detail-result .phase5f2-vm-annotation').remove();
            var $sections = $('#phase5e-detail-result .phase5e-detail-section');
            if ($sections.length) $sections.eq(0).after(response);
            else $('#phase5e-detail-result').prepend(response);
        });
    }

    function normalizeLayout(left, right) {
        left = parseFloat(left);
        right = parseFloat(right);
        if (!isFinite(left)) left = defaultLeft;
        if (!isFinite(right)) right = defaultRight;
        left = Math.max(12, Math.min(30, left));
        right = Math.max(24, Math.min(44, right));
        if (100 - left - right < 30) {
            var deficit = 30 - (100 - left - right);
            if (right - deficit >= 24) right -= deficit;
            else left = Math.max(12, left - (deficit - (right - 24))), right = 24;
        }
        return {left: Math.round(left * 10) / 10, right: Math.round(right * 10) / 10};
    }

    function loadLayout() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(layoutKey) || '{}');
            return normalizeLayout(parsed.left, parsed.right);
        } catch (e) {
            return normalizeLayout(defaultLeft, defaultRight);
        }
    }

    function saveLayout(layout) {
        try { window.localStorage.setItem(layoutKey, JSON.stringify(layout)); } catch (e) {}
    }

    function applyLayout(layout) {
        var row = document.querySelector('#vmware-phase5e .phase5e-workspace-row');
        if (!row) return;
        layout = normalizeLayout(layout.left, layout.right);
        row.style.setProperty('--phase5f2-left', layout.left + '%');
        row.style.setProperty('--phase5f2-right', layout.right + '%');
    }

    function resetLayout() {
        var layout = normalizeLayout(defaultLeft, defaultRight);
        applyLayout(layout);
        saveLayout(layout);
    }

    function installResizableLayout() {
        var $row = $('#vmware-phase5e .phase5e-workspace-row');
        if (!$row.length || $row.hasClass('phase5f2-resizable')) return;
        var $cols = $row.children('.phase5e-workspace-col');
        if ($cols.length !== 3) return;

        $('<div class="phase5f2-splitter" data-phase5f2-split="left" role="separator" aria-label="Изменить ширину Инфраструктуры"></div>').insertAfter($cols.eq(0));
        $('<div class="phase5f2-splitter" data-phase5f2-split="right" role="separator" aria-label="Изменить ширину Деталей"></div>').insertAfter($cols.eq(1));
        $row.addClass('phase5f2-resizable');
        applyLayout(loadLayout());

        var $detailHeading = $cols.eq(2).find('.panel-heading').first();
        if ($detailHeading.length && !$('#phase5f2-layout-reset').length) {
            $detailHeading.append('<button type="button" class="btn btn-xs btn-default pull-right phase5f2-layout-reset" id="phase5f2-layout-reset" title="Сбросить ширину панелей"><i class="fa fa-columns"></i></button>');
        }
    }

    function beginResize(event) {
        if (window.innerWidth < 1200) return;
        event.preventDefault();
        var split = String($(this).attr('data-phase5f2-split') || '');
        var row = document.querySelector('#vmware-phase5e .phase5e-workspace-row.phase5f2-resizable');
        if (!row || (split !== 'left' && split !== 'right')) return;
        var rect = row.getBoundingClientRect();
        if (!rect.width) return;
        var layout = loadLayout();
        var startX = event.pageX;
        var startLeft = layout.left;
        var startRight = layout.right;
        $(row).addClass('phase5f2-resizing');

        function move(moveEvent) {
            var deltaPct = ((moveEvent.pageX - startX) / rect.width) * 100;
            var next = {left: startLeft, right: startRight};
            if (split === 'left') next.left = startLeft + deltaPct;
            else next.right = startRight - deltaPct;
            next = normalizeLayout(next.left, next.right);
            applyLayout(next);
            layout = next;
        }

        function stop() {
            $(document).off('mousemove.phase5f2-resize', move).off('mouseup.phase5f2-resize', stop);
            $(row).removeClass('phase5f2-resizing');
            saveLayout(layout);
        }

        $(document).on('mousemove.phase5f2-resize', move).on('mouseup.phase5f2-resize', stop);
    }

    installVmSearch();
    installResizableLayout();
    syncVmSearchVisibility();

    $(document).on('input.phase5f2', '#phase5f2-vm-search', function () { applyGlobalVmSearch($(this).val()); });
    $(document).on('click.phase5f2', '#phase5f2-vm-search-clear', function () {
        $('#phase5f2-vm-search').val('');
        applyGlobalVmSearch('');
    });
    $(document).on('click.phase5f2', '#phase5f2-layout-reset', resetLayout);
    $(document).on('mousedown.phase5f2', '.phase5f2-splitter', beginResize);

    $(document).on('click.phase5f2', '.phase5e-view-link', function () {
        window.setTimeout(syncVmSearchVisibility, 0);
    });

    $('#phase5e-search').on('input.phase5f2 change.phase5f2', function () {
        if (String($('#phase5e-view').val() || '') === 'vms') $('#phase5f2-vm-search').val($(this).val());
    });

    $(document).ajaxComplete(function (event, xhr, settings) {
        var url = settings && settings.url ? String(settings.url) : '';
        if (url.indexOf('workspace-result.php') !== -1 && xhr.status >= 200 && xhr.status < 300) {
            syncVmSearchVisibility();
        }
        if (url.indexOf('vm-card-result.php') !== -1 && xhr.status >= 200 && xhr.status < 300) {
            var vmId = $('.phase5e-vm-row.active .phase5e-vm-link').attr('data-vm-id');
            if (vmId) loadVmAnnotation(vmId);
        }
    });
})(window.jQuery);
