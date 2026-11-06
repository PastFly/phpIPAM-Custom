<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/layers/dns1/functions/classes/class.IntegrationDnsAdminView.php';
require $root.'/tools/live/dns9-ui-smoke.php';
$id='33b6fade9323f15aa7b1f56a8a865a9d';
$zone=['name'=>'example.invalid','zone_type'=>'Primary','ds_integrated'=>true,'reverse_lookup'=>false];
$state=['profiles'=>[],'connections'=>[['id'=>$id,'revision'=>1,'name'=>'DNS','server'=>'dc.example.invalid',
    'enabled'=>false,'credential_mode'=>'collector_identity','interval_seconds'=>300]],
    'connection_runtime'=>[$id=>['zone_result'=>['id'=>'3af8e09411c0e94a17ed19d3f0872359',
        'finished_at'=>1700000000,'zones'=>array_fill(0,172,$zone)]]]];
if (dns9UiCheck($state)!==172) throw new RuntimeException('zone_count_invalid');
$html=(new IntegrationDnsAdminView())->render($state,'fixture');
if (!str_contains($html,'Показать зоны DNS (172)') || !str_contains($html,'data-dns-zones-toggle')) throw new RuntimeException('zone_disclosure_not_visible');
array_pop($state['connection_runtime'][$id]['zone_result']['zones']);
try {dns9UiCheck($state); throw new RuntimeException('missing_zone_accepted');}
catch (DomainException $e) {if ($e->getMessage()!=='zone_result_unavailable') throw $e;}
echo "DNS9_UI_SMOKE_TEST_OK=1\n";
