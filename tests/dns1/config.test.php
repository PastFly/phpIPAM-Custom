<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
$root = dirname(__DIR__, 2);
$n = 0;
function ok(bool $condition, string $label): void { global $n; ++$n; if (!$condition) throw new RuntimeException('ASSERT: '.$label); }
function rejects(callable $fn, string $code): void { try { $fn(); } catch (DomainException $e) { ok($e->getMessage() === $code, 'reject: '.$code); return; } throw new RuntimeException('ASSERT: rejection expected '.$code); }
foreach (['IntegrationDnsCipher', 'IntegrationDnsConfigRepository', 'IntegrationDnsConfigService', 'IntegrationDnsAdminController', 'IntegrationDnsAdminView'] as $name) {
    $path = "$root/layers/dns1/functions/classes/class.$name.php";
    ok(is_file($path), "$name implementation present"); require_once $path;
}
require __DIR__.'/memory-repository.php';
$key = random_bytes(32); $cipher = new IntegrationDnsCipher('test-key', ['test-key'=>$key]);
$raw = ' Test password <&> \\" '.bin2hex(random_bytes(8));
$context = ['a'=>str_repeat('a',32),'u'=>'svc@example.invalid','v'=>1];
$enc = $cipher->seal($raw, $context['a'], $context['u'], 1);
ok($cipher->open($enc, $context['a'], $context['u'], 1) === $raw, 'cipher exact roundtrip');
ok(!str_contains($enc, $raw), 'ciphertext not plaintext');
ok($enc !== $cipher->seal($raw, $context['a'], $context['u'], 1), 'random nonces');
rejects(fn()=> $cipher->open($enc, str_repeat('b',32), $context['u'], 1), 'secret_unavailable');
rejects(fn()=> $cipher->open($enc, $context['a'], 'other@example.invalid', 1), 'secret_unavailable');
rejects(fn()=> $cipher->open($enc, $context['a'], $context['u'], 2), 'secret_unavailable');
$bad=json_decode($enc,true); $bad['ct'][0]=$bad['ct'][0]==='a'?'b':'a';
rejects(fn()=> $cipher->open(json_encode($bad), $context['a'], $context['u'], 1), 'secret_unavailable');
$wrong=new IntegrationDnsCipher('test-key',['test-key'=>random_bytes(32)]);
rejects(fn()=> $wrong->open($enc,$context['a'],$context['u'],1), 'secret_unavailable');
rejects(fn()=> $cipher->open('{}',$context['a'],$context['u'],1), 'secret_unavailable');
ok(!str_contains(print_r($cipher,true),bin2hex($key)), 'key hidden from debug');

