'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../layers/dns1/app/admin/integrations/dns/config.js'), 'utf8');

async function click(action, error) {
    const listeners = {};
    const message = {textContent: ''};
    const form = {elements: {namedItem(name) { return name === 'password' ? null : {value: name === 'id' ? 'a'.repeat(32) : '1'}; }}};
    const button = {disabled: false, dataset: {dnsOperation: action}, closest() { return form; }};
    const root = {dataset: {csrf: 'fixture'}, contains() { return true; },
        querySelector() { return message; }, addEventListener(name, fn) { listeners[name] = fn; }};
    const window = {location: {href: 'https://example.invalid/', origin: 'https://example.invalid'},
        fetch: async () => ({status: 409, ok: false, json: async () => ({ok: false, error})})};
    vm.runInNewContext(source, {window, URL, Object, JSON, Error});
    window.PhpIpamDnsConfig.mount(root, '/action', () => {});
    listeners.click({target: {closest() { return button; }}, preventDefault() {}});
    await new Promise(resolve => setImmediate(resolve));
    return message.textContent;
}
(async () => {
    assert.match(await click('queue_test_connection', 'job_already_pending'), /уже.*очеред/i);
    assert.match(await click('queue_list_zones', 'job_already_pending'), /уже.*очеред/i);
    assert.match(await click('save_connection', 'revision_conflict'), /Данные изменились/);
    console.log('DNS1_PENDING_FEEDBACK_TESTS_OK=1');
})().catch(error => { console.error(error); process.exitCode = 1; });
