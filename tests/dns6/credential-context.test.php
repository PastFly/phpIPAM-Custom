<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/layers/dns6/functions/classes/class.IntegrationDnsCredentialContextReader.php';

$n=0;
function ok6ctx(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }

final class Dns6ContextDb {
    public array $queries=[];
    public ?array $connection=null;
    public ?array $profile=null;
    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        $this->queries[]=[$table,$sql,$values,$class];
        if(str_contains($sql,'custom_dns_connections')) return $this->connection===null?[]:[(object)$this->connection];
        if(str_contains($sql,'custom_dns_credential_profiles')) return $this->profile===null?[]:[(object)$this->profile];
        throw new RuntimeException('unexpected_query');
    }
}

$id=str_repeat('a',32); $profileId=str_repeat('b',32);
$db=new Dns6ContextDb();
$db->connection=['id'=>$id,'server'=>'dc01.example.invalid','revision'=>'5','binding_epoch'=>'7','credential_mode'=>'profile','profile_id'=>$profileId];
$db->profile=['id'=>$profileId,'username'=>'RBank\\svc_dns','secret_envelope'=>'fixture-envelope','secret_version'=>'3'];
$reader=new IntegrationDnsCredentialContextReader($db);

$c=$reader->current($id);
ok6ctx($c['id']===$id && $c['profile_id']===$profileId,'server derives profile id');
ok6ctx($c['username']==='RBank\\svc_dns' && $c['secret_envelope']==='fixture-envelope','credential material internal result');
ok6ctx($c['secret_version']===3 && $c['revision']===5 && $c['binding_epoch']===7,'versions normalized');
ok6ctx(count($db->queries)===2,'profile mode uses two reads');
ok6ctx(str_contains($db->queries[1][1],'secret_envelope'),'secure reader explicitly selects envelope');

$db->queries=[];
$db->connection=['id'=>$id,'server'=>'dc01.example.invalid','revision'=>6,'binding_epoch'=>8,'credential_mode'=>'collector_identity','profile_id'=>null];
$c=$reader->current($id);
ok6ctx($c===['id'=>$id,'server'=>'dc01.example.invalid','revision'=>6,'binding_epoch'=>8,'credential_mode'=>'collector_identity','profile_id'=>null,'username'=>null,'secret_version'=>null,'secret_envelope'=>null],'collector identity has no central secret');
ok6ctx(count($db->queries)===1,'collector identity never queries profile');

$db->connection=null;
ok6ctx($reader->current($id)===null,'missing connection rejected');
$db->connection=['id'=>$id,'server'=>'dc01.example.invalid','revision'=>5,'binding_epoch'=>7,'credential_mode'=>'profile','profile_id'=>$profileId];
$db->profile=null;
ok6ctx($reader->current($id)===null,'missing/revoked profile rejected');

try { $reader->current('bad'); throw new RuntimeException('bad id accepted'); }
catch(DomainException $e) { ok6ctx($e->getMessage()==='id_invalid','id validated'); }

try { new IntegrationDnsCredentialContextReader(new stdClass()); throw new RuntimeException('bad db accepted'); }
catch(DomainException $e) { ok6ctx($e->getMessage()==='control_unavailable','db capability validated'); }

printf("DNS6_CREDENTIAL_CONTEXT_ASSERTIONS=%d\nDNS6_CREDENTIAL_CONTEXT_TESTS_OK=1\n",$n);