$repo = new DnsMemoryRepository(); $service = new IntegrationDnsConfigService($repo, $cipher);
function profileInput(string $password): array { return ['id'=>null,'expected_revision'=>0,'name'=>'DNS service','username'=>'svc@example.invalid','password_action'=>'replace','password'=>$password,'acknowledge_connections'=>[]]; }
function connectionInput(string $id): array { return ['id'=>null,'expected_revision'=>0,'name'=>'Основной DNS','server'=>'DC01.Example.Invalid.','interval_seconds'=>300,'credential_mode'=>'profile','profile_id'=>$id,'share_profile'=>false]; }
$p=$service->saveProfile(profileInput($raw),1);
ok($p['password_set']===true && $p['secret_version']===1 && $p['revision']===1, 'create profile');
ok(!isset($p['secret_envelope']) && !isset($p['password']), 'redacted create');
ok(!str_contains(json_encode($service->snapshot()), $raw), 'snapshot secret absent');
$stored=$repo->profiles[$p['id']]['secret_envelope'];
ok($cipher->open($stored,$p['id'],$p['username'],1)===$raw, 'stored encrypted credential');
$input=profileInput(''); $input['id']=$p['id']; $input['expected_revision']=1; $input['name']='Renamed'; $input['password_action']='unchanged';
$p=$service->saveProfile($input,1);
ok($repo->profiles[$p['id']]['secret_envelope']===$stored && $p['secret_version']===1 && $p['revision']===2, 'metadata edit preserves envelope');
rejects(fn()=> $service->saveProfile($input,1),'revision_conflict');
$input['expected_revision']=2; $input['password']='********';
rejects(fn()=> $service->saveProfile($input,1),'password_action_invalid');
$input['password']=''; $input['username']='changed@example.invalid';
rejects(fn()=> $service->saveProfile($input,1),'password_required_for_username_change');
$input['username']=$p['username'];
$badInput=profileInput(''); rejects(fn()=> $service->saveProfile($badInput,1),'password_invalid');
$badInput=profileInput('********'); rejects(fn()=> $service->saveProfile($badInput,1),'password_invalid');
$badInput=profileInput($raw); $badInput['unexpected']=true;
rejects(fn()=> $service->saveProfile($badInput,1),'fields_invalid');
$badInput=profileInput($raw); $badInput['username']='username';
rejects(fn()=> $service->saveProfile($badInput,1),'username_invalid');
rejects(fn()=> $service->saveProfile(profileInput($raw),0),'actor_invalid');
$c=$service->saveConnection(connectionInput($p['id']),1);
ok($c['server']==='dc01.example.invalid' && $c['enabled']===false && $c['binding_epoch']===1, 'normalized disabled connection');
ok($c['collector_id']===null && $c['status']==='not_configured', 'no collector side effects');
$ci=connectionInput($p['id']); $ci['server']='dc02.example.invalid';
rejects(fn()=> $service->saveConnection($ci,1),'profile_share_confirmation_required');
$ci['share_profile']=true; $c2=$service->saveConnection($ci,1);
ok(count($service->snapshot()['profiles'][0]['connections'])===2,'shared profile impacts visible');
rejects(fn()=> $service->deleteProfile($p['id'],2,1),'profile_in_use');
$rot=$input; $rot['password_action']='replace'; $rot['password']='new fictional password';
rejects(fn()=> $service->saveProfile($rot,1),'profile_impact_conflict');
$rot['acknowledge_connections']=[$c['id'],$c2['id']];
$before=serialize([$repo->profiles,$repo->connections,$repo->events]); $repo->failAt=2;
try { $service->saveProfile($rot,1); throw new LogicException('injected failure absent'); } catch (RuntimeException $e) { ok($e->getMessage()==='injected_write_failure','inject mid-transaction'); }
ok($before===serialize([$repo->profiles,$repo->connections,$repo->events]),'rollback profile plus consumers plus audit');
$repo->failAt=null; $p=$service->saveProfile($rot,1);
ok($p['secret_version']===2 && $p['revision']===3,'rotation versions');
foreach([$c,$c2] as $consumer) {
    $r=$repo->connections[$consumer['id']];
    ok($r['binding_epoch']===2 && $r['revision']===2 && !$r['enabled'] && $r['status']==='needs_validation','rotation invalidates each consumer');
}
$ci=connectionInput($p['id']); $ci['id']=$c['id']; $ci['expected_revision']=1;
rejects(fn()=> $service->saveConnection($ci,1),'revision_conflict');
$ci['expected_revision']=2; $ci['server']='https://dc.example.invalid';
rejects(fn()=> $service->saveConnection($ci,1),'server_invalid');
foreach(['127.0.0.1','dc','dc.invalid:5985','dc.invalid/path','-x.example.invalid',"dc.invalid\n"] as $host) {
    $ci['server']=$host; rejects(fn()=> $service->saveConnection($ci,1),'server_invalid');
}
$ci['server']='new.example.invalid'; $ci['interval_seconds']='300';
rejects(fn()=> $service->saveConnection($ci,1),'interval_invalid');
$ci['interval_seconds']=300; $ci['enabled']=true;
rejects(fn()=> $service->saveConnection($ci,1),'fields_invalid'); unset($ci['enabled']);
$ci['credential_mode']='collector_identity'; $ci['profile_id']=null;
$c=$service->saveConnection($ci,1);
ok($c['profile_id']===null && $c['binding_epoch']===3 && !$c['enabled'],'explicit identity switch invalidates');
$service->revokeProfile($p['id'],3,[$c2['id']],1);
ok($repo->profiles[$p['id']]['secret_envelope']===null && !$service->snapshot()['profiles'][0]['password_set'],'revoke clears ciphertext');
ok($repo->connections[$c2['id']]['status']==='credential_revoked' && $repo->connections[$c2['id']]['binding_epoch']===3,'revoke invalidates dependent');
rejects(fn()=> $service->saveConnection(connectionInput($p['id']),1),'profile_unavailable');
$service->deleteConnection($c2['id'],3,1); $service->deleteProfile($p['id'],4,1);
ok(count($service->snapshot()['profiles'])===0,'delete unused profile');
rejects(fn()=> $service->deleteConnection($c['id'],1,1),'revision_conflict');
$service->deleteConnection($c['id'],3,1); ok(count($service->snapshot()['connections'])===0,'delete disabled connection');
foreach ($repo->events as $e) ok(!str_contains(json_encode($e),$raw) && !isset($e['username']) && !isset($e['password']),'audit redacted');

