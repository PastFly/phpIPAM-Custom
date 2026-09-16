<?php
final class IntegrationVmwareFastStore
{
    public function __construct(public string $root) {}
}
final class IntegrationVmwareInventoryContext
{
    public int $fastStoreCalls = 0;
    public int $currentCalls = 0;
    public array $roots = [];
    public function fastStore(string $root): IntegrationVmwareFastStore
    {
        ++$this->fastStoreCalls; $this->roots[] = $root; return new IntegrationVmwareFastStore($root);
    }
    public function current(): bool { ++$this->currentCalls; return true; }
}
final class IntegrationVmwareFastReader
{
    public static int $constructed = 0;
    public function __construct(
        public IntegrationVmwareFastStore $store,
        public int $propertyAge,
        public int $metricAge,
        public int $rosterAge
    ) { ++self::$constructed; }
}
final class IntegrationVmwareFastOverlayReader
{
    public static int $constructed = 0;
    public static ?self $last = null;
    private $base;
    private $authorized;
    private $clock;
    public function __construct(
        callable $base,
        public IntegrationVmwareFastReader $fast,
        callable $authorized,
        public bool $enabled,
        ?callable $clock = null
    ) {
        ++self::$constructed; self::$last = $this;
        $this->base = $base; $this->authorized = $authorized; $this->clock = $clock;
    }
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        if (($this->authorized)() !== true) throw new RuntimeException('mirror_access_denied');
        $out = ($this->base)($view,$id,$search,$page,$pageSize);
        $out['phase5h_factory_overlay'] = true;
        $out['clock_value'] = $this->clock === null ? null : ($this->clock)();
        return $out;
    }
}
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php';
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php';
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareFastOverlayFactory.php';
$n = 0;
function ff(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
function fffails(callable $f, string $code): void { global $n; try { $f(); } catch (Throwable $e) { ++$n; if ($e->getMessage() === $code) return; throw $e; } throw new RuntimeException('expected_' . $code); }

$base = new class {
    public int $calls = 0;
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    { ++$this->calls; return ['generation'=>'phase5g','view'=>$view,'id'=>$id]; }
};

// Canonical disabled config never reaches Context::fastStore() or constructs any fast class.
$ctx = new IntegrationVmwareInventoryContext();
$disabled = new IntegrationVmwareFastOverlayFactory($ctx, IntegrationVmwareFastReaderConfig::disabled(), 1, static fn(): int => 123);
$selector = $disabled->selector($base);
ff($selector instanceof IntegrationVmwareInventoryQuerySelector, 'disabled selector type');
ff($ctx->fastStoreCalls===0 && IntegrationVmwareFastReader::$constructed===0 && IntegrationVmwareFastOverlayReader::$constructed===0, 'disabled eager construction');
$r = $selector->query('vm','vm-1');
ff($r===['generation'=>'phase5g','view'=>'vm','id'=>'vm-1'], 'disabled exact base result');
ff($ctx->fastStoreCalls===0 && $ctx->currentCalls===0, 'disabled touched context');

$config = IntegrationVmwareFastReaderConfig::fromArray([
    'schema'=>1,'enabled'=>true,'connector_id'=>1,'root'=>'/var/lib/phpipam-custom/vmware-fast-shadow',
    'max_property_age'=>120,'max_metric_age'=>180,'max_roster_age'=>240,
]);
$ctx2 = new IntegrationVmwareInventoryContext();
$factory = new IntegrationVmwareFastOverlayFactory($ctx2, $config, 1, static fn(): int => 1800000000);
$enabled = $factory->selector($base);
ff($ctx2->fastStoreCalls===0 && IntegrationVmwareFastOverlayReader::$constructed===0, 'enabled selector was eager');
$r = $enabled->query('vm','vm-2');
ff($r['generation']==='phase5g' && $r['phase5h_factory_overlay']===true, 'overlay result');
ff($r['clock_value']===1800000000, 'clock propagation');
ff($ctx2->fastStoreCalls===1 && $ctx2->roots===['/var/lib/phpipam-custom/vmware-fast-shadow'], 'exact fast root construction');
ff(IntegrationVmwareFastReader::$constructed===1 && IntegrationVmwareFastOverlayReader::$constructed===1, 'fast construction count');
ff(IntegrationVmwareFastOverlayReader::$last->fast->propertyAge===120, 'property age propagation');
ff(IntegrationVmwareFastOverlayReader::$last->fast->metricAge===180, 'metric age propagation');
ff(IntegrationVmwareFastOverlayReader::$last->fast->rosterAge===240, 'roster age propagation');
ff(IntegrationVmwareFastOverlayReader::$last->enabled===true, 'overlay must be enabled inside selected path');
ff($ctx2->currentCalls===1, 'overlay authorization callable');
$enabled->query('status');
ff($ctx2->fastStoreCalls===1 && IntegrationVmwareFastReader::$constructed===1 && IntegrationVmwareFastOverlayReader::$constructed===1, 'factory not memoized by selector');
ff($ctx2->currentCalls===2, 'authorization recheck on subsequent query');

// An enabled profile for another connector is an exact disabled path for this factory instance.
$config2 = IntegrationVmwareFastReaderConfig::fromArray([
    'schema'=>1,'enabled'=>true,'connector_id'=>2,'root'=>'/var/lib/phpipam-custom/vmware-fast-shadow',
    'max_property_age'=>300,'max_metric_age'=>300,'max_roster_age'=>300,
]);
$ctx3 = new IntegrationVmwareInventoryContext();
$mismatch = (new IntegrationVmwareFastOverlayFactory($ctx3,$config2,1))->selector($base);
$r = $mismatch->query('status');
ff($r['generation']==='phase5g' && !isset($r['phase5h_factory_overlay']), 'connector mismatch fallback');
ff($ctx3->fastStoreCalls===0 && $ctx3->currentCalls===0, 'connector mismatch touched fast context');

// Config contract is exact: unknown/missing/coerced values never enable the reader.
$valid = ['schema'=>1,'enabled'=>true,'connector_id'=>1,'root'=>'/var/lib/phpipam-custom/vmware-fast-shadow',
    'max_property_age'=>60,'max_metric_age'=>300,'max_roster_age'=>120];
ff(IntegrationVmwareFastReaderConfig::fromArray($valid)->enabledFor(1)===true, 'valid enabled config');
ff(IntegrationVmwareFastReaderConfig::fromArray($valid)->enabledFor(2)===false, 'connector-specific enablement');
foreach ([
    array_diff_key($valid,['schema'=>true]),
    $valid+['extra'=>1],
    array_replace($valid,['schema'=>'1']),
    array_replace($valid,['enabled'=>1]),
    array_replace($valid,['connector_id'=>'1']),
    array_replace($valid,['root'=>'/tmp/shadow']),
    array_replace($valid,['max_property_age'=>59]),
    array_replace($valid,['max_metric_age'=>301]),
    array_replace($valid,['max_roster_age'=>'120']),
] as $bad) fffails(fn()=>IntegrationVmwareFastReaderConfig::fromArray($bad),'fast_reader_config_invalid');
fffails(fn()=>IntegrationVmwareFastReaderConfig::disabled()->enabledFor(0),'fast_reader_connector_invalid');
fffails(fn()=>new IntegrationVmwareFastOverlayFactory($ctx,$config,0),'fast_reader_connector_invalid');

echo 'PHASE5H8_FAST_OVERLAY_FACTORY_ASSERTIONS='.$n."\n";
echo "PHASE5H8_FAST_CONFIG_DEFAULT_DISABLED_OK\n";
echo "PHASE5H8_FAST_FACTORY_LAZY_CONSTRUCTION_OK\n";
echo "PHASE5H8_FAST_FACTORY_CONNECTOR_SCOPE_OK\n";
echo "PHASE5H8_FAST_FACTORY_AGE_PROPAGATION_OK\n";
