'use strict';
// The responses are produced by the real PHP native renderers and encrypted-reader fixture.
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {JSDOM} = require('jsdom'), jquery = require('jquery');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const root = path.join(__dirname, '..');
const dom = new JSDOM('<base href="https://ipam.test/sub/"><div id="vmware-phase5e"><form id="phase5e-workspace-form"><input name="csrf_cookie" value="token"><select id="phase5e-config"><option value="1">one</option></select></form><div class="phase5e-vm-row active"><a class="phase5e-vm-link" data-vm-id="vm-1">one</a></div><div id="phase5e-detail-result"></div></div>', {url: 'https://ipam.test/sub/', runScripts: 'outside-only'});
const w = dom.window, $ = jquery(w); w.$ = w.jQuery = $;
let primaryDelay = 1, aborts = 0, completions = 0;
const seen = [];
for (const file of ['layers/phase5f24/app/integrations/vmware/phase5f24-stability.js',
    'layers/phase5f31/app/integrations/vmware/phase5f31-vm-network-path.js',
    'layers/phase5f32/app/integrations/vmware/phase5f32-vm-guest-facts.js']) w.eval(fs.readFileSync(path.join(root, file), 'utf8'));
const routes = {'vm-card-result.php': 'vm', 'vm-network-path-result.php': 'network_path', 'vm-guest-facts-result.php': 'guest_facts'};
$.ajaxTransport('+*', options => ({
    send(headers, done) {
        const key = routes[options.url.split('/').pop()];
        assert.ok(key, 'unexpected request: ' + options.url); seen.push(options.url);
        const reply = fixture[key];
        this.timer = w.setTimeout(() => done(200, 'OK', {text: reply.format === 'json' ? JSON.stringify(reply.body) : reply.body},
            'Content-Type: ' + (reply.format === 'json' ? 'application/json' : 'text/html') + '\r\nX-Phpipam-Inventory-Vcenter-Requests: 0\r\n'), key === 'vm' ? primaryDelay : 1);
    }, abort() { aborts++; w.clearTimeout(this.timer); }
}));
$(w.document).ajaxComplete((event, xhr, options) => { if (options.url === 'app/integrations/vmware/vm-card-result.php') completions++; });
function card() { return $.ajax({url: 'app/integrations/vmware/vm-card-result.php', type: 'POST', data: {config_id: 1, vm_id: 'vm-1', csrf_cookie: 'token'}}); }
const wait = ms => new Promise(resolve => w.setTimeout(resolve, ms));
(async () => {
    card().done(body => $('#phase5e-detail-result').html(body)); await wait(150);
    assert.equal($('#phase5e-detail-result code.phase5e-id').first().text(), 'vm-1');
    assert.equal($('#phase5e-detail-result script').length, 0);
    assert.ok($('#phase5e-detail-result').text().includes('Debian Friendly'));
    assert.equal($('#phase5e-detail-result .phase5f31-vm-network-path').length, 1);
    assert.ok(seen.some(url => url.endsWith('vm-guest-facts-result.php')));
    assert.equal(completions, 1, 'legacy completion contract preserved');
    assert.equal(w.localStorage.length, 0, 'no persistent browser inventory');
    primaryDelay = 120; const received = []; card().done(() => received.push('old'));
    primaryDelay = 1; card().done(() => received.push('new')); await wait(180);
    assert.deepEqual(received, ['new']); assert.ok(aborts >= 1);
    console.log('PHASE5G2_ACTUAL_NATIVE_HTML_ENRICHMENT_ABORT_OK'); dom.window.close();
})().catch(error => { console.error(error); dom.window.close(); process.exitCode = 1; });
