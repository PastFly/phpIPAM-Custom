<?php
/** Real DOM parsing with controlled SOAP transports. No production credentials, DB or vCenter. */
require_once __DIR__ . '/../layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterDirectTransport.php';
foreach (['Session', 'Inventory', 'Performance'] as $name) require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatch' . $name . '.php';
if (!class_exists('DOMDocument')) throw new RuntimeException('DOM_REQUIRED_NO_SKIP');
$n = 0;
function check(bool $ok, string $label): void { global $n; ++$n; if (!$ok) throw new RuntimeException('assert_' . $label); }
function reject(callable $f, string $code): void {
    try { $f(); } catch (Throwable $e) { check($e->getMessage() === $code, $code . '_got_' . $e->getMessage()); return; }
    throw new RuntimeException('missing_rejection_' . $code);
}
function env(string $body): string { return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><s:Body>' . $body . '</s:Body></s:Envelope>'; }
function reply(string $op, string $body): string { return env('<' . $op . 'Response xmlns="urn:vim25">' . $body . '</' . $op . 'Response>'); }
function field(string $name, string $value, string $attributes = ''): string { return '<propSet><name>' . $name . '</name><val' . $attributes . '>' . $value . '</val></propSet>'; }
function obj(string $id, string $type, string $fields): string { return '<returnval><obj type="' . $type . '">' . $id . '</obj>' . $fields . '</returnval>'; }
function fixture(array &$calls, ?callable $alter = null): callable {
    return static function ($method, $url, $headers, $body, $tls, $connect, $timeout) use (&$calls, $alter): array {
        check($method === 'POST' && $url === 'https://vc.example.test/sdk' && $tls === true && $connect <= $timeout && $timeout <= 20, 'scope_tls');
        $doc = new DOMDocument(); $doc->loadXML($body); $xp = new DOMXPath($doc);
        $operation = $xp->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*')->item(0)->localName;
        $calls[] = $operation;
        $authenticated = !in_array($operation, ['RetrieveServiceContent', 'Login'], true);
        check($authenticated === in_array('Cookie: vmware_soap_session="fixture-session"', $headers, true), 'cookie_scope');
        $payload = ''; $responseHeaders = [];
        if ($operation === 'RetrieveServiceContent') $payload = '<returnval><sessionManager type="SessionManager">SessionManager</sessionManager><propertyCollector type="PropertyCollector">propertyCollector</propertyCollector><perfManager type="PerformanceManager">PerfMgr</perfManager></returnval>';
        if ($operation === 'Login') { $payload = '<returnval/>'; $responseHeaders = ['Set-Cookie: vmware_soap_session="fixture-session"; Secure; HttpOnly']; }
        if ($operation === 'RetrieveProperties' && str_contains($body, '<pathSet>perfCounter</pathSet>')) {
            $counters = '';
            foreach (['cpu' => 71, 'mem' => 84, 'disk' => 99] as $group => $id) $counters .= '<PerfCounterInfo><key>' . $id . '</key><groupInfo><key>' . $group . '</key></groupInfo><nameInfo><key>usage</key></nameInfo><unitInfo><key>' . ($group === 'disk' ? 'kiloBytesPerSecond' : 'percent') . '</key></unitInfo><rollupType>average</rollupType></PerfCounterInfo>';
            $payload = obj('PerfMgr', 'PerformanceManager', field('perfCounter', $counters));
        } elseif ($operation === 'RetrieveProperties') {
            foreach ($xp->query('//*[local-name()="objectSet"]/*[local-name()="obj"]') as $object) {
                $id = $object->textContent; $type = $object->getAttribute('type'); $fields = field('name', 'Example &amp; &lt;name&gt;');
                if ($type === 'VirtualMachine') $fields .= field('runtime.powerState', 'poweredOn') . field('runtime.host', 'host-1', ' type="HostSystem"') . field('config.hardware.numCPU', '4') . field('config.hardware.memoryMB', '8192') . field('config.annotation', 'note') . field('guest.hostName', 'example.test') . field('guest.ipAddress', '192.0.2.8') . field('guest.toolsRunningStatus', 'guestToolsRunning');
                if ($type === 'HostSystem') $fields .= field('runtime.connectionState', 'connected') . field('runtime.inMaintenanceMode', 'false') . field('summary.quickStats.overallCpuUsage', '0') . field('summary.quickStats.overallMemoryUsage', '42');
                if ($type === 'Datastore') $fields .= field('summary.capacity', '1000000000000') . field('summary.freeSpace', '900000000000') . field('summary.accessible', 'true');
                $payload .= obj($id, $type, $fields);
            }
        }
        if ($operation === 'QueryPerfProviderSummary') $payload = '<returnval><currentSupported>true</currentSupported><summarySupported>true</summarySupported><refreshRate>20</refreshRate></returnval>';
        if ($operation === 'QueryAvailablePerfMetric') $payload = '<returnval><counterId>71</counterId><instance>0</instance></returnval><returnval><counterId>84</counterId><instance></instance></returnval>';
        if ($operation === 'QueryPerf') {
            foreach ($xp->query('//*[local-name()="querySpec"]') as $spec) {
                $entity = IntegrationVmwareBatchSession::one($spec, 'entity');
                $payload .= '<returnval xsi:type="PerfEntityMetric"><entity type="' . $entity->getAttribute('type') . '">' . $entity->textContent . '</entity>';
                foreach ([time() - 86420, time() - 86400] as $time) $payload .= '<sampleInfo><timestamp>' . gmdate('Y-m-d\TH:i:s\Z', $time) . '</timestamp><interval>20</interval></sampleInfo>';
                foreach (IntegrationVmwareBatchSession::children($spec, 'metricId') as $metric) {
                    $id = IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($metric, 'counterId'));
                    $payload .= '<value xsi:type="PerfMetricIntSeries"><id><counterId>' . $id . '</counterId><instance></instance></id><value>2500</value><value>-1</value></value>';
                }
                $payload .= '</returnval>';
            }
        }
        $out = ['status' => 200, 'headers' => $responseHeaders, 'body' => reply($operation, $payload)];
        return $alter === null ? $out : $alter($operation, $out, $body);
    };
}
function runtime(): array { return ['endpoint' => 'https://vc.example.test', 'username' => 'fixture-user', 'password' => 'NOT_A_REAL_PASSWORD', 'verify_tls' => true]; }
function session(array &$calls, ?callable $alter = null, int $limit = 128): IntegrationVmwareBatchSession {
    return new IntegrationVmwareBatchSession(runtime(), static fn() => true, fixture($calls, $alter), $limit);
}
function plans(int $count = 1): array {
    $out=[]; for ($i=1;$i <= $count;$i++) $out[]=['kind'=>'vm','id'=>'vm-'.$i,'interval_id'=>20,'counters'=>['cpu.usage.average','mem.usage.average','disk.usage.average']]; return $out;
}
$small = [['kind'=>'vm','id'=>'vm-1'],['kind'=>'host','id'=>'host-1'],['kind'=>'datastore','id'=>'datastore-1']];
$calls=[]; $s=session($calls);
$out=$s->run(static function($s) use($small) { return (new IntegrationVmwareBatchInventory($s))->read($small); });
check(count($out['objects'])===3 && $out['object_coverage_complete'] && $out['all_requested_fields_available'] && !$out['authoritative_membership'], 'mixed_inventory');
check($out['objects']['vm-1']['values']['config.hardware.numCPU']===4, 'integer_cpu');
check($out['objects']['vm-1']['values']['name']==='Example & <name>', 'literal_text');
check($out['objects']['vm-1']['source_observed_at']===null, 'no_fake_event_time');
check($calls===['RetrieveServiceContent','Login','RetrieveProperties','Logout'], 'one_session');
reject(static fn()=>$s->run(static fn()=>[]),'batch_session_single_use');
reject(static fn()=>$s->read('QueryPerf',''),'batch_read_scope');

