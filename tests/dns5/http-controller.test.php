<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/layers/dns5/functions/classes/class.IntegrationDnsCollectorMachineHttpController.php';

$n=0;
function ok5(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }

final class Dns5FakeMachineService {
    public array $pollTokens=[],$results=[];
    public mixed $pollResult=['id'=>null];
    public ?string $throwCode=null;
    public function poll(string $token): ?array {
        $this->pollTokens[]=$token;
        if($this->throwCode!==null) throw new DomainException($this->throwCode);
        return $this->pollResult;
    }
    public function submitResult(string $token,string $job,string $lease,array $result): array {
        $this->results[]=[$token,$job,$lease,$result];
        if($this->throwCode!==null) throw new DomainException($this->throwCode);
        return ['id'=>$job,'state'=>'succeeded','result_code'=>'ok','finished_at'=>1700000000];
    }
}
$id=str_repeat('a',32); $secret=str_repeat('B',43); $token="dnsctl1.$id.$secret";
$service=new Dns5FakeMachineService(); $constructed=0;
$controller=new IntegrationDnsCollectorMachineHttpController(static function() use (&$constructed,$service){ ++$constructed; return $service; });
function req5(string $body,string $method='POST',bool $https=true,string $type='application/json',?string $auth=null): array {
    global $token;
    return ['method'=>$method,'https'=>$https,'content_type'=>$type,'authorization'=>$auth??('Bearer '.$token),'raw_body'=>$body];
}

$service->pollResult=['id'=>str_repeat('c',32),'job_type'=>'list_zones','server'=>'dc01.example.invalid','lease_id'=>str_repeat('d',32)];
$out=$controller->handle(req5('{"action":"poll"}'));
ok5($out['status']===200 && $out['body']['ok']===true,'poll accepted');
ok5($constructed===1 && $service->pollTokens[0]===$token,'poll token forwarded after validation');
ok5($out['headers']['Cache-Control']==='no-store' && $out['headers']['X-Content-Type-Options']==='nosniff','response security headers');

$service->pollResult=null;
$out=$controller->handle(req5('{"action":"poll"}'));
ok5($out['status']===204 && $out['body']===null,'empty poll is 204');

$job=str_repeat('c',32); $lease=str_repeat('d',32);
$body=json_encode(['action'=>'result','data'=>['job_id'=>$job,'lease_id'=>$lease,'result'=>['ok'=>false,'error_code'=>'timeout']]],JSON_THROW_ON_ERROR);
$out=$controller->handle(req5($body));
ok5($out['status']===200 && $out['body']['data']['state']==='succeeded','result accepted');
ok5($service->results[0]===[$token,$job,$lease,['ok'=>false,'error_code'=>'timeout']],'result forwarded exactly');

$before=$constructed;
foreach([
    req5('{"action":"poll"}','GET'),
    req5('{"action":"poll"}','POST',false),
    req5('{"action":"poll"}','POST',true,'text/plain'),
    req5('{"action":"poll"}','POST',true,'application/json',''),
    req5('{"action":"poll"}','POST',true,'application/json','Basic abc'),
    req5('{"action":"poll"}','POST',true,'application/json','Bearer bad')
] as $bad) {
    $o=$controller->handle($bad); ok5($o['status']>=400 && $constructed===$before,'transport denied before service construction');
}

$out=$controller->handle(req5(str_repeat('x',73729)));
ok5($out['status']===413 && $constructed===$before,'body size bounded before parse');

foreach([
    '{"action":"poll","action":"result"}',
    '{"action":"result","data":{"job_id":"'.str_repeat('c',32).'","job_id":"'.str_repeat('d',32).'","lease_id":"'.str_repeat('e',32).'","result":{}}}',
    '{"action":"poll","admin":true}',
    '{"action":"poll","data":{"unexpected":1}}',
    '{"action":',
    '[]'
] as $raw) {
    $o=$controller->handle(req5($raw)); ok5($o['status']===400 && $constructed===$before,'strict JSON/input rejected before service construction');
}

foreach([
    ['data'=>['job_id'=>$job,'lease_id'=>$lease,'result'=>['ok'=>true,'error_code'=>null]]],
    ['action'=>'result','data'=>['job_id'=>$job,'lease_id'=>$lease]],
    ['action'=>'result','data'=>['job_id'=>'bad','lease_id'=>$lease,'result'=>[]]],
    ['action'=>'arbitrary']
] as $input) {
    $raw=json_encode($input,JSON_THROW_ON_ERROR);
    $o=$controller->handle(req5($raw)); ok5($o['status']===400 && $constructed===$before,'action shape rejected before service construction');
}

foreach([
    'collector_auth_failed'=>401,
    'lease_invalid'=>409,
    'job_not_running'=>409,
    'job_obsolete'=>409,
    'assignment_changed'=>409,
    'job_expired'=>409,
    'result_invalid'=>400,
    'id_invalid'=>400
] as $code=>$status) {
    $service->throwCode=$code;
    $o=$controller->handle(req5('{"action":"poll"}'));
    ok5($o['status']===$status && $o['body']['error']===$code,'domain code mapped '.$code);
}
$service->throwCode=null;

$boom=new IntegrationDnsCollectorMachineHttpController(static function(){ return new class {
    public function poll(string $token): ?array { throw new RuntimeException('password=do-not-leak'); }
};});
$o=$boom->handle(req5('{"action":"poll"}'));
ok5($o['status']===503 && !str_contains(json_encode($o), 'do-not-leak'),'unexpected exception sanitized');

printf("DNS5_MACHINE_HTTP_ASSERTIONS=%d\nDNS5_MACHINE_HTTP_TESTS_OK=1\n",$n);
