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
require $root.'/layers/dns9/functions/classes/class.IntegrationDnsAutonomousScheduler.php';
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php';
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php';
require $root.'/tools/live/dns9-activation-mutex.php';

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
$auto=$repo->transaction(function() use($repo,$testJob,$listJob,$now) {
    $repo->audit(['action'=>'autonomous_job_queued','object_id'=>$testJob['id'],'actor_id'=>0,'created_at'=>$now]);
    $repo->audit(['action'=>'autonomous_job_queued','object_id'=>$listJob['id'],'actor_id'=>17,'created_at'=>$now]);
    return $repo->autonomousJobIds();
});
ok3sql($auto===[],'manual actor and non-zone job cannot establish autonomous origin');
$auto=$repo->transaction(function() use($repo,$listJob,$now) {
    $repo->audit(['action'=>'autonomous_job_queued','object_id'=>$listJob['id'],'actor_id'=>0,'created_at'=>$now]);
    return $repo->autonomousJobIds();
});
ok3sql($auto===[$listJob['id']=>true],'autonomous zone origin verified through audit');

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

// Exercise pressure cleanup and rollback in the isolated real MariaDB fixture.
$pdo->exec('DELETE FROM custom_dns_control_events');
$baseJob=$repo->transaction(function() use($repo,$listJob) {
    foreach($repo->jobs() as $job) if($job['id']===$listJob['id']) return $job;
    throw new RuntimeException('fixture_job_missing');
});
$repo->transaction(function() use($repo,$baseJob,$now) {
    for($i=0;$i<384;$i++) {
        $job=$baseJob;
        $job['id']=str_pad(dechex($i+1),32,'0',STR_PAD_LEFT);
        $job['state']='succeeded'; $job['result_code']='ok'; $job['result_payload']=null;
        $job['lease_id']=null; $job['lease_expires_at']=null; $job['attempt_count']=1;
        $job['created_at']=$now-8000+$i; $job['expires_at']=$job['created_at']+300;
        $job['finished_at']=$job['created_at']+1; $job['updated_at']=$job['finished_at']; $job['updated_by']=0;
        $repo->putJob($job);
        $repo->audit(['action'=>'autonomous_job_queued','object_id'=>$job['id'],'actor_id'=>0,'created_at'=>$job['created_at']]);
    }
});
$scheduler=new IntegrationDnsAutonomousScheduler($repo,static fn(): int=>$now);
$ctx=['id'=>$connectionId,'enabled'=>false,'interval_seconds'=>300,'revision'=>5,'binding_epoch'=>7,
    'credential_mode'=>'collector_identity','credential_version'=>null];
$beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM custom_dns_control_jobs')->fetchColumn();
$pdo->exec("CREATE TRIGGER dns3_fixture_fail_prune BEFORE INSERT ON custom_dns_control_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_rejection'");
try {$scheduler->run([$ctx]); throw new RuntimeException('prune_failure_missing');}
catch (PDOException $e) {ok3sql($e->getCode()==='45000','pressure audit failure propagated');}
ok3sql((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_control_jobs')->fetchColumn()===$beforeCount,'pressure cleanup rolls back on audit failure');
$pdo->exec('DROP TRIGGER dns3_fixture_fail_prune');
$trim=$scheduler->run([$ctx]);
ok3sql($trim['pressure_pruned']>0,'real database trims verified autonomous jobs');
$q=$pdo->prepare('SELECT COUNT(*) FROM custom_dns_control_jobs WHERE id=?'); $q->execute([$listJob['id']]);
ok3sql((int)$q->fetchColumn()===1,'real database retains manual job under pressure');
ok3sql((int)$pdo->query("SELECT COUNT(*) FROM custom_dns_control_events WHERE action='autonomous_job_pruned'")->fetchColumn()===$trim['pressure_pruned'],'real database audits pressure cleanup');

// The activation handshake runs only in this disposable local fixture database.
$pdo->exec('DELETE FROM custom_dns_control_jobs');
$pdo->exec('DELETE FROM custom_dns_control_events');
$pdo->exec(file_get_contents($root.'/database/dns9/001-autonomous-enable.sql'));
$pdo->exec("CREATE TABLE users (id INT PRIMARY KEY,disabled VARCHAR(8) NOT NULL,role VARCHAR(32) NOT NULL)");
$pdo->exec("INSERT INTO users VALUES (4,'No','Administrator')");
$pdo->exec('SET timestamp=2000000000');
$pilot=DNS9_ACTIVATION_CONNECTION; $pilotCollector='f57057d6677e1ca7a032aab8b68796ac';
$q=$pdo->prepare('INSERT INTO custom_dns_connections (id,provider,name,server,interval_seconds,credential_mode,profile_id,collector_id,enabled,status,revision,binding_epoch,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$q->execute([$pilot,'microsoft_dns','Activation fixture','srv-dc01.rbank.local',300,'collector_identity',null,null,0,'disabled',7,4,$now,4]);
$q=$pdo->prepare('INSERT INTO custom_dns_collectors (id,name,token_digest,revision,control_epoch,revoked,created_at,created_by,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
$q->execute([$pilotCollector,'Activation collector',str_repeat('a',64),1,1,0,$now,4,$now,4]);
$q=$pdo->prepare('INSERT INTO custom_dns_collector_assignments (connection_id,collector_id,revision,assigned_at,assigned_by) VALUES (?,?,?,?,?)');
$q->execute([$pilot,$pilotCollector,3,$now,4]);
$repo->transaction(function() use($repo,$pilot,$pilotCollector) {
    foreach ([['0f5fbf81fc71164cc7c624fc6101925f','list_zones',1793966475],['edfcd75935083e51c4536a390174057e','test_connection',1793966491]] as [$id,$kind,$created]) {
        $repo->putJob(['id'=>$id,'collector_id'=>$pilotCollector,'connection_id'=>$pilot,'assignment_revision'=>3,'job_type'=>$kind,
            'state'=>'pending','configuration_revision'=>7,'binding_epoch'=>4,'credential_version'=>null,'lease_id'=>null,
            'lease_expires_at'=>null,'attempt_count'=>0,'credential_delivered_at'=>null,'result_code'=>null,'result_payload'=>null,
            'finished_at'=>null,'created_at'=>$created,'expires_at'=>$created+300,'updated_at'=>$created,'updated_by'=>4]);
        $repo->audit(['action'=>'control_job_queued','object_id'=>$id,'actor_id'=>4,'created_at'=>$created]);
    }
});
$configRepo=new IntegrationDnsPhpIpamConfigRepository($db);
foreach(['ABORT','COMMIT_ENABLE'] as $command) {
    $dir=sys_get_temp_dir().'/dns9-mutex-mysql-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
    $input=fopen('php://temp','w+'); fwrite($input,$command."\n"); rewind($input); $output=fopen('php://temp','w+');
    try {
        if($command==='ABORT') {
            try {dns9ActivationMutex($db,$configRepo,$dir,$input,$output); throw new RuntimeException('abort_missing');}
            catch(DomainException $e) {ok3sql($e->getMessage()==='activation_cancelled','real mutex aborts');}
            $q=$pdo->prepare('SELECT enabled FROM custom_dns_connections WHERE id=?'); $q->execute([$pilot]);
            ok3sql((int)$q->fetchColumn()===0,'real transaction rollback leaves connection disabled');
        } else {
            $result=dns9ActivationMutex($db,$configRepo,$dir,$input,$output);
            ok3sql($result===['revision'=>8,'binding_epoch'=>5],'real mutex increments connection versions');
            $q=$pdo->prepare('SELECT enabled FROM custom_dns_connections WHERE id=?'); $q->execute([$pilot]);
            ok3sql((int)$q->fetchColumn()===1,'real transaction commits enabled connection');
        }
        ok3sql(is_file($dir.'/prestate.json'),'real mutex writes private prestate');
    } finally {fclose($input);fclose($output);if(is_file($dir.'/prestate.json')) unlink($dir.'/prestate.json');rmdir($dir);}
}

// Cross-adapter revoke uses the same guarded DB transaction in this disposable fixture.
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigService.php';
require $root.'/layers/dns9/functions/classes/class.IntegrationDnsAutonomousAdminService.php';
$revokeConnection=str_repeat('e',32);$revokeCollector=str_repeat('f',32);$revokeJob=str_repeat('9',32);
$q=$pdo->prepare('INSERT INTO custom_dns_connections (id,provider,name,server,interval_seconds,credential_mode,profile_id,collector_id,enabled,status,revision,binding_epoch,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$q->execute([$revokeConnection,'microsoft_dns','Synthetic fixture','dns.example.invalid',300,'collector_identity',null,null,1,'active',3,4,$now,17]);
$repo->transaction(function() use($repo,$revokeConnection,$revokeCollector,$revokeJob,$now): void {
    $repo->putCollector(['id'=>$revokeCollector,'name'=>'Synthetic collector','token_digest'=>str_repeat('a',64),
        'revision'=>2,'control_epoch'=>2,'revoked'=>false,'created_at'=>$now,'created_by'=>17,'updated_at'=>$now,'updated_by'=>17]);
    $repo->putAssignment(['connection_id'=>$revokeConnection,'collector_id'=>$revokeCollector,'revision'=>1,'assigned_at'=>$now,'assigned_by'=>17]);
    $repo->putJob(['id'=>$revokeJob,'collector_id'=>$revokeCollector,'connection_id'=>$revokeConnection,'assignment_revision'=>1,
        'job_type'=>'test_connection','state'=>'pending','configuration_revision'=>3,'binding_epoch'=>4,'credential_version'=>null,
        'lease_id'=>null,'lease_expires_at'=>null,'attempt_count'=>0,'credential_delivered_at'=>null,'result_code'=>null,'result_payload'=>null,
        'finished_at'=>null,'created_at'=>$now,'expires_at'=>$now+300,'updated_at'=>$now,'updated_by'=>17]);
});
$configService=(new ReflectionClass(IntegrationDnsConfigService::class))->newInstanceWithoutConstructor();
$admin=new IntegrationDnsAutonomousAdminService($configService,$configRepo,$service);
$readRevokeState=static function() use($pdo,$revokeConnection,$revokeCollector,$revokeJob): array {
    $rows=[];
    foreach([
        ['SELECT enabled,status,revision,binding_epoch,updated_at,updated_by FROM custom_dns_connections WHERE id=?',$revokeConnection],
        ['SELECT revoked,revision,control_epoch,updated_at,updated_by FROM custom_dns_collectors WHERE id=?',$revokeCollector],
        ['SELECT connection_id,collector_id,revision FROM custom_dns_collector_assignments WHERE connection_id=?',$revokeConnection],
        ['SELECT state,updated_at,updated_by FROM custom_dns_control_jobs WHERE id=?',$revokeJob]
    ] as [$sql,$id]) {$q=$pdo->prepare($sql);$q->execute([$id]);$rows[]=$q->fetch(PDO::FETCH_ASSOC);}
    $rows[]=(int)$pdo->query('SELECT COUNT(*) FROM custom_dns_config_events')->fetchColumn();
    $rows[]=(int)$pdo->query('SELECT COUNT(*) FROM custom_dns_control_events')->fetchColumn();
    return $rows;
};
$beforeRevoke=$readRevokeState();
$pdo->exec("CREATE TRIGGER dns3_fixture_fail_composed BEFORE UPDATE ON custom_dns_control_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_rejection'");
try {
    try {$admin->revokeCollector($revokeCollector,2,17);throw new RuntimeException('composed_failure_missing');}
    catch(PDOException $e) {ok3sql($e->getCode()==='45000','composed revoke later failure propagated');}
    ok3sql($readRevokeState()===$beforeRevoke,'composed revoke rolls back config, control and audits');
} finally {$pdo->exec('DROP TRIGGER dns3_fixture_fail_composed');}
$admin->revokeCollector($revokeCollector,2,17);
$afterRevoke=$readRevokeState();
ok3sql((int)$afterRevoke[0]['enabled']===0 && (int)$afterRevoke[0]['revision']===4 && (int)$afterRevoke[0]['binding_epoch']===5,
    'composed revoke commits disabled connection and versions');
ok3sql((int)$afterRevoke[1]['revoked']===1 && $afterRevoke[2]===false && $afterRevoke[3]['state']==='obsolete',
    'composed revoke commits collector assignment and job changes');
ok3sql($afterRevoke[4]===$beforeRevoke[4]+1 && $afterRevoke[5]===$beforeRevoke[5]+1,'composed revoke commits both audits');

echo "DNS3_MYSQL_ASSERTIONS=$n\nDNS3_MYSQL_TESTS_OK=1\n";
