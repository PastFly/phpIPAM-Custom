<?php
/** Generated 5G.5 source must turn only structured stale-MOR SOAP faults into membership drift. */
if ($argc !== 2 || !is_dir($argv[1])) throw new RuntimeException('generated_runtime_root_required');
$root = rtrim($argv[1], '/');
require_once $root . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventoryWorker.php';

class IntegrationVmwareVcenter
{
    public function normalize_endpoint($value) { return rtrim((string)$value, '/'); }
}
class IntegrationVmwareVcenterDirectRestTransport
{
    public function __construct(...$args) {}
    public function __invoke(...$args): array { return ['status' => 200, 'body' => '{}', 'headers' => []]; }
}
class IntegrationVmwareVcenterDirectTransport
{
    public static array $reply = ['status' => 200, 'body' => '<ok/>', 'headers' => []];
    public function __construct(...$args) {}
    public function __invoke(...$args): array { return self::$reply; }
}
class IntegrationVmwareVcenterFaultAwareTransport
{
    private $transport;
    public function __construct($transport) { $this->transport = $transport; }
    public function __invoke(...$args): array { return ($this->transport)(...$args); }
}

require_once $root . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php';

function expect_code(callable $callback, string $expected): void
{
    try { $callback(); }
    catch (Throwable $e) {
        if ($e->getMessage() !== $expected) {
            throw new RuntimeException('expected ' . $expected . ', got ' . $e->getMessage());
        }
        return;
    }
    throw new RuntimeException('expected exception ' . $expected);
}

$runtime = [
    'id' => 1,
    'endpoint' => 'https://fixture.invalid',
    'username' => 'fixture',
    'password' => 'not-production',
    'verify_tls' => true,
];
$source = new IntegrationVmwareInventorySource($runtime);
$soap = (new ReflectionProperty($source, 'soap'))->getValue($source);
$request = '<RetrieveProperties xmlns="urn:vim25"><_this>propertyCollector</_this></RetrieveProperties>';

IntegrationVmwareVcenterDirectTransport::$reply = [
    'status' => 500,
    'headers' => [],
    'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><detail><ManagedObjectNotFoundFault xmlns="urn:vim25"/></detail></s:Fault></s:Body></s:Envelope>',
];
expect_code(fn() => $soap('POST', $runtime['endpoint'] . '/sdk', [], $request, true, 8, 20), 'mirror_membership_changed');

IntegrationVmwareVcenterDirectTransport::$reply = [
    'status' => 500,
    'headers' => [],
    'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><s:Body><s:Fault><detail><fault xsi:type="ManagedObjectNotFound"/></detail></s:Fault></s:Body></s:Envelope>',
];
expect_code(fn() => $soap('POST', $runtime['endpoint'] . '/sdk', [], $request, true, 8, 20), 'mirror_membership_changed');

// A generic SOAP fault is not silently relabeled as roster drift.
IntegrationVmwareVcenterDirectTransport::$reply = [
    'status' => 500,
    'headers' => [],
    'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><detail><InvalidPropertyFault xmlns="urn:vim25"/></detail></s:Fault></s:Body></s:Envelope>',
];
$result = $soap('POST', $runtime['endpoint'] . '/sdk', [], $request, true, 8, 20);
if (($result['status'] ?? null) !== 500) throw new RuntimeException('generic_fault_was_reclassified');

// Authorization faults retain the stronger existing fail-closed path.
IntegrationVmwareVcenterDirectTransport::$reply = [
    'status' => 500,
    'headers' => [],
    'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><detail><NoPermissionFault xmlns="urn:vim25"/></detail></s:Fault></s:Body></s:Envelope>',
];
expect_code(fn() => $soap('POST', $runtime['endpoint'] . '/sdk', [], $request, true, 8, 20), 'mirror_access_denied');

// HTTP success carrying an ordinary property value cannot trigger drift classification.
IntegrationVmwareVcenterDirectTransport::$reply = [
    'status' => 200,
    'headers' => [],
    'body' => '<RetrievePropertiesResponse xmlns="urn:vim25"><returnval><propSet><name>name</name><val>ManagedObjectNotFound</val></propSet></returnval></RetrievePropertiesResponse>',
];
$result = $soap('POST', $runtime['endpoint'] . '/sdk', [], $request, true, 8, 20);
if (($result['status'] ?? null) !== 200) throw new RuntimeException('success_response_changed');

echo "PHASE5G5_STALE_HOST_MOR_MEMBERSHIP_RECOVERY_OK\n";
