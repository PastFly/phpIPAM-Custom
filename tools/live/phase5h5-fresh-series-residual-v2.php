#!/usr/bin/env php
<?php
/** Read-only aggregate classifier for fresh metric series age/name. No IDs, names or values emitted. */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H5_FRESHRESIDUAL_ERROR=identity\n"); exit(1);
}
const APP='/var/www/html';
const ACTIVE='/opt/phpipam-custom/inventory-recovery5g5';
const SHADOW='/opt/phpipam-custom/vmware-fast-shadow';
const ROOT='/var/lib/phpipam-custom/vmware-fast-shadow';
$key=null;
try {
    require_once APP.'/functions/functions.php';
    require_once APP.'/functions/classes/class.IntegrationVmwareSnapshotService.php';
    foreach (['Store','Context'] as $n) require_once ACTIVE.'/layers/phase5g/functions/classes/class.IntegrationVmwareInventory'.$n.'.php';
    require_once SHADOW.'/layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';
    $db=new Database_PDO();
    $ctx=new IntegrationVmwareInventoryContext($db,APP,1);
    if(!$ctx->current()) throw new RuntimeException('binding_changed');
    $rows=$db->getObjectsQuery('no_html_escape','SELECT `id`,`provider`,`enabled`,`endpoint`,`username`,`verify_tls`,`secret_envelope`,`secret_key_id` FROM `custom_integrations` WHERE `id` = ? AND `provider` = ?;',[1,'vmware_vcenter']);
    if(!is_array($rows)||count($rows)!==1) throw new RuntimeException('connector_unavailable');
    $row=is_object($rows[0])?get_object_vars($rows[0]):$rows[0];
    $key=IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-cache.key',32,0,0640,posix_getegid());
    $binding=IntegrationVmwareInventoryStore::binding($key,APP,$row);
    $fast=new IntegrationVmwareFastStore(ROOT,$key,$binding);
    $metrics=$fast->read('metrics');
    if(!is_array($metrics)||($metrics['domain']??null)!=='metrics'||!is_array($metrics['objects']??null)) throw new RuntimeException('metrics_shape');
    $now=time();
    $names=['cpu_percent','memory_percent','disk_kib_per_second'];
    $out=['fresh_total'=>0,'fresh_over_300'=>0,'fresh_over_3600'=>0,'by_name'=>[],'object_state_with_fresh_series'=>[]];
    foreach($names as $n) $out['by_name'][$n]=['fresh'=>0,'over_300'=>0,'over_3600'=>0,'max_age'=>null];
    foreach(['fresh','partial','stale','unsupported','other'] as $s) $out['object_state_with_fresh_series'][$s]=0;
    foreach($metrics['objects'] as $obj){
        if(!is_array($obj)) throw new RuntimeException('metrics_shape');
        $objState=$obj['object_state']??'other'; if(!isset($out['object_state_with_fresh_series'][$objState])) $objState='other';
        $hasFresh=false;
        foreach(($obj['series']??[]) as $name=>$series){
            if(!in_array($name,$names,true)||!is_array($series)) continue;
            if(($series['state']??null)!=='fresh') continue;
            $hasFresh=true; ++$out['fresh_total']; ++$out['by_name'][$name]['fresh'];
            $obs=$series['observed_at']??null;
            if(!is_int($obs)||$obs<1||$obs>$now+60) continue;
            $age=max(0,$now-$obs);
            $m=$out['by_name'][$name]['max_age']; $out['by_name'][$name]['max_age']=$m===null?$age:max($m,$age);
            if($age>300){++$out['fresh_over_300'];++$out['by_name'][$name]['over_300'];}
            if($age>3600){++$out['fresh_over_3600'];++$out['by_name'][$name]['over_3600'];}
        }
        if($hasFresh) ++$out['object_state_with_fresh_series'][$objState];
    }
    $out['shadow_written']=false;$out['website_served_from_shadow']=false;
    echo 'PHASE5H5_FRESHRESIDUAL_RESULT='.json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\nPHASE5H5_FRESHRESIDUAL_EXIT=0\n";
    $exit=0;
} catch(Throwable $e){fwrite(STDERR,"PHASE5H5_FRESHRESIDUAL_ERROR=operation\nPHASE5H5_FRESHRESIDUAL_EXIT=1\n");$exit=1;}
finally{if(is_string($key)) sodium_memzero($key);} exit($exit);
