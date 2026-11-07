<?php
declare(strict_types=1);
/** Opt-in destructive fixture ONLY in an empty localhost database named phpipam_dns1_test. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$dsn=getenv('DNS1_TEST_DSN');
if (!$dsn || !in_array('mysql',PDO::getAvailableDrivers(),true)) { echo "DNS1_MYSQL_NOT_RUN=1\n"; exit(77); }
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=phpipam_dns1_test;charset=utf8mb4$/D',$dsn)) throw new RuntimeException('local_test_database_required');
$root=dirname(__DIR__,2);
foreach(['IntegrationDnsCipher','IntegrationDnsConfigRepository','IntegrationDnsPdoConfigRepository','IntegrationDnsConfigService'] as $name) require "$root/layers/dns1/functions/classes/class.$name.php";
require "$root/layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php";
function db(string $dsn): PDO { return new PDO($dsn,getenv('DNS1_TEST_USER'),getenv('DNS1_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
final class Dns1LazyDatabasePdoFixture {
    public ?PDO $pdo=null;
    public int $connections=0;
    public function __construct(private string $dsn) {}
    public function isConnected(): bool {return $this->pdo!==null;}
    public function connect(): void {$this->pdo=db($this->dsn);++$this->connections;}
    public function beginTransaction(): bool {return $this->pdo->beginTransaction();}
    public function commit(): bool {return $this->pdo->commit();}
    public function rollBack(): bool {return $this->pdo->inTransaction() ? $this->pdo->rollBack() : false;}
    public function getObjectsQuery($table,$sql=null,$values=[],$class='stdClass'): array {
        $q=$this->pdo->prepare($sql);$q->execute((array)$values);return $q->fetchAll(PDO::FETCH_OBJ);
    }
    public function runQuery($sql,$values=[]): bool {$q=$this->pdo->prepare($sql);return $q->execute((array)$values);}
}
$pdo=db($dsn);
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='phpipam_dns1_test' || $pdo->query('SHOW TABLES')->fetchAll()) throw new RuntimeException('empty_test_database_required');
// DDL is a separate provisioning step, never part of a service transaction.
$pdo->exec(file_get_contents($root.'/database/dns1/001-configuration.sql'));
$n=0;
function ok(bool $v,string $label): void { global $n; ++$n; if (!$v) throw new RuntimeException($label); }
$lazyDb=new Dns1LazyDatabasePdoFixture($dsn);
$lazyRepo=new IntegrationDnsPhpIpamConfigRepository($lazyDb);
ok($lazyRepo->transaction(static fn()=>17)===17 && $lazyDb->connections===1 && !$lazyDb->pdo->inTransaction(),
    'fresh lazy phpIPAM connection opens before the transaction');
$cipher=new IntegrationDnsCipher('test',['test'=>random_bytes(32)]); $repo=new IntegrationDnsPdoConfigRepository($pdo);
$service=new IntegrationDnsConfigService($repo,$cipher);
$input=['id'=>null,'expected_revision'=>0,'name'=>'DB profile','username'=>'svc@example.invalid','password_action'=>'replace','password'=>'Fictional password A','acknowledge_connections'=>[]];
$p=$service->saveProfile($input,1);
$ci=['id'=>null,'expected_revision'=>0,'name'=>'DB connection','server'=>'dc01.example.invalid','interval_seconds'=>300,'credential_mode'=>'profile','profile_id'=>$p['id'],'share_profile'=>false];
$c=$service->saveConnection($ci,1); $ci['server']='dc02.example.invalid'; $ci['share_profile']=true; $c2=$service->saveConnection($ci,1);
ok(count($service->snapshot()['connections'])===2,'persist/read connections');
$q=$pdo->prepare('SELECT secret_envelope FROM custom_dns_credential_profiles WHERE id=?'); $q->execute([$p['id']]); $before=$q->fetchColumn();
ok(!str_contains($before,'Fictional password A'),'database encrypted only');
$input['id']=$p['id']; $input['expected_revision']=1; $input['password_action']='unchanged'; $input['password']='';
$p=$service->saveProfile($input,1); $q->execute([$p['id']]); ok($q->fetchColumn()===$before,'database unchanged envelope');
$input['expected_revision']=2; $input['password_action']='replace'; $input['password']='Fictional password B'; $input['acknowledge_connections']=[$c['id'],$c2['id']];
$pdo->exec("CREATE TRIGGER dns1_fixture_fail BEFORE UPDATE ON custom_dns_connections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture_rejection'");
try { $service->saveProfile($input,1); throw new RuntimeException('failure injection absent'); }
catch (PDOException $e) { ok($e->getCode()==='45000','consumer update rejected by fixture trigger'); }
$q->execute([$p['id']]); ok($q->fetchColumn()===$before,'real transaction rolls back profile rotation');
ok($service->snapshot()['profiles'][0]['revision']===2,'profile revision rolls back');
foreach($service->snapshot()['connections'] as $r) ok($r['binding_epoch']===1,'dependent epochs roll back');
$pdo->exec('DROP TRIGGER dns1_fixture_fail');
$p=$service->saveProfile($input,1); ok($p['secret_version']===2,'database rotation works');
foreach($service->snapshot()['connections'] as $r) ok($r['binding_epoch']===2 && $r['enabled']===false,'real consumers invalidated');
$other=db($dsn); $other->exec('SET SESSION innodb_lock_wait_timeout=1'); $otherRepo=new IntegrationDnsPdoConfigRepository($other);
$repo->transaction(function() use($otherRepo) {
    try { $otherRepo->transaction(fn()=>null); throw new RuntimeException('configuration mutex absent'); }
    catch (PDOException $e) { ok(($e->errorInfo[1]??null)===1205,'second connection blocked by InnoDB mutex'); }
});
ok(!$other->inTransaction(),'timed-out transaction rolled back');
$repo->transaction(function() use($repo) { for($i=0;$i<1005;++$i) $repo->audit(['action'=>'fixture','object_id'=>str_repeat('a',32),'actor_id'=>1,'created_at'=>1]); });
ok((int)$pdo->query('SELECT COUNT(*) FROM custom_dns_config_events')->fetchColumn()===1000,'real audit retention bounded');
try { $service->saveProfile($input,1); throw new RuntimeException('stale edit accepted'); }
catch (DomainException $e) { ok($e->getMessage()==='revision_conflict','real stale edit rejected'); }
echo "DNS1_MYSQL_ASSERTIONS=$n\nDNS1_MYSQL_TESTS_OK=1\n";
