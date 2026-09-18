<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php';
require dirname(__DIR__,2).'/layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php';

$n=0;
function ok_repo(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }

final class DnsPhpIpamDbFixture {
    public bool $tx=false;
    public int $begins=0,$commits=0,$rollbacks=0;
    public array $queries=[],$runs=[];
    public bool $failRun=false;
    public array $profileRows=[],$connectionRows=[],$eventRows=[];
    public function beginTransaction(): bool { if($this->tx) return false; $this->tx=true; ++$this->begins; return true; }
    public function commit(): bool { if(!$this->tx) return false; $this->tx=false; ++$this->commits; return true; }
    public function rollBack(): bool { if(!$this->tx) return false; $this->tx=false; ++$this->rollbacks; return true; }
    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        $this->queries[]=[$table,$sql,$values,$class];
        if (str_contains($sql,'custom_dns_config_guard')) return [(object)['id'=>1]];
        if (str_contains($sql,'custom_dns_credential_profiles')) {
            $rows=$this->profileRows;
            if (str_contains($sql,'WHERE id = ?')) $rows=array_values(array_filter($rows,fn($r)=>$r['id']===$values[0]));
            return array_map(fn($r)=>(object)$r,$rows);
        }
        if (str_contains($sql,'custom_dns_connections')) {
            $rows=$this->connectionRows;
            if (str_contains($sql,'WHERE id = ?')) $rows=array_values(array_filter($rows,fn($r)=>$r['id']===$values[0]));
            return array_map(fn($r)=>(object)$r,$rows);
        }
        if (str_contains($sql,'custom_dns_config_events')) {
            if (str_contains($sql,'OFFSET 999')) return count($this->eventRows)>999?[(object)['event_id'=>$this->eventRows[count($this->eventRows)-1000]['event_id']]]:[];
        }
        throw new RuntimeException('unexpected query '.$sql);
    }
    public function runQuery($sql,$values=[]): bool {
        $this->runs[]=[$sql,$values];
        if($this->failRun) throw new RuntimeException('fixture_db_failure');
        return true;
    }
}

$db=new DnsPhpIpamDbFixture();
$repo=new IntegrationDnsPhpIpamConfigRepository($db);
$profile=['id'=>str_repeat('a',32),'name'=>'DNS','username'=>'svc@example.invalid','secret_envelope'=>'cipher','secret_version'=>1,'revision'=>1,'updated_at'=>10,'updated_by'=>17];
$conn=['id'=>str_repeat('b',32),'provider'=>'microsoft_dns','name'=>'Primary','server'=>'dc01.example.invalid','interval_seconds'=>300,'credential_mode'=>'profile','profile_id'=>$profile['id'],'collector_id'=>null,'enabled'=>false,'status'=>'not_configured','revision'=>1,'binding_epoch'=>1,'updated_at'=>10,'updated_by'=>17];
$db->profileRows=[$profile]; $db->connectionRows=[$conn];

$out=$repo->transaction(function() use($repo,$profile,$conn) {
    ok_repo($repo->profiles()[0]['revision']===1,'profile row normalized');
    ok_repo($repo->connections()[0]['enabled']===false,'connection boolean normalized');
    ok_repo($repo->profile($profile['id'])['username']==='svc@example.invalid','profile by id');
    ok_repo($repo->connection($conn['id'])['server']==='dc01.example.invalid','connection by id');
    $repo->putProfile($profile);
    $repo->putConnection($conn);
    $repo->audit(['action'=>'fixture','object_id'=>$profile['id'],'actor_id'=>17,'created_at'=>10]);
    return 'done';
});
ok_repo($out==='done','transaction result returned');
ok_repo($db->begins===1 && $db->commits===1 && $db->rollbacks===0,'transaction committed');
ok_repo($db->tx===false,'transaction closed');
ok_repo(count(array_filter($db->queries,fn($q)=>str_contains($q[1],'FOR UPDATE')))===1,'configuration mutex acquired');
ok_repo(count($db->runs)===3,'profile connection audit writes executed');
foreach($db->runs as [$sql,$values]) {
    ok_repo(str_contains($sql,'?'),'writes remain parameterized');
    ok_repo(is_array($values),'write values separate from sql');
}

try { $repo->profiles(); throw new RuntimeException('outside transaction accepted'); }
catch (DomainException $e) { ok_repo($e->getMessage()==='configuration_unavailable','reads require transaction'); }

$db->failRun=true;
try {
    $repo->transaction(function() use($repo,$profile) { $repo->putProfile($profile); });
    throw new RuntimeException('database failure absent');
} catch (RuntimeException $e) {
    ok_repo($e->getMessage()==='fixture_db_failure','db failure propagated');
}
ok_repo($db->rollbacks===1 && $db->tx===false,'database failure rolled back');

try {
    $repo->transaction(function() use($repo) { $repo->transaction(fn()=>null); });
    throw new RuntimeException('nested transaction accepted');
} catch (DomainException $e) {
    ok_repo($e->getMessage()==='configuration_unavailable','nested transaction rejected');
}
ok_repo($db->rollbacks===2,'nested transaction rolls outer transaction back');

$bad=new stdClass();
try { new IntegrationDnsPhpIpamConfigRepository($bad); throw new RuntimeException('bad adapter accepted'); }
catch (DomainException $e) { ok_repo($e->getMessage()==='configuration_unavailable','adapter capability validation'); }

printf("DNS1_PHPIPAM_REPOSITORY_ASSERTIONS=%d\nDNS1_PHPIPAM_REPOSITORY_TESTS_OK=1\n",$n);
