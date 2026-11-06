<?php
declare(strict_types=1);
$path=dirname(__DIR__,2).'/layers/dns10/functions/classes/class.IntegrationDnsZoneUploadToken.php';
if (!is_file($path)) throw new RuntimeException('snapshot_upload_token_missing');
require $path;
$n=0;
function tokenCheck10(bool $value,string $label): void {global $n;++$n;if(!$value)throw new RuntimeException($label);}
function tokenReject10(string $token,string $label): void {
    try {IntegrationDnsZoneUploadToken::identify($token);}
    catch (DomainException $e) {tokenCheck10($e->getMessage()==='upload_auth_failed' && ($token==='' || !str_contains($e->getMessage(),$token)),$label);return;}
    throw new RuntimeException('not_rejected: '.$label);
}
$item=IntegrationDnsZoneUploadToken::issue();
tokenCheck10(array_keys($item)===['token_id','token','token_digest'],'issuance shape');
tokenCheck10((bool)preg_match('/^[0-9a-f]{32}$/D',$item['token_id']),'random id format');
tokenCheck10((bool)preg_match('/^dnsup1\.[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$/D',$item['token']),'upload token format');
tokenCheck10((bool)preg_match('/^[0-9a-f]{64}$/D',$item['token_digest']),'digest format');
tokenCheck10(!str_contains($item['token_digest'],$item['token']),'verifier is not plaintext token');
tokenCheck10(IntegrationDnsZoneUploadToken::identify($item['token'])===$item['token_id'],'identification');
tokenCheck10(IntegrationDnsZoneUploadToken::verify($item['token'],$item['token_id'],$item['token_digest'])===true,'matching verifier');
$parts=explode('.',$item['token']);
$decoded=base64_decode(strtr($parts[2],'-_','+/').'=',true);
tokenCheck10(is_string($decoded) && strlen($decoded)===32,'secret length');
$changed=$parts[2];$changed[0]=$changed[0]==='A'?'B':'A';
$wrongSecret='dnsup1.'.$item['token_id'].'.'.$changed;
tokenCheck10(IntegrationDnsZoneUploadToken::verify($wrongSecret,$item['token_id'],$item['token_digest'])===false,'wrong secret');
tokenCheck10(IntegrationDnsZoneUploadToken::verify($item['token'],str_repeat('f',32),$item['token_digest'])===false,'wrong stored id');
tokenCheck10(IntegrationDnsZoneUploadToken::verify($item['token'],$item['token_id'],str_repeat('0',64))===false,'wrong digest');
tokenCheck10(IntegrationDnsZoneUploadToken::verify($item['token'],$item['token_id'],null)===false,'missing digest');
tokenCheck10(IntegrationDnsZoneUploadToken::verify('dnsctl1.'.$parts[1].'.'.$parts[2],$item['token_id'],$item['token_digest'])===false,'control token not accepted');
foreach (['','dnsctl1.'.$parts[1].'.'.$parts[2],$item['token'].'=',strtoupper($parts[1]).'.'.$parts[2],
          'dnsup1.'.str_repeat('g',32).'.'.$parts[2],$item['token']."\n"] as $invalid) tokenReject10($invalid,'malformed token');
$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
$position=strpos($alphabet,$parts[2][42]);
if ($position===false) throw new RuntimeException('fixture_base64_invalid');
$alternate=$parts[2];$alternate[42]=$alphabet[$position^1];
tokenCheck10(base64_decode(strtr($alternate,'-_','+/').'=',true)===$decoded,'noncanonical spelling fixture');
tokenReject10('dnsup1.'.$parts[1].'.'.$alternate,'noncanonical base64 rejected');
$ids=[];$verifiers=[];
for ($i=0;$i<64;++$i) {
    $next=IntegrationDnsZoneUploadToken::issue();$ids[$next['token_id']]=true;$verifiers[$next['token_digest']]=true;
    if (!IntegrationDnsZoneUploadToken::verify($next['token'],$next['token_id'],$next['token_digest'])) throw new RuntimeException('issued token verification failed');
}
tokenCheck10(count($ids)===64 && count($verifiers)===64,'different issuance values');
echo 'DNS10_UPLOAD_TOKEN_ASSERTIONS='.$n."\n";
echo "DNS10_UPLOAD_TOKEN_TEST_OK=1\n";