$constructed=0;
$controller=new IntegrationDnsAdminController(static function() use (&$constructed,$service) { ++$constructed; return $service; });
function request(array $post=[], string $method='POST', bool $session=true, bool $admin=true, bool $csrf=true, bool $https=true): array { return ['method'=>$method,'authenticated'=>$session,'admin'=>$admin,'csrf_valid'=>$csrf,'https'=>$https,'actor_id'=>1,'input'=>$post]; }
foreach ([request([], 'GET'),request([], 'POST',false),request([], 'POST',true,false),request([], 'POST',true,true,false),request([], 'POST',true,true,true,false)] as $r) {
    $out=$controller->handle($r); ok($out['status']>=400 && $constructed===0,'deny before service/key/db construction');
}
$out=$controller->handle(request(['action'=>'snapshot']));
ok($out['status']===200 && $constructed===1 && $out['headers']['Cache-Control']==='no-store','authorized snapshot');
$out=$controller->handle(request(['action'=>'save_profile','data'=>profileInput($raw)]));
ok($out['status']===200 && !str_contains(json_encode($out),$raw),'controller save redacted');
$boom=new IntegrationDnsAdminController(static function() use ($raw) { throw new RuntimeException($raw); });
$out=$boom->handle(request(['action'=>'snapshot']));
ok($out['status']===503 && !str_contains(json_encode($out),$raw),'errors never expose secrets');
foreach(['decrypt','enable','test_connection','list_zones','collect_selected_zones','arbitrary'] as $action) {
    $out=$controller->handle(request(['action'=>$action])); ok($out['status']===400,'unimplemented/external operation refused');
}
$out=$controller->handle(request(['action'=>'snapshot','password'=>$raw])); ok($out['status']===400,'unexpected transport keys refused');
$view=new IntegrationDnsAdminView(); $state=$service->snapshot();
$state['profiles'][0]['name']='<script>alert(1)</script>';
$html=$view->render($state,'csrf-test');
ok(!str_contains($html,'<script>alert(1)</script>') && str_contains($html,'&lt;script&gt;'),'HTML escaped');
ok(!str_contains($html,$raw) && !str_contains($html,'secret_envelope'),'HTML contains no secret');
ok(str_contains($html,'password_action') && str_contains($html,'unchanged') && str_contains($html,'replace'),'explicit password UI');
ok(str_contains($html,'name="password"') && !preg_match('/name="password"[^>]*value="[^"]+"/',$html),'password input never prefilled');
ok(str_contains($html,'data-dns-action="save_connection"') && str_contains($html,'disabled'),'connection form and disabled collection visible');
$viewReflection=new ReflectionClass($view); ok($viewReflection->isFinal(),'view narrow extension boundary');
printf("DNS1_CONFIG_ASSERTIONS=%d\nDNS1_CONFIG_TESTS_OK=1\n",$n);
