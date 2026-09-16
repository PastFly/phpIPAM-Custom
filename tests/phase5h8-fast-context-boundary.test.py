#!/usr/bin/env python3
from pathlib import Path
root = Path(__file__).resolve().parents[1]
ctx = (root / 'layers/phase5g/functions/classes/class.IntegrationVmwareInventoryContext.php').read_text()
builder = (root / 'tools/build-phase5g5-runtime.py').read_text()
native = (root / 'layers/phase5g2/app/integrations/vmware/mirror/native.php').read_text()
n = 0
def ok(value, label):
    global n
    n += 1
    if not value:
        raise AssertionError(label)

start = ctx.index('    public function fastStore(')
end = ctx.index('    public function workerRuntime(', start)
fast = ctx[start:end]
for token in [
    'public function fastStore(string $root): IntegrationVmwareFastStore',
    "$root !== '/var/lib/phpipam-custom/vmware-fast-shadow'",
    "throw new RuntimeException('mirror_configuration_invalid')",
    "if (!$this->current()) throw new RuntimeException('mirror_access_denied')",
    'return new IntegrationVmwareFastStore($root, $this->key, $this->binding);',
]:
    ok(token in fast, 'required_fast_context:' + token)

ok(fast.index("$root !== '/var/lib/phpipam-custom/vmware-fast-shadow'") < fast.index('$this->current()'), 'root_guard_before_authorization')
ok(fast.index('$this->current()') < fast.index('new IntegrationVmwareFastStore'), 'authorization_before_construction')

for token in [
    'IntegrationSecretCipher', 'workerRuntime', 'endpoint', 'username', 'password', 'secret_envelope',
    'readPrivate', 'getObjectsQuery', 'file_put_contents', 'fopen(', 'unlink(', 'mkdir(',
    '->write(', '->publish(', '->update(', 'curl_', 'SoapClient', 'PropertyCollector', 'QueryPerf',
    'getenv(', '$_ENV', '$_SERVER', 'system(', 'exec(', 'shell_exec(', 'proc_open(',
]:
    ok(token not in fast, 'forbidden_fast_context:' + token)

for token in ['return $this->key', 'return $this->binding', 'function key(', 'function binding(', 'function masterKey(']:
    ok(token not in ctx, 'secret_getter_forbidden:' + token)

# The 5G.5 recovery builder stages the transformed Phase5G Context into both release and app.
for token in [
    "context = 'functions/classes/class.IntegrationVmwareInventoryContext.php'",
    "release['layers/phase5g/' + context]",
    "replace('/etc/phpipam-custom/vmware-inventory.json', CONFIG)",
    "replace('/var/lib/phpipam-custom/vmware-inventory', DATA)",
    "app[context] = release['layers/phase5g/' + context] = text.encode()",
]:
    ok(token in builder, 'recovery_builder_context:' + token)

# Source serving route is still untouched; selector/fast-store construction is not wired here yet.
for token in ['IntegrationVmwareInventoryQuerySelector', 'IntegrationVmwareFastStore', 'fastStore(', 'vmware-fast-shadow']:
    ok(token not in native, 'route_wiring_forbidden:' + token)

print(f'PHASE5H8_FAST_CONTEXT_BOUNDARY_ASSERTIONS={n}')
print('PHASE5H8_FAST_CONTEXT_NO_KEY_GETTER_OK')
print('PHASE5H8_FAST_CONTEXT_5G5_TRANSFORM_PRESERVED_OK')
print('PHASE5H8_FAST_CONTEXT_ROUTE_STILL_UNWIRED_OK')
