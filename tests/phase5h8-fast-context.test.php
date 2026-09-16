<?php
// Isolated stubs let the Context method be exercised without /var/www/html or secret files.
final class IntegrationVmwareInventoryStore
{
    public static function binding(string $key, string $application, array $row): string
    {
        return hash_hmac('sha256', json_encode([$application,(string)($row['id'] ?? ''),$row['provider'] ?? '',
            $row['endpoint'] ?? '',$row['username'] ?? '',$row['secret_envelope'] ?? '',$row['secret_key_id'] ?? ''], JSON_THROW_ON_ERROR), $key);
    }
}
final class IntegrationVmwareFastStore
{
    public static int $constructed = 0;
    public function __construct(public string $root, public string $masterKey, public string $binding)
    { ++self::$constructed; }
}
final class Phase5h8ContextDb
{
    public int $calls = 0;
    public function __construct(public array $row) {}
    public function getObjectsQuery(...$args): array { ++$this->calls; return [$this->row]; }
}
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryContext.php';
$n = 0;
function fc(bool $ok, string $why): void { global $n; ++$n; if (!$ok) throw new RuntimeException($why); }
function fcfails(callable $f, string $code): void { global $n; try { $f(); } catch (Throwable $e) { ++$n; if ($e->getMessage() === $code) return; throw $e; } throw new RuntimeException('expected_' . $code); }
function setPrivate(object $object, string $name, mixed $value): void {
    $p = new ReflectionProperty($object, $name); $p->setAccessible(true); $p->setValue($object, $value);
}

$row = ['id'=>1,'provider'=>'vmware_vcenter','enabled'=>1,'endpoint'=>'https://vcenter.test','username'=>'svc',
    'verify_tls'=>1,'secret_envelope'=>'cipher-one','secret_key_id'=>'key-one'];
$db = new Phase5h8ContextDb($row);
$key = random_bytes(32); $binding = IntegrationVmwareInventoryStore::binding($key, '/var/www/html', $row);
$ref = new ReflectionClass(IntegrationVmwareInventoryContext::class);
$context = $ref->newInstanceWithoutConstructor();
setPrivate($context, 'database', $db); setPrivate($context, 'application', '/var/www/html'); setPrivate($context, 'id', 1);
setPrivate($context, 'key', $key); setPrivate($context, 'binding', $binding); setPrivate($context, 'row', $row);
setPrivate($context, 'directory', '/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1');

$fast = $context->fastStore('/var/lib/phpipam-custom/vmware-fast-shadow');
fc($fast instanceof IntegrationVmwareFastStore, 'fast store type');
fc($fast->root === '/var/lib/phpipam-custom/vmware-fast-shadow', 'exact fast root');
fc(hash_equals($key, $fast->masterKey), 'master key not passed exactly');
fc(hash_equals($binding, $fast->binding), 'binding not passed exactly');
fc(IntegrationVmwareFastStore::$constructed === 1, 'unexpected construction count');
fc($db->calls === 1, 'authorization not rechecked');

// Root validation happens before authorization and never constructs a store.
fcfails(fn()=>$context->fastStore('/var/lib/phpipam-custom/other'), 'mirror_configuration_invalid');
fc($db->calls === 1 && IntegrationVmwareFastStore::$constructed === 1, 'invalid root caused side effect');

// A changed credential identity invalidates the current binding before any fast store is returned.
$db->row['secret_envelope'] = 'cipher-two';
fcfails(fn()=>$context->fastStore('/var/lib/phpipam-custom/vmware-fast-shadow'), 'mirror_access_denied');
fc(IntegrationVmwareFastStore::$constructed === 1, 'binding mismatch constructed store');

// Disabled connector is also fail-closed through current() / loadRow().
$db->row = $row; $db->row['enabled'] = 0;
fcfails(fn()=>$context->fastStore('/var/lib/phpipam-custom/vmware-fast-shadow'), 'mirror_access_denied');
fc(IntegrationVmwareFastStore::$constructed === 1, 'disabled connector constructed store');

echo 'PHASE5H8_FAST_CONTEXT_ASSERTIONS='.$n."\n";
echo "PHASE5H8_FAST_CONTEXT_KEY_NOT_EXPOSED_OK\n";
echo "PHASE5H8_FAST_CONTEXT_BINDING_RECHECK_OK\n";
echo "PHASE5H8_FAST_CONTEXT_EXACT_ROOT_OK\n";
