<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/layers/dns1/functions/classes/class.IntegrationDnsCipher.php';
$n=0;
function ok(bool $v,string $label): void { global $n; ++$n; if (!$v) throw new RuntimeException($label); }
function deny(string $path): void { try { IntegrationDnsCipher::fromKeyringFile($path); } catch (DomainException $e) { ok($e->getMessage()==='keyring_unavailable','sanitized failure'); return; } throw new RuntimeException('unsafe key accepted'); }
if (!function_exists('posix_geteuid') || posix_geteuid()!==0) { echo "DNS1_KEYRING_ROOT_FIXTURES_NOT_RUN=1\n"; exit(77); }
$root=sys_get_temp_dir().'/dns1-key-test-'.bin2hex(random_bytes(8)); mkdir($root,0700); $path=$root.'/key';
$key=random_bytes(32); $raw=json_encode(['version'=>1,'active'=>'test','keys'=>['test'=>bin2hex($key)]]);
try {
    deny($path);
    file_put_contents($path,$raw); chown($path,0); chgrp($path,33); chmod($path,0640);
    $c=IntegrationDnsCipher::fromKeyringFile($path);
    $id=str_repeat('a',32); $e=$c->seal('Fictional test secret',$id,'svc@example.invalid',1);
    ok($c->open($e,$id,'svc@example.invalid',1)==='Fictional test secret','private file works');
    chmod($path,0644); deny($path); chmod($path,0640);
    chgrp($path,0); deny($path); chgrp($path,33);
    chown($path,33); deny($path); chown($path,0);
    link($path,$root.'/hard'); deny($path); unlink($root.'/hard');
    symlink($path,$root.'/sym'); deny($root.'/sym'); unlink($root.'/sym');
    symlink($root,$root.'/parent-link'); deny($root.'/parent-link/key'); unlink($root.'/parent-link');
    file_put_contents($path,str_repeat('x',4097)); deny($path);
    file_put_contents($path,'{"version":1}'); deny($path);
    file_put_contents($path,json_encode(['version'=>2,'active'=>'test','keys'=>['test'=>bin2hex($key)]])); deny($path);
    file_put_contents($path,$raw); $c=IntegrationDnsCipher::fromKeyringFile($path); ok(true,'after rejected metadata restored test fixture works');
    try { serialize($c); throw new RuntimeException('serialized secret'); } catch (LogicException $e) { ok(true,'keyring serialization refused'); }
    echo "DNS1_KEYRING_ASSERTIONS=$n\nDNS1_KEYRING_TESTS_OK=1\n";
} finally { if (is_file($path)) unlink($path); rmdir($root); }
