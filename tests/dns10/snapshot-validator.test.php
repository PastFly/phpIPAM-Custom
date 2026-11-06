<?php
declare(strict_types=1);

$path=dirname(__DIR__,2).'/layers/dns10/functions/classes/class.IntegrationDnsZoneSnapshotValidator.php';
if (!is_file($path)) throw new RuntimeException('snapshot_validator_missing');
require $path;

$assertions=0;
function check10(bool $condition,string $name): void {
    global $assertions; ++$assertions;
    if (!$condition) throw new RuntimeException($name);
}
function reject10(array $snapshot,array $context,int $now,string $name): void {
    try {IntegrationDnsZoneSnapshotValidator::validate($snapshot,$context,$now);}
    catch (DomainException $e) {check10($e->getMessage()==='snapshot_invalid',$name); return;}
    throw new RuntimeException('not_rejected: '.$name);
}
$context=['source_id'=>str_repeat('a',32),'dataset_id'=>str_repeat('b',32),'zone'=>'example.test.',
    'configuration_revision'=>4,'binding_epoch'=>7];
$snapshot=['schema_version'=>1,'source_id'=>$context['source_id'],'dataset_id'=>$context['dataset_id'],
    'zone'=>'EXAMPLE.TEST','virtualization_instance'=>'default','zone_scope'=>'default',
    'configuration_revision'=>4,'binding_epoch'=>7,'run_id'=>'12345678-1234-4abc-8def-123456789abc',
    'sequence'=>42,'started_at'=>1999999900,'finished_at'=>2000000000,
    'supported_types'=>['A','AAAA','CNAME','PTR'],'complete'=>true,'record_count'=>4,
    'records'=>[
        ['owner'=>'EXAMPLE.TEST.','display_owner'=>'@','type'=>'A','record_class'=>'IN','rdata'=>'192.0.2.10','display_rdata'=>'192.0.2.10','ttl_seconds'=>0,'source_aging_timestamp'=>null],
        ['owner'=>'*.Example.Test','display_owner'=>'*','type'=>'AAAA','record_class'=>'IN','rdata'=>'2001:0DB8:0:0:0:0:0:1','display_rdata'=>'2001:db8::1','ttl_seconds'=>4294967295,'source_aging_timestamp'=>1],
        ['owner'=>'_service.node.example.test.','display_owner'=>'_service.node','type'=>'CNAME','record_class'=>'IN','rdata'=>'ALIAS.Example.Test','display_rdata'=>'ALIAS.Example.Test','ttl_seconds'=>60,'source_aging_timestamp'=>100],
        ['owner'=>'ptr.example.test.','display_owner'=>'ptr','type'=>'PTR','record_class'=>'IN','rdata'=>'Host.Other.Test.','display_rdata'=>'Host.Other.Test.','ttl_seconds'=>300,'source_aging_timestamp'=>null],
    ]];
$copy=$snapshot;
$normalized=IntegrationDnsZoneSnapshotValidator::validate($snapshot,$context,2000000000);
check10($snapshot===$copy,'input remains unchanged');
check10($normalized['zone']==='example.test.','zone canonicalized');
check10($normalized['records'][0]['owner']==='example.test.','apex accepted');
check10($normalized['records'][1]['owner']==='*.example.test.' && $normalized['records'][1]['rdata']==='2001:db8::1','wildcard and ipv6 normalized');
check10($normalized['records'][2]['owner']==='_service.node.example.test.' && $normalized['records'][2]['rdata']==='alias.example.test.','underscore and cname normalized');
check10($normalized['records'][3]['rdata']==='host.other.test.','external ptr target normalized without resolution');

$empty=$snapshot; $empty['records']=[]; $empty['record_count']=0;
check10(IntegrationDnsZoneSnapshotValidator::validate($empty,$context,2000000000)['records']===[],'complete empty snapshot accepted');
$old=$empty; $old['started_at']=1; $old['finished_at']=2; $old['sequence']=9007199254740991;
$observed=IntegrationDnsZoneSnapshotValidator::validate($old,$context,2000000000);
check10($observed['started_at']===1 && $observed['finished_at']===2,'older collection timestamps are preserved');
check10($observed['sequence']===9007199254740991,'maximum safe sequence accepted');
$future=$empty; $future['started_at']=2000000300; $future['finished_at']=2000000300;
check10(IntegrationDnsZoneSnapshotValidator::validate($future,$context,2000000000)['finished_at']===2000000300,'clock tolerance boundary accepted');
$maximum=$empty; $maximum['record_count']=10000;
for ($i=0;$i<10000;++$i) { $row=$snapshot['records'][0]; $row['owner']='host'.$i.'.example.test.'; $maximum['records'][]=$row; }
check10(count(IntegrationDnsZoneSnapshotValidator::validate($maximum,$context,2000000000)['records'])===10000,'maximum record count accepted');
$rootContext=$context; $rootContext['zone']='.'; $root=$snapshot; $root['zone']='.';
check10(IntegrationDnsZoneSnapshotValidator::validate($root,$rootContext,2000000000)['record_count']===4,'root dataset permits owners');

