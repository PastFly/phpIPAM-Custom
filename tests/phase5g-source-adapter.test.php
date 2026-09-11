<?php
/** Network is deliberately stubbed; these are adapter-boundary tests, not live TLS tests. */
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventoryWorker.php';
class IntegrationVmwareVcenter { public function normalize_endpoint($v) { return rtrim($v, '/'); } }
class IntegrationVmwareVcenterDirectRestTransport {
    public static array $calls = []; public static int $status = 200;
    public function __construct(...$args) {}
    public function __invoke(...$args) { self::$calls[] = $args; return ['status' => self::$status, 'body' => '{}']; }
}
class IntegrationVmwareVcenterDirectTransport {
    public static array $calls = []; public static string $body = '<ok/>';
    public function __construct(...$args) {}
    public function __invoke(...$args) { self::$calls[] = $args; return ['status' => 200, 'body' => self::$body]; }
}
class IntegrationVmwareVcenterFaultAwareTransport {
    private $transport;
    public function __construct($t) { $this->transport = $t; }
    public function __invoke(...$a) { return ($this->transport)(...$a); }
}
require_once __DIR__ . '/../layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php';
function denied(callable $f, string $code): void { try { $f(); } catch (Throwable $e) { if (str_contains($e->getMessage(), $code)) return; throw $e; } throw new RuntimeException('expected ' . $code); }
$r = ['id' => 1, 'endpoint' => 'https://fixture.invalid', 'username' => 'fixture', 'password' => 'not-production', 'verify_tls' => true];
denied(fn() => new IntegrationVmwareInventorySource(array_replace($r, ['verify_tls' => false])), 'strict_tls_worker_required');
$source = new IntegrationVmwareInventorySource($r);
$rest = (new ReflectionProperty($source, 'rest'))->getValue($source);
$soap = (new ReflectionProperty($source, 'soap'))->getValue($source);
$rest('GET', $r['endpoint'] . '/api/vcenter/vm', [], null, null, true, 8, 20);
if (count(IntegrationVmwareVcenterDirectRestTransport::$calls) !== 1) throw new RuntimeException('missing read');
denied(fn() => $rest('POST', $r['endpoint'] . '/api/vcenter/vm/vm-1/power', [], null, null, true, 8, 20), 'transport_method');
denied(fn() => $rest('GET', 'https://other.invalid/api/vcenter/vm', [], null, null, true, 8, 20), 'transport_scope');
denied(fn() => $rest('GET', $r['endpoint'] . '/api/vcenter/vm', [], null, null, false, 8, 20), 'transport_scope');
if (count(IntegrationVmwareVcenterDirectRestTransport::$calls) !== 1) throw new RuntimeException('denied call escaped');
$rest('POST', $r['endpoint'] . '/api/session', [], 'fixture', 'not-production', true, 8, 20);
$rest('DELETE', $r['endpoint'] . '/api/session', [], null, null, true, 8, 20);
IntegrationVmwareVcenterDirectRestTransport::$status = 403;
denied(fn() => $rest('GET', $r['endpoint'] . '/api/vcenter/vm', [], null, null, true, 8, 20), 'access_denied');
$soap('POST', $r['endpoint'] . '/sdk', [], '<RetrieveProperties xmlns="urn:vim25"><_this>propertyCollector</_this></RetrieveProperties>', true, 8, 20);
denied(fn() => $soap('POST', $r['endpoint'] . '/sdk', [], '<PowerOnVM_Task xmlns="urn:vim25"/>', true, 8, 20), 'soap_operation');
denied(fn() => $soap('POST', $r['endpoint'] . '/sdk', [], '<RetrievePropertiesEx xmlns="urn:vim25"/>', true, 8, 20), 'soap_operation');
IntegrationVmwareVcenterDirectTransport::$body = '<soap:Fault><detail><NoPermissionFault/></detail></soap:Fault>';
denied(fn() => $soap('POST', $r['endpoint'] . '/sdk', [], '<RetrieveProperties xmlns="urn:vim25"><_this>propertyCollector</_this></RetrieveProperties>', true, 8, 20), 'access_denied');
if (!(new ReflectionProperty($source, 'denial'))->getValue($source)) throw new RuntimeException('sticky transport denial was lost');
echo "PHASE5G_ADAPTER_SCOPE_TLS_DENIAL_OK\n";
