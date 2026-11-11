#!/usr/bin/env python3
from pathlib import Path
p = Path(__file__).resolve().parents[1] / 'layers/phase5h8/functions/classes/class.IntegrationVmwareInventoryQuerySelector.php'
s = p.read_text(); n = 0
def ok(value, label):
    global n
    n += 1
    if not value:
        raise AssertionError(label)

for token in [
    'final class IntegrationVmwareInventoryQuerySelector',
    'private object $base;',
    'private $overlayFactory;',
    'private bool $enabled;',
    'private ?object $selected = null;',
    'public function __construct($base, callable $overlayFactory, bool $enabled = false)',
    "public function query(string $view = 'status'",
    'return $this->reader()->query($view, $id, $search, $page, $pageSize);',
    'if (!$this->enabled) return $this->base;',
    'if ($this->selected !== null) return $this->selected;',
    '$candidate = ($this->overlayFactory)($this->base);',
    'self::queryObject($candidate);',
    'if ($candidate === $this->base || $candidate === $this)',
    "throw new RuntimeException('inventory_overlay_unavailable')",
    "throw new InvalidArgumentException('invalid_inventory_query_reader')",
]:
    ok(token in s, 'required:' + token)

for token in [
    'IntegrationVmwareFastReader', 'IntegrationVmwareFastOverlayReader', 'IntegrationVmwareFastMetricCompat',
    'IntegrationVmwareFastStore', 'IntegrationVmwareVcenter', 'IntegrationVmwareBatchSession',
    'PropertyCollector', 'QueryPerf', 'curl_', 'SoapClient', 'IntegrationSecretCipher',
    'username', 'password', 'endpoint', 'secret_envelope',
    'file_put_contents', 'fopen(', 'unlink(', 'mkdir(', '->write(', '->publish(', '->update(',
    'system(', 'exec(', 'shell_exec(', 'proc_open(', 'getenv(', '$_ENV', '$_SERVER', 'time()', 'microtime(',
]:
    ok(token not in s, 'forbidden:' + token)

ok(s.index('if (!$this->enabled) return $this->base;') < s.index('$candidate = ($this->overlayFactory)($this->base);'), 'disabled_before_factory')
ok(s.count('($this->overlayFactory)(') == 1, 'single_factory_call_site')
ok(s.count('->query(') == 1, 'single_query_delegate')
print(f'PHASE5H8_QUERY_SELECTOR_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H8_SELECTOR_DEFAULT_FALSE_CONSTRUCTION_ISOLATION_OK')
print('PHASE5H8_SELECTOR_NO_PHASE5H_PROVIDER_WRITE_DEPENDENCY_OK')
