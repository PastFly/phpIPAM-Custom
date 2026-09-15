#!/usr/bin/env php
<?php
/**
 * Read-only live diagnostic for a Phase 5G.5 collection_failed state.
 * It serializes with the production worker, inspects only the current pending cursor,
 * and may replay one object collection without writing inventory/telemetry state.
 * Object ids, names, payloads, credentials and raw provider errors are never emitted.
 */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5G5_FAILDIAG_ERROR=identity\nPHASE5G5_FAILDIAG_EXIT=1\n");
    exit(1);
}

const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const ACTIVE_WORKER_LOCK = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock';

$runtime = null;
$productionLock = null;
$lockHeld = false;
$stage = 'bootstrap';

$classify = static function (Throwable $e): string {
    $m = $e->getMessage();
    if (preg_match('/(?:http[ _]*(?:401|403)|notauthenticated|nopermission|forbidden|authentication|authorization|access_denied)/i', $m)) return 'access_denied';
    if (preg_match('/managed\s*object\s*not\s*found|managedobjectnotfound/i', $m)) return 'managed_object_not_found';
    if (preg_match('/membership_changed/i', $m)) return 'membership_changed';
    if (preg_match('/(?:\b404\b|not[ _-]?found|does not exist|no such)/i', $m)) return 'not_found';
    if (preg_match('/certificate|ssl|tls/i', $m)) return 'tls';
    if (preg_match('/timeout|timed out|deadline/i', $m)) return 'timeout';
    if (preg_match('/curl|network|transport|connection|connect failed|resolve host|reset by peer/i', $m)) return 'transport';
    if (preg_match('/json|xml|decode|parse|payload|response|shape|malformed/i', $m)) return 'response_invalid';
    return 'unexpected';
};

