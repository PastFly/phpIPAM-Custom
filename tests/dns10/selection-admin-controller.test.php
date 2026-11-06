<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$path=$root.'/layers/dns10/functions/classes/class.IntegrationDnsZoneSelectionAdminController.php';
if(!is_file($path))throw new RuntimeException('selection_admin_controller_missing');
require $path;
$n=0;
function checkAdmin10(bool $condition,string $name): void {global $n;++$n;if(!$condition)throw new RuntimeException($name);}
final class SelectionAdminFake10 {
    public array $calls=[];
    public ?string $error=null;
    public function setSelected(string $id,string $zone,bool $selected,?int $revision,?string $discovery,int $actor): array {
        $this->calls[]=func_get_args();return $this->result($id,$zone,$selected,false);
    }
    public function setCollectionEnabled(string $id,int $revision,bool $enabled,?string $discovery,int $actor): array {
        $this->calls[]=func_get_args();return $this->result($id,'example.test.',true,$enabled);
    }
    private function result(string $id,string $zone,bool $selected,bool $enabled): array {
        if($this->error!==null)throw new DomainException($this->error);
        return ['id'=>$id,'zone_name'=>$zone,'selected'=>$selected,'collection_enabled'=>$enabled,'revision'=>2,'secret'=>'not-returned'];
    }
}
$service=new SelectionAdminFake10();$built=0;
$controller=new IntegrationDnsZoneSelectionAdminController(function() use($service,&$built) {$built++;return $service;});
$id=str_repeat('a',32);$job=str_repeat('b',32);
$request=['method'=>'POST','authenticated'=>true,'admin'=>true,'https'=>true,'csrf_valid'=>true,'reauthenticated'=>true,'actor_id'=>7,
    'input'=>['action'=>'select_zone','data'=>['connection_id'=>$id,'zone'=>'example.test.','selected'=>true,'expected_revision'=>null,'discovery_id'=>$job]]];
foreach(['authenticated'=>401,'admin'=>403,'https'=>403,'csrf_valid'=>403,'reauthenticated'=>403] as $key=>$status) {
    $bad=$request;$bad[$key]=false;$before=$built;$response=$controller->handle($bad);
    checkAdmin10($response['status']===$status && $built===$before,'auth rejected before service '.$key);
}
$bad=$request;$bad['method']='GET';checkAdmin10($controller->handle($bad)['status']===405,'post required');
$bad=$request;$bad['actor_id']='7';checkAdmin10($controller->handle($bad)['status']===403,'trusted actor type');
$bad=$request;$bad['input']['data']['admin']=true;checkAdmin10($controller->handle($bad)['status']===400,'unknown input rejected');
$bad=$request;$bad['input']['data']['selected']=1;checkAdmin10($controller->handle($bad)['status']===400,'boolean strict');
$bad=$request;$bad['input']['data']['expected_revision']='1';checkAdmin10($controller->handle($bad)['status']===400,'revision strict');
$bad=$request;$bad['input']['data']['discovery_id']=null;checkAdmin10($controller->handle($bad)['status']===400,'selection requires discovery');
$bad=$request;$bad['reauthenticated']=false;$bad['input']['reauthenticated']=true;checkAdmin10($controller->handle($bad)['status']===400,'input cannot supply reauth');
$bad=$request;unset($bad['reauthenticated']);checkAdmin10($controller->handle($bad)['status']===403,'missing trusted reauth fails closed');
$response=$controller->handle($request);
checkAdmin10($response['status']===200 && $response['body']['data']['selected']===true,'selection succeeds');
checkAdmin10(!array_key_exists('secret',$response['body']['data']),'response projected');
checkAdmin10(end($service->calls)===[$id,'example.test.',true,null,$job,7],'actor from trusted context');
$off=$request;$off['reauthenticated']=false;$off['input']['data']['selected']=false;$off['input']['data']['expected_revision']=1;$off['input']['data']['discovery_id']=null;
checkAdmin10($controller->handle($off)['status']===200,'deselect available without recent reauth');
$enable=$request;$enable['input']=['action'=>'set_zone_collection','data'=>['id'=>$id,'expected_revision'=>1,'enabled'=>true,'discovery_id'=>$job]];
checkAdmin10($controller->handle($enable)['status']===200 && end($service->calls)===[$id,1,true,$job,7],'explicit enable');
$enable['reauthenticated']=false;checkAdmin10($controller->handle($enable)['status']===403,'enable requires reauth');
$enable['input']['data']['enabled']=false;$enable['input']['data']['discovery_id']=null;
checkAdmin10($controller->handle($enable)['status']===200,'disable available without recent reauth');
$service->error='revision_conflict';checkAdmin10($controller->handle($request)['status']===409,'revision conflict');
$service->error='discovery_unavailable';checkAdmin10($controller->handle($request)['status']===409,'stale discovery');
$service->error='secret SQL detail';$response=$controller->handle($request);
checkAdmin10($response['status']===503 && !str_contains(json_encode($response),'secret SQL detail'),'exception redacted');
checkAdmin10(($response['headers']['Cache-Control']??null)==='no-store','responses not cached');
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsPhpIpamAdminBridge.php';
final class SelectionCryptoFake10 {public function csrf_cookie($action,$scope,$value): bool {return $action==='validate'&&$value==='valid';}}
final class SelectionUserFake10 {
    public object $user;public object $Crypto;
    public function __construct() {$this->user=(object)['id'=>7];$this->Crypto=new SelectionCryptoFake10();}
    public function is_admin($unused): bool {return true;}
    public function isHttps(): bool {return true;}
}
$bridge=new IntegrationDnsPhpIpamAdminBridge(fn()=>$controller);$service->error=null;
$before=$built;
$result=$bridge->handle(new SelectionUserFake10(),['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json'],['X-Phpipam-Dns-Csrf'=>'valid'],json_encode($request['input']));
checkAdmin10($result['status']===403 && $built===$before,'legacy bridge cannot invent reauthentication');
$result=$bridge->handle(new SelectionUserFake10(),['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json'],['X-Phpipam-Dns-Csrf'=>'valid'],json_encode($off['input']));
checkAdmin10($result['status']===200,'legacy bridge permits safe deselection');
echo 'DNS10_SELECTION_ADMIN_ASSERTIONS='.$n."\nDNS10_SELECTION_ADMIN_TEST_OK=1\n";
