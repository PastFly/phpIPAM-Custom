#!/usr/bin/env php
<?php
/** Read-only classifier for the exact pending 5G.5 host SOAP fault. */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5G5_HOSTFAULT_ERROR=identity\nPHASE5G5_HOSTFAULT_EXIT=1\n");
    exit(1);
}

const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const ACTIVE_WORKER_LOCK = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock';

$runtime = null;
$productionLock = null;
$lockHeld = false;
$stage = 'bootstrap';

$operationName = static function (string $body): string {
    foreach (['RetrieveServiceContent', 'RetrieveProperties', 'Login', 'Logout'] as $name) {
        if (strpos($body, '<' . $name) !== false) return $name;
    }
    return 'request';
};

$faultType = static function (string $xml): ?string {
    if ($xml === '' || !class_exists('DOMDocument')) return null;
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$ok) return null;
    $xpath = new DOMXPath($doc);
    $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);
    if ($fault === null) return null;

    $signals = [];
    if ($fault instanceof DOMElement) $signals[] = $fault->localName;
    foreach ($xpath->query('.//*', $fault) as $node) {
        if (!($node instanceof DOMElement)) continue;
        $signals[] = $node->localName;
        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attribute) {
                $signals[] = (string)$attribute->value;
            }
        }
    }
    foreach ($signals as $signal) {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$signal));
        if (str_contains($normalized, 'managedobjectnotfound')) return 'managed_object_not_found';
        if (str_contains($normalized, 'invalidproperty')) return 'invalid_property';
        if (str_contains($normalized, 'nopermission') || str_contains($normalized, 'notauthenticated')) return 'access_denied';
        if (str_contains($normalized, 'notsupported')) return 'not_supported';
        if (str_contains($normalized, 'invalidargument')) return 'invalid_argument';
        if (str_contains($normalized, 'invalidtype')) return 'invalid_type';
        if (str_contains($normalized, 'methodnotfound')) return 'method_not_found';
    }
    return 'soap_fault_unclassified';
};

$fallbackClassify = static function (Throwable $e): string {
    $m = $e->getMessage();
    if (preg_match('/(?:http[ _]*(?:401|403)|notauthenticated|nopermission|forbidden|authentication|authorization|access_denied)/i', $m)) return 'access_denied';
    if (preg_match('/managed\s*object\s*not\s*found|managedobjectnotfound|already been deleted|not been completely created/i', $m)) return 'managed_object_not_found';
    if (preg_match('/invalid\s*property/i', $m)) return 'invalid_property';
    if (preg_match('/not\s*supported/i', $m)) return 'not_supported';
    if (preg_match('/invalid\s*argument/i', $m)) return 'invalid_argument';
    if (preg_match('/certificate|ssl|tls/i', $m)) return 'tls';
    if (preg_match('/timeout|timed out|deadline/i', $m)) return 'timeout';
    if (preg_match('/curl|network|transport|connection|connect failed|resolve host|reset by peer/i', $m)) return 'transport';
    if (preg_match('/summary was not returned/i', $m)) return 'summary_missing';
    if (preg_match('/servicecontent missing/i', $m)) return 'service_content_invalid';
    if (preg_match('/session cookie/i', $m)) return 'session_cookie_missing';
    if (preg_match('/json|xml|decode|parse|payload|response|shape|malformed/i', $m)) return 'response_invalid';
    return 'unexpected';
};