foreach ([
    ['schema_version',2],['source_id',str_repeat('c',32)],['dataset_id',str_repeat('c',32)],['zone','other.test.'],
    ['virtualization_instance','other'],['zone_scope','other'],['configuration_revision',5],['binding_epoch',8],
    ['run_id','not-a-uuid'],['sequence',0],['sequence',9007199254740992],['sequence',true],
    ['started_at',0],['started_at',2000000001],['finished_at',2000000301],
    ['supported_types',['A','AAAA','CNAME']],['complete',false],['record_count',3],['record_count','4'],
] as [$key,$value]) {
    $bad=$snapshot; $bad[$key]=$value; reject10($bad,$context,2000000000,'envelope '.$key);
}
$bad=$snapshot; $bad['unexpected']=1; reject10($bad,$context,2000000000,'unknown envelope field');
$bad=$snapshot; unset($bad['run_id']); reject10($bad,$context,2000000000,'missing envelope field');
$bad=$snapshot; $bad['records']=array_fill(0,10001,$snapshot['records'][0]); $bad['record_count']=10001;
reject10($bad,$context,2000000000,'oversized record count');
$bad=$snapshot; $bad['records'][0]['owner']='outside.test.'; reject10($bad,$context,2000000000,'out-of-zone owner');
$bad=$snapshot; $bad['records'][0]['owner']='notexample.test.'; reject10($bad,$context,2000000000,'suffix is not a zone boundary');
$bad=$snapshot; $bad['records'][0]['owner']='bad..example.test.'; reject10($bad,$context,2000000000,'invalid owner');
$bad=$snapshot; $bad['records'][0]['owner']='пример.example.test.'; reject10($bad,$context,2000000000,'unsupported unicode labels fail closed');
$bad=$snapshot; $bad['records'][0]['owner']='bad.*.example.test.'; reject10($bad,$context,2000000000,'non-leftmost wildcard');
$bad=$snapshot; $bad['records'][0]['rdata']='2001:db8::1'; reject10($bad,$context,2000000000,'ipv6 not ipv4');
$bad=$snapshot; $bad['records'][1]['rdata']='192.0.2.1'; reject10($bad,$context,2000000000,'ipv4 not ipv6');
$bad=$snapshot; $bad['records'][2]['rdata']='bad..test.'; reject10($bad,$context,2000000000,'invalid target');
$bad=$snapshot; $bad['records'][0]['ttl_seconds']=-1; reject10($bad,$context,2000000000,'negative ttl');
$bad=$snapshot; $bad['records'][0]['ttl_seconds']=4294967296; reject10($bad,$context,2000000000,'ttl overflow');
$bad=$snapshot; $bad['records'][0]['source_aging_timestamp']=false; reject10($bad,$context,2000000000,'bad aging timestamp');
$bad=$snapshot; $bad['records'][0]['display_owner']="bad\0name"; reject10($bad,$context,2000000000,'control char in display');
$bad=$snapshot; $bad['records'][0]['display_owner']="\xff"; reject10($bad,$context,2000000000,'invalid utf8 display');
$bad=$snapshot; $bad['records'][0]['display_owner']=str_repeat('x',1025); reject10($bad,$context,2000000000,'oversized display');
$bad=$snapshot; $bad['records'][0]['type']='TXT'; reject10($bad,$context,2000000000,'unsupported type');
$bad=$snapshot; $bad['records'][0]['record_class']='CH'; reject10($bad,$context,2000000000,'unsupported class');
$bad=$snapshot; $bad['records'][0]['extra']=1; reject10($bad,$context,2000000000,'unknown record field');
$bad=$snapshot; unset($bad['records'][0]['display_rdata']); reject10($bad,$context,2000000000,'missing record field');
$bad=$snapshot; $bad['records'][]=$snapshot['records'][0]; $bad['records'][4]['owner']='example.test'; $bad['record_count']=5;
reject10($bad,$context,2000000000,'duplicate normalized record');
$badContext=$context; $badContext['source_id']=strtoupper(str_repeat('a',32)); reject10($snapshot,$badContext,2000000000,'invalid trusted context');
$badContext=$context; $badContext['zone']='*.example.test.'; reject10($snapshot,$badContext,2000000000,'wildcard dataset');
reject10($snapshot,$context,0,'invalid receiver time');
echo 'DNS10_SNAPSHOT_VALIDATOR_ASSERTIONS='.$assertions."\n";
echo "DNS10_SNAPSHOT_VALIDATOR_TEST_OK=1\n";
