<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli') {
    fwrite(STDERR,"dns9_cli_only\n");
    exit(2);
}

require_once(dirname(__FILE__).'/../functions.php');
require_once(dirname(__FILE__).'/../classes/class.IntegrationDnsConfigRepository.php');
require_once(dirname(__FILE__).'/../classes/class.IntegrationDnsPhpIpamConfigRepository.php');
require_once(dirname(__FILE__).'/../classes/class.IntegrationDnsCollectorControlRepository.php');
require_once(dirname(__FILE__).'/../classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php');
require_once(dirname(__FILE__).'/../classes/class.IntegrationDnsAutonomousScheduler.php');

$Database=new Database_PDO;
$configRepo=new IntegrationDnsPhpIpamConfigRepository($Database);
$controlRepo=new IntegrationDnsCollectorControlPhpIpamRepository($Database);

try {
    $snapshot=$configRepo->transaction(static function() use($configRepo) {
        return [
            'connections'=>$configRepo->connections(),
            'profiles'=>$configRepo->profiles()
        ];
    });

    $profileVersions=[];
    foreach($snapshot['profiles'] as $profile) {
        $profileVersions[$profile['id']]=$profile['secret_version'];
    }

    $contexts=[];
    foreach($snapshot['connections'] as $connection) {
        $credentialVersion=null;
        if($connection['credential_mode']==='profile') {
            $profileId=$connection['profile_id'];
            if(!is_string($profileId)||!array_key_exists($profileId,$profileVersions)) {
                continue;
            }
            $credentialVersion=(int)$profileVersions[$profileId];
        }
        $contexts[]=[
            'id'=>$connection['id'],
            'enabled'=>(bool)$connection['enabled'],
            'interval_seconds'=>(int)$connection['interval_seconds'],
            'revision'=>(int)$connection['revision'],
            'binding_epoch'=>(int)$connection['binding_epoch'],
            'credential_mode'=>$connection['credential_mode'],
            'credential_version'=>$credentialVersion
        ];
    }

    $scheduler=new IntegrationDnsAutonomousScheduler($controlRepo);
    $result=$scheduler->run($contexts);
    echo json_encode(
        ['ok'=>$result['skipped_capacity']===0,'data'=>$result],
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
    ).PHP_EOL;
    if ($result['skipped_capacity']>0) exit(1);
} catch(Throwable $e) {
    echo '{"ok":false,"error":"scheduler_unavailable"}'.PHP_EOL;
    exit(1);
}
