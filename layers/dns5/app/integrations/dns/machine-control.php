<?php
declare(strict_types=1);

/**
 * DNS5 source-only machine poll/result endpoint candidate.
 * Machine authentication is the DNS control Bearer token; no user session is involved.
 */
require_once(dirname(__FILE__).'/../../../functions/functions.php');
disable_php_errors();

require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorControlRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorMachineService.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsMachineContextReader.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorMachineHttpController.php');

$Tools=new Common_functions();

$authorization='';
if(isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorization=$_SERVER['HTTP_AUTHORIZATION'];
} elseif(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authorization=$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif(function_exists('getallheaders')) {
    $headers=getallheaders();
    if(is_array($headers)) {
        foreach($headers as $name=>$value) {
            if(is_string($name) && strcasecmp($name,'Authorization')===0 && is_string($value)) {
                $authorization=$value;
                break;
            }
        }
    }
}

$stream=@fopen('php://input','rb');
$raw=$stream===false ? '' : stream_get_contents($stream,73729);
if(is_resource($stream)) fclose($stream);
if(!is_string($raw)) $raw='';

$controller=new IntegrationDnsCollectorMachineHttpController(static function() {
    $Database=new Database_PDO;
    $repo=new IntegrationDnsCollectorControlPhpIpamRepository($Database);
    $context=new IntegrationDnsMachineContextReader($Database);
    return new IntegrationDnsCollectorMachineService($repo,[$context,'current']);
});

$response=$controller->handle([
    'method'=>isset($_SERVER['REQUEST_METHOD'])?(string)$_SERVER['REQUEST_METHOD']:'',
    'https'=>$Tools->isHttps(),
    'content_type'=>isset($_SERVER['CONTENT_TYPE'])?(string)$_SERVER['CONTENT_TYPE']:'',
    'authorization'=>$authorization,
    'raw_body'=>$raw
]);

http_response_code((int)$response['status']);
foreach($response['headers'] as $name=>$value) {
    header($name.': '.$value);
}
if ($response['body']!==null) {
    try {
        echo json_encode($response['body'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch(Throwable $e) {
        http_response_code(503);
        echo '{"ok":false,"error":"control_unavailable"}';
    }
}

if($authorization!=='' && function_exists('sodium_memzero')) @sodium_memzero($authorization);
if($raw!=='' && function_exists('sodium_memzero')) @sodium_memzero($raw);
