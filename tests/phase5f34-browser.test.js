'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom'),jquery=require('jquery');
const source=fs.readFileSync(path.join(__dirname,'../layers/phase5f34/app/integrations/vmware/phase5f34-cache.js'),'utf8');
const wait=ms=>new Promise(r=>setTimeout(r,ms));
function env(header=false){
 const body=header?'<section id="phase5f331-global-search"><div class="panel-heading">VMware</div><select id="phase5f331-connector"><option value="1">one</option></select><button id="phase5f331-retry">Retry</button></section>':'<div id="vmware-phase5e"><div class="phase5e-page-header"><p class="text-muted">inventory существует только в памяти текущего запроса.</p></div></div><form id="phase5e-workspace-form"><select id="phase5e-config"><option value="1">one</option><option value="2">two</option></select><button type="button" id="phase5e-refresh">Refresh</button></form>';
 const d=new JSDOM('<base href="https://ipam.test/sub/">'+body+'<div id="phase5e-list-result">selected list</div><div id="phase5e-detail-result">selected VM</div>',{url:'https://ipam.test/sub/',runScripts:'outside-only'});
 const w=d.window,$=jquery(w);w.$=w.jQuery=$;let visible='visible';Object.defineProperty(w.document,'visibilityState',{get:()=>visible});
 let tick;w.setInterval=f=>{tick=f;return 99;};w.clearInterval=()=>{};
 let now=1000000;w.Date.now=()=>now;
 const requests=[],responses=[];let aborts=0;
 w.eval(source);
 $.ajaxTransport('+*',o=>{
   if(o.phase5f34Managed&&!o.phase5f34Inner)return;
   return {send(headers,complete){requests.push(o);const item=responses.shift()||{};
     this.timer=w.setTimeout(()=>complete(item.status||200,item.status===503?'busy':'OK',{text:JSON.stringify(item.body||{ok:true,connector_id:1,cache_enabled:true,cache:{domain:'core',state:'fresh',age_seconds:1,soft_ttl_seconds:60,refresh_required:false,retry_after_seconds:0}})},'Content-Type: application/json\r\n'),item.delay||1);
   },abort(){aborts++;w.clearTimeout(this.timer);}};
 });
 function request(url='app/integrations/vmware/workspace-result.php',data={config_id:'1',csrf_cookie:'fixture'}){return $.ajax({url,type:'POST',dataType:'json',data});}
 return {w,$,d,requests,responses,request,tick:()=>tick(),advance:n=>{now+=n;},hide:()=>{visible='hidden';w.document.dispatchEvent(new w.Event('visibilitychange'));},aborts:()=>aborts};
}
function promise(xhr){return new Promise((resolve,reject)=>xhr.done(resolve).fail((x,s)=>reject(new Error(s))));}
(async()=>{
 const e=env();let completes=0;e.$(e.w.document).ajaxComplete(()=>completes++);
 await promise(e.request());assert.match(e.requests[0].url,/\/sub\/app\/integrations\/vmware\/cache\/workspace-result.php$/);
 assert.equal(completes,1);assert.equal(e.$('#phase5e-list-result').text(),'selected list');assert.equal(e.w.localStorage.length,0);
 assert.match(e.$('#phase5f34-cache-indicator').text(),/готов/);
 assert.match(e.$('.phase5e-page-header > p').text(),/зашифрованные снимки на сервере/);
 assert.doesNotMatch(e.$('.phase5e-page-header > p').text(),/только в памяти/);
 await promise(e.request('app/integrations/vmware/vm-card-result.php'));assert.doesNotMatch(e.requests.at(-1).url,/\/cache\//);
 await promise(e.request('https://other.test/app/integrations/vmware/workspace-result.php'));assert.doesNotMatch(e.requests.at(-1).url,/\/cache\//);
 await promise(e.request('https://ipam.test/other/app/integrations/vmware/workspace-result.php'));assert.doesNotMatch(e.requests.at(-1).url,/\/cache\//);
 e.responses.push({status:503,body:{ok:false,error:'snapshot_refresh_busy'}},{status:503,body:{ok:false,error:'snapshot_refresh_busy'}},{});
 const count=e.requests.length;await promise(e.request());assert.equal(e.requests.length-count,3,'bounded cold retry');
 e.advance(61000);e.tick();await wait(20);assert.match(e.requests.at(-1).url,/cache\/refresh.php/);assert.equal(e.$('#phase5e-detail-result').text(),'selected VM');
 const before=e.requests.length;e.hide();e.advance(61000);e.tick();await wait(10);assert.equal(e.requests.length,before,'hidden page no refresh');
 e.responses.push({delay:1000});const pending=e.request();pending.abort();await wait(10);assert.ok(e.aborts()>0,'abort propagated');
 e.d.window.close();
 const h=env(true);await promise(h.request('app/tools/search/vmware-global-result.php'));assert.match(h.requests[0].url,/tools\/search\/cache\/vmware-global-result.php/);assert.equal(h.$('#phase5f34-cache-indicator').length,1);
 h.$('#phase5f331-retry')[0].dispatchEvent(new h.w.MouseEvent('click',{bubbles:true}));await promise(h.request('app/tools/search/vmware-global-result.php'));assert.match(h.requests.at(-1).data,/force_refresh=1/);
 h.d.window.close();console.log('PHASE5F34_BROWSER_ROUTING_REFRESH_ABORT_OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
