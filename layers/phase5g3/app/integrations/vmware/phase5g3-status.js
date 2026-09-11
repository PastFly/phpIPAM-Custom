/* Read-only status polling. The background worker never depends on an open page. */
(function (win, $) {
    'use strict';
    if (!$ || win.phpipamInventoryStatusBound) return;
    win.phpipamInventoryStatusBound = true;
    $(function () {
        var $form = $('#phase5e-workspace-form');
        if (!$form.length) return;
        var $panel = $('<div id="phase5g3-status" class="panel panel-default" role="status" aria-live="polite">').insertBefore($form);
        var $body = $('<div class="panel-body">').appendTo($panel);
        var $title = $('<strong>').appendTo($body);
        var $progress = $('<progress max="100" value="0" aria-label="Прогресс синхронизации">').css({width: '100%'}).appendTo($body);
        var $detail = $('<div class="small text-muted">').appendTo($body);
        var $metrics = $('<div class="small text-muted">').appendTo($body);
        var $retry = $('<button type="button" class="btn btn-default btn-xs">Обновить состояние</button>').appendTo($body);
        var flight = null, timer = null, serial = 0, stopped = false, closed = false;
        var connector = '', initializing = false, touched = false;
        var labels = {ready: 'Инвентарь готов', collecting: 'Синхронизация инвентаря', initializing: 'Первичная синхронизация',
            error: 'Ошибка фоновой синхронизации', idle: 'Инвентарь готов'};
        var errors = {access_denied: 'отказ в доступе', tls: 'проверка TLS', timeout: 'истекло время ожидания',
            incomplete: 'неполные данные', membership_changed: 'изменился состав объектов', size_limit: 'ограничение объёма',
            space_low: 'недостаточно места', generation_expired: 'прерванный цикл устарел', collection_failed: 'ошибка сбора'};
        function active() { return !closed && win.document.visibilityState !== 'hidden'; }
        function date(value) {
            if (!Number.isFinite(value) || value <= 0) return 'ещё не завершена';
            var d = new Date(value * 1000); return isNaN(d.getTime()) ? 'неизвестно' : d.toISOString().replace('T', ' ').replace('.000Z', ' UTC');
        }
        function abort() { serial++; if (timer !== null) win.clearTimeout(timer); timer = null; if (flight) flight.abort(); flight = null; }
        function schedule() { if (active() && !stopped) timer = win.setTimeout(poll, 30000); }
        function render(data) {
            var wasInitializing = initializing; initializing = data.has_inventory === false;
            $title.text(labels[data.state] || 'Состояние инвентаря');
            var p = data.progress || {}, total = Number(p.total), count = Number(p.completed);
            var hasTotal = Number.isFinite(total) && total > 0 && Number.isFinite(count);
            $progress.prop('hidden', data.state !== 'collecting' && !initializing);
            if (hasTotal) $progress.attr('value', Math.min(100, Math.max(0, 100 * count / total)));
            else $progress.removeAttr('value');
            var message = initializing ? 'Данные ещё не опубликованы.' : 'Последняя публикация: ' + date(data.published_at) + '.';
            if (hasTotal && data.state === 'collecting') message += ' Обработано ' + Math.max(0, count) + ' из ' + total + '.';
            if (data.stale && !initializing) message += ' Показаны ранее собранные данные.';
            if (data.worker_silent) message += ' Давно нет сигнала от фоновой службы; проверьте её состояние.';
            if (data.last_error) message += ' Ошибка: ' + (errors[data.last_error] || 'сбой фоновой службы') + '.';
            if (data.retry_at) message += ' Следующая попытка не раньше ' + date(data.retry_at) + '.';
            $detail.text(message + ' Открытие страницы не запускает сбор.');
            var metrics = data.telemetry || {};
            $metrics.text('Графики: ' + (metrics.last_success_at ? 'последние полученные измерения ' + date(metrics.last_success_at) :
                'ожидание независимого сбора') + '. Время каждой серии указано в карточке.');
            $('#phase5f34-cache-indicator').hide();
            $('#vmware-phase5e .phase5e-page-header > p.text-muted').text('Инвентарь и графики читаются из сохранённых данных. Синхронизация выполняется фоновой службой независимо от браузера.');
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
                    $title.text('Не удалось получить локальное состояние'); return;
                }
                render(data);
            }).fail(function (xhr, state) {
                if (request !== serial || state === 'abort') return;
                stopped = xhr.status === 401 || xhr.status === 403;
                $title.text(stopped ? 'Нет доступа к инвентарю' : 'Состояние синхронизации временно недоступно');
                $detail.text('Опрос vCenter из браузера не выполняется.');
            }).always(function () { if (request === serial) { flight = null; schedule(); } });
        }
        $retry.on('click', function () { stopped = false; abort(); poll(); });
        $('#phase5e-config').on('change.phase5g3', function () { abort(); stopped = false; connector = ''; poll(); });
        $('#vmware-phase5e').on('click.phase5g3', '.phase5e-vm-link, .phase5f-host-link, .phase5e-scope-link', function () { touched = true; });
        win.document.addEventListener('visibilitychange', function () { abort(); if (active()) poll(); });
        win.addEventListener('pagehide', function () { closed = true; abort(); });
        $title.text('Чтение состояния фоновой службы…'); poll();
    });
})(window, window.jQuery);