$calls=[];$s=session($calls);$out=$s->run(static function($s){$p=new IntegrationVmwareBatchPerformance($s);$a=$p->definitions();$b=$p->definitions();check($a===$b,'same_registry');return $p->read(plans(2));});
check(count($out['objects'])===2 && $out['objects']['vm-1']['state']==='returned','metric_entities');
check($out['objects']['vm-1']['samples'][0]['values']['cpu.usage.average']===25.0,'percent_scale');
check($out['objects']['vm-1']['samples'][0]['values']['disk.usage.average']===2500,'disk_unit');
check($out['objects']['vm-1']['samples'][1]['values']['cpu.usage.average']===null,'negative_unknown');
check($out['objects']['vm-1']['observed_at'] < time()-80000,'old_series_not_freshened');
check($calls===['RetrieveServiceContent','Login','RetrieveProperties','QueryPerf','Logout'],'cached_definitions_combined_metrics');

$calls=[];$s=session($calls);$out=$s->run(static function($s){$p=new IntegrationVmwareBatchPerformance($s);return [$p->provider('vm','vm-1'),$p->availability('vm','vm-1',20)];});
check($out[0]['current_supported'] && $out[0]['interval_id']===20,'provider_rate');
check(!$out[1]['aggregate_available']['cpu.usage.average'] && $out[1]['aggregate_available']['mem.usage.average'],'core_instance_not_aggregate');

