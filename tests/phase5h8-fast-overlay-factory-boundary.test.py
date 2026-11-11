#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[1]
config = (root / 'layers/phase5h8/functions/classes/class.IntegrationVmwareFastReaderConfig.php').read_text()
factory = (root / 'layers/phase5h8/functions/classes/class.IntegrationVmwareFastOverlayFactory.php').read_text()
native = (root / 'layers/phase5g2/app/integrations/vmware/mirror/native.php').read_text()
n = 0
def ok(value, label):
    global n
    n += 1
    if not value:
        raise AssertionError(label)

for token in [
    'final class IntegrationVmwareFastReaderConfig',
    "private const ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';",
    'public static function disabled(): self',
    'return new self(false, null, self::ROOT, 300, 300, 300);',
    'public static function fromArray(array $config): self',
    "['connector_id','enabled','max_metric_age','max_property_age','max_roster_age','root','schema']",
    "($config['schema'] ?? null) !== 1",
    "!is_bool($config['enabled'] ?? null)",
    "$config[$name] < 60 || $config[$name] > 300",
    'public function enabledFor(int $connectorId): bool',
    'return $this->enabled && $this->connectorId === $connectorId;',
]: ok(token in config, 'required_config:' + token)

for token in [
    'final class IntegrationVmwareFastOverlayFactory',
    'private IntegrationVmwareInventoryContext $context',
    'private IntegrationVmwareFastReaderConfig $config',
    'private int $connectorId',
    'public function selector($base): IntegrationVmwareInventoryQuerySelector',
    '$enabled = $this->config->enabledFor($this->connectorId);',
    '$store = $this->context->fastStore($this->config->root());',
    'new IntegrationVmwareFastReader(',
    '$this->config->maxPropertyAge()', '$this->config->maxMetricAge()', '$this->config->maxRosterAge()',
    'new IntegrationVmwareFastOverlayReader(',
    "[$base, 'query']", "[$this->context, 'current']", 'true',
    '}, $enabled);',
]: ok(token in factory, 'required_factory:' + token)

# The optional path must stay inside the selector factory closure; constructing the factory cannot touch fast storage.
closure = factory.index('return new IntegrationVmwareInventoryQuerySelector($base, function ($base) {')
fast_store = factory.index('$this->context->fastStore(', closure)
selector_end = factory.index('}, $enabled);', fast_store)
ok(closure < fast_store < selector_end, 'fast_store_outside_lazy_closure')
ok(factory.count('fastStore(') == 1, 'single_fast_store_call_site')
ok(factory.count('new IntegrationVmwareFastReader(') == 1, 'single_fast_reader_construction')
ok(factory.count('new IntegrationVmwareFastOverlayReader(') == 1, 'single_overlay_construction')

for text, name in [(config,'config'),(factory,'factory')]:
    for token in [
        'readPrivate', 'file_get_contents', 'json_decode', 'getenv(', '$_ENV', '$_SERVER',
        'IntegrationSecretCipher', 'workerRuntime', 'PropertyCollector', 'QueryPerf', 'curl_', 'SoapClient',
        'username', 'password', 'secret_envelope', 'file_put_contents', 'fopen(', 'unlink(', 'mkdir(',
        '->write(', '->publish(', '->update(', 'system(', 'exec(', 'shell_exec(', 'proc_open(',
    ]: ok(token not in text, f'forbidden_{name}:' + token)

# No route source is wired by the config/factory slice.
for token in ['IntegrationVmwareFastReaderConfig','IntegrationVmwareFastOverlayFactory','IntegrationVmwareInventoryQuerySelector','fastStore(','vmware-fast-shadow']:
    ok(token not in native, 'route_wiring_forbidden:' + token)

print(f'PHASE5H8_FAST_OVERLAY_FACTORY_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H8_FAST_CONFIG_NO_FILE_ENV_LOADER_OK')
print('PHASE5H8_FAST_FACTORY_LAZY_CONTEXT_ACCESS_OK')
print('PHASE5H8_FAST_FACTORY_NO_PROVIDER_WRITE_DEPENDENCY_OK')
print('PHASE5H8_FAST_FACTORY_ROUTE_STILL_UNWIRED_OK')
