<?php
declare(strict_types=1);

/** One narrowly scoped CLI queue operation using the existing authenticated actor's audit record. */
const DNS9_OLD_JOB='3bf6ee81197e2be7ff5452bc0b4b84c7';
const DNS9_CONNECTION='33b6fade9323f15aa7b1f56a8a865a9d';
const DNS9_COLLECTOR='f57057d6677e1ca7a032aab8b68796ac';

$dns9Direct=isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__;
if ($dns9Direct) {
    if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0 ||
        !isset($argv[1]) || !in_array($argv[1],['--preflight','--queue'],true) || count($argv)!==2) {
        fwrite(STDERR,"DNS9_CLI_ERROR=invocation_invalid\n"); exit(1);
    }
    $web='/var/www/html';
    require_once $web.'/functions/functions.php';
    foreach (['IntegrationDnsConfigRepository','IntegrationDnsPhpIpamConfigRepository',
        'IntegrationDnsCollectorControlRepository','IntegrationDnsCollectorControlPhpIpamRepository',
        'IntegrationDnsCollectorControlService'] as $class) {
        require_once $web.'/functions/classes/class.'.$class.'.php';
    }
}

final class Dns9CliAuditRepository implements IntegrationDnsCollectorControlRepository
{
    public function __construct(private IntegrationDnsCollectorControlRepository $inner) {}
    public function transaction(callable $work): mixed {return $this->inner->transaction($work);}
    public function collectors(): array {return $this->inner->collectors();}
    public function collector(string $id): ?array {return $this->inner->collector($id);}
    public function putCollector(array $row): void {$this->inner->putCollector($row);}
    public function assignments(): array {return $this->inner->assignments();}
    public function assignment(string $id): ?array {
        $row=$this->inner->assignment($id);
        if ($row!==null && ($id!==DNS9_CONNECTION || ($row['collector_id']??null)!==DNS9_COLLECTOR)) {
            throw new DomainException('assignment_unexpected');
        }
        return $row;
    }
    public function putAssignment(array $row): void {$this->inner->putAssignment($row);}
    public function removeAssignment(string $id): void {$this->inner->removeAssignment($id);}
    public function jobs(): array {return $this->inner->jobs();}
    public function putJob(array $row): void {$this->inner->putJob($row);}
    public function removeJob(string $id): void {$this->inner->removeJob($id);}
    public function audit(array $event): void {
        $this->inner->audit($event);
        if (($event['action']??null)==='control_job_queued') {
            $event['action']='control_job_cli_queued';
            $this->inner->audit($event);
        }
    }
}

function dns9PreviousActor(object $db): int {
    $rows=$db->getObjectsQuery('no_html_escape',
        "SELECT e.actor_id FROM custom_dns_control_events e ".
        "JOIN custom_dns_control_jobs j ON j.id=e.object_id AND j.updated_by=e.actor_id ".
        "JOIN users u ON u.id=e.actor_id ".
        "WHERE e.action='control_job_queued' AND j.id=? AND j.connection_id=? AND j.collector_id=? ".
        "AND j.job_type='test_connection' AND j.state='pending' AND j.attempt_count=0 ".
        "AND j.lease_id IS NULL AND j.credential_delivered_at IS NULL AND j.expires_at<UNIX_TIMESTAMP() ".
        "AND u.disabled='No' AND u.role='Administrator'",
        [DNS9_OLD_JOB,DNS9_CONNECTION,DNS9_COLLECTOR]);
    if (!is_array($rows) || count($rows)!==1) throw new DomainException('previous_actor_unverified');
    $row=$rows[0]; $value=is_object($row)?($row->actor_id??null):($row['actor_id']??null);
    if ((!is_int($value) && !(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value))) || (int)$value<1) {
        throw new DomainException('previous_actor_unverified');
    }
    return (int)$value;
}

function dns9QueueOnce(object $db, bool $apply): array {
    $actor=dns9PreviousActor($db);
    if (!$apply) return ['actor_id'=>$actor];
    $configRepo=new IntegrationDnsPhpIpamConfigRepository($db);
    $resolver=static function(string $id) use ($configRepo): ?array {
        return $configRepo->transaction(static function() use ($configRepo,$id): ?array {
            $c=$configRepo->connection($id);
            if ($c===null || ($c['credential_mode']??null)!=='collector_identity' ||
                ($c['server']??null)!=='srv-dc01.rbank.local' || ($c['enabled']??null)!==false) return null;
            return [
                'id'=>$c['id'],'revision'=>(int)$c['revision'],'binding_epoch'=>(int)$c['binding_epoch'],
                'credential_mode'=>'collector_identity','profile_id'=>null,'credential_version'=>null,'enabled'=>false
            ];
        });
    };
    $repo=new Dns9CliAuditRepository(new IntegrationDnsCollectorControlPhpIpamRepository($db));
    $service=new IntegrationDnsCollectorControlService($repo,$resolver);
    $job=$service->queueJob(DNS9_CONNECTION,'test_connection',$actor);
    if (($job['collector_id']??null)!==DNS9_COLLECTOR || !preg_match('/^[0-9a-f]{32}$/D',(string)($job['id']??''))) {
        throw new DomainException('queued_job_identity_unexpected');
    }
    return ['actor_id'=>$actor,'job_id'=>$job['id']];
}

if ($dns9Direct) {
    try {
        $result=dns9QueueOnce(new Database_PDO(),$argv[1]==='--queue');
        echo 'DNS9_CLI_JSON='.json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    } catch (Throwable $e) {
        fwrite(STDERR,"DNS9_CLI_ERROR=preflight_or_queue_failed\n"); exit(1);
    }
}
