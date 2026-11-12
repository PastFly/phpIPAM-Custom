<?php
declare(strict_types=1);

/**
 * DNS1 admin panel fragment. Included only from an authenticated phpIPAM admin page.
 * Missing schema/key material fails closed and reveals no sensitive exception detail.
 */
if (!isset($Database,$User) || !is_object($Database) || !is_object($User) ||
    !method_exists($User,'is_admin') || $User->is_admin(false)!==true) {
    return;
}

require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCipher.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsConfigRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsConfigService.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsAdminView.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorControlRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorControlService.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsAutonomousAdminService.php');

try {
    $csrf=$User->Crypto->csrf_cookie('create','integration_microsoft_dns_config');
    if (!is_string($csrf) || $csrf==='') throw new RuntimeException('csrf_unavailable');

    $cipher=IntegrationDnsCipher::fromKeyringFile();
    $repo=new IntegrationDnsPhpIpamConfigRepository($Database);
    $config=new IntegrationDnsConfigService($repo,$cipher);
    $controlRepo=new IntegrationDnsCollectorControlPhpIpamRepository($Database);
    $resolver=static function(string $id) use ($repo): ?array {
        return $repo->transaction(static function() use ($repo,$id): ?array {
            $connection=$repo->connection($id);
            if($connection===null) return null;
            $credentialVersion=null;
            if($connection['credential_mode']==='profile') {
                $profileId=$connection['profile_id'];
                if(!is_string($profileId)) return null;
                $profile=$repo->profile($profileId);
                if($profile===null||$profile['secret_envelope']===null) return null;
                $credentialVersion=(int)$profile['secret_version'];
            }
            return [
                'id'=>$connection['id'],
                'revision'=>(int)$connection['revision'],
                'binding_epoch'=>(int)$connection['binding_epoch'],
                'credential_mode'=>$connection['credential_mode'],
                'profile_id'=>$connection['profile_id'],
                'credential_version'=>$credentialVersion,
                'enabled'=>(bool)$connection['enabled']
            ];
        });
    };
    $control=new IntegrationDnsCollectorControlService($controlRepo,$resolver);
    $service=new IntegrationDnsAutonomousAdminService($config,$repo,$control);
    $state=$service->snapshot();
    $view=new IntegrationDnsAdminView();
    echo $view->render($state,$csrf);
} catch (Throwable $e) {
    echo '<div class="alert alert-warning"><i class="fa fa-lock"></i> Настройки Microsoft DNS пока недоступны. Проверьте подготовку хранилища и ключа интеграции.</div>';
}
