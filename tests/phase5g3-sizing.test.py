#!/usr/bin/env python3
"""Synthetic envelopes and four independent PHP readers. Never a production benchmark."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
ROOT = Path(__file__).resolve().parents[1]
BOOT = '''<?php
$root = $argv[1]; $dir = $argv[2];
require $root.'/layers/phase5g/functions/classes/class.IntegrationVmwareInventoryStore.php';
require $root.'/layers/phase5g/functions/classes/class.IntegrationVmwareInventoryReader.php';
require $root.'/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php';
$key = str_repeat('k', 32); $binding = str_repeat('c', 64);
$store = new IntegrationVmwareInventoryStore($dir, $key, $binding);
$metrics = new IntegrationVmwareInventoryTelemetryStore($dir.'/telemetry', $key, $binding);
'''
SETUP = BOOT + '''
$count = (int)$argv[3]; $now = time();
$objects=['vm'=>[], 'host'=>[], 'datastore'=>[]]; $rows=[];
for($i=1;$i<=$count;$i++) {
 $id='vm-'.$i; $disks=[];$nics=[];
 for($d=0;$d<4;$d++)$disks[]=['label'=>'Disk '.$d,'capacity_bytes'=>107374182400,'datastore'=>'datastore-'.($i%70+1),'backing'=>'[FixtureDS] '.$id.'/disk'.$d.'.vmdk'];
 for($v=0;$v<2;$v++)$nics[]=['label'=>'NIC '.$v,'mac'=>'02:00:00:01:02:03','addresses'=>['192.0.2.10','2001:db8::10'],'network'=>'fixture-network','uplinks'=>[['role'=>'active','device'=>'vmnic0','port'=>'Ethernet1/10'],['role'=>'standby','device'=>'vmnic1','port'=>'Ethernet1/11']]];
 $objects['vm'][$id]=['collected_at'=>$now,'data'=>['ok'=>true,'name'=>'fixture-'.$i,'id'=>$id,
   'context'=>['host_external_id'=>'host-'.($i%64+1),'datacenter_external_id'=>'datacenter-1'],
   'detail'=>['vm'=>['name'=>'fixture-'.$i,'cpu_count'=>4,'memory_size_mib'=>16384],'disks'=>$disks,'nics'=>$nics],
   'sections'=>['annotation'=>['state'=>'ok','observed_at'=>$now,'data'=>['annotation'=>str_repeat('Inventory fixture description. ',128)]],
   'network_path'=>['state'=>'ok','observed_at'=>$now,'data'=>['nics'=>$nics]],'guest_facts'=>['state'=>'ok','observed_at'=>$now,'data'=>['memory_mb'=>16384,'num_cpu'=>4,'guest_guest_full_name'=>'Fixture Linux (64-bit)']]]]];
 $rows[]=['vm_external_id'=>$id,'name'=>'fixture-'.$i,'tags'=>[['name'=>'fixture','category'=>'environment']]];
}
for($i=1;$i<=64;$i++)$objects['host']['host-'.$i]=['collected_at'=>$now,'data'=>['name'=>'host-'.$i,'summary'=>['hardware'=>['memory_total_bytes'=>137438953472]]]];
for($i=1;$i<=70;$i++)$objects['datastore']['datastore-'.$i]=['collected_at'=>$now,'data'=>['name'=>'datastore-'.$i,'detail'=>['capacity_bytes'=>4398046511104]]];
$publication=['state'=>'ready','blocked'=>false,'started_at'=>$now,'published_at'=>$now,'heartbeat_at'=>$now,'generation'=>'fixture-generation',
 'inventory'=>['objects'=>$objects,'core'=>['ok'=>true,'topology'=>['vms'=>$rows],'datastores'=>[]], 'search'=>['collected_at'=>$now,'data'=>['rows'=>$rows]]]];
$store->exclusive(fn()=>$store->write('current',$publication));
$metrics->exclusive(function()use($metrics,$count,$now){
 $points=[];for($i=0;$i<60;$i++)$points[]=['timestamp'=>gmdate('c',$now-20*(59-$i)),'cpu_percent'=>12.5,'memory_percent'=>25,'usage_kib_per_second'=>123.5];
 for($i=1;$i<=$count;$i++)$metrics->write('vm-'.$i,'initial',['performance'=>['state'=>'ok','observed_at'=>$now,'data'=>['samples'=>$points]],'storage_performance'=>['state'=>'ok','observed_at'=>$now,'data'=>['samples'=>$points]]]);
 $metrics->saveState(['state'=>'idle','last_success_at'=>$now,'completed'=>$count*2,'total'=>$count*2]);return [];
});
echo json_encode(['vm_count'=>$count,'host_count'=>64,'datastore_count'=>70,'inventory_wire_bytes'=>filesize($dir.'/current.enc'),'writer_php_peak_bytes'=>memory_get_peak_usage(true)]);
'''
READ = BOOT + '''
$reader = new IntegrationVmwareInventoryReader($store,fn()=>true,null,$metrics);$ms=[];
$before=hash_file('sha256',$dir.'/current.enc');
for($i=0;$i<12;$i++) {
 $start=hrtime(true);$entry=$reader->query('vm','vm-'.($i+1));$ms[]=(hrtime(true)-$start)/1000000;
 if($entry['vcenter_requests']!==0||!isset($entry['data']['sections']['performance']['data']['samples'][59]))throw new RuntimeException('bad join');
 unset($entry);
}
if(hash_file('sha256',$dir.'/current.enc')!==$before)throw new RuntimeException('reader wrote publication');
foreach(get_included_files() as $file)if(str_contains($file,'Sampler')||str_contains($file,'MetricSource')||str_contains($file,'InventorySource'))throw new RuntimeException('provider loaded into reader');
sort($ms);$usage=getrusage();
echo json_encode(['median_ms'=>$ms[6],'p95_ms'=>$ms[11],'php_peak_bytes'=>memory_get_peak_usage(true),'linux_maxrss_kib'=>$usage['ru_maxrss']??null,'reads'=>12,'provider_classes_loaded'=>0]);
'''
reports=[]
for count in (640,1000):
    with tempfile.TemporaryDirectory(prefix='ipam-sizing-') as tmp:
        tmp=Path(tmp);data=tmp/'data';data.mkdir(mode=0o700);(data/'telemetry').mkdir(mode=0o700)
        (tmp/'setup.php').write_text(SETUP);(tmp/'read.php').write_text(READ)
        create=subprocess.run(['php','-d','memory_limit=256M',str(tmp/'setup.php'),str(ROOT),str(data),str(count)],capture_output=True,text=True,timeout=90)
        assert create.returncode==0,(create.stdout,create.stderr)
        before={str(p.relative_to(data)):p.stat().st_mtime_ns for p in data.rglob('*') if p.is_file()}
        processes=[subprocess.Popen(['php','-d','memory_limit=256M',str(tmp/'read.php'),str(ROOT),str(data)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(4)]
        readings=[]
        for p in processes:
            out,err=p.communicate(timeout=90);assert p.returncode==0 and not err,(out,err);readings.append(json.loads(out))
        after={str(p.relative_to(data)):p.stat().st_mtime_ns for p in data.rglob('*') if p.is_file()}
        assert before==after,'read-only run modified a file'
        record=json.loads(create.stdout);record.update(concurrent_readers=4,total_reads=48,readers=readings,scope='synthetic local PHP readers; filesystem cache uncontrolled; not HTTP/vCenter/production')
        assert max(x['php_peak_bytes'] for x in readings)<268435456
        reports.append(record)
print(json.dumps({'synthetic_sizing':reports},indent=2))
print('PHASE5G3_SYNTHETIC_CONCURRENT_SIZING_OK')
