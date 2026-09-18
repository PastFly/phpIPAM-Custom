<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/layers/dns5/functions/classes/class.IntegrationDnsMachineContextReader.php';

$n=0;
function ok5ctx(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }

final class Dns5ContextDb {
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
$db=new Dns5ContextDb();
$db->connection=['id'=>$id,'server'=>'dc01.example.invalid','revision'=>'5','binding_epoch'=>'7','credential_mode'=>'profile','profile_id'=>$profileId];
$db->profile=['id'=>$profileId,'secret_version'=>'3'];
$reader=new IntegrationDnsMachineContextReader($db);

$c=$reader->current($id);
ok5ctx($c===['id'=>$id,'server'=>'dc01.example.invalid','revision'=>5,'binding_epoch'=>7,'credential_mode'=>'profile','credential_version'=>3],'profile context normalized');
ok5ctx(!array_key_exists('profile_id',$c),'profile id not exposed to machine service');
ok5ctx(count($db->queries)===2,'profile mode uses two bounded reads');
ok5ctx(str_contains($db->queries[1][1],'secret_envelope IS NOT NULL'),'revoked profile excluded without fetching ciphertext');
ok5ctx(!preg_match('/SELECT[^\n]*secret_envelope(?:,|\s+FROM)/i',$db->queries[1][1]),'ciphertext column not selected');

$db->queries=[];
$db->connection=['id'=>$id,'server'=>'dc01.example.invalid','revision'=>6,'binding_epoch'=>8,'credential_mode'=>'collector_identity','profile_id'=>null];
$c=$reader->current($id);
ok5ctx($c===['id'=>$id,'server'=>'dc01.example.invalid','revision'=>6,'binding_epoch'=>8,'credential_mode'=>'collector_identity','credential_version'=>null],'collector identity context');
ok5ctx(count($db->queries)===1,'collector identity does not query profile');

$db->connection=null;
ok5ctx($reader->current($id)===null,'missing connection unavailable');

$db->connection=['id'=>$id,'server'=>'dc01.example.invalid','revision'=>5,'binding_epoch'=>7,'credential_mode'=>'profile','profile_id'=>$profileId];
$db->profile=null;
ok5ctx($reader->current($id)===null,'revoked or missing profile unavailable');

foreach([
    ['id'=>$id,'server'=>'bad host','revision'=>5,'binding_epoch'=>7,'credential_mode'=>'collector_identity','profile_id'=>null],
    ['id'=>$id,'server'=>'dc01.example.invalid','revision'=>0,'binding_epoch'=>7,'credential_mode'=>'collector_identity','profile_id'=>null],
    ['id'=>$id,'server'=>'dc01.example.invalid','revision'=>5,'binding_epoch'=>0,'credential_mode'=>'collector_identity','profile_id'=>null],
    ['id'=>$id,'server'=>'dc01.example.invalid','revision'=>5,'binding_epoch'=>7,'credential_mode'=>'other','profile_id'=>null]
] as $bad) {
    $db->connection=$bad;
    ok5ctx($reader->current($id)===null,'invalid connection context rejected');
}

try { $reader->current('bad'); throw new RuntimeException('bad id accepted'); }
catch (DomainException $e) { ok5ctx($e->getMessage()==='id_invalid','id validated'); }

try { new IntegrationDnsMachineContextReader(new stdClass()); throw new RuntimeException('bad db accepted'); }
catch (DomainException $e) { ok5ctx($e->getMessage()==='control_unavailable','db capability validated'); }

printf("DNS5_CONTEXT_READER_ASSERTIONS=%d\nDNS5_CONTEXT_READER_TESTS_OK=1\n",$n);
