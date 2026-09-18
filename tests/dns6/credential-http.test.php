<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/layers/dns6/functions/classes/class.IntegrationDnsCredentialHttpController.php';

$n=0;
function ok6h(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }

final class Dns6FakeCredentialService {
    public int $calls=0;
    public ?string $throwCode=null;
    public function deliver(string $token,string $job,string $lease): array {
        ++$this->calls;
        if($this->throwCode!==null) throw new DomainException($this->throwCode);
        return [
            'credential_mode'=>'profile','server'=>'dc01.example.invalid','credential_version'=>3,
            'username'=>'EXAMPLE\\dns-reader','password'=>'fixture secret value'
        ];
    }
}
$collector=str_repeat('a',32);
$token='dnsctl1.'.$collector.'.'.str_repeat('F',43);
$job=str_repeat('b',32);
$lease=str_repeat('c',32);
$svc=new Dns6FakeCredentialService();
$constructed=0;
$controller=new IntegrationDnsCredentialHttpController(static function() use (&$constructed,$svc){++$constructed;return $svc;});
function req6(string $body,string $method='POST',bool $https=true,string $type='application/json',?string $auth=null): array {
    global $token;
    return ['method'=>$method,'https'=>$https,'content_type'=>$type,'authorization'=>$auth??('Bearer '.$token),'raw_body'=>$body];
}

$raw=json_encode(['job_id'=>$job,'lease_id'=>$lease],JSON_THROW_ON_ERROR);
$out=$controller->handle(req6($raw));
ok6h($out['status']===200 && is_string($out['body']),'credential response encoded');
$decoded=json_decode($out['body'],true,8,JSON_THROW_ON_ERROR);
ok6h($decoded['ok']===true && $decoded['data']['password']==='fixture secret value','password delivered only in success body');
ok6h(!isset($decoded['data']['profile_id']),'profile id absent');
ok6h($out['headers']['Cache-Control']==='no-store' && $out['headers']['Pragma']==='no-cache','sensitive response not cached');
ok6h($constructed===1 && $svc->calls===1,'service constructed after validation');

$before=$constructed;
foreach([
    req6($raw,'GET'),
    req6($raw,'POST',false),
    req6($raw,'POST',true,'text/plain'),
    req6($raw,'POST',true,'application/json',''),
    req6($raw,'POST',true,'application/json','Bearer bad'),
    req6(str_repeat('x',4097))
] as $bad) {
    $o=$controller->handle($bad);
    ok6h($o['status']>=400 && $constructed===$before,'transport denied before service');
}

foreach([
    '{"job_id":"'.str_repeat('b',32).'","job_id":"'.str_repeat('d',32).'","lease_id":"'.str_repeat('c',32).'"}',
    '{"job_id":"'.$job.'","lease_id":"'.$lease.'","profile_id":"'.str_repeat('d',32).'"}',
    '{"job_id":"bad","lease_id":"'.$lease.'"}',
    '{"job_id":"'.$job.'","lease_id":"bad"}',
    '[]','{"job_id":'
] as $badJson) {
    $o=$controller->handle(req6($badJson));
    ok6h($o['status']===400 && $constructed===$before,'strict request shape denied before service');
}

foreach([
    'collector_auth_failed'=>401,'lease_invalid'=>409,'job_not_running'=>409,
    'job_obsolete'=>409,'assignment_changed'=>409,'job_expired'=>409,
    'credential_already_delivered'=>409,'job_not_found'=>409,'credential_unavailable'=>503
] as $code=>$status) {
    $svc->throwCode=$code;
    $o=$controller->handle(req6($raw));
    ok6h($o['status']===$status,'domain mapping '.$code);
    ok6h(!str_contains((string)$o['body'],'fixture secret value'),'error body has no prior secret');
}
$svc->throwCode=null;

$boom=new IntegrationDnsCredentialHttpController(static function(){return new class {
    public function deliver(string $token,string $job,string $lease): array { throw new RuntimeException('password=do-not-leak'); }
};});
$o=$boom->handle(req6($raw));
ok6h($o['status']===503 && !str_contains((string)$o['body'],'do-not-leak'),'unexpected errors sanitized');

printf("DNS6_CREDENTIAL_HTTP_ASSERTIONS=%d\nDNS6_CREDENTIAL_HTTP_TESTS_OK=1\n",$n);
