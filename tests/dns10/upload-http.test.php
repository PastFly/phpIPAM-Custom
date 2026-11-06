<?php
declare(strict_types=1);
$path=dirname(__DIR__,2).'/layers/dns10/functions/classes/class.IntegrationDnsZoneUploadHttpController.php';
if (!is_file($path)) throw new RuntimeException('snapshot_upload_controller_missing');
require $path;

$n=0;
function httpCheck10(bool $value,string $label): void {global $n;++$n;if(!$value)throw new RuntimeException($label);}
final class Dns10UploadFixture {
    public array $calls=[];
    public ?string $authError=null;
    public ?string $publishError=null;
    public array $result=['decision'=>'publish'];
    public array $received=[];
    public function authenticateUpload(#[SensitiveParameter] string $token): void {
        $this->calls[]='authenticate';
        if ($this->authError!==null) throw new DomainException($this->authError);
    }
    public function publishUpload(#[SensitiveParameter] string $token,array $document,string $digest,int $bytes): array {
        $this->calls[]='publish';$this->received=['document'=>$document,'digest'=>$digest,'bytes'=>$bytes];
        if ($this->publishError!==null) throw new DomainException($this->publishError);
        return $this->result;
    }
}
$token='dnsup1.'.str_repeat('a',32).'.'.str_repeat('B',43);
$raw='{"dataset":"fixture","records":[]}';
$request=['method'=>'POST','https'=>true,'content_type'=>'application/json; charset=utf-8',
          'authorization'=>'Bearer '.$token,'raw_body'=>$raw];
$fixture=new Dns10UploadFixture();$created=0;
$factory=static function() use($fixture,&$created): object {++$created;return $fixture;};
$controller=new IntegrationDnsZoneUploadHttpController($factory);
foreach ([
    [['method'=>'GET'],405],[['https'=>false],403],[['content_type'=>'text/plain'],415],
    [['authorization'=>'Bearer dnsctl1.'.str_repeat('a',32).'.'.str_repeat('B',43)],401],
    [['authorization'=>'Bearer '.$token.'x'],401],[['raw_body'=>''],400],
    [['raw_body'=>[]],400],[['raw_body'=>str_repeat('x',4194305)],413],
] as [$change,$status]) {
    $before=$created;$response=$controller->handle(array_replace($request,$change));
    httpCheck10($response['status']===$status && $created===$before,'guard before service');
}
$fixture->authError='upload_auth_failed';
$response=$controller->handle(array_replace($request,['raw_body'=>'{bad json']));
httpCheck10($response['status']===401 && $fixture->calls===['authenticate'],'invalid token rejected before JSON');
$fixture->authError=null;
foreach ([
    '{"a":1,"a":2}', '{"a":1,"\\u0061":2}', '{"outer":{"a":1,"a":2}}',
    '[1,2]', '{"a":}', '{"a":1,}', "{\"a\":\"\xff\"}", str_repeat('[',21).str_repeat(']',21),
] as $invalid) {
    $fixture->calls=[];$response=$controller->handle(array_replace($request,['raw_body'=>$invalid]));
    httpCheck10($response['status']===400 && $fixture->calls===['authenticate'],'strict JSON rejected after auth');
}
$fixture->calls=[];
$response=$controller->handle($request);
httpCheck10($response['status']===200 && $response['body']===['ok'=>true,'decision'=>'publish'],'publication acknowledged');
httpCheck10($fixture->calls===['authenticate','publish'],'authenticate before publish');
httpCheck10($fixture->received['digest']===hash('sha256',$raw) && $fixture->received['bytes']===strlen($raw),'digest and size of exact body');
httpCheck10($fixture->received['document']===['dataset'=>'fixture','records'=>[]],'decoded document passed to service');
httpCheck10(($response['headers']['Cache-Control']??'')==='no-store' && ($response['headers']['X-Content-Type-Options']??'')==='nosniff','response is not cached or sniffed');
$fixture->result=['decision'=>'replay'];
httpCheck10($controller->handle($request)['body']===['ok'=>true,'decision'=>'replay'],'replay acknowledged');
foreach ([['snapshot_out_of_order',409],['snapshot_sequence_conflict',409],['snapshot_invalid',400],['upload_auth_failed',401],['secret exception text',503]] as [$code,$status]) {
    $fixture->publishError=$code;$response=$controller->handle($request);
    httpCheck10($response['status']===$status && !str_contains(json_encode($response),$token) && !str_contains(json_encode($response),'secret exception text'),'error response does not leak sensitive data');
}
$fixture->publishError=null;$fixture->result=['decision'=>'unexpected','secret'=>'fixture'];
$response=$controller->handle($request);
httpCheck10($response['status']===503 && !str_contains(json_encode($response),'fixture'),'untrusted service output rejected');
$broken=new IntegrationDnsZoneUploadHttpController(static function(): object {throw new RuntimeException('secret exception text');});
$response=$broken->handle($request);
httpCheck10($response['status']===503 && !str_contains(json_encode($response),'secret exception text'),'unexpected factory error redacted');
echo 'DNS10_UPLOAD_HTTP_ASSERTIONS='.$n."\n";
echo "DNS10_UPLOAD_HTTP_TEST_OK=1\n";
