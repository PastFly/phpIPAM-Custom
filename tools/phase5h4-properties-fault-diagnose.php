#!/usr/bin/env php
<?php
/**
 * Narrow non-writing diagnostic for a Phase5H inventory property SOAP fault.
 * It shares the active 5G.5 connector worker mutex, repeats only the bounded
 * BatchInventory property sweep, and reports only an allowlisted SOAP fault type.
 */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H_PROPFAULT_ERROR=identity\nPHASE5H_PROPFAULT_EXIT=1\n");
    exit(1);
}

const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const ACTIVE_WORKER_LOCK = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock';
const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

$runtime = null;
$productionLock = null;
$lockHeld = false;
$propertyBatch = 0;
$faultClass = null;
$started = microtime(true);

$clean = static function (Throwable $e): string {
    $m = $e->getMessage();
    return preg_match('/^(?:batch|shadow|mirror|active)_[a-z0-9_:-]{1,90}$/D', $m) ? $m : 'unexpected';
};

$classifyFault = static function (string $wire): ?string {
    if ($wire === '' || strlen($wire) > 8388608 || preg_match('/<!DOCTYPE|<!ENTITY/i', $wire)) return null;
    $doc = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    try { $ok = $doc->loadXML($wire, LIBXML_NONET | LIBXML_NOBLANKS); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    if (!$ok || $doc->doctype !== null) return null;
    $xp = new DOMXPath($doc);
    $fault = $xp->query('//*[local-name()="Fault"]')->item(0);
    if (!$fault instanceof DOMElement) return null;

    $map = [
        'NoPermission' => 'access_denied', 'NoPermissionFault' => 'access_denied',
        'NotAuthenticated' => 'access_denied', 'NotAuthenticatedFault' => 'access_denied',
        'ManagedObjectNotFound' => 'managed_object_not_found', 'ManagedObjectNotFoundFault' => 'managed_object_not_found',
        'InvalidProperty' => 'invalid_property', 'InvalidPropertyFault' => 'invalid_property',
        'InvalidArgument' => 'invalid_argument', 'InvalidArgumentFault' => 'invalid_argument',
        'NotSupported' => 'not_supported', 'NotSupportedFault' => 'not_supported',
        'MethodNotFound' => 'method_not_found', 'MethodNotFoundFault' => 'method_not_found',
        'InvalidType' => 'invalid_type', 'InvalidTypeFault' => 'invalid_type',
    ];
    foreach ($xp->query('.//*', $fault) as $node) {
        if (!$node instanceof DOMElement) continue;
        $type = preg_replace('/^.*:/', '', $node->getAttributeNS(XSI, 'type'));
        foreach ([$node->localName, $type] as $token) {
            if (is_string($token) && isset($map[$token])) return $map[$token];
        }
    }
    return 'soap_fault';
};

try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService','IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterDirectTransport'] as $name) {
        require_once APP . '/functions/classes/class.' . $name . '.php';
    }
    foreach (['Store','Context'] as $name) {
        require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    }
    $root = dirname(__DIR__);
    foreach (['Session','Inventory'] as $name) {
        require_once $root . '/layers/phase5h/functions/classes/class.IntegrationVmwareBatch' . $name . '.php';
    }
    require_once $root . '/layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';

    $db = new Database_PDO();
    $ctx = new IntegrationVmwareInventoryContext($db, APP, 1);
    $store = $ctx->store();

    IntegrationVmwareInventoryStore::noLinks(ACTIVE_WORKER_LOCK);
    clearstatcache(true, ACTIVE_WORKER_LOCK);
    $st = @lstat(ACTIVE_WORKER_LOCK);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['uid'] !== 33 || $st['gid'] !== 33 ||
        ($st['mode'] & 0777) !== 0600 || $st['nlink'] !== 1 || $st['size'] !== 0) {
        throw new RuntimeException('active_worker_lock_invalid');
    }
    $productionLock = @fopen(ACTIVE_WORKER_LOCK, 'r+b');
    if ($productionLock === false) throw new RuntimeException('active_worker_lock_invalid');
    $opened = fstat($productionLock);
    if (!$opened || $opened['ino'] !== $st['ino'] || $opened['dev'] !== $st['dev'] ||
        ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0600 ||
        $opened['uid'] !== 33 || $opened['gid'] !== 33 || $opened['nlink'] !== 1 || $opened['size'] !== 0) {
        throw new RuntimeException('active_worker_lock_changed');
    }

    $wait = microtime(true); $announced = false;
    while (!flock($productionLock, LOCK_EX | LOCK_NB)) {
        if (!$announced) { print "PHASE5H_PROPFAULT_LOCK_WAIT=1\n"; $announced = true; }
        if (microtime(true) - $wait >= 600) throw new RuntimeException('active_worker_busy');
        usleep(250000);
    }
    $lockHeld = true;
    print "PHASE5H_PROPFAULT_LOCK_ACQUIRED=1\n";
    print 'PHASE5H_PROPFAULT_LOCK_WAIT_MS=' . (int)round((microtime(true) - $wait) * 1000) . "\n";

    if (!$ctx->current()) throw new RuntimeException('mirror_access_denied');
    $current = $store->read('current');
    if (!is_array($current) || !is_array($current['inventory'] ?? null) || !empty($current['blocked'])) {
        throw new RuntimeException('shadow_inventory_unavailable');
    }
    $runtime = $ctx->workerRuntime(IntegrationSecretCipher::from_keyring_file());
    $roster = IntegrationVmwareShadowPlanner::roster($current);
    printf("PHASE5H_PROPFAULT_COUNTS=%s\n", json_encode([
        'vm'=>count($roster['membership']['vm']), 'host'=>count($roster['membership']['host']),
        'datastore'=>count($roster['membership']['datastore'])
    ], JSON_THROW_ON_ERROR));

    $baseTransport = new IntegrationVmwareVcenterDirectTransport(8388608);
    $transport = static function ($method, $url, $headers, $body, $tls, $connect, $timeout)
        use ($baseTransport, $classifyFault, &$propertyBatch, &$faultClass): array {
        if (is_string($body) && str_contains($body, '<RetrieveProperties xmlns="urn:vim25">')) ++$propertyBatch;
        $reply = $baseTransport($method, $url, $headers, $body, $tls, $connect, $timeout);
        if (is_array($reply) && is_string($reply['body'] ?? null)) {
            $classified = $classifyFault($reply['body']);
            if ($classified !== null) $faultClass = $classified;
        }
        return $reply;
    };

    $session = new IntegrationVmwareBatchSession($runtime, [$ctx,'current'], $transport, 128, 120);
    $result = $session->run(static fn(IntegrationVmwareBatchSession $s): array =>
        (new IntegrationVmwareBatchInventory($s, 50))->read($roster['entities']));
    $stats = $session->statistics();
    print 'PHASE5H_PROPFAULT_RESULT=' . json_encode([
        'property_objects'=>count($result['objects']),
        'property_batches'=>$stats['operations']['RetrieveProperties'] ?? 0,
        'requests'=>$stats['requests'],
        'wall_ms'=>(int)round((microtime(true)-$started)*1000),
        'shadow_written'=>false
    ], JSON_THROW_ON_ERROR) . "\n";
    print "PHASE5H_PROPFAULT_EXIT=0\n";
    $rc = 0;
} catch (Throwable $e) {
    $error = $faultClass ?? $clean($e);
    fwrite(STDERR, 'PHASE5H_PROPFAULT_BATCH=' . $propertyBatch . "\n");
    fwrite(STDERR, 'PHASE5H_PROPFAULT_ERROR=' . $error . "\nPHASE5H_PROPFAULT_EXIT=1\n");
    $rc = 1;
} finally {
    if ($lockHeld && is_resource($productionLock)) flock($productionLock, LOCK_UN);
    if (is_resource($productionLock)) fclose($productionLock);
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
}
exit($rc);
