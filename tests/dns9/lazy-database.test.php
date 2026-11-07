<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsConfigRepository.php';
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlRepository.php';
require $root.'/layers/dns3/functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php';
require $root.'/layers/dns4/functions/classes/class.IntegrationDnsCollectorMachineService.php';
require $root.'/layers/dns5/functions/classes/class.IntegrationDnsCollectorMachineHttpController.php';
function dns9LazyAssert(bool $value,string $message): void {if(!$value) throw new RuntimeException($message);}
final class Dns9FakePdo {
    public bool $tx=false;
    public function beginTransaction(): bool {if($this->tx) return false;$this->tx=true;return true;}
    public function commit(): bool {if(!$this->tx) return false;$this->tx=false;return true;}
    public function rollBack(): bool {if(!$this->tx) return false;$this->tx=false;return true;}
}
final class Dns9LazyPhpIpamDb {
    public ?Dns9FakePdo $pdo=null;
    public int $connections=0;
    public bool $failConnect=false;
    public function isConnected(): bool {return $this->pdo!==null;}
    public function connect(): void {if($this->failConnect) throw new RuntimeException('connect_failed');$this->pdo=new Dns9FakePdo();++$this->connections;}
    public function beginTransaction(): bool {return $this->pdo->beginTransaction();}
    public function commit(): bool {return $this->pdo->commit();}
    public function rollBack(): bool {return $this->pdo->rollBack();}
    public function getObjectsQuery($table,$query=null,$values=[],$class='stdClass'): array {
        if(str_contains($query,'custom_dns_config_guard'))return [(object)['id'=>1]];
        if(str_contains($query,'custom_dns_collectors'))return [];
        throw new RuntimeException('unexpected_query');
    }
    public function runQuery($query,$values=[]): bool {throw new RuntimeException('unexpected_write');}
}
foreach(['config','control'] as $type) {
    $db=new Dns9LazyPhpIpamDb();
    $repo=$type==='config' ? new IntegrationDnsPhpIpamConfigRepository($db) : new IntegrationDnsCollectorControlPhpIpamRepository($db);
    dns9LazyAssert($repo->transaction(static fn()=>17)===17,'fresh connection transaction');
    dns9LazyAssert($db->connections===1 && !$db->pdo->tx,'fresh connection opened once and committed');
    dns9LazyAssert($repo->transaction(static fn()=>23)===23 && $db->connections===1,'connected object reused');
    $db=new Dns9LazyPhpIpamDb(); $db->failConnect=true;
    $repo=$type==='config' ? new IntegrationDnsPhpIpamConfigRepository($db) : new IntegrationDnsCollectorControlPhpIpamRepository($db);
    try {$repo->transaction(static fn()=>null);throw new RuntimeException('connect_failure_not_propagated');}
    catch(RuntimeException $e){dns9LazyAssert($e->getMessage()==='connect_failed','connect failure propagated');}
}
$db=new Dns9LazyPhpIpamDb();
$controller=new IntegrationDnsCollectorMachineHttpController(static function() use($db){
    $repo=new IntegrationDnsCollectorControlPhpIpamRepository($db);
    return new IntegrationDnsCollectorMachineService($repo,static fn($id)=>null);
});
$fake='dnsctl1.'.str_repeat('0',32).'.'.str_repeat('A',43);
$response=$controller->handle(['method'=>'POST','https'=>true,'content_type'=>'application/json',
    'authorization'=>'Bearer '.$fake,'raw_body'=>'{"action":"poll"}']);
dns9LazyAssert($response['status']===401 && $response['body']['error']==='collector_auth_failed',
    'fresh connection reaches authentication and rejects fake token');
dns9LazyAssert($db->connections===1 && !$db->pdo->tx,'negative authentication rolled back');
echo "DNS9_LAZY_DATABASE_TEST_OK=1\n";