try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService','IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareSnapshotCache',
        'IntegrationVmwareVcenterDirectRestTransport','IntegrationVmwareVcenterDirectTransport','IntegrationVmwareVcenterFaultAwareTransport',
        'IntegrationVmwareVcenterTopology','IntegrationVmwareVcenterDatastore','IntegrationVmwareVcenterDatastoreResolver',
        'IntegrationVmwareVcenterExplorer','IntegrationVmwareVcenterVmDetail','IntegrationVmwareVcenterGuestNetwork',
        'IntegrationVmwareVcenterFolderInventory','IntegrationVmwareVcenterObjectFolders','IntegrationVmwareVcenterVmSearchFacts',
        'IntegrationVmwareVcenterVmTagIndex','IntegrationVmwareVcenterSoapReadOnly','IntegrationVmwareVcenterHostNetworkHints',
        'IntegrationVmwareVcenterVmGuestFacts','IntegrationVmwareVcenterVmAnnotation','IntegrationVmwareVcenterVmNetworkPath',
        'IntegrationVmwareVcenterPerformance','IntegrationVmwareVcenterStoragePerformance'] as $class) {
        require_once APP . '/functions/classes/class.' . $class . '.php';
    }
    foreach (['Store','Context','Worker'] as $name) {
        require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    }
    require_once ACTIVE . '/collectors/class.IntegrationVmwareInventoryGuestNetwork.php';
    define('PHPIPAM_VMWARE_CACHE_GUARDED', true);
    require_once APP . '/app/integrations/vmware/cache/search-provider.php';
    require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventorySource.php';

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
        if (!$announced) { print "PHASE5G5_FAILDIAG_LOCK_WAIT=1\n"; $announced = true; }
        if (microtime(true) - $waitStarted >= 600) throw new RuntimeException('active_worker_busy');
        usleep(250000);
        clearstatcache(true, ACTIVE_WORKER_LOCK);
        $nowLock = @lstat(ACTIVE_WORKER_LOCK);
        if (!$nowLock || $nowLock['ino'] !== $st['ino'] || $nowLock['dev'] !== $st['dev'] || $nowLock['size'] !== 0) {
            throw new RuntimeException('active_worker_lock_changed');
        }
    }
    $lockHeld = true;
    $lockWaitMs = (int)round((microtime(true) - $waitStarted) * 1000);
    print "PHASE5G5_FAILDIAG_LOCK_ACQUIRED=1\n";
    print 'PHASE5G5_FAILDIAG_LOCK_WAIT_MS=' . $lockWaitMs . "\n";

    $stage = 'state';
    if (!$ctx->current()) throw new RuntimeException('mirror_access_denied');
    $current = $store->read('current') ?? [];
    $pending = $store->read('pending');
    $currentState = is_string($current['state'] ?? null) ? $current['state'] : 'unknown';
    $currentError = is_string($current['last_error'] ?? null) ? $current['last_error'] : 'none';
    $retryAt = is_int($current['retry_at'] ?? null) ? $current['retry_at'] : 0;

    if ($pending === null) {
        print 'PHASE5G5_FAILDIAG_STATE=' . json_encode([
            'current_state'=>$currentState, 'last_error'=>$currentError,
            'pending'=>false, 'retry_pending'=>$retryAt > time(), 'shadow_written'=>false
        ], JSON_THROW_ON_ERROR) . "\n";
        print "PHASE5G5_FAILDIAG_EXIT=0\n";
        $rc = 0;
    } else {
        $jobs = $pending['jobs'] ?? null; $cursor = $pending['cursor'] ?? null;
        if (!is_array($jobs) || !array_is_list($jobs) || !is_int($cursor) || $cursor < 0 || $cursor >= count($jobs) || count($jobs) > 3000) {
            throw new RuntimeException('mirror_pending_shape');
        }
        $job = $jobs[$cursor] ?? null;
        if (!is_array($job) || count($job) !== 2 || !is_string($job[0] ?? null) || !is_string($job[1] ?? null)) {
            throw new RuntimeException('mirror_pending_shape');
        }
        [$kind, $id] = $job;
        if (!in_array($kind, ['domain','vm','host','datastore'], true)) throw new RuntimeException('mirror_pending_shape');
        if ($kind !== 'domain' && !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id)) throw new RuntimeException('mirror_pending_shape');
        if ($kind === 'domain' && !in_array($id, ['host_folders','object_folders','search'], true)) throw new RuntimeException('mirror_pending_shape');

        print 'PHASE5G5_FAILDIAG_STATE=' . json_encode([
            'current_state'=>$currentState, 'last_error'=>$currentError,
            'pending'=>true, 'cursor'=>$cursor, 'total'=>count($jobs), 'kind'=>$kind,
            'domain_job'=>$kind === 'domain' ? $id : null,
            'retry_pending'=>$retryAt > time(), 'shadow_written'=>false
        ], JSON_THROW_ON_ERROR) . "\n";

        // Search generation can involve its own cache boundary; do not replay it in a diagnostic.
        if ($kind === 'domain' && $id === 'search') {
            print "PHASE5G5_FAILDIAG_REPLAY=deferred_domain_search\n";
            print "PHASE5G5_FAILDIAG_EXIT=0\n";
            $rc = 0;
        } else {
            $seed = $pending['seed'] ?? null;
            if (!is_array($seed)) throw new RuntimeException('mirror_pending_shape');
            $runtime = $ctx->workerRuntime(IntegrationSecretCipher::from_keyring_file());
            $source = new IntegrationVmwareInventorySource($runtime);
            $stage = 'replay_' . $kind;
            try {
                $result = $source->collect($kind, $id, $seed);
                $ok = ($result['ok'] ?? null) === true;
                $partial = !empty($result['partial']);
                $sections = ['ok'=>0,'stale'=>0,'unavailable'=>0,'other'=>0];
                foreach (is_array($result['sections'] ?? null) ? $result['sections'] : [] as $section) {
                    $state = is_array($section) ? ($section['state'] ?? null) : null;
                    if (is_string($state) && isset($sections[$state])) ++$sections[$state]; else ++$sections['other'];
                }
                print 'PHASE5G5_FAILDIAG_REPLAY=' . json_encode([
                    'result'=>'returned', 'ok'=>$ok, 'partial'=>$partial,
                    'section_states'=>$sections, 'would_be_complete'=>$ok && !$partial,
                    'shadow_written'=>false
                ], JSON_THROW_ON_ERROR) . "\n";
                print "PHASE5G5_FAILDIAG_EXIT=0\n";
                $rc = 0;
            } catch (Throwable $e) {
                print 'PHASE5G5_FAILDIAG_REPLAY=' . json_encode([
                    'result'=>'exception', 'cause'=>$classify($e), 'shadow_written'=>false
                ], JSON_THROW_ON_ERROR) . "\n";
                print "PHASE5G5_FAILDIAG_EXIT=0\n";
                $rc = 0;
            }
        }
    }
} catch (Throwable $e) {
    $safe = preg_match('/^(?:active|mirror)_[a-z0-9_:-]{1,100}$/D', $e->getMessage()) ? $e->getMessage() : 'unexpected';
    fwrite(STDERR, 'PHASE5G5_FAILDIAG_STAGE=' . $stage . "\n");
    fwrite(STDERR, 'PHASE5G5_FAILDIAG_ERROR=' . $safe . "\n");
    fwrite(STDERR, "PHASE5G5_FAILDIAG_EXIT=1\n");
    $rc = 1;
} finally {
    if ($lockHeld && is_resource($productionLock)) flock($productionLock, LOCK_UN);
    if (is_resource($productionLock)) fclose($productionLock);
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
}
exit($rc);
