<?php
declare(strict_types=1);
// CLI-only, test fixture state. Never installed beneath a production web root.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__,2);
foreach(['IntegrationDnsCipher','IntegrationDnsConfigRepository','IntegrationDnsConfigService','IntegrationDnsAdminController','IntegrationDnsAdminView'] as $name) require "$root/layers/dns1/functions/classes/class.$name.php";
require __DIR__.'/memory-repository.php';
$request=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$repo=new DnsMemoryRepository();
foreach(['profiles','connections','events'] as $prop) $repo->$prop=$request['state'][$prop]??[];
$cipher=new IntegrationDnsCipher('browser-test',['browser-test'=>hex2bin($request['test_key'])]);
$service=new IntegrationDnsConfigService($repo,$cipher);
if (($request['operation']??null)==='render') {
    $result=['html'=>(new IntegrationDnsAdminView())->render($service->snapshot(),'test-csrf-only')];
} else {
    $controller=new IntegrationDnsAdminController(fn()=>$service);
    $result=$controller->handle(['method'=>'POST','authenticated'=>true,'admin'=>true,'https'=>true,'actor_id'=>1,
        'csrf_valid'=>$request['csrf_valid']??false,'input'=>$request['input']]);
}
echo json_encode(['result'=>$result,'state'=>['profiles'=>$repo->profiles,'connections'=>$repo->connections,'events'=>$repo->events]],JSON_THROW_ON_ERROR);
