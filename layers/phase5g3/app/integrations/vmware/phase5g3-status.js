/* Read-only status polling. The background worker never depends on an open page. */
(function (win, $) {
    'use strict';
    if (!$ || win.phpipamInventoryStatusBound) return;
    win.phpipamInventoryStatusBound = true;
    $(function () {
        var $form = $('#phase5e-workspace-form');
        if (!$form.length) return;
        var $panel = $('<div id="phase5g3-status" class="small" role="status" aria-live="polite">').css({
            display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', margin: '0 0 8px'
        }).insertBefore($form);
        var $title = $('<strong class="phase5g3-status-title">').appendTo($panel);
        var $progress = $('<progress max="100" value="0" aria-label="Прогресс синхронизации">').css({
            width: '180px', height: '8px', verticalAlign: 'middle'
        }).appendTo($panel);
        var $info = $('<button type="button" class="btn btn-default btn-xs phase5g3-status-info" aria-expanded="false" aria-label="Подробности синхронизации">!</button>').css({
            width: '22px', height: '22px', padding: '0', lineHeight: '20px', borderRadius: '50%', fontWeight: 'bold'
        }).appendTo($panel);
        // Details expand as a normal flex row below the compact status. They must never float over
        // the VMware workspace or cover the Details column on narrower screens.
        var $popover = $('<div class="panel panel-default phase5g3-status-popover" role="dialog" aria-label="Подробности синхронизации">').css({
            position: 'static', flexBasis: '100%', width: '100%', maxWidth: '720px',
            marginTop: '4px', marginBottom: '0', display: 'none'
        }).appendTo($panel);
        var $popoverBody = $('<div class="panel-body">').appendTo($popover);
        var $detail = $('<div class="small text-muted phase5g3-status-detail">').appendTo($popoverBody);
        var $metrics = $('<div class="small text-muted phase5g3-status-metrics">').css({marginTop: '4px'}).appendTo($popoverBody);
        var $retry = $('<button type="button" class="btn btn-default btn-xs phase5g3-status-retry">Обновить состояние</button>').css({marginTop: '8px'}).appendTo($popoverBody);
        var flight = null, timer = null, serial = 0, stopped = false, closed = false;
        var connector = '', initializing = false, touched = false;
        var labels = {ready: 'Инвентарь синхронизирован', collecting: 'Синхронизация', initializing: 'Первичная синхронизация',
            error: 'Ошибка синхронизации', idle: 'Инвентарь синхронизирован'};
        var errors = {access_denied: 'отказ в доступе', tls: 'проверка TLS', timeout: 'истекло время ожидания',
            incomplete: 'неполные данные', membership_changed: 'изменился состав объектов', size_limit: 'ограничение объёма',
            space_low: 'недостаточно места', generation_expired: 'прерванный цикл устарел', collection_failed: 'ошибка сбора'};
        function active() { return !closed && win.document.visibilityState !== 'hidden'; }
        function date(value) {
            if (!Number.isFinite(value) || value <= 0) return 'ещё не завершена';
            var d = new Date((value + 10800) * 1000);
            return isNaN(d.getTime()) ? 'неизвестно' : d.toISOString().replace('T', ' ').replace('.000Z', ' UTC+3');
        }
        function abort() { serial++; if (timer !== null) win.clearTimeout(timer); timer = null; if (flight) flight.abort(); flight = null; }
        function schedule() { if (active() && !stopped) timer = win.setTimeout(poll, 30000); }
        function togglePopover(force) {
            var open = typeof force === 'boolean' ? force : $popover.css('display') === 'none';
            $popover.css('display', open ? 'block' : 'none');
            $info.attr('aria-expanded', open ? 'true' : 'false');
        }
        function warnIndicator(value) {
            $info.toggleClass('btn-warning', !!value).toggleClass('btn-default', !value);
            $info.attr('title', value ? 'Есть предупреждения синхронизации' : 'Подробности синхронизации');
        }
        function render(data) {
            var wasInitializing = initializing; initializing = data.has_inventory === false;
            var p = data.progress || {}, total = Number(p.total), count = Number(p.completed);
            var hasTotal = Number.isFinite(total) && total > 0 && Number.isFinite(count);
            var label = labels[data.state] || 'Состояние инвентаря';
            if (hasTotal && (data.state === 'collecting' || initializing)) label += ' ' + Math.max(0, count) + '/' + total;
            $title.text(label);
            $progress.prop('hidden', data.state !== 'collecting' && !initializing);
            if (hasTotal) $progress.attr('value', Math.min(100, Math.max(0, 100 * count / total)));
            else $progress.removeAttr('value');
            var message = initializing ? 'Данные ещё не опубликованы.' : 'Последняя публикация: ' + date(data.published_at) + '.';
            if (hasTotal && data.state === 'collecting') message += ' Обработано ' + Math.max(0, count) + ' из ' + total + '.';
            if (data.stale && !initializing) message += ' Показаны ранее собранные данные.';
            if (data.worker_silent) message += ' Давно нет сигнала от фоновой службы; проверьте её состояние.';
            if (data.last_error) {
                message += (data.state === 'collecting' ? ' Предыдущая ошибка: ' : ' Ошибка: ') +
                    (errors[data.last_error] || 'сбой фоновой службы') + '.';
            }
            if (data.retry_at) message += ' Следующая попытка не раньше ' + date(data.retry_at) + '.';
            $detail.text(message + ' Открытие страницы не запускает сбор.');
            var metrics = data.telemetry || {};
            $metrics.text('Графики: ' + (metrics.last_success_at ? 'последние полученные измерения ' + date(metrics.last_success_at) :
                'ожидание независимого сбора') + '. Время каждой серии хранится отдельно.');
            warnIndicator(data.state === 'error' || data.stale || data.worker_silent || !!data.last_error);
            $('#phase5f34-cache-indicator').hide();
            // Only bootstrap an untouched empty workspace. Later generations must not redraw a selection.
            if (wasInitializing && !initializing && !touched) $('#phase5e-refresh').trigger('click');
        }
        function poll() {
            timer = null;
            if (!active() || stopped || flight) return;
            var id = String($('#phase5e-config').val() || ''), token = $form.find('[name="csrf_cookie"]').val();
            if (!/^[1-9][0-9]{0,8}$/.test(id) || typeof token !== 'string' || !token) return;
            if (id !== connector) { connector = id; initializing = false; touched = false; }
            var target;
            try { target = new URL('app/integrations/vmware/mirror/read.php', win.document.baseURI); } catch (ignored) { return; }
            if (target.origin !== win.location.origin) return;
            var request = ++serial;
            flight = $.ajax({url: 'app/integrations/vmware/mirror/read.php', type: 'POST', dataType: 'json', global: false,
                timeout: 10000, data: {config_id: id, csrf_cookie: token, view: 'status'}});
            flight.done(function (data) {
                if (request !== serial || id !== String($('#phase5e-config').val()) || !active()) return;
                if (!data || data.ok !== true || String(data.connector_id) !== id || data.vcenter_requests !== 0) {
                    $title.text('Не удалось получить локальное состояние'); warnIndicator(true); return;
                }
                render(data);
            }).fail(function (xhr, state) {
                if (request !== serial || state === 'abort') return;
                stopped = xhr.status === 401 || xhr.status === 403;
                $title.text(stopped ? 'Нет доступа к инвентарю' : 'Состояние синхронизации временно недоступно');
                $detail.text('Опрос vCenter из браузера не выполняется.');
                $metrics.text('Последнее сохранённое состояние сайта не изменено.');
                warnIndicator(true);
            }).always(function () { if (request === serial) { flight = null; schedule(); } });
        }
        $info.on('click', function (event) { event.preventDefault(); event.stopPropagation(); togglePopover(); });
        $popover.on('click', function (event) { event.stopPropagation(); });
        $(win.document).on('click.phase5g3-info', function () { togglePopover(false); });
        $(win.document).on('keydown.phase5g3-info', function (event) { if (event.key === 'Escape') togglePopover(false); });
        $retry.on('click', function () { stopped = false; abort(); poll(); });
        $('#phase5e-config').on('change.phase5g3', function () { abort(); stopped = false; connector = ''; togglePopover(false); poll(); });
        $('#vmware-phase5e').on('click.phase5g3', '.phase5e-vm-link, .phase5f-host-link, .phase5e-scope-link', function () { touched = true; });
        win.document.addEventListener('visibilitychange', function () { abort(); if (active()) poll(); });
        win.addEventListener('pagehide', function () { closed = true; abort(); togglePopover(false); });
        $title.text('Чтение состояния фоновой службы…'); poll();
    });
})(window, window.jQuery);
