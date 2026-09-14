<?php
// Exercise the actual generated HTML with valid absent CPU and a guest-only interface.
define('PHPIPAM_VMWARE_MIRROR_GUARDED',true);
require $argv[1].'/app/integrations/vmware/mirror/native-views.php';
set_error_handler(static function($level,$message){throw new RuntimeException($message);});
$p=['counter_status'=>['cpu.usage.average'=>'aggregate_unavailable','mem.usage.average'=>'available'],'samples'=>[
    ['cpu_percent'=>null,'memory_percent'=>21.0],['cpu_percent'=>null,'memory_percent'=>25.0]],'provider'=>['refresh_rate'=>20]];
$html=PhpIpamMirrorViews\render_performance($p);
if (!str_contains($html,'не предоставляет агрегированные показатели CPU') || substr_count($html,'<svg')!==1 || !str_contains($html,'25.00%')) throw new RuntimeException('capability_and_ram_html');
$p['counter_status']['mem.usage.average']='aggregate_unavailable';$p['samples']=[];
$html=PhpIpamMirrorViews\render_performance($p);
if (str_contains($html,'<svg') || !str_contains($html,'CPU / RAM')) throw new RuntimeException('no_fake_graphs');
$guest=['status'=>'ok','interfaces'=>[['nic_external_id'=>null,'mac_address'=>'00:50:56:11:22:33','ip_addresses'=>[['ip_address'=>'192.0.2.1']]]],'ip_count'=>1];
$detail=['vm'=>['name'=>'VM fixture','power_state'=>'powered_on','disks'=>[]], 'guest_identity'=>[], 'tools'=>[]];
$context=['datacenter_external_id'=>'datacenter-1','cluster_external_id'=>null,'host_external_id'=>'host-1','datacenter_name'=>'DC','cluster_name'=>null,'host_name'=>'Host'];
$html=PhpIpamMirrorViews\render_vm($detail,$context,$guest,['tags'=>[],'tag_count'=>0],'vm-1',false,null);
if (!str_contains($html,'Без аппаратной привязки') || !str_contains($html,'192.0.2.1') || !str_contains($html,'00:50:56:11:22:33')) throw new RuntimeException('guest_unassociated_html');
echo "PHASE5G5_NULL_COUNTER_AND_GUEST_MAPPING_HTML_OK\n";
