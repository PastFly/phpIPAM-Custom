<?php
// Narrow stubs exercise H9 loader -> H8 factory -> H8 selector without phpIPAM/bootstrap side effects.
final class IntegrationVmwareInventoryContext
{
    public int $fastStoreCalls = 0;
    public function fastStore(string $root): object { ++$this->fastStoreCalls; return (object)['root'=>$root]; }
    public function current(): bool { return true; }
}
final class IntegrationVmwareFastReader
{
    public function __construct(public object $store, public int $propertyAge, public int $metricAge, public int $rosterAge) {}
}
final class IntegrationVmwareFastOverlayReader
{
    private $base;
    public function __construct(callable $base, IntegrationVmwareFastReader $fast, callable $authorized,
        bool $enabled, ?callable $clock = null) { $this->base = $base; }
    public function query(string $view='status', string $id='', string $search='', int $page=1, int $pageSize=50): array
    { $out = ($this->base)($view,$id,$search,$page,$pageSize); $out['phase5h_selected'] = true; return $out; }
}
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php';
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php';
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareFastOverlayFactory.php';
require_once __DIR__ . '/../layers/phase5h9/functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php';
$n=0;
function rs(bool $ok,string $why):void{global $n;++$n;if(!$ok)throw new RuntimeException($why);}
function rsfails(callable $f,string $code):void{global $n;try{$f();}catch(Throwable $e){++$n;if($e->getMessage()===$code)return;throw $e;}throw new RuntimeException('expected_'.$code);}

$base = new class {
    public int $calls=0;
    public function query(string $view='status',string $id='',string $search='',int $page=1,int $pageSize=50):array
    { ++$this->calls; return ['source'=>'phase5g','view'=>$view,'id'=>$id,'vcenter_requests'=>0,'inventory_files_written'=>false]; }
};
$context = new IntegrationVmwareInventoryContext();

// Missing config is exact disabled selection: no secure read and no fast-store construction.
$reads=0;
$config = IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p):bool=>false,
    function(string $p)use(&$reads):string{++$reads;throw new RuntimeException('read_must_not_run');});
$selector=(new IntegrationVmwareFastOverlayFactory($context,$config,1))->selector($base);
$r=$selector->query('vm','vm-1');
rs($r===['source'=>'phase5g','view'=>'vm','id'=>'vm-1','vcenter_requests'=>0,'inventory_files_written'=>false],'missing exact base');
rs($reads===0 && $context->fastStoreCalls===0,'missing construction isolation');

// Explicit disabled and connector-mismatched enabled config also never touch fast domains.
$raw=['schema'=>1,'enabled'=>false,'connector_id'=>1,'root'=>'/var/lib/phpipam-custom/vmware-fast-shadow',
    'max_property_age'=>120,'max_metric_age'=>90,'max_roster_age'=>180];
$config=IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p):bool=>true,
    static fn(string $p):string=>json_encode($raw,JSON_THROW_ON_ERROR));
$r=(new IntegrationVmwareFastOverlayFactory($context,$config,1))->selector($base)->query('status');
rs(($r['source']??null)==='phase5g' && !isset($r['phase5h_selected']),'explicit disabled base');
rs($context->fastStoreCalls===0,'explicit disabled construction isolation');
$raw['enabled']=true;$raw['connector_id']=2;
$config=IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p):bool=>true,
    static fn(string $p):string=>json_encode($raw,JSON_THROW_ON_ERROR));
$r=(new IntegrationVmwareFastOverlayFactory($context,$config,1))->selector($base)->query('vm','vm-2');
rs(($r['source']??null)==='phase5g' && !isset($r['phase5h_selected']),'connector mismatch base');
rs($context->fastStoreCalls===0,'connector mismatch construction isolation');

// Matching explicit enablement lazily constructs the fast reader only on the first query.
$raw['connector_id']=1;
$config=IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p):bool=>true,
    static fn(string $p):string=>json_encode($raw,JSON_THROW_ON_ERROR));
$selector=(new IntegrationVmwareFastOverlayFactory($context,$config,1))->selector($base);
rs($context->fastStoreCalls===0,'enabled still lazy');
$r=$selector->query('vm','vm-3');
rs(($r['source']??null)==='phase5g' && ($r['phase5h_selected']??false)===true,'enabled overlay selected');
rs($context->fastStoreCalls===1,'enabled one fast-store construction');
$selector->query('vm','vm-4');
rs($context->fastStoreCalls===1,'selected overlay memoized');

// Existing malformed config is a server configuration failure, not a fallback.
rsfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p):bool=>true,
    static fn(string $p):string=>'{bad'), 'fast_reader_configuration_invalid');
rs($base->calls===5,'base query call count');

echo 'PHASE5H9_DISABLED_ROUTE_SELECTION_ASSERTIONS='.$n."\n";
echo "PHASE5H9_MISSING_DISABLED_ZERO_FASTSTORE_OK\n";
echo "PHASE5H9_MISMATCH_DISABLED_ZERO_FASTSTORE_OK\n";
echo "PHASE5H9_EXPLICIT_ENABLE_LAZY_FASTSTORE_OK\n";
