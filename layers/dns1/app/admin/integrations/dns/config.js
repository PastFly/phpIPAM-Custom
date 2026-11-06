/* Source component only: the authenticated phpIPAM host page explicitly calls mount(). */
(function (global) {
    'use strict';
    function mount(root, endpoint, afterSave) {
        if (!root || root.dataset.dnsMounted) return;
        var url = new URL(endpoint, global.location.href);
        if (url.origin !== global.location.origin || url.protocol !== 'https:' || url.username || url.password) {
            throw new Error('dns_same_origin_https_required');
        }
        root.dataset.dnsMounted = '1';
        function read(form, name) { return form.elements.namedItem(name).value; }
        function base(form) { return {id: read(form, 'id') || null, expected_revision: Number(read(form, 'expected_revision'))}; }
        async function send(form, action) {
            if (root.dataset.dnsBusy) return;
            var data = base(form), message = root.querySelector('[data-dns-message]');
            var passwordInput = form.elements.namedItem('password');
            try {
                if (action === 'save_profile') {
                    Object.assign(data, {name: read(form, 'name'), username: read(form, 'username'),
                        password_action: read(form, 'password_action'), password: read(form, 'password'),
                        acknowledge_connections: JSON.parse(read(form, 'acknowledge_connections'))});
                    if (data.password_action === 'replace' && data.acknowledge_connections.length &&
                        !global.confirm('Заменить сохранённый пароль и потребовать повторную проверку всех указанных подключений?')) return;
                } else if (action === 'save_connection') {
                    var mode = read(form, 'credential_mode');
                    Object.assign(data, {name: read(form, 'name'), server: read(form, 'server'),
                        interval_seconds: Number(read(form, 'interval_seconds')), credential_mode: mode,
                        profile_id: mode === 'profile' ? read(form, 'profile_id') || null : null,
                        share_profile: form.elements.namedItem('share_profile').checked});
                } else if (action === 'register_collector') {
                    data = {name: read(form, 'name')};
                } else if (action === 'save_assignment') {
                    var collectorId = read(form, 'collector_assignment');
                    if (!collectorId) { message.textContent = 'Выберите Windows-сборщик.'; return; }
                    data = {connection_id: read(form, 'id'), collector_id: collectorId,
                        expected_revision: Number(read(form, 'assignment_revision'))};
                } else if (action === 'revoke_collector') {
                    if (!global.confirm('Отозвать этот Windows-сборщик? Его активные задания станут недействительными.')) return;
                    data = {id: read(form, 'id'), expected_revision: Number(read(form, 'expected_revision'))};
                } else if (action === 'queue_test_connection' || action === 'queue_list_zones') {
                    data = {id: read(form, 'id')};
                } else if (action === 'set_collection_enabled') {
                    var button = form.__dnsClickedButton;
                    var enable = button && button.dataset.dnsEnabled === '1';
                    if (!global.confirm(enable ? 'Включить автоматический сбор для этого подключения?' : 'Отключить автоматический сбор для этого подключения?')) return;
                    data = {id: read(form, 'id'), expected_revision: Number(read(form, 'expected_revision')), enabled: enable};
                } else {
                    if (!['revoke_profile', 'delete_profile', 'delete_connection'].includes(action)) throw new Error();
                    if (!global.confirm('Подтвердить действие с указанным профилем или подключением?')) return;
                    if (action === 'revoke_profile') data.acknowledge_connections = JSON.parse(read(form, 'acknowledge_connections'));
                }
                root.dataset.dnsBusy = '1';
                message.textContent = 'Сохранение…';
                var body = JSON.stringify({action: action, data: data});
                if (passwordInput) passwordInput.value = '';
                delete data.password;
                var response = await global.fetch(url.href, {method: 'POST', credentials: 'same-origin',
                    redirect: 'error', cache: 'no-store', headers: {'Content-Type': 'application/json',
                        'X-Phpipam-Dns-Csrf': root.dataset.csrf}, body: body});
                body = null;
                var result = await response.json();
                if (response.status === 409) {
                    message.textContent = result && result.error === 'job_already_pending'
                        ? 'Такое задание уже находится в очереди или выполняется. Повторно запускать его не нужно.'
                        : 'Данные изменились или затронуты другие подключения. Обновите страницу и повторите проверку.';
                    return;
                }
                if (!response.ok || !result || result.ok !== true) { message.textContent = 'Не удалось подтвердить сохранение. Проверьте поля, права и состояние хранилища; при сомнении обновите страницу.'; return; }
                if (action === 'register_collector') {
                    var token = result.data && result.data.control_token;
                    var enrollment = root.querySelector('[data-dns-enrollment]');
                    var tokenBox = root.querySelector('[data-dns-enrollment-token]');
                    if (typeof token !== 'string' || !token || !enrollment || !tokenBox) throw new Error();
                    tokenBox.textContent = token;
                    enrollment.hidden = false;
                    message.textContent = 'Сборщик создан. Control token показан один раз — используйте его для первичной установки.';
                    return;
                }
                if (action === 'queue_test_connection') message.textContent = 'Проверка доступа поставлена в очередь.';
                else if (action === 'queue_list_zones') message.textContent = 'Получение зон поставлено в очередь.';
                else if (action === 'save_assignment') message.textContent = 'Windows-сборщик назначен.';
                else if (action === 'revoke_collector') message.textContent = 'Windows-сборщик отозван.';
                else if (action === 'set_collection_enabled') message.textContent = data.enabled ? 'Автоматический сбор включён.' : 'Автоматический сбор отключён.';
                else message.textContent = 'Сохранено.';
                if (typeof afterSave === 'function') afterSave(result.data); else global.location.reload();
            } catch (_) { message.textContent = 'Ответ не получен. Обновите страницу для проверки результата; пароль повторно не отправлялся.'; }
            finally { if (passwordInput) passwordInput.value = ''; delete root.dataset.dnsBusy; }
        }
        root.addEventListener('submit', function (event) {
            var form = event.target.closest('form[data-dns-action]');
            if (form && root.contains(form)) { event.preventDefault(); send(form, form.dataset.dnsAction); }
        });
        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-dns-operation]');
            if (button && root.contains(button) && !button.disabled) {
                event.preventDefault();
                var form = button.closest('form');
                form.__dnsClickedButton = button;
                send(form, button.dataset.dnsOperation).finally(function () { delete form.__dnsClickedButton; });
            }
        });
    }
    global.PhpIpamDnsConfig = Object.freeze({mount: mount});
})(window);
