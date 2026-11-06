<?php
declare(strict_types=1);

/** Opt-in destructive fixture ONLY in an empty localhost database named phpipam_dns3_test. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }

$dsn=getenv('DNS3_TEST_DSN');
if (!$dsn || !in_array('mysql',PDO::getAvailableDrivers(),true)) {
    echo "DNS3_MYSQL_NOT_RUN=1\n";
    exit(77);
}
if (!preg_match('/^mysql:host=127\\.0\\.0\\.1;port=[0-9]{1,5};dbname=phpipam_dns3_test;charset=utf8mb4$/D',$dsn)) {
    throw new RuntimeException('local_test_database_required');
}

$root=dirname(__DIR__,2);
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlService.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php';
require $root.'/layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php';

function dns3pdo(string $dsn): PDO {
    return new PDO(
        $dsn,
        getenv('DNS3_TEST_USER'),
        getenv('DNS3_TEST_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

final class Dns3DatabasePdoFixture
{
    public function __construct(public PDO $pdo) {}
    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->inTransaction() ? $this->pdo->rollBack() : false; }
    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        $q=$this->pdo->prepare($sql); $q->execute((array)$values);
        return $q->fetchAll(PDO::FETCH_OBJ);
    }
    public function runQuery($sql,$values=[]): bool {
        $q=$this->pdo->prepare($sql);
        return $q->execute((array)$values);
    }
}

final class Dns3LazyDatabasePdoFixture
{
    public ?PDO $pdo=null;
    public int $connections=0;
    public function __construct(private string $dsn) {}
    public function isConnected(): bool {return $this->pdo!==null;}
    public function connect(): void {$this->pdo=dns3pdo($this->dsn);++$this->connections;}
    public function beginTransaction(): bool {return $this->pdo->beginTransaction();}
    public function commit(): bool {return $this->pdo->commit();}
    public function rollBack(): bool {return $this->pdo->inTransaction() ? $this->pdo->rollBack() : false;}
    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        $q=$this->pdo->prepare($sql);$q->execute((array)$values);return $q->fetchAll(PDO::FETCH_OBJ);
    }
    public function runQuery($sql,$values=[]): bool {$q=$this->pdo->prepare($sql);return $q->execute((array)$values);}
}

$n=0;
function ok3sql(bool $v,string $label): void {
    global $n; ++$n;
    if(!$v) throw new RuntimeException($label);
}
function reject3sql(callable $fn,string $code): void {
    try { $fn(); }
    catch (DomainException $e) {
        ok3sql($e->getMessage()===$code,'reject '.$code);
        return;
    }
    throw new RuntimeException('expected '.$code);
}

$pdo=dns3pdo($dsn);
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='phpipam_dns3_test' || $pdo->query('SHOW TABLES')->fetchAll()) {
    throw new RuntimeException('empty_test_database_required');
}

$pdo->exec(file_get_contents($root.'/database/dns1/001-configuration.sql'));
$pdo->exec(file_get_contents($root.'/database/dns3/001-collector-control.sql'));
$pdo->exec(file_get_contents($root.'/database/dns4/001-machine-job-lifecycle.sql'));
$pdo->exec(file_get_contents($root.'/database/dns6/001-credential-delivery-marker.sql'));

$lazyDb=new Dns3LazyDatabasePdoFixture($dsn);
$lazyRepo=new IntegrationDnsCollectorControlPhpIpamRepository($lazyDb);
$machine=new IntegrationDnsCollectorMachineService($lazyRepo,static fn($id)=>null);
$fakeToken='dnsctl1.'.str_repeat('0',32).'.'.str_repeat('A',43);
reject3sql(fn()=> $machine->poll($fakeToken),'collector_auth_failed');
ok3sql($lazyDb->connections===1 && $lazyDb->isConnected() && !$lazyDb->pdo->inTransaction(),
    'fresh lazy connection starts and rolls back the authentication transaction');
ok3sql((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_control_jobs')->fetchColumn()===0,
    'fake-token poll does not mutate jobs');

$profileId=str_repeat('a',32);
$connectionId=str_repeat('b',32);
$now=1700000000;

$q=$pdo->prepare(
    'INSERT INTO custom_dns_credential_profiles '.
    '(id,name,username,secret_envelope,secret_version,revision,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?)'
);
$q->execute([$profileId,'Fixture profile','svc@example.invalid','fixture-ciphertext',3,1,$now,17]);

$q=$pdo->prepare(
    'INSERT INTO custom_dns_connections '.
    '(id,provider,name,server,interval_seconds,credential_mode,profile_id,collector_id,enabled,status,revision,binding_epoch,updated_at,updated_by) '.
    'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
$q->execute([
    $connectionId,'microsoft_dns','Fixture DNS','dc01.example.invalid',300,'profile',
    $profileId,null,0,'not_configured',5,7,$now,17
]);

$resolver=static function(string $id) use($pdo): ?array {
    $q=$pdo->prepare(
        'SELECT c.id,c.revision,c.binding_epoch,c.credential_mode,p.secret_version AS credential_version '.
        'FROM custom_dns_connections c LEFT JOIN custom_dns_credential_profiles p ON p.id=c.profile_id WHERE c.id=?'
    );
    $q->execute([$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return [
        'id'=>$row['id'],
        'revision'=>(int)$row['revision'],
        'binding_epoch'=>(int)$row['binding_epoch'],
        'credential_mode'=>$row['credential_mode'],
        'credential_version'=>$row['credential_version']===null?null:(int)$row['credential_version']
    ];
};

$db=new Dns3DatabasePdoFixture($pdo);
$repo=new IntegrationDnsCollectorControlPhpIpamRepository($db);
$clock=$now;
$service=new IntegrationDnsCollectorControlService(
    $repo,
    $resolver,
    static function() use (&$clock): int { return $clock; }
);

$registration=$service->registerCollector(['name'=>'Fixture collector'],17);
$collectorId=$registration['collector']['id'];
$token=$registration['control_token'];
ok3sql($service->authenticateCollectorToken($token)['id']===$collectorId,'persisted token authenticates');

$q=$pdo->prepare('SELECT token_digest FROM custom_dns_collectors WHERE id=?');
$q->execute([$collectorId]);
$digest=$q->fetchColumn();
ok3sql(is_string($digest) && strlen($digest)===64 && !str_contains($digest,$token),'database stores digest only');

$assignment=$service->saveAssignment([
    'connection_id'=>$connectionId,'collector_id'=>$collectorId,'expected_revision'=>0
],17);
ok3sql($assignment['revision']===1,'assignment persisted');

$testJob=$service->queueJob($connectionId,'test_connection',17);
$listJob=$service->queueJob($connectionId,'list_zones',17);
ok3sql($testJob['configuration_revision']===5 && $testJob['binding_epoch']===7 && $testJob['credential_version']===3,'job versions persisted');
ok3sql((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_control_jobs')->fetchColumn()===2,'two jobs persisted');

$q=$pdo->prepare("UPDATE custom_dns_control_jobs SET state='running' WHERE id=?");
$q->execute([$testJob['id']]);
reject3sql(fn()=> $service->queueJob($connectionId,'test_connection',17),'job_already_pending');
$q=$pdo->prepare("UPDATE custom_dns_control_jobs SET state='pending' WHERE id=?");
$q->execute([$testJob['id']]);

$other=dns3pdo($dsn);
$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$otherRepo=new IntegrationDnsCollectorControlPhpIpamRepository(new Dns3DatabasePdoFixture($other));
$repo->transaction(function() use($otherRepo) {
    try {
        $otherRepo->transaction(fn()=>null);
        throw new RuntimeException('mutex_missing');
    } catch (PDOException $e) {
        ok3sql(($e->errorInfo[1]??null)===1205,'second connection blocked by shared DNS mutex');
    }
});
ok3sql(!$other->inTransaction(),'timed-out transaction rolled back');

$pdo->exec(
    "CREATE TRIGGER dns3_fixture_fail BEFORE UPDATE ON custom_dns_control_jobs ".
    "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_rejection'"
);
try {
    $service->revokeCollector($collectorId,1,17);
    throw new RuntimeException('failure_injection_absent');
} catch (PDOException $e) {
    ok3sql($e->getCode()==='45000','job-update trigger rejects revoke');
}
$q=$pdo->prepare('SELECT revoked,revision,control_epoch FROM custom_dns_collectors WHERE id=?');
$q->execute([$collectorId]);
$row=$q->fetch(PDO::FETCH_ASSOC);
ok3sql((int)$row['revoked']===0 && (int)$row['revision']===1 && (int)$row['control_epoch']===1,'collector revoke rolled back');
ok3sql((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_collector_assignments')->fetchColumn()===1,'assignment delete rolled back');
ok3sql((int)$pdo->query("SELECT COUNT(*) FROM custom_dns_control_jobs WHERE state IN ('pending','running')")->fetchColumn()===2,'job states rolled back');

$pdo->exec('DROP TRIGGER dns3_fixture_fail');
$service->revokeCollector($collectorId,1,17);
ok3sql((int)$pdo->query('SELECT revoked FROM custom_dns_collectors')->fetchColumn()===1,'collector revoke persists');
ok3sql((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_collector_assignments')->fetchColumn()===0,'assignments removed');
ok3sql((int)$pdo->query("SELECT COUNT(*) FROM custom_dns_control_jobs WHERE state='obsolete'")->fetchColumn()===2,'jobs obsoleted');
reject3sql(fn()=> $service->authenticateCollectorToken($token),'collector_auth_failed');

$pdo->exec('TRUNCATE TABLE custom_dns_control_events');
$batchSize=500;
$total=10005;
$inserted=0;
while ($inserted<$total) {
    $count=min($batchSize,$total-$inserted);
    $values=[];
    $params=[];
    for($i=0;$i<$count;++$i) {
        $values[]='(?,?,?,?)';
        $params[]='fixture';
        $params[]=str_repeat('f',32);
        $params[]=17;
        $params[]=$now;
    }
    $q=$pdo->prepare(
        'INSERT INTO custom_dns_control_events (action,object_id,actor_id,created_at) VALUES '.implode(',',$values)
    );
    $q->execute($params);
    $inserted+=$count;
}
$repo->transaction(function() use($repo,$now) {
    $repo->audit(['action'=>'fixture','object_id'=>str_repeat('e',32),'actor_id'=>17,'created_at'=>$now]);
});
ok3sql((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_control_events')->fetchColumn()===10000,'security audit retention bounded');

echo "DNS3_MYSQL_ASSERTIONS=$n\nDNS3_MYSQL_TESTS_OK=1\n";
