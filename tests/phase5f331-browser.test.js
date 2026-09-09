'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const jquery = require('jquery');
const root = path.resolve(__dirname, '..');
const searchPath = path.join(root, 'layers/phase5f331/app/tools/search/phase5f331-global-search.js');
const linkPath = path.join(root, 'layers/phase5f331/app/integrations/vmware/phase5f331-deeplink.js');
const api = require(searchPath), linkApi = require(linkPath);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const row = {vm_external_id: 'vm-20', name: 'VM test', search_blob: 'описание бухгалтерия\nvirtualization', mac_addresses: ['00:50:56:aa:7f:6b'], num_cpu: 4, memory_gib: 8, hardware_version: 'vmx-13', tags: [{name: 'Finance', category_name: 'Business'}]};
for (const query of ['БУХГАЛТЕРИЯ', 'Finance', 'business', 'virtualization', 'vmx-13', '4', '8', '00-50-56-aa-7f-6b', '0050.56aa.7f6b', '005056aa7f6b', 'опис*бух']) assert.equal(api.matches(row, query), true, query);
assert.equal(api.matches(row, '.*(a+)+$'), false);
assert.equal(api.matches({...row, vm_external_id: 'vm-20" onclick="bad'}, 'VM'), false);
assert.equal(api.matches(row, ''), false);
assert.equal(api.fragment(1, 'vm-20'), '#vmware-connector=1&vmware-vm=vm-20');
assert.equal(api.fragment('1&injection', 'vm-20'), null);
assert.deepEqual(linkApi.parse('#vmware-connector=1&vmware-vm=vm-20'), {connector: '1', vm: 'vm-20'});
assert.equal(linkApi.parse('#vmware-connector=0&vmware-vm=vm-20'), null);
assert.equal(linkApi.parse('#vmware-connector=1&vmware-vm=vm-20&extra=1'), null);

async function page(options = '') {
    const dom = new JSDOM('<form id="search"><input name="ip"></form><div class="searchResult">NATIVE RESULTS</div>' +
        '<section id="phase5f331-global-search" data-query="VM" data-csrf="fixture-only" data-workspace-url="/ipam/index.php?page=integrations&amp;section=vmware">' +
        '<select id="phase5f331-connector">' + (options || '<option value="1" data-connector="1">One</option>') + '</select>' +
        '<button id="phase5f331-retry"></button><div id="phase5f331-status"></div><div id="phase5f331-results"></div></section>',
        {url: 'https://fixture.invalid/ipam/index.php?page=tools&section=search', runScripts: 'outside-only'});
    const w = dom.window, $ = jquery(w), requests = [];
    w.jQuery = $;
    $.ajax = options => { const d = $.Deferred(); requests.push({options, d}); return d.promise(); };
    w.eval(fs.readFileSync(searchPath, 'utf8'));
    await sleep(25);
    return {dom, w, $, requests};
}
function response(rows, id = 1, tag = 'ok') { return {ok: true, connector_id: id, rows, tag_status: tag}; }
async function main() {
    let p = await page();
    assert.equal(p.requests.length, 1);
    assert.equal(p.requests[0].options.type, 'POST');
    assert.equal(p.requests[0].options.global, false);
    assert.deepEqual(Object.keys(p.requests[0].options.data).sort(), ['config_id', 'csrf_cookie']);
    p.requests[0].d.resolve(response([{...row, name: '<img src=x onerror=alert(1)> VM', primary_ip: '192.0.2.1'}]));
    assert.equal(p.$('.searchResult').text(), 'NATIVE RESULTS');
    assert.equal(p.$('#phase5f331-results img').length, 0);
    assert.match(p.$('#phase5f331-results a').attr('href'), /index\.php\?page=integrations&section=vmware#vmware-connector=1&vmware-vm=vm-20$/);
    p.$('#phase5f331-connector').trigger('change');
    assert.equal(p.requests.length, 1, 'cached index reused');
    p.dom.window.close();

    p = await page();
    const rows = Array.from({length: 120}, (_, i) => ({...row, vm_external_id: 'vm-' + (1000 + i), name: 'VM ' + String(i).padStart(3, '0')}));
    p.requests[0].d.resolve(response(rows));
    assert.equal(p.$('#phase5f331-results tbody tr').length, 50);
    p.$('#phase5f331-results button').last().trigger('click');
    assert.equal(p.$('#phase5f331-results tbody tr').first().text().includes('VM 050'), true);
    assert.equal(p.requests.length, 1, 'pagination is local');
    p.dom.window.close();

    p = await page('<option value="all">All</option><option value="1" data-connector="1">One</option><option value="2" data-connector="1">Two</option>');
    assert.equal(p.requests.length, 1, 'one concurrent connector');
    p.requests[0].d.reject();
    assert.equal(p.requests.length, 2, 'second connector continues after first error');
    p.requests[1].d.resolve(response([row], 2, 'authorization'));
    assert.match(p.$('#phase5f331-status').text(), /неполный/);
    assert.match(p.$('#phase5f331-status').text(), /One: подключение недоступно/);
    assert.match(p.$('#phase5f331-results a').attr('href'), /connector=2&vmware-vm=vm-20$/);
    assert.equal(p.$('.searchResult').text(), 'NATIVE RESULTS');
    p.dom.window.close();

    p = await page(); p.requests[0].d.resolve(response([row], 99));
    assert.equal(p.$('#phase5f331-results a').length, 0, 'wrong connector response rejected');
    p.dom.window.close();

    async function deeplink(cancel, hash = '#vmware-connector=1&vmware-vm=vm-20') {
        const dom = new JSDOM('<div id="vmware-phase5e"><select id="phase5e-config"><option value="1">One</option></select>' +
            '<input id="phase5e-search"><input id="phase5e-view" value="overview"><input id="phase5e-page"><input id="phase5e-scope"><input id="phase5e-scope-id">' +
            '<div id="phase5e-client-warning"></div><div id="phase5e-center-title"></div><div id="phase5e-list-result"></div>' +
            '<a class="phase5e-scope-link" href="#">Host</a></div>', {url: 'https://fixture.invalid/ipam/' + hash, runScripts: 'outside-only'});
        const w = dom.window, $ = jquery(w); w.jQuery = $;
        let clicks = 0;
        $(w.document).on('click', '.phase5e-vm-link', function(e) { e.preventDefault(); clicks++; });
        w.eval(fs.readFileSync(linkPath, 'utf8'));
        if (cancel) $('#phase5e-search').val('another VM').trigger('input');
        $('#phase5e-list-result').html('<a class="phase5e-vm-link" data-vm-id="vm-20" href="#">VM 20</a><a class="phase5e-vm-link" data-vm-id="vm-200" href="#">VM 200</a>');
        $(w.document).trigger('ajaxComplete', [{status: 200, responseJSON: {ok: true}}, {url: 'app/integrations/vmware/workspace-result.php', data: 'config_id=1&search=vm-20'}]);
        await sleep(15);
        const initialView = $('#phase5e-view').val();
        dom.window.close(); return {clicks, initialView};
    }
    assert.deepEqual(await deeplink(false), {clicks: 1, initialView: 'vms'});
    assert.equal((await deeplink(true)).clicks, 0, 'manual input cancels auto-open');
    assert.deepEqual(await deeplink(false, '#vmware-connector=99&vmware-vm=vm-20'), {clicks: 0, initialView: 'overview'});
    assert.equal((await deeplink(false, '#vmware-connector=1&vmware-vm=javascript:alert(1)')).clicks, 0);
    console.log('PHASE5F331_BROWSER_REGRESSION_OK');
}
main().catch(e => { console.error(e); process.exitCode = 1; });
