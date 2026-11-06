<?php
declare(strict_types=1);
$path=dirname(__DIR__,2).'/layers/dns10/functions/classes/class.IntegrationDnsZonePublicationPolicy.php';
if (!is_file($path)) throw new RuntimeException('publication_policy_missing');
require $path;
$assertions=0;
function policyCheck10(bool $condition,string $name): void {
    global $assertions; ++$assertions; if (!$condition) throw new RuntimeException($name);
}
function policyReject10(array $state,array $next,mixed $used,mixed $free,string $code): void {
    try {IntegrationDnsZonePublicationPolicy::decide($state,$next,$used,$free);}
    catch (DomainException $e) {policyCheck10($e->getMessage()===$code,'expected '.$code); return;}
    throw new RuntimeException('not_rejected: '.$code);
}
$digest=str_repeat('a',64);
$state=['sequence'=>7,'digest'=>$digest,'current_bytes'=>100,'previous_bytes'=>50];
$next=['sequence'=>8,'digest'=>str_repeat('b',64),'payload_bytes'=>200];
$floor=512*1024*1024; $budget=256*1024*1024;
$result=IntegrationDnsZonePublicationPolicy::decide($state,$next,1000,$floor+200);
policyCheck10($result===['decision'=>'publish','accounted_bytes_after'=>1150],'new generation accounts previous removal');
$empty=['sequence'=>0,'digest'=>null,'current_bytes'=>0,'previous_bytes'=>0];
$first=['sequence'=>1,'digest'=>$digest,'payload_bytes'=>1];
policyCheck10(IntegrationDnsZonePublicationPolicy::decide($empty,$first,0,$floor+1)===['decision'=>'publish','accounted_bytes_after'=>1],'first generation');
$maxBody=['sequence'=>1,'digest'=>$digest,'payload_bytes'=>4*1024*1024];
policyCheck10(IntegrationDnsZonePublicationPolicy::decide($empty,$maxBody,0,$floor+4*1024*1024)['decision']==='publish','maximum request accepted');
$replay=['sequence'=>7,'digest'=>$digest,'payload_bytes'=>100];
policyCheck10(IntegrationDnsZonePublicationPolicy::decide($state,$replay,1000,0)===['decision'=>'replay','accounted_bytes_after'=>1000],'identical replay cannot refresh or require free disk');
policyReject10($state,['sequence'=>6,'digest'=>$digest,'payload_bytes'=>100],1000,$floor+100,'snapshot_out_of_order');
policyReject10($state,['sequence'=>7,'digest'=>str_repeat('c',64),'payload_bytes'=>100],1000,$floor+100,'snapshot_sequence_conflict');
policyReject10($state,['sequence'=>7,'digest'=>$digest,'payload_bytes'=>101],1000,$floor+101,'snapshot_sequence_conflict');
policyCheck10(IntegrationDnsZonePublicationPolicy::decide($state,['sequence'=>9,'digest'=>str_repeat('d',64),'payload_bytes'=>50],$budget,$floor+50)['accounted_bytes_after']===$budget,'budget equality accepted');
policyReject10($state,['sequence'=>9,'digest'=>str_repeat('d',64),'payload_bytes'=>51],$budget,$floor+51,'snapshot_capacity_exceeded');
policyReject10($state,$next,1000,$floor+199,'snapshot_disk_floor');
policyReject10($state,$next,149,$floor+200,'snapshot_policy_invalid');
policyReject10($empty,['sequence'=>1,'digest'=>$digest,'payload_bytes'=>4*1024*1024+1],0,$floor+5000000,'snapshot_policy_invalid');
policyReject10($empty,['sequence'=>0,'digest'=>$digest,'payload_bytes'=>1],0,$floor+1,'snapshot_policy_invalid');
policyReject10($empty,['sequence'=>9007199254740992,'digest'=>$digest,'payload_bytes'=>1],0,$floor+1,'snapshot_policy_invalid');
policyReject10($empty,['sequence'=>true,'digest'=>$digest,'payload_bytes'=>1],0,$floor+1,'snapshot_policy_invalid');
policyReject10($empty,['sequence'=>1,'digest'=>'secret','payload_bytes'=>1],0,$floor+1,'snapshot_policy_invalid');
policyReject10($empty,['sequence'=>1,'digest'=>$digest,'payload_bytes'=>0],0,$floor+1,'snapshot_policy_invalid');
policyReject10(['sequence'=>0,'digest'=>null,'current_bytes'=>0,'previous_bytes'=>1],$first,1,$floor+1,'snapshot_policy_invalid');
policyReject10(['sequence'=>1,'digest'=>null,'current_bytes'=>100,'previous_bytes'=>0],$first,100,$floor+1,'snapshot_policy_invalid');
policyReject10(['sequence'=>1,'digest'=>$digest,'current_bytes'=>100,'previous_bytes'=>1],$next,101,$floor+200,'snapshot_policy_invalid');
policyReject10($state,$next,'1000',$floor+200,'snapshot_policy_invalid');
policyReject10($state,$next,1000,-1,'snapshot_policy_invalid');
$bad=$next; $bad['extra']=1; policyReject10($state,$bad,1000,$floor+200,'snapshot_policy_invalid');
echo 'DNS10_PUBLICATION_POLICY_ASSERTIONS='.$assertions."\n";
echo "DNS10_PUBLICATION_POLICY_TEST_OK=1\n";
