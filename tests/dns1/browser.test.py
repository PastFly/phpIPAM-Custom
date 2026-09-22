#!/usr/bin/env python3
"""Offline Chromium component + real PHP service; mocked HTTPS transport and in-memory repository, no network."""
import json
import os
from pathlib import Path
import secrets
import subprocess
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
state = {}
key = secrets.token_hex(32)
count = 0
requests = []

def check(value, label):
    global count
    count += 1
    if not value:
        raise AssertionError(label)

def php(operation, **kwargs):
    global state
    payload = dict(operation=operation, state=state, test_key=key, **kwargs)
    p = subprocess.run(['php', str(ROOT / 'tests/dns1/browser-bridge.php')], input=json.dumps(payload), text=True, capture_output=True, timeout=10)
    if p.returncode or p.stderr:
        raise AssertionError('PHP browser fixture failed')
    decoded = json.loads(p.stdout)
    state = decoded['state']
    return decoded['result']

script = (ROOT / 'layers/dns1/app/admin/integrations/dns/config.js').read_text()
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
    page = browser.new_page(viewport={'width': 1365, 'height': 1000})
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('dialog', lambda dialog: dialog.accept())
    def dispatch(payload):
        requests.append(payload['input']['action'])
        check(payload['url'] == 'https://dns-fixture.invalid/action', 'fixed same-origin destination')
        check(payload['options']['method'] == 'POST' and payload['options']['redirect'] == 'error', 'POST without redirects')
        result = php('action', input=payload['input'], csrf_valid=payload['options']['headers'].get('X-Phpipam-Dns-Csrf') == 'test-csrf-only')
        return {'status': result['status'], 'body': result['body']}
    page.expose_function('fixtureDispatch', dispatch)
    def render():
        html = php('render')['html']
        page.set_content('<html><meta charset="utf-8"><title>DNS component fixture</title><body>' + html + '</body></html>')
        page.evaluate("""(source) => {
            window.fixtureSaved = false;
            const fixture = {
                location: {href: 'https://dns-fixture.invalid/', origin: 'https://dns-fixture.invalid'},
                confirm: () => true,
                fetch: async (url, options) => {
                    const result = await window.fixtureDispatch({url, options: {
                        method: options.method, redirect: options.redirect, headers: options.headers
                    }, input: JSON.parse(options.body)});
                    return {status: result.status, ok: result.status === 200, json: async () => result.body};
                }
            };
            new Function('window', source)(fixture);
            fixture.PhpIpamDnsConfig.mount(document.querySelector('#dns-configuration'), '/action', () => {window.fixtureSaved = true;});
        }""", script)
    render()
    def form(summary, action):
        item = page.locator('details').filter(has=page.locator('summary', has_text=summary)).first
        item.locator('summary').click()
        return item.locator(f'form[data-dns-action="{action}"]')
    def save(f):
        f.locator('button[type=submit]').click()
        page.wait_for_function('window.fixtureSaved === true')
        render()
    f = form('Новый профиль','save_profile')
    f.locator('[name=name]').fill('DNS read-only')
    f.locator('[name=username]').fill('svc@example.invalid')
    f.locator('[name=password]').fill('Fictional test password A')
    save(f)
    check(len(state['profiles']) == 1, 'browser creates profile through controller')
    pid = next(iter(state['profiles']))
    envelope = state['profiles'][pid]['secret_envelope']
    check('Fictional test password A' not in page.content(), 'password not rendered after save')
    f = form('DNS read-only','save_profile')
    check(f.locator('[name=password]').input_value() == '', 'saved password not populated')
    check(f.locator('[name=password_action]').input_value() == 'unchanged', 'default unchanged on edit')
    f.locator('[name=name]').fill('DNS renamed')
    save(f)
    check(state['profiles'][pid]['secret_envelope'] == envelope, 'metadata edit preserves encrypted credential')
    f = form('Новое подключение','save_connection')
    f.locator('[name=name]').fill('DNS source')
    f.locator('[name=server]').fill('dc01.example.invalid')
    f.locator('[name=profile_id]').select_option(pid)
    save(f)
    check(len(state['connections']) == 1, 'browser creates DNS connection')
    cid = next(iter(state['connections']))
    check(state['connections'][cid]['enabled'] is False, 'save never enables collection')
    f = form('DNS source','save_connection')
    check(f.get_by_role('button', name='Проверить доступ').is_disabled(), 'provider test explicitly unavailable')
    check(f.get_by_role('button', name='Включить сбор').is_disabled(), 'enable unavailable')
    render()
    f = form('DNS renamed','save_profile')
    f.locator('[name=password_action]').select_option('replace')
    f.locator('[name=password]').fill('Fictional test password B')
    save(f)
    check(state['profiles'][pid]['secret_version'] == 2, 'browser password replacement')
    check(state['connections'][cid]['binding_epoch'] == 2, 'replacement invalidates consumer')
    check('Fictional test password B' not in page.content(), 'replacement not in DOM')
    f = form('DNS source','save_connection')
    f.locator('[name=expected_revision]').evaluate('(e) => e.value = "1"')
    f.locator('[name=server]').fill('changed.example.invalid')
    f.locator('button[type=submit]').click()
    page.wait_for_function('document.querySelector("[data-dns-message]").textContent.includes("Данные изменились")')
    check(state['connections'][cid]['server'] == 'dc01.example.invalid', 'stale form rejected without overwrite')
    render()
    f = form('DNS renamed','save_profile')
    f.locator('[name=name]').fill('<img src=x onerror="window.dnsXss=1">')
    save(f)
    check(page.evaluate('window.dnsXss === undefined'), 'stored display name escaped')
    check(page.locator('img').count() == 0, 'no HTML interpreted from name')
    f = form('<img src=x','save_profile')
    f.get_by_role('button', name='Отозвать сохранённый пароль').click()
    page.wait_for_function('window.fixtureSaved === true')
    render()
    check(state['profiles'][pid]['secret_envelope'] is None, 'browser revoke removes stored secret')
    check(state['connections'][cid]['status'] == 'credential_revoked', 'browser revoke invalidates connection')
    check(not errors, 'no browser JavaScript errors')
    check(set(requests) <= {'save_profile','save_connection','revoke_profile'}, 'no provider/control/secret-read requests')
    if os.environ.get('DNS1_SCREENSHOT_PATH'):
        page.screenshot(path=os.environ['DNS1_SCREENSHOT_PATH'], full_page=True)
    browser.close()
print(f'DNS1_BROWSER_ASSERTIONS={count}')
print('DNS1_BROWSER_TESTS_OK=1')
