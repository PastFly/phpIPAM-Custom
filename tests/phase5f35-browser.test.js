'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom'),jquery=require('jquery');
const wait=ms=>new Promise(r=>setTimeout(r,ms));
const read=p=>fs.readFileSync(path.join(__dirname,'..',p),'utf8');
const legacy='app/integrations/vmware/vm-card-result.php';
function environment(enrichment=true){
 const dom=new JSDOM('<base href="https://ipam.test/sub/"><div id="vmware-phase5e"><form id="phase5e-workspace-form"><input name="csrf_cookie" value="good"><select id="phase5e-config"><option value="1">one</option></select></form><div class="phase5e-vm-row active"><a class="phase5e-vm-link" data-vm-id="vm-1">one</a></div><div id="phase5e-detail-result"></div></div>',{url:'https://ipam.test/sub/',runScripts:'outside-only'});
 const w=dom.window,$=jquery(w);w.$=w.jQuery=$;let aborts=0,completions=0;
 const requests=[],responses=[];
 w.eval(read('layers/phase5f34/app/integrations/vmware/phase5f34-cache.js'));
 w.eval(read('layers/phase5f24/app/integrations/vmware/phase5f24-stability.js'));
 if(enrichment){w.eval(read('layers/phase5f31/app/integrations/vmware/phase5f31-vm-network-path.js'));w.eval(read('layers/phase5f32/app/integrations/vmware/phase5f32-vm-guest-facts.js'));}
 w.eval(read('layers/phase5f35/app/integrations/vmware/phase5f35-vmcard.js'));
 $(w.document).ajaxComplete((event,xhr,settings)=>{if(settings.url===legacy)completions++;});
 $.ajaxTransport('+*',options=>{
  if(options.phase5f35Target&&!options.phase5f35Inner)return;
  return {send(headers,complete){requests.push(options);const primary=options.url.includes('phase5f35-vm-card-result.php');
   const response=primary?(responses.shift()||{}):{};
   const body=response.body|| (options.url.includes('vm-guest-facts-result.php')?'{}':'<div class="phase5e-detail-section"><code class="phase5e-id">vm-1</code>fixture</div>');
   this.timer=w.setTimeout(()=>complete(response.status||200,'OK',{text:body},'Content-Type: text/html\r\nServer-Timing: core;dur=1.25\r\n'+(response.busy?'X-Phpipam-VM-Context-Error: snapshot_refresh_busy\r\n':'')),response.delay||1);
  },abort(){aborts++;w.clearTimeout(this.timer);}};
 });
 function request(url=legacy,extra={}){return $.ajax({url,type:'POST',dataType:'html',data:{config_id:'1',vm_id:'vm-1',csrf_cookie:'good'},...extra});}
 return {dom,w,$,requests,responses,request,aborts:()=>aborts,completions:()=>completions};
}
const done=xhr=>new Promise((resolve,reject)=>xhr.done((data,status,x)=>resolve({data,x})).fail((x,s)=>reject(new Error(s))));
(async()=>{
 const e=environment();e.request().done(body=>e.$('#phase5e-detail-result').html(body));await wait(80);
 assert.match(e.requests[0].url,/\/sub\/app\/integrations\/vmware\/phase5f35-vm-card-result.php$/);
 assert.equal(e.completions(),1,'one completion using exact legacy URL');
 assert.ok(e.requests.some(o=>o.url==='app/integrations/vmware/vm-network-path-result.php'),'real network-path listener retained');
 assert.ok(e.requests.some(o=>o.url==='app/integrations/vmware/vm-guest-facts-result.php'),'real guest-facts listener retained');
 assert.equal(e.w.localStorage.length,0);e.dom.window.close();
 const f=environment(false);const result=await done(f.request());assert.match(result.x.getResponseHeader('Server-Timing'),/core;dur=1.25/);
 for(const url of ['https://other.test/sub/'+legacy,'https://ipam.test/other/'+legacy,'app/integrations/vmware/host-card-result.php']){await done(f.request(url));assert.equal(f.requests.at(-1).url,url,'unrelated URL untouched');}
 f.responses.push({status:503,busy:true},{});const before=f.requests.length;await done(f.request());assert.equal(f.requests.length-before,2,'bounded busy retry');
 const received=[];f.responses.push({delay:100,body:'OLD'},{body:'NEW'});
 f.request().done(x=>received.push(x));f.request().done(x=>received.push(x));await wait(150);
 assert.deepEqual(received,['NEW'],'late VM cannot overwrite newer selection');assert.ok(f.aborts()>0);
 f.responses.push({delay:1000});const pending=f.request();pending.abort();await wait(20);assert.ok(f.aborts()>1,'abort propagated');
 f.dom.window.close();console.log('PHASE5F35_LEGACY_COMPLETION_ROUTING_ABORT_OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
