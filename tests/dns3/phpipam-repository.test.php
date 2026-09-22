<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php';

$n=0;
function ok3db(bool $v,string $label): void { global $n; ++$n; if(!$v) throw new RuntimeException($label); }

final class Dns3PhpIpamDbFixture {
    public bool $tx=false,$failRun=false;
    public int $begins=0,$commits=0,$rollbacks=0;
    public array $queries=[],$runs=[];
    public array $collectors=[],$assignments=[],$jobs=[],$events=[];

    public function beginTransaction(): bool { if($this->tx) return false; $this->tx=true; ++$this->begins; return true; }
    public function commit(): bool { if(!$this->tx) return false; $this->tx=false; ++$this->commits; return true; }
    public function rollBack(): bool { if(!$this->tx) return false; $this->tx=false; ++$this->rollbacks; return true; }

    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        $this->queries[]=[$table,$sql,$values,$class];
        if (str_contains($sql,'custom_dns_config_guard')) return [(object)['id'=>1]];
        if (str_contains($sql,'custom_dns_collectors')) {
            $rows=$this->collectors;
            if (str_contains($sql,'WHERE id = ?')) $rows=array_values(array_filter($rows,fn($r)=>$r['id']===$values[0]));
            return array_map(fn($r)=>(object)$r,$rows);
        }
        if (str_contains($sql,'custom_dns_collector_assignments')) {
            $rows=$this->assignments;
            if (str_contains($sql,'WHERE connection_id = ?')) $rows=array_values(array_filter($rows,fn($r)=>$r['connection_id']===$values[0]));
            return array_map(fn($r)=>(object)$r,$rows);
        }
        if (str_contains($sql,'custom_dns_control_jobs')) return array_map(fn($r)=>(object)$r,$this->jobs);
        if (str_contains($sql,'custom_dns_control_events') && str_contains($sql,'OFFSET 9999')) {
            return count($this->events)>9999?[(object)['event_id'=>$this->events[count($this->events)-10000]['event_id']]]:[];
        }
        throw new RuntimeException('unexpected_query');
    }
    public function runQuery($sql,$values=[]): bool {
        $this->runs[]=[$sql,$values];
        if($this->failRun) throw new RuntimeException('fixture_db_failure');
        return true;
    }
}

$collector=[
    'id'=>str_repeat('a',32),'name'=>'Collector','token_digest'=>str_repeat('b',64),
    'revision'=>1,'control_epoch'=>1,'revoked'=>false,'created_at'=>10,'created_by'=>17,'updated_at'=>10,'updated_by'=>17
];
$assignment=['connection_id'=>str_repeat('c',32),'collector_id'=>$collector['id'],'revision'=>1,'assigned_at'=>10,'assigned_by'=>17];
$job=[
    'id'=>str_repeat('d',32),'collector_id'=>$collector['id'],'connection_id'=>$assignment['connection_id'],
    'assignment_revision'=>1,'job_type'=>'list_zones','state'=>'pending','configuration_revision'=>5,'binding_epoch'=>7,
    'credential_version'=>3,'lease_id'=>null,'lease_expires_at'=>null,'attempt_count'=>0,'credential_delivered_at'=>null,
    'result_code'=>null,'result_payload'=>null,'finished_at'=>null,
    'created_at'=>10,'expires_at'=>310,'updated_at'=>10,'updated_by'=>17
];

$db=new Dns3PhpIpamDbFixture();
$db->collectors=[$collector]; $db->assignments=[$assignment]; $db->jobs=[$job];
$repo=new IntegrationDnsCollectorControlPhpIpamRepository($db);

$result=$repo->transaction(function() use($repo,$collector,$assignment,$job) {
    ok3db($repo->collectors()[0]['revoked']===false,'collector boolean normalized');
    ok3db($repo->collector($collector['id'])['control_epoch']===1,'collector lookup normalized');
    ok3db($repo->assignments()[0]['revision']===1,'assignment normalized');
    ok3db($repo->assignment($assignment['connection_id'])['collector_id']===$collector['id'],'assignment lookup');
    ok3db($repo->jobs()[0]['credential_version']===3,'job normalized');
    $repo->putCollector($collector);
    $repo->putAssignment($assignment);
    $repo->putJob($job);
    $repo->audit(['action'=>'fixture','object_id'=>$job['id'],'actor_id'=>17,'created_at'=>10]);
    return 'ok';
});
ok3db($result==='ok','transaction result');
ok3db($db->begins===1 && $db->commits===1 && $db->rollbacks===0,'transaction committed');
ok3db(count(array_filter($db->queries,fn($q)=>str_contains($q[1],'custom_dns_config_guard')&&str_contains($q[1],'FOR UPDATE')))===1,'shared DNS config mutex used');
ok3db(count($db->runs)===4,'three upserts and audit insert');
foreach($db->runs as [$sql,$values]) {
    ok3db(str_contains($sql,'?'),'write parameterized');
    ok3db(is_array($values),'values separated');
}

try { $repo->jobs(); throw new RuntimeException('outside_transaction_accepted'); }
catch (DomainException $e) { ok3db($e->getMessage()==='control_unavailable','outside transaction denied'); }

$db->failRun=true;
try {
    $repo->transaction(function() use($repo,$collector) { $repo->putCollector($collector); });
    throw new RuntimeException('failure_missing');
} catch (RuntimeException $e) {
    ok3db($e->getMessage()==='fixture_db_failure','db error propagated');
}
ok3db($db->rollbacks===1 && $db->tx===false,'failed write rolled back');

try {
    $repo->transaction(function() use($repo) { $repo->transaction(fn()=>null); });
    throw new RuntimeException('nested_accepted');
} catch (DomainException $e) {
    ok3db($e->getMessage()==='control_unavailable','nested transaction denied');
}
ok3db($db->rollbacks===2,'nested failure rolls outer back');

try { new IntegrationDnsCollectorControlPhpIpamRepository(new stdClass()); throw new RuntimeException('bad_adapter_accepted'); }
catch (DomainException $e) { ok3db($e->getMessage()==='control_unavailable','adapter capabilities required'); }

printf("DNS3_PHPIPAM_REPOSITORY_ASSERTIONS=%d\nDNS3_PHPIPAM_REPOSITORY_TESTS_OK=1\n",$n);
