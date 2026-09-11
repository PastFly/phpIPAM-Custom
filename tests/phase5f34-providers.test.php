<?php
// Synthetic fixtures only. No real server or credentials.
require __DIR__.'/../layers/phase5f34/functions/classes/class.IntegrationVmwareSnapshotCache.php';
require __DIR__.'/../layers/phase5f34/functions/classes/class.IntegrationVmwareSnapshotService.php';
define('PHPIPAM_VMWARE_CACHE_GUARDED',true);
require __DIR__.'/../layers/phase5f34/app/integrations/vmware/cache/search-provider.php';
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
class IntegrationVmwareVcenterDirectRestTransport { function __construct(...$args) {} }
class IntegrationVmwareVcenterDirectTransport { function __construct(...$args) {} }
class IntegrationVmwareVcenterFaultAwareTransport { function __construct(...$args) {} }
class IntegrationVmwareVcenterTopology {
    static $vms=[['external_id'=>'vm-1','name'=>'Fixture One','host_external_id'=>'host-1','datacenter_external_id'=>'dc-1','cluster_external_id'=>null,'cpu_count'=>2,'memory_size_mib'=>4096],['external_id'=>'vm-2','name'=>'Fixture Two']];
    function __construct(...$args) {}
    function read_topology(...$args) { return ['ok'=>true,'vms'=>self::$vms,'hosts'=>[['external_id'=>'host-1','name'=>'ESXi']],'datacenters'=>[['external_id'=>'dc-1','name'=>'DC']],'clusters'=>[]]; }
}
class IntegrationVmwareVcenterVmSearchFacts {
    static $mode='missing';
    function __construct(...$args) {}
    function read_facts(...$args) {
        if(self::$mode==='failed') throw new RuntimeException('timeout private fixture');
        if(self::$mode==='denied') throw new RuntimeException('HTTP 403');
        $r=['vm-1'=>['name'=>'Fixture One','annotation'=>'Search note','mac_addresses'=>['00:11:22:33:44:55'],'guest_guest_full_name'=>'Debian','memory_mb'=>4096]];
        if(self::$mode==='full') $r['vm-2']=['name'=>'Fixture Two'];
        return ['rows'=>$r,'partial'=>false,'soap_request_count'=>3];
    }
}
class IntegrationVmwareVcenterVmTagIndex {
    static $mode='ok';
    function __construct(...$args) {}
    function read_index(...$args) {
        if(self::$mode==='denied') throw new RuntimeException('HTTP 403');
        if(self::$mode==='unsupported') throw new RuntimeException('bulk_tag_api_unsupported');
        return ['vm_tags'=>['vm-1'=>[['name'=>'test','category_name'=>'type']]],'tag_count'=>1,'category_count'=>1];
    }
}
$r=['endpoint'=>'https://fixture.invalid','username'=>'fixture','password'=>'fixture','verify_tls'=>false];
$x=phase5f34_build_search($r,1);
check($x['row_count']===2 && $x['partial'],'missing enrichment preserves topology VM');
check(str_contains($x['rows'][1]['search_blob'],'fixture two'),'fallback VM searchable');
check(str_contains($x['rows'][0]['search_blob'],'search note') && str_contains($x['rows'][0]['search_blob'],'00:11:22:33:44:55'),'notes/MAC');
IntegrationVmwareVcenterVmSearchFacts::$mode='full'; $x=phase5f34_build_search($r,1); check(!$x['partial'],'complete index');
IntegrationVmwareVcenterVmTagIndex::$mode='unsupported'; $x=phase5f34_build_search($r,1); check($x['partial'] && $x['row_count']===2,'unsupported tags not false complete');
IntegrationVmwareVcenterVmTagIndex::$mode='denied';
try { phase5f34_build_search($r,1); throw new RuntimeException('denial hidden'); } catch(IntegrationVmwareSnapshotAccessDenied $expected) {}
IntegrationVmwareVcenterVmTagIndex::$mode='ok'; IntegrationVmwareVcenterVmSearchFacts::$mode='failed';
$x=phase5f34_build_search($r,1); check($x['row_count']===2 && $x['partial'],'failed facts keep names');
IntegrationVmwareVcenterTopology::$vms=[];
$x=phase5f34_build_search($r,1); check(!$x['partial'] && $x['row_count']===0,'empty inventory valid');
echo "PHASE5F34_PROVIDER_COMPLETENESS_OK\n";
