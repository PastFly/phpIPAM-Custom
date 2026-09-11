<?php
/** Real retained SOAP parsers with controlled transport, not a live vCenter. Requires DOM. */
$root=dirname(__DIR__);
if (!class_exists('DOMDocument')) { fwrite(STDERR,"DOM_REQUIRED_FOR_METRIC_ADAPTER_TEST\n"); exit(2); }
foreach (['IntegrationVmwareVcenter','IntegrationVmwareVcenterFaultAwareTransport','IntegrationVmwareVcenterStoragePerformance'] as $class) {
    $files=glob($root.'/layers/*/functions/classes/class.'.$class.'.php');
    if(count($files)!==1)throw new RuntimeException('ambiguous source: '.$class);require_once $files[0];
}
require_once $root.'/layers/phase5g/functions/classes/class.IntegrationVmwareInventoryWorker.php';
require_once $root.'/layers/phase5g3/functions/classes/class.IntegrationVmwareInventorySampler.php';
require_once $root.'/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryMetricSource.php';
require $root.'/tests/vmware-vcenter-phase5f-performance-regression.php';
$runtime=['endpoint'=>'https://vc.example.test','username'=>'fixture','password'=>'fixture-not-live','verify_tls'=>true];
$fixture=$transport;$observed=[];
$checked=function(...$a)use($fixture,&$observed){
    p5f_assert($a[0]==='POST'&&$a[1]==='https://vc.example.test/sdk'&&$a[4]===true,'strict endpoint/tls');
    p5f_assert($a[5]<=8&&$a[6]<=20,'request timeouts clamped');$observed[]=$a[3];return $fixture(...$a);
};
$source=new IntegrationVmwareInventoryMetricSource($runtime,$checked);
$metric=$source->sample('vm','vm-42','performance');
p5f_assert($metric['entity_external_id']==='vm-42'&&$metric['verify_tls']===true&&$metric['sample_count']===2,'real parser joined through guarded source');
p5f_assert(str_contains(implode('',$observed),'<maxSample>60</maxSample>'),'bounded graph history');
p5f_assert($metric['samples'][0]['cpu_percent']===20.67,'percent scaling retained');
$disk=$source->sample('host','host-42','storage_performance');
p5f_assert($disk['supported']===false && $disk['samples']===[],'unsupported disk counter is not fabricated zeros');
$wrong=$runtime;$wrong['verify_tls']=false;
try{new IntegrationVmwareInventoryMetricSource($wrong,$checked);throw new LogicException('insecure accepted');}catch(RuntimeException $e){p5f_assert($e->getMessage()==='mirror_strict_tls_worker_required','insecure refused');}
$reflect=new ReflectionProperty($source,'transport');$guarded=$reflect->getValue($source);
foreach ([['POST','https://other.invalid/sdk',[],p5f_env('<QueryPerf xmlns="urn:vim25"/>'),true,8,20],
 ['POST','https://vc.example.test/sdk',[],p5f_env('<PowerOnVM_Task xmlns="urn:vim25"/>'),true,8,20],
 ['POST','https://vc.example.test/sdk',[],p5f_env('<QueryPerf xmlns="urn:vim25"/><QueryPerf xmlns="urn:vim25"/>'),true,8,20],
 ['POST','https://vc.example.test/sdk',[],p5f_env('<QueryPerf xmlns="urn:vim25"/>'),false,8,20]] as $args) {
 try{$guarded(...$args);throw new LogicException('invalid SOAP accepted');}catch(RuntimeException $e){p5f_assert(str_contains($e->getMessage(),'mirror_metric_'),'scope or operation rejected');}
}
foreach (['Logout','QueryPerfProviderSummary'] as $deniedOperation) {
 $denied=new IntegrationVmwareInventoryMetricSource($runtime,function(...$args)use($fixture,$deniedOperation){
    if(str_contains($args[3],'<'.$deniedOperation.' '))return ['status'=>403,'headers'=>[],'body'=>'secret-error'];
    return $fixture(...$args);
 });
 try{$denied->sample('vm','vm-42','performance');throw new LogicException('denial hidden');}
 catch(RuntimeException $e){p5f_assert($e->getMessage()==='mirror_access_denied','denial including suppressed Logout preserved');}
}
echo "PHASE5G3_REAL_PARSERS_STRICT_SCOPE_DENIAL_OK\n";