// Unknown/missing properties and objects remain distinct from proven absence or deletion.
foreach (['object','field','fault','nil','usage'] as $case) {
    $calls=[];$s=session($calls,static function($op,$r)use($case){
        if($op!=='RetrieveProperties')return$r;
        if($case==='object')$r['body']=reply($op,'');
        if($case==='field')$r['body']=str_replace(field('config.annotation','note'),'',$r['body']);
        if($case==='fault')$r['body']=str_replace(field('config.annotation','note'),'<missingSet><path>config.annotation</path><fault><fault xsi:type="InvalidProperty"/></fault></missingSet>',$r['body']);
        if($case==='nil')$r['body']=str_replace(field('runtime.host','host-1',' type="HostSystem"'),field('runtime.host','',' xsi:nil="true"'),$r['body']);
        if($case==='usage')$r['body']=str_replace(field('summary.quickStats.overallCpuUsage','0'),field('summary.quickStats.overallCpuUsage','-1'),$r['body']);
        return$r;
    });
    $out=$s->run(static fn($s)=>(new IntegrationVmwareBatchInventory($s))->read($small));
    check(!$out['all_requested_fields_available'],'partial_field_'.$case);
    if($case==='object')check(!$out['object_coverage_complete']&&$out['objects']['vm-1']['state']==='unobserved','no_delete');
    if($case==='fault')check($out['objects']['vm-1']['property_states']['config.annotation']==='property_fault','missing_fault');
}
// The returned QueryPerf roster can be sparse or contain only one supported counterpart.
foreach (['entity','cpu','empty'] as $case) {
    $calls=[];$s=session($calls,static function($op,$r)use($case){
        if($op==='QueryPerf') {
            if($case==='entity')$r['body']=reply($op,'');
            if($case==='cpu')$r['body']=preg_replace('/<value xsi:type="PerfMetricIntSeries"><id><counterId>71<\/counterId>.*?<\/id><value>.*?<\/value><value>.*?<\/value><\/value>/s','',$r['body']);
            if($case==='empty')$r['body']=reply($op,'<returnval xsi:type="PerfEntityMetric"><entity type="VirtualMachine">vm-1</entity></returnval>');
        }return$r;
    });
    $out=$s->run(static fn($s)=>(new IntegrationVmwareBatchPerformance($s))->read(plans()));
    check(!$out['capability_inferred_from_missing_samples'] && $out['objects']['vm-1']['state']!== 'returned','not_unsupported_'.$case);
    if($case==='cpu')check($out['objects']['vm-1']['samples'][0]['values']['cpu.usage.average']===null&&$out['objects']['vm-1']['samples'][0]['values']['mem.usage.average']===25.0,'partial_preserves_ram');
}

