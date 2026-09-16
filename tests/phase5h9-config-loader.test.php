<?php
require_once __DIR__ . '/../layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php';
require_once __DIR__ . '/../layers/phase5h9/functions/classes/class.IntegrationVmwareFastReaderConfigLoader.php';
$n = 0;
function cl(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
function clfails(callable $fn, string $code): void {
    global $n;
    try { $fn(); }
    catch (Throwable $e) { ++$n; if ($e->getMessage() === $code) return; throw $e; }
    throw new RuntimeException('expected_' . $code);
}

$reads = 0; $probes = 0;
$missing = IntegrationVmwareFastReaderConfigLoader::load(
    function (string $path) use (&$probes): bool { ++$probes; cl($path === IntegrationVmwareFastReaderConfigLoader::PATH, 'probe path'); return false; },
    function (string $path) use (&$reads): string { ++$reads; throw new RuntimeException('missing_reader_must_not_run'); }
);
cl($probes === 1 && $reads === 0, 'missing must not read');
cl(!$missing->enabledFor(1) && !$missing->enabledFor(99), 'missing is disabled');
cl($missing->root() === '/var/lib/phpipam-custom/vmware-fast-shadow', 'disabled root');
cl($missing->maxPropertyAge() === 300 && $missing->maxMetricAge() === 300 && $missing->maxRosterAge() === 300, 'disabled ages');

$valid = [
    'schema'=>1,'enabled'=>true,'connector_id'=>1,
    'root'=>'/var/lib/phpipam-custom/vmware-fast-shadow',
    'max_property_age'=>120,'max_metric_age'=>90,'max_roster_age'=>180,
];
$reads = 0;
$config = IntegrationVmwareFastReaderConfigLoader::load(
    static fn(string $path): bool => true,
    function (string $path) use (&$reads, $valid): string { ++$reads; cl($path === IntegrationVmwareFastReaderConfigLoader::PATH, 'read path'); return json_encode($valid, JSON_THROW_ON_ERROR); }
);
cl($reads === 1, 'read once');
cl($config->enabledFor(1), 'matching connector enabled');
cl(!$config->enabledFor(2), 'other connector disabled');
cl($config->maxPropertyAge() === 120 && $config->maxMetricAge() === 90 && $config->maxRosterAge() === 180, 'ages preserved');

$disabled = $valid; $disabled['enabled'] = false;
$config = IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p): bool => true,
    static fn(string $p): string => json_encode($disabled, JSON_THROW_ON_ERROR));
cl(!$config->enabledFor(1), 'explicit disabled');

clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p) => 'yes', static fn(string $p): string => '{}'), 'fast_reader_configuration_invalid');
clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p): bool => true, static fn(string $p): string => '{bad'), 'fast_reader_configuration_invalid');
clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p): bool => true, static fn(string $p): string => '[]'), 'fast_reader_configuration_invalid');
$bad = $valid; $bad['enabled'] = 1;
clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p): bool => true,
    static fn(string $p): string => json_encode($bad, JSON_THROW_ON_ERROR)), 'fast_reader_configuration_invalid');
$bad = $valid; $bad['extra'] = true;
clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p): bool => true,
    static fn(string $p): string => json_encode($bad, JSON_THROW_ON_ERROR)), 'fast_reader_configuration_invalid');
$oversize = str_repeat('x', 4097);
clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p): bool => true, static fn(string $p): string => $oversize), 'fast_reader_configuration_invalid');

// Secure-reader/runtime failures are preserved rather than disguised as a missing configuration.
clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(static fn(string $p): bool => true,
    static function (string $p): string { throw new RuntimeException('cache_private_file_unavailable'); }), 'cache_private_file_unavailable');
clfails(fn()=>IntegrationVmwareFastReaderConfigLoader::load(
    static function (string $p): bool { throw new RuntimeException('cache_path_invalid'); },
    static fn(string $p): string => '{}'), 'cache_path_invalid');

cl(IntegrationVmwareFastReaderConfigLoader::PATH === '/etc/phpipam-custom/vmware-fast-reader.json', 'fixed config path');
echo 'PHASE5H9_CONFIG_LOADER_ASSERTIONS=' . $n . "\n";
echo "PHASE5H9_CONFIG_MISSING_EXACT_DISABLED_OK\n";
echo "PHASE5H9_CONFIG_INVALID_FAIL_CLOSED_OK\n";
echo "PHASE5H9_CONFIG_FIXED_PATH_OK\n";
