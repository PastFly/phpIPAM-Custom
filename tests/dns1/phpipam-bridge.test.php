<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/layers/dns1/functions/classes/class.IntegrationDnsAdminController.php';
require dirname(__DIR__,2).'/layers/dns1/functions/classes/class.IntegrationDnsPhpIpamAdminBridge.php';

$n=0;
function ok_bridge(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }

final class DnsBridgeFakeCrypto {
    public array $calls=[];
    public bool $valid=true;
    public function csrf_cookie($mode,$name,$value=null) {
        $this->calls[]=[$mode,$name,$value];
        if ($mode==='validate') return $this->valid;
        return 'fixture-token';
    }
}
final class DnsBridgeFakeUser {
    public object $user;
    public DnsBridgeFakeCrypto $Crypto;
    public bool $admin=true;
    public bool $https=true;
    public function __construct() { $this->user=(object)['id'=>17]; $this->Crypto=new DnsBridgeFakeCrypto(); }
    public function is_admin($strict=false): bool { return $this->admin; }
    public function isHttps(): bool { return $this->https; }
}
final class DnsBridgeFakeController {
    public array $seen=[];
    public function handle(array $request): array {
        $this->seen[]=$request;
        return ['status'=>200,'headers'=>['Cache-Control'=>'no-store'],'body'=>['ok'=>true,'data'=>['accepted'=>true]]];
    }
}

$constructed=0; $controller=new DnsBridgeFakeController();
$bridge=new IntegrationDnsPhpIpamAdminBridge(static function() use (&$constructed,$controller) { ++$constructed; return $controller; });
$user=new DnsBridgeFakeUser();
$baseServer=['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','HTTPS'=>'on'];
$headers=['X-Phpipam-Dns-Csrf'=>'csrf-ok'];
$body=json_encode(['action'=>'snapshot'],JSON_THROW_ON_ERROR);

$out=$bridge->handle($user,$baseServer,$headers,$body);
ok_bridge($out['status']===200,'valid request accepted');
ok_bridge($constructed===1,'controller constructed after trust checks');
ok_bridge(count($controller->seen)===1,'controller called once');
$seen=$controller->seen[0];
ok_bridge($seen['method']==='POST' && $seen['authenticated']===true && $seen['admin']===true,'trusted method/session/admin injected');
ok_bridge($seen['https']===true && $seen['csrf_valid']===true && $seen['actor_id']===17,'trusted https/csrf/actor injected');
ok_bridge($seen['input']===['action'=>'snapshot'],'only decoded input forwarded');
ok_bridge($user->Crypto->calls[0]===['validate','integration_microsoft_dns_config','csrf-ok'],'dedicated csrf scope');

$constructed=0; $controller->seen=[];
$bad=$baseServer; $bad['REQUEST_METHOD']='GET';
$out=$bridge->handle($user,$bad,$headers,$body);
ok_bridge($out['status']===405 && $constructed===0,'method denied before controller');

$bad=$baseServer; $bad['CONTENT_TYPE']='application/x-www-form-urlencoded';
$out=$bridge->handle($user,$bad,$headers,$body);
ok_bridge($out['status']===415 && $constructed===0,'content type denied before controller');

$user->admin=false;
$out=$bridge->handle($user,$baseServer,$headers,$body);
ok_bridge($out['status']===403 && $constructed===0,'non-admin denied');
$user->admin=true; $user->https=false;
$out=$bridge->handle($user,$baseServer,$headers,$body);
ok_bridge($out['status']===403 && $constructed===0,'non-https denied');
$user->https=true; $user->Crypto->valid=false;
$out=$bridge->handle($user,$baseServer,$headers,$body);
ok_bridge($out['status']===403 && $constructed===0,'csrf denied');
$user->Crypto->valid=true;

$out=$bridge->handle($user,$baseServer,[],$body);
ok_bridge($out['status']===403 && $constructed===0,'missing csrf denied');

$out=$bridge->handle($user,$baseServer,$headers,str_repeat('x',16385));
ok_bridge($out['status']===413 && $constructed===0,'body limit before parsing');

$out=$bridge->handle($user,$baseServer,$headers,'{"action":"snapshot","action":"save_profile"}');
ok_bridge($out['status']===400 && $constructed===0,'duplicate top-level key rejected');

$out=$bridge->handle($user,$baseServer,$headers,'{"action":"save_connection","data":{"name":"a","name":"b"}}');
ok_bridge($out['status']===400 && $constructed===0,'duplicate nested key rejected');

$out=$bridge->handle($user,$baseServer,$headers,'{"action":');
ok_bridge($out['status']===400 && $constructed===0,'invalid json rejected');

$out=$bridge->handle($user,$baseServer,$headers,'{"action":"snapshot","admin":true}');
ok_bridge($out['status']===400 && $constructed===0,'request cannot inject trusted flag');

$badUser=new DnsBridgeFakeUser(); $badUser->user=(object)['id'=>0];
$out=$bridge->handle($badUser,$baseServer,$headers,$body);
ok_bridge($out['status']===403 && $constructed===0,'invalid actor denied');

printf("DNS1_PHPIPAM_BRIDGE_ASSERTIONS=%d\nDNS1_PHPIPAM_BRIDGE_TESTS_OK=1\n",$n);