$badResponses=[
 'foreign_object'=>['RetrieveProperties','batch_unexpected_or_duplicate_object',static fn($r)=>str_replace('vm-1','vm-999',$r)],
 'duplicate_object'=>['RetrieveProperties','batch_unexpected_or_duplicate_object',static fn($r)=>str_replace('</RetrievePropertiesResponse>',obj('vm-1','VirtualMachine','').'</RetrievePropertiesResponse>',$r)],
 'duplicate_property'=>['RetrieveProperties','batch_property_scope',static fn($r)=>str_replace('</RetrievePropertiesResponse>', '</RetrievePropertiesResponse>',str_replace(field('config.annotation','note'),field('config.annotation','note').field('config.annotation','note'),$r))],
 'wrong_type'=>['RetrieveProperties','batch_unexpected_or_duplicate_object',static fn($r)=>str_replace('type="VirtualMachine"','type="HostSystem"',$r)],
 'bad_integer'=>['RetrieveProperties','batch_integer_invalid',static fn($r)=>str_replace('<val>4</val>','<val>bogus</val>',$r)],
 'unknown_property'=>['RetrieveProperties','batch_property_scope',static fn($r)=>str_replace('config.annotation','config.extraConfig',$r)],
 'unsafe_xml'=>['RetrieveProperties','batch_xml_unsafe',static fn($r)=>'<!DOCTYPE x []>'.$r],
 'wrong_operation'=>['RetrieveProperties','batch_operation_invalid',static fn($r)=>reply('Wrong','')],
 'two_operations'=>['RetrieveProperties','batch_operation_invalid',static fn($r)=>str_replace('</s:Body>','<QueryPerfResponse xmlns="urn:vim25"/></s:Body>',$r)],
 'missing_set_denial'=>['RetrieveProperties','batch_access_denied',static fn($r)=>str_replace(field('config.annotation','note'),'<missingSet><path>config.annotation</path><fault><fault xsi:type="NoPermission"/></fault></missingSet>',$r)],
 'wrong_unit'=>['RetrieveProperties','batch_counter_unit_invalid',static fn($r)=>str_replace('<key>percent</key>','<key>megaHertz</key>',$r)],
 'duplicate_counter'=>['RetrieveProperties','batch_counter_duplicate',static fn($r)=>str_replace('<key>84</key>','<key>71</key>',$r)],
 'query_foreign_entity'=>['QueryPerf','batch_metric_entity_invalid',static fn($r)=>str_replace('vm-1','vm-999',$r)],
 'query_device_instance'=>['QueryPerf','batch_metric_scope',static fn($r)=>str_replace('<instance></instance>','<instance>0</instance>',$r)],
 'query_wrong_counter'=>['QueryPerf','batch_metric_scope',static fn($r)=>str_replace('<counterId>71</counterId>','<counterId>999</counterId>',$r)],
 'query_bad_value'=>['QueryPerf','batch_integer_invalid',static fn($r)=>str_replace('<value>2500</value>','<value>nan</value>',$r)],
 'query_short_values'=>['QueryPerf','batch_sample_alignment',static fn($r)=>str_replace('<value>-1</value>','',$r)],
 'query_future_time'=>['QueryPerf','batch_sample_time_invalid',static fn($r)=>preg_replace('/<timestamp>.*?<\/timestamp>/','<timestamp>2999-01-01T00:00:00Z</timestamp>',$r)],
 'query_duplicate_time'=>['QueryPerf','batch_sample_time_invalid',static fn($r)=>preg_replace('/<timestamp>.*?<\/timestamp>/','<timestamp>2020-01-01T00:00:00Z</timestamp>',$r)],
];
foreach($badResponses as $name=>[$op,$code,$alter]){
    $calls=[];$s=session($calls,static function($method,$r)use($op,$alter){if($method===$op)$r['body']=$alter($r['body']);return$r;});
    $perf=str_starts_with($name,'query_')||in_array($name,['wrong_unit','duplicate_counter'],true);
    reject(static fn()=>$s->run(static fn($s)=>$perf?(new IntegrationVmwareBatchPerformance($s))->read(plans()):(new IntegrationVmwareBatchInventory($s))->read([['kind'=>'vm','id'=>'vm-1']])),$code);
    check(end($calls)==='Logout','cleanup_'.$name);
}
foreach ([401,403,500] as $status) {
    $calls=[];$s=session($calls,static function($op,$r)use($status){if($op==='RetrieveProperties'){$r['status']=$status;$r['body']=env('<s:Fault><detail><NoPermissionFault xmlns="urn:vim25"/></detail></s:Fault>');}return$r;});
    reject(static fn()=>$s->run(static fn($s)=>(new IntegrationVmwareBatchInventory($s))->read([['kind'=>'vm','id'=>'vm-1']])),'batch_access_denied');
}
// Cleanup failure cannot silently deliver data with revoked access.
$calls=[];$s=session($calls,static function($op,$r){if($op==='Logout')$r['status']=403;return$r;});
reject(static fn()=>$s->run(static fn($s)=>(new IntegrationVmwareBatchInventory($s))->read([['kind'=>'vm','id'=>'vm-1']])),'batch_access_denied');
$allowed=true;$calls=[];$transport=fixture($calls,static function($op,$r)use(&$allowed){if($op==='RetrieveProperties')$allowed=false;return$r;});
$s=new IntegrationVmwareBatchSession(runtime(),static function()use(&$allowed){return$allowed;},$transport);
reject(static fn()=>$s->run(static fn($s)=>(new IntegrationVmwareBatchInventory($s))->read([['kind'=>'vm','id'=>'vm-1']])),'batch_binding_changed');
$calls=[];$s=session($calls,null,4);
reject(static fn()=>$s->run(static fn($s)=>(new IntegrationVmwareBatchInventory($s,1))->read($small)),'batch_budget_exhausted');
check(count($calls)===4&&end($calls)==='Logout','reserved_cleanup_budget');
$calls=[];$s=session($calls);reject(static fn()=>$s->run(static fn($s)=>$s->read('PowerOnVM_Task','')),'batch_read_scope');
check(!in_array('PowerOnVM_Task',$calls,true),'no_mutation');
reject(static fn()=>new IntegrationVmwareBatchSession(array_replace(runtime(),['verify_tls'=>false]),static fn()=>true),'batch_configuration_invalid');
foreach ([[],[['kind'=>'vm','id'=>'host-1']],[['kind'=>'vm','id'=>'vm-1'],['kind'=>'vm','id'=>'vm-1']]] as $bad) {
    $code=$bad===[]?'batch_roster_invalid':(count($bad)===2?'batch_entity_duplicate':'batch_entity_invalid');
    reject(static fn()=>IntegrationVmwareBatchInventory::roster($bad),$code);
}
$calls=[];$s=session($calls);$bad=plans();unset($bad[0]['interval_id']);
reject(static fn()=>$s->run(static fn($s)=>(new IntegrationVmwareBatchPerformance($s))->read($bad)),'batch_interval_required');
$calls=[];$s=session($calls);$bad=plans();$bad[0]['counters']=['arbitrary.counter.average'];
reject(static fn()=>$s->run(static fn($s)=>(new IntegrationVmwareBatchPerformance($s))->read($bad)),'batch_counters_invalid');

