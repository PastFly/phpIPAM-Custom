/* Phase 5F.3.3.1: stable connector+VM deeplink, loaded BEFORE Phase 5F.1 auto-load. */
(function (win, $) {
    'use strict';
    function parse(hash) {
        var match = /^#vmware-connector=([1-9][0-9]{0,9})&vmware-vm=(vm-[0-9]{1,20})$/.exec(String(hash || ''));
        return match ? {connector: match[1], vm: match[2]} : null;
    }
    if (typeof module === 'object' && module.exports) module.exports = {parse: parse};
    if (!$ || !win.document) return;
    var pending = parse(win.location.hash);
    if (!pending || !$('#vmware-phase5e').length) return;
    var allowed = $('#phase5e-config option').filter(function () { return String($(this).val()) === pending.connector; }).length === 1;
    if (!allowed) {
        $('#phase5e-client-warning').text('Подключение из ссылки недоступно. Выбери доступное подключение.');
        return;
    }
    // Set initial controls before the existing auto-load timer is even registered.
    $('#phase5e-config').val(pending.connector);
    $('#phase5e-view').val('vms'); $('#phase5e-page').val('1');
    $('#phase5e-scope, #phase5e-scope-id').val('');
    $('#phase5e-search').val(pending.vm);
    $('.phase5e-view-link').parent().removeClass('active');
    $('.phase5e-view-link[data-view="vms"]').parent().addClass('active');
    $('#phase5e-center-title').text('Виртуальные машины');
    function cancel() { pending = null; }
    $(document).on('input.phase5f331 change.phase5f331', '#phase5e-search, #phase5f2-vm-search, #phase5e-config', cancel);
    $(document).on('click.phase5f331', '.phase5e-scope-link, .phase5e-view-link, .phase5e-vm-link, #phase5e-clear-scope', cancel);
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (!pending || !settings || !/(^|\/)workspace-result\.php(?:\?|$)/.test(String(settings.url || ''))) return;
        var params = new URLSearchParams(typeof settings.data === 'string' ? settings.data : $.param(settings.data || {}));
        if (params.get('config_id') !== pending.connector || params.get('search') !== pending.vm) return;
        var target = pending;
        win.setTimeout(function () {
            if (pending !== target || String($('#phase5e-config').val()) !== target.connector || String($('#phase5e-search').val()) !== target.vm) return;
            var data = xhr.responseJSON;
            if (!data) { try { data = JSON.parse(xhr.responseText); } catch (e) {} }
            var $link = $('#phase5e-list-result .phase5e-vm-link').filter(function () { return $(this).attr('data-vm-id') === target.vm; });
            pending = null;
            if (xhr.status >= 200 && xhr.status < 300 && data && data.ok === true && $link.length === 1) {
                // Reuse the normal VM-card loader and every existing enrichment/guard.
                $link.trigger('click');
            } else {
                $('#phase5e-client-warning').text('VM из ссылки не найдена или недоступна. Обнови поиск VMware.');
            }
        }, 0);
    });
})(typeof window !== 'undefined' ? window : {}, typeof window !== 'undefined' ? window.jQuery : null);