try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService', 'IntegrationVmwareVcenter', 'IntegrationSecretCipher',
        'IntegrationVmwareVcenterDirectTransport', 'IntegrationVmwareVcenterFaultAwareTransport',
        'IntegrationVmwareVcenterSoapReadOnly'] as $class) {
        require_once APP . '/functions/classes/class.' . $class . '.php';
    }
    foreach (['Store', 'Context'] as $name) {
        require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    }

    $stage = 'context';
    $ctx = new IntegrationVmwareInventoryContext(new Database_PDO(), APP, 1);
    $store = $ctx->store();

    $stage = 'production_lock';
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
    $waitStarted = microtime(true); $announced = false;
    while (!flock($productionLock, LOCK_EX | LOCK_NB)) {
        if (!$announced) { print "PHASE5G5_HOSTFAULT_LOCK_WAIT=1\n"; $announced = true; }
        if (microtime(true) - $waitStarted >= 600) throw new RuntimeException('active_worker_busy');
        usleep(250000);
    }
    $lockHeld = true;
    print "PHASE5G5_HOSTFAULT_LOCK_ACQUIRED=1\n";
    print 'PHASE5G5_HOSTFAULT_LOCK_WAIT_MS=' . (int)round((microtime(true) - $waitStarted) * 1000) . "\n";

    $stage = 'state';
    if (!$ctx->current()) throw new RuntimeException('mirror_access_denied');
    $current = $store->read('current') ?? [];
    $pending = $store->read('pending');
    if (!is_array($pending)) {
        print 'PHASE5G5_HOSTFAULT_STATE=' . json_encode(['pending'=>false, 'shadow_written'=>false], JSON_THROW_ON_ERROR) . "\n";
        print "PHASE5G5_HOSTFAULT_EXIT=0\n";
        $rc = 0;
    } else {
        $jobs = $pending['jobs'] ?? null; $cursor = $pending['cursor'] ?? null;
        if (!is_array($jobs) || !array_is_list($jobs) || !is_int($cursor) || $cursor < 0 || $cursor >= count($jobs)) {
            throw new RuntimeException('mirror_pending_shape');
        }
        $job = $jobs[$cursor] ?? null;
        if (!is_array($job) || count($job) !== 2 || ($job[0] ?? null) !== 'host' ||
            !is_string($job[1] ?? null) || !preg_match('/^host-[0-9]{1,20}$/D', $job[1])) {
            print 'PHASE5G5_HOSTFAULT_STATE=' . json_encode([
                'pending'=>true, 'cursor'=>$cursor, 'total'=>count($jobs),
                'kind'=>is_array($job) && is_string($job[0] ?? null) ? $job[0] : 'other',
                'host_probe'=>'not_applicable', 'shadow_written'=>false
            ], JSON_THROW_ON_ERROR) . "\n";
            print "PHASE5G5_HOSTFAULT_EXIT=0\n";
            $rc = 0;
        } else {
            $hostId = $job[1];
            print 'PHASE5G5_HOSTFAULT_STATE=' . json_encode([
                'pending'=>true, 'cursor'=>$cursor, 'total'=>count($jobs), 'kind'=>'host',
                'current_state'=>is_string($current['state'] ?? null) ? $current['state'] : 'unknown',
                'last_error'=>is_string($current['last_error'] ?? null) ? $current['last_error'] : 'none',
                'shadow_written'=>false
            ], JSON_THROW_ON_ERROR) . "\n";

            $runtime = $ctx->workerRuntime(IntegrationSecretCipher::from_keyring_file());
            $endpoint = (new IntegrationVmwareVcenter())->normalize_endpoint($runtime['endpoint']);
            $direct = new IntegrationVmwareVcenterDirectTransport(8388608);
            $firstFault = null; $firstFaultOperation = null; $firstFaultStatus = null;
            $lastOperation = 'request';

            $checked = function ($method, $url, $headers, $body, $tls, $connect, $timeout) use (
                $direct, $endpoint, $operationName, $faultType,
                &$firstFault, &$firstFaultOperation, &$firstFaultStatus, &$lastOperation
            ): array {
                $lastOperation = $operationName((string)$body);
                if ($tls !== true || $url !== $endpoint . '/sdk' || $method !== 'POST') {
                    throw new RuntimeException('mirror_transport_scope');
                }
                if (!in_array($lastOperation, ['RetrieveServiceContent', 'RetrieveProperties', 'Login', 'Logout'], true)) {
                    throw new RuntimeException('mirror_soap_operation');
                }
                $out = $direct($method, $url, $headers, $body, true, min(8, $connect), min(20, $timeout));
                $type = $faultType(is_array($out) ? (string)($out['body'] ?? '') : '');
                if ($type !== null && $firstFault === null) {
                    $firstFault = $type;
                    $firstFaultOperation = $lastOperation;
                    $firstFaultStatus = is_array($out) ? (int)($out['status'] ?? 0) : 0;
                }
                return $out;
            };

            $soap = new IntegrationVmwareVcenterFaultAwareTransport($checked);
            $reader = new IntegrationVmwareVcenterSoapReadOnly($soap, 8, 20, 4194304);
            $stage = 'host_summary_probe';
            try {
                $reader->read_host_summary($endpoint, $runtime['username'], $runtime['password'], $hostId, true);
                print 'PHASE5G5_HOSTFAULT_RESULT=' . json_encode([
                    'result'=>'returned', 'operation'=>'RetrieveProperties', 'cause'=>'none',
                    'shadow_written'=>false
                ], JSON_THROW_ON_ERROR) . "\n";
            } catch (Throwable $e) {
                $operation = $firstFaultOperation;
                if ($operation === null && preg_match('/SOAP\s+(RetrieveServiceContent|RetrieveProperties|Login|Logout)\b/i', $e->getMessage(), $match)) {
                    $operation = $match[1];
                }
                if ($operation === null) $operation = $lastOperation === 'Logout' ? 'unknown' : $lastOperation;
                $cause = $firstFault ?? $fallbackClassify($e);
                print 'PHASE5G5_HOSTFAULT_RESULT=' . json_encode([
                    'result'=>'exception', 'operation'=>$operation, 'cause'=>$cause,
                    'http_status'=>$firstFaultStatus, 'shadow_written'=>false
                ], JSON_THROW_ON_ERROR) . "\n";
            }
            print "PHASE5G5_HOSTFAULT_EXIT=0\n";
            $rc = 0;
        }
    }
} catch (Throwable $e) {
    $safe = preg_match('/^(?:active|mirror)_[a-z0-9_:-]{1,100}$/D', $e->getMessage()) ? $e->getMessage() : 'unexpected';
    fwrite(STDERR, 'PHASE5G5_HOSTFAULT_STAGE=' . $stage . "\n");
    fwrite(STDERR, 'PHASE5G5_HOSTFAULT_ERROR=' . $safe . "\n");
    fwrite(STDERR, "PHASE5G5_HOSTFAULT_EXIT=1\n");
    $rc = 1;
} finally {
    if ($lockHeld && is_resource($productionLock)) flock($productionLock, LOCK_UN);
    if (is_resource($productionLock)) fclose($productionLock);
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
}
exit($rc);
