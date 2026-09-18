<?php
declare(strict_types=1);

/**
 * DNS6 source-only machine credential endpoint candidate.
 * Dedicated machine auth; no phpIPAM user session and no collector-selected profile.
 */
require_once(dirname(__FILE__).'/../../../functions/functions.php');
disable_php_errors();

require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCipher.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorControlRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCollectorControlPhpIpamRepository.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCredentialDeliveryService.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCredentialContextReader.php');
require_once(dirname(__FILE__).'/../../../functions/classes/class.IntegrationDnsCredentialHttpController.php');

$Tools=new Common_functions();

$authorization='';
if(isset($_SERVER['HTTP_AUTHORIZATION'])&&is_string($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorization=$_SERVER['HTTP_AUTHORIZATION'];
} elseif(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])&&is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authorization=$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif(function_exists('getallheaders')) {
    $headers=getallheaders();
    if(is_array($headers)) {
        foreach($headers as $name=>$value) {
            if(is_string($name)&&strcasecmp($name,'Authorization')===0&&is_string($value)) {
                $authorization=$value;
                break;
            }
        }
    }
}

$stream=@fopen('php://input','rb');
$raw=$stream===false?'':stream_get_contents($stream,4097);
if(is_resource($stream)) fclose($stream);
if(!is_string($raw)) $raw='';

$controller=new IntegrationDnsCredentialHttpController(static function() {
    $Database=new Database_PDO;
    $repo=new IntegrationDnsCollectorControlPhpIpamRepository($Database);
    $reader=new IntegrationDnsCredentialContextReader($Database);
    $resolver=[$reader,'current'];
    $decryptor=static function(array $context): string {
        $cipher=IntegrationDnsCipher::fromKeyringFile();
        return $cipher->open(
            $context['secret_envelope'],
            $context['profile_id'],
            $context['username'],
            $context['secret_version']
        );
    };
    return new IntegrationDnsCredentialDeliveryService($repo,$resolver,$decryptor);
});

$response=$controller->handle([
    'method'=>isset($_SERVER['REQUEST_METHOD'])?(string)$_SERVER['REQUEST_METHOD']:'',
    'https'=>$Tools->isHttps(),
    'content_type'=>isset($_SERVER['CONTENT_TYPE'])?(string)$_SERVER['CONTENT_TYPE']:'',
    'authorization'=>$authorization,
    'raw_body'=>$raw
]);

http_response_code((int)$response['status']);
foreach($response['headers'] as $name=>$value) header($name.': '.$value);
echo $response['body'];

if(isset($response['body'])&&is_string($response['body'])&&function_exists('sodium_memzero')) @sodium_memzero($response['body']);
if($authorization!==''&&function_exists('sodium_memzero')) @sodium_memzero($authorization);
if($raw!==''&&function_exists('sodium_memzero')) @sodium_memzero($raw);
