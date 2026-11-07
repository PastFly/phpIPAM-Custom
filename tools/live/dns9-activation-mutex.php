<?php
declare(strict_types=1);

const DNS9_ACTIVATION_CONNECTION='33b6fade9323f15aa7b1f56a8a865a9d';
const DNS9_ACTIVATION_PENDING_IDS=['0f5fbf81fc71164cc7c624fc6101925f','edfcd75935083e51c4536a390174057e'];

/** Hold the existing DNS configuration mutex through the activation handshake. */
function dns9ActivationMutex(object $db,IntegrationDnsConfigRepository $repo,string $directory,$input,$output): array {
    return $repo->transaction(function() use($db,$repo,$directory,$input,$output): array {
        $sql=file_get_contents(__DIR__.'/dns9-activation-queue.sql');
        if (!is_string($sql)) throw new RuntimeException('queue_policy_unavailable');
        $summary=$db->getObjectsQuery('no_html_escape',$sql,[]);
        if (!is_array($summary) || count($summary)!==1 || (string)($summary[0]->queue_state??'')!=='2|0|0|0|0|2') {
            throw new DomainException('queue_or_retention_requires_review');
        }
        $actor=$db->getObjectsQuery('no_html_escape',"SELECT id FROM users WHERE id=4 AND disabled='No' AND role='Administrator'",[]);
        if (!is_array($actor) || count($actor)!==1 || (int)($actor[0]->id??0)!==4) throw new DomainException('actor_changed');
        $jobs=$db->getObjectsQuery('no_html_escape',
            'SELECT * FROM custom_dns_control_jobs WHERE id IN (?,?) ORDER BY id FOR UPDATE',DNS9_ACTIVATION_PENDING_IDS);
        $ids=is_array($jobs)?array_map(static fn($row)=>(string)($row->id??''),$jobs):[]; sort($ids);
        $expected=DNS9_ACTIVATION_PENDING_IDS; sort($expected);
        if (count($ids)!==2 || $ids!==$expected) throw new DomainException('queue_or_retention_requires_review');
        $events=$db->getObjectsQuery('no_html_escape',
            'SELECT event_id,action,object_id,actor_id,created_at FROM custom_dns_control_events WHERE object_id IN (?,?) ORDER BY event_id LIMIT 101',DNS9_ACTIVATION_PENDING_IDS);
        if (!is_array($events) || count($events)>100) throw new DomainException('audit_backup_invalid');
        $connection=$repo->connection(DNS9_ACTIVATION_CONNECTION);
        if (!is_array($connection) || ($connection['enabled']??null)!==false || ($connection['server']??null)!=='srv-dc01.rbank.local' ||
            ($connection['credential_mode']??null)!=='collector_identity' || ($connection['interval_seconds']??null)!==300 ||
            !is_int($connection['revision']??null) || $connection['revision']<1 || $connection['revision']>=2147483647 ||
            !is_int($connection['binding_epoch']??null) || $connection['binding_epoch']<1 || $connection['binding_epoch']>=2147483647) {
            throw new DomainException('connection_state_changed');
        }
        $data=json_encode(['connection'=>$connection,'jobs'=>$jobs,'events'=>$events],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
        $path=$directory.'/prestate.json';
        $file=fopen($path,'xb');
        if ($file===false) throw new RuntimeException('backup_write_failed');
        try {
            if (!chmod($path,0600) || fwrite($file,$data)!==strlen($data) || !fflush($file) || (function_exists('fsync') && !fsync($file))) {
                throw new RuntimeException('backup_write_failed');
            }
        } finally {fclose($file);}
        $digest=hash_file('sha256',$path);
        if (!is_string($digest) || !hash_equals(hash('sha256',$data),$digest)) throw new RuntimeException('backup_verify_failed');
        fwrite($output,'DNS9_ACTIVATION_MUTEX_READY='.$digest."\n"); fflush($output);
        $command=fgets($input);
        if (!is_string($command) || trim($command)!=='COMMIT_ENABLE') throw new DomainException('activation_cancelled');
        $connection['revision']++;
        $connection['binding_epoch']++;
        $connection['enabled']=true;
        $connection['status']='active';
        $connection['updated_at']=time();
        $connection['updated_by']=4;
        $repo->putConnection($connection);
        $repo->audit(['action'=>'collection_enabled','object_id'=>DNS9_ACTIVATION_CONNECTION,'actor_id'=>4,'created_at'=>time()]);
        return ['revision'=>$connection['revision'],'binding_epoch'=>$connection['binding_epoch']];
    });
}

$direct=isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__;
if ($direct) {
    if (PHP_SAPI!=='cli' || !function_exists('posix_geteuid') || posix_geteuid()!==0 || count($argv)!==2 ||
        !preg_match('#^/var/backups/phpipam-custom/dns9-activation-[0-9a-f]{16}$#D',$argv[1])) exit(2);
    try {
        $dir=$argv[1]; $st=lstat($dir);
        if ($st===false || is_link($dir) || !is_dir($dir) || $st['uid']!==0 || $st['gid']!==0 || ($st['mode']&077)!==0) throw new RuntimeException('backup_directory_invalid');
        require_once '/var/www/html/functions/functions.php';
        require_once '/var/www/html/functions/classes/class.IntegrationDnsConfigRepository.php';
        require_once '/var/www/html/functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php';
        $db=new Database_PDO();
        $result=dns9ActivationMutex($db,new IntegrationDnsPhpIpamConfigRepository($db),$dir,STDIN,STDOUT);
        echo 'DNS9_ACTIVATION_MUTEX_COMMITTED='.$result['revision'].'|'.$result['binding_epoch']."\n";
    } catch(Throwable $e) {
        $allowed=['queue_or_retention_requires_review','actor_changed','audit_backup_invalid','connection_state_changed',
            'backup_directory_invalid','backup_write_failed','backup_verify_failed','activation_cancelled','queue_policy_unavailable'];
        $code=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'mutex_failed_requires_review';
        fwrite(STDERR,'DNS9_ACTIVATION_MUTEX_ERROR='.$code."\n"); exit(1);
    }
}
