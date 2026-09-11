<?php
$root = dirname(__DIR__);
foreach (['Store', 'Reader', 'Worker'] as $name) require_once "$root/layers/phase5g/functions/classes/class.IntegrationVmwareInventory$name.php";
foreach (['TelemetryStore', 'Sampler'] as $name) require_once "$root/layers/phase5g3/functions/classes/class.IntegrationVmwareInventory$name.php";
$n = 0;
function ok($condition, string $label): void { global $n; if (!$condition) throw new RuntimeException($label); $n++; }
function fails(callable $f, string $text): void { try { $f(); } catch (Throwable $e) { ok(str_contains($e->getMessage(), $text), $text . ': ' . $e->getMessage()); return; } throw new RuntimeException('expected ' . $text); }
function wipe(string $p): void { if (is_dir($p) && !is_link($p)) { foreach (array_diff(scandir($p), ['.', '..']) as $x) wipe($p.'/'.$x); rmdir($p); } elseif (file_exists($p) || is_link($p)) unlink($p); }
class MetricFixture implements IntegrationVmwareInventoryMetricProvider {
    public array $calls = []; public ?string $fault = null; public $during = null;
    public function sample(string $kind, string $id, string $section): array {
        $this->calls[] = [$kind, $id, $section];
        if ($this->during) ($this->during)();
        if ($this->fault) throw new RuntimeException($this->fault);
        return ['entity_type'=>$kind, 'entity_external_id'=>$id, 'verify_tls'=>true, 'samples'=>[
            ['timestamp'=>'2026-09-11T00:00:00Z','cpu_percent'=>12.5,'memory_percent'=>25],
            ['timestamp'=>'2026-09-11T00:00:20Z','cpu_percent'=>13,'memory_percent'=>26]],
            'password'=>'NEVER-PERSIST','headers'=>['NEVER-PERSIST']];
    }
}
$dir = sys_get_temp_dir() . '/metric-test-' . bin2hex(random_bytes(5)); mkdir($dir,0700); mkdir($dir.'/telemetry',0700);
try {
    $key=random_bytes(32); $binding=str_repeat('c',64); $now=1789084800; $allow=true;
    $clock=function()use(&$now):int{return $now;}; $guard=function()use(&$allow):bool{return $allow;};
    $store=new IntegrationVmwareInventoryStore($dir,$key,$binding);
    $telemetry=new IntegrationVmwareInventoryTelemetryStore($dir.'/telemetry',$key,$binding);
    $provider=new MetricFixture(); $sampler=new IntegrationVmwareInventorySampler($store,$telemetry,$provider,$guard,$clock);
    $reader=new IntegrationVmwareInventoryReader($store,$guard,$clock,$telemetry);
    $before=scandir($dir.'/telemetry');
    ok($reader->query('status')['state']==='initializing','initial state');
    ok(scandir($dir.'/telemetry')===$before,'empty read creates no metric files');
    ok($sampler->tick()['state']==='waiting_inventory','sampling waits for complete inventory');
    ok($provider->calls===[],'no discovery or metric reads from initial state');
    $current=['state'=>'ready','blocked'=>false,'started_at'=>$now,'published_at'=>$now,'heartbeat_at'=>$now,
        'generation'=>'generation-one','inventory'=>['objects'=>['vm'=>['vm-1'=>['collected_at'=>$now,'data'=>['name'=>'one','sections'=>[]]],
            'vm-2'=>['collected_at'=>$now,'data'=>['name'=>'two','sections'=>[]]]],
            'host'=>['host-3'=>['collected_at'=>$now,'data'=>['name'=>'three','sections'=>[]]]],'datastore'=>[]],
            'search'=>['collected_at'=>$now,'data'=>['rows'=>[]]]]];
    $store->exclusive(function()use($store,$current){$store->write('current',$current);});
    $inventoryHash=hash_file('sha256',$dir.'/current.enc');
    ok($sampler->tick(1)['work_done']===1,'bounded sample unit');
    ok(count($provider->calls)===1,'one collector call');
    $vm=$reader->query('vm','vm-1');
    ok($vm['data']['sections']['performance']['data']['samples'][0]['cpu_percent']===12.5,'saved numeric metric available on local read');
    ok(!isset($vm['data']['sections']['storage_performance']),'disk has independent sampling progress');
    ok(!str_contains(json_encode($telemetry->read('vm-1','initial')),'NEVER-PERSIST'),'credentials removed');
    ok($vm['data']['sections']['performance']['observed_at']===1789084820,'freshness is tied to sample timestamp');
    ok(!str_contains(file_get_contents($dir.'/telemetry/vm-1/current.enc'),'cpu_percent'),'metrics ciphertext');
    ok(hash_file('sha256',$dir.'/current.enc')===$inventoryHash,'metrics do not rewrite inventory on successful sample');
    unset($sampler); $sampler=new IntegrationVmwareInventorySampler($store,$telemetry,$provider,$guard,$clock);
    $sampler->tick(1); ok($provider->calls[1]===['vm','vm-1','storage_performance'],'resume next unit');
    $sampler->tick(10); ok(count($provider->calls)===6,'all vm/host graph jobs');
    ok($sampler->tick()['work_done']===0,'per-section cadence, not per-browser cadence');
    $status=$reader->query('status'); ok(isset($status['telemetry']['cycle_completed_at']),'cycle progress exposed');
    $now+=86400;
    ok($reader->query('status')['stale']===true,'status staleness not just entity staleness');
    ok($reader->query('status')['worker_silent']===true,'missing worker heartbeat explicit');
    ok($reader->query('vm','vm-1')['data']['sections']['performance']['state']==='stale','old graphs visibly dated');
    $paths=[]; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f)if($f->isFile())$paths[$f->getPathname()]=hash_file('sha256',$f->getPathname());
    $calls=count($provider->calls);for($i=0;$i<100;$i++)$reader->query('vm','vm-1');
    foreach($paths as $p=>$h)ok(hash_file('sha256',$p)===$h,'local stale read hash unchanged');
    ok(count($provider->calls)===$calls,'100 reads cause no provider call');
    $provider->fault='timeout credentials SECRET';$sampler->tick(1);
    $failed=end($provider->calls);$part=$reader->query($failed[0],$failed[1])['data']['sections'][$failed[2]];
    ok($part['state']==='stale' && $part['observed_at']===1789084820,'transient failure retains original dated graph');
    ok(!str_contains(json_encode($telemetry->state()),'SECRET'),'safe error category only');
    $provider->fault=null;$sampler->tick(1);
    ok(end($provider->calls)!==$failed,'one failure does not starve next graph');
    $provider->fault='HTTP 403 forbidden SECRET';$denial=$sampler->tick(1);
    ok($denial['error']==='access_denied','known denial invalidates inventory');
    fails(fn()=>$reader->query('vm','vm-1'),'access_denied');
    ok($sampler->tick()['state']==='blocked','no retries from metrics while inventory denial sticky');
    // Simulate a complete inventory recovery while preserving the new authorization epoch.
    $denied=$store->read('current');$epoch=$denied['access_epoch'];
    ok(is_string($epoch)&&$epoch!=='initial','denial rotates sample epoch');
    $recovery = new class implements IntegrationVmwareInventoryProvider {
        public function discover(): array { return ['ok'=>true,'ids'=>['vm'=>['vm-1','vm-2'],'host'=>['host-3'],'datastore'=>[]],'core'=>[]]; }
        public function collect(string $kind,string $id,array $seed): array { return $kind==='domain' ? ['ok'=>true,'rows'=>[]] : ['ok'=>true,'name'=>$id,'sections'=>[]]; }
    };
    $recoverResult=(new IntegrationVmwareInventoryWorker($store,$recovery,$guard,$clock))->tick(20);
    ok($recoverResult['state']==='ready','real inventory worker completes denial recovery');
    $current=$store->read('current');ok($current['access_epoch']===$epoch,'complete publication preserves rotated authorization epoch');
    ok(!isset($reader->query('vm','vm-1')['data']['sections']['performance']),'old pre-denial graphs not resurrected by inventory recovery');
    $provider->fault=null;$sampler->tick(2);
    ok(isset($reader->query('vm','vm-1')['data']['sections']['performance']),'fresh graph after recovery');
    $allow=false;$calls=count($provider->calls);fails(fn()=>$sampler->tick(),'binding_changed');fails(fn()=>$reader->query(),'access_denied');
    ok(count($provider->calls)===$calls,'revoked connector no network');$allow=true;
    $other=new IntegrationVmwareInventoryTelemetryStore($dir.'/telemetry',$key,str_repeat('d',64));
    ok($other->read('vm-1',$epoch)===[],'metrics cannot cross credential binding');
    fails(fn()=>$telemetry->read('../1',$epoch),'id_invalid');fails(fn()=>$telemetry->write('vm-1',$epoch,[]),'lock_required');
    $locker=new IntegrationVmwareInventoryStore($dir,$key,$binding);$busy=$locker->exclusive(fn()=>$sampler->tick());ok($busy['state']==='busy','inventory and metrics connector lock shared');
    // Removing confirmed inventory membership retires only its metric files.
    unset($current['inventory']['objects']['vm']['vm-2']);$store->exclusive(fn()=>$store->write('current',$current));
    $sampler->tick(1);ok(!file_exists($dir.'/telemetry/vm-2'),'confirmed removal prunes bounded shard');
    $enc=$dir.'/telemetry/vm-1/current.enc';$wire=file_get_contents($enc);file_put_contents($enc,substr($wire,0,-1).(substr($wire,-1)^"\x01"));
    fails(fn()=>$reader->query('vm','vm-1'),'ciphertext_invalid');file_put_contents($enc,$wire);
    symlink($dir.'/telemetry/vm-1',$dir.'/telemetry/vm-777');fails(fn()=>$telemetry->read('vm-777',$epoch),'path_invalid');unlink($dir.'/telemetry/vm-777');
    // A fresh PHP process reads a saved graph without even loading Sampler/MetricSource.
    $code='require '.var_export($root.'/layers/phase5g/functions/classes/class.IntegrationVmwareInventoryStore.php',true).';require '.
        var_export($root.'/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php',true).';$s=new IntegrationVmwareInventoryTelemetryStore('.
        var_export($dir.'/telemetry',true).',hex2bin('.var_export(bin2hex($key),true).'),'.var_export($binding,true).');echo count($s->read("vm-1",'.var_export($epoch,true).'));';
    $p=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    ok(proc_close($p)===0&&$out==='2'&&$err==='','restart reads sharded metrics');
    echo "PHASE5G3_TELEMETRY_ASSERTIONS=$n\nPHASE5G3_OFFLINE_METRICS_OK\n";
} finally {wipe($dir);}
