<?php
declare(strict_types=1);

/**
 * DNS1 administration JSON endpoint candidate.
 * No schema/key provisioning and no collector/provider calls occur here.
 */
require_once(dirname(__FILE__).'/../../../functions/functions.php');
disable_php_errors();

$Database=new Database_PDO;
$User=new User($Database);
$User->check_user_session();

require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCipher.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsConfigRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsConfigService.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsPhpIpamConfigRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsAdminController.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsPhpIpamAdminBridge.php');

$headers=[];
if (function_exists('getallheaders')) {
    $all=getallheaders();
    if (is_array($all)) $headers=$all;
}
if (isset($_SERVER['HTTP_X_PHPIPAM_DNS_CSRF']) && !array_key_exists('X-Phpipam-Dns-Csrf',$headers)) {
    $headers['X-Phpipam-Dns-Csrf']=(string)$_SERVER['HTTP_X_PHPIPAM_DNS_CSRF'];
}

$stream=@fopen('php://input','rb');
$raw=$stream===false ? '' : stream_get_contents($stream,16385);
if (is_resource($stream)) fclose($stream);
if (!is_string($raw)) $raw='';

$bridge=new IntegrationDnsPhpIpamAdminBridge(static function() use ($Database) {
    return new IntegrationDnsAdminController(static function() use ($Database) {
        $cipher=IntegrationDnsCipher::fromKeyringFile();
        $repo=new IntegrationDnsPhpIpamConfigRepository($Database);
        return new IntegrationDnsConfigService($repo,$cipher);
    });
});

$response=$bridge->handle($User,$_SERVER,$headers,$raw);
http_response_code((int)$response['status']);
foreach ($response['headers'] as $name=>$value) header($name.': '.$value);
try {
    echo json_encode($response['body'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"ok":false,"error":"configuration_unavailable"}';
}
