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

try {
    $csrf=$User->Crypto->csrf_cookie('create','integration_microsoft_dns_config');
    if (!is_string($csrf) || $csrf==='') throw new RuntimeException('csrf_unavailable');

    $cipher=IntegrationDnsCipher::fromKeyringFile();
    $repo=new IntegrationDnsPhpIpamConfigRepository($Database);
    $service=new IntegrationDnsConfigService($repo,$cipher);
    $state=$service->snapshot();
    $view=new IntegrationDnsAdminView();
    echo $view->render($state,$csrf);
} catch (Throwable $e) {
    echo '<div class="alert alert-warning"><i class="fa fa-lock"></i> Настройки Microsoft DNS пока недоступны. Проверьте подготовку хранилища и ключа интеграции.</div>';
}