// Actual parser/transport call count at the Owner's scale; NOT a live latency benchmark.
$roster=[];$queries=[];
foreach(['vm'=>645,'host'=>61,'datastore'=>70]as$kind=>$count)for($i=1;$i<=$count;$i++){
    $roster[]=['kind'=>$kind,'id'=>$kind.'-'.$i];
    if($kind!=='datastore')$queries[]=['kind'=>$kind,'id'=>$kind.'-'.$i,'interval_id'=>20,'counters'=>['cpu.usage.average','mem.usage.average','disk.usage.average']];
}
$calls=[];$s=session($calls);$out=$s->run(static function($s)use($roster,$queries){return[(new IntegrationVmwareBatchInventory($s))->read($roster),(new IntegrationVmwareBatchPerformance($s))->read($queries)];});
check(count($out[0]['objects'])===776&&count($out[1]['objects'])===706,'full_scale');
$stats=$s->statistics();check($stats['operations']['Login']===1&&$stats['operations']['Logout']===1,'scale_one_session');
check($stats['operations']['RetrieveProperties']===17&&$stats['operations']['QueryPerf']===29&&$stats['requests']===49,'bounded_scale_requests');
check(!str_contains(json_encode($out),'fixture-session')&&!str_contains(json_encode($out),'NOT_A_REAL_PASSWORD'),'no_credentials_in_result');
echo 'PHASE5H_BATCH_ASSERTIONS='.$n."\n";
echo 'PHASE5H_SCALE='.json_encode(['inventory_objects'=>776,'metric_objects'=>706,'property_batches'=>16,'counter_registry_reads'=>1,'metric_batches'=>29,'requests_including_session'=>49,'live_benchmark'=>false])."\n";
echo "PHASE5H_BATCH_SOAP_FIXTURES_OK\n";
