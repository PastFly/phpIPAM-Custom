#!/usr/bin/env php
<?php
/**
 * Read-only staged diagnostic after stale-MOR compatibility.
 * Uses the same production 5G.5 connector mutex, follows the reviewed shadow wire policy,
 * and dry-runs fast-domain merges in memory without publishing any shadow domain.
 */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H_POSTSTALE_ERROR=identity\nPHASE5H_POSTSTALE_EXIT=1\n");
    exit(1);
}

const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const SHADOW_ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';
const ACTIVE_WORKER_LOCK = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock';

$stage = 'bootstrap';
$runtime = null;
$key = null;
$productionLock = null;
$lockHeld = false;
$startedWall = microtime(true);

$clean = static function (Throwable $e): string {
    $m = $e->getMessage();
    return preg_match('/^(?:batch|shadow|mirror|fast|active)_[a-z0-9_:-]{1,100}$/D', $m) ? $m : 'unexpected';
};

try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService','IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterDirectTransport'] as $name) {
        require_once APP . '/functions/classes/class.' . $name . '.php';
    }
    foreach (['Store','Context'] as $name) {
        require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    }
    require_once ACTIVE . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php';

    $root = dirname(__DIR__);
    foreach (['Session','Inventory','Performance'] as $name) {
        require_once $root . '/layers/phase5h/functions/classes/class.IntegrationVmwareBatch' . $name . '.php';
    }
    require_once $root . '/layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
    require_once $root . '/layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';
    require_once $root . '/layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
    require_once $root . '/layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';

    $stage = 'context';
    $db = new Database_PDO();
    $ctx = new IntegrationVmwareInventoryContext($db, APP, 1);
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

    $waitStarted = microtime(true);
    $announced = false;
    while (!flock($productionLock, LOCK_EX | LOCK_NB)) {
        if (!$announced) {
            print "PHASE5H_POSTSTALE_LOCK_WAIT=1\n";
            $announced = true;
        }
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
    print "PHASE5H_POSTSTALE_LOCK_ACQUIRED=1\n";
    print 'PHASE5H_POSTSTALE_LOCK_WAIT_MS=' . $lockWaitMs . "\n";

    $stage = 'context_locked';
    if (!$ctx->current()) throw new RuntimeException('mirror_access_denied');
    $current = $store->read('current');
    if (!is_array($current) || !is_array($current['inventory'] ?? null) || !empty($current['blocked'])) {
        throw new RuntimeException('shadow_inventory_unavailable');
    }
    $telemetry = $ctx->telemetry();
    if ($telemetry === null) throw new RuntimeException('active_telemetry_unavailable');
    $epoch = $current['access_epoch'] ?? 'initial';
    if (!is_string($epoch) || $epoch === '') throw new RuntimeException('shadow_epoch_invalid');
    $runtime = $ctx->workerRuntime(IntegrationSecretCipher::from_keyring_file());

    $stage = 'shadow_store_read';
    $rows = $db->getObjectsQuery('no_html_escape',
        'SELECT `id`,`provider`,`enabled`,`endpoint`,`username`,`verify_tls`,`secret_envelope`,`secret_key_id` FROM `custom_integrations` WHERE `id` = ? AND `provider` = ?;',
        [1, 'vmware_vcenter']);
    if (!is_array($rows) || count($rows) !== 1) throw new RuntimeException('mirror_connector_unavailable');
    $row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
    if (!is_array($row)) throw new RuntimeException('mirror_connector_unavailable');
    $key = IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-cache.key', 32, 0, 0640, posix_getegid());
    $binding = IntegrationVmwareInventoryStore::binding($key, APP, $row);
    $fast = new IntegrationVmwareFastStore(SHADOW_ROOT, $key, $binding);
    $capabilities = $fast->read('capabilities');
    $oldProperties = $fast->read('properties');
    $oldMetrics = $fast->read('metrics');

    $stage = 'planning';
    $roster = IntegrationVmwareShadowPlanner::roster($current);
    $metricEntities = array_values(array_filter($roster['entities'],
        static fn(array $e): bool => in_array($e['kind'], ['vm','host'], true)));
    $started = time();
    $planning = IntegrationVmwareShadowPlanner::metricPlans($metricEntities,
        static fn(string $id): array => $telemetry->read($id, $epoch), $capabilities, $started);

    print 'PHASE5H_POSTSTALE_COUNTS=' . json_encode([
        'vm'=>count($roster['membership']['vm']),
        'host'=>count($roster['membership']['host']),
        'datastore'=>count($roster['membership']['datastore']),
        'plans'=>count($planning['plans']),
        'due'=>count($planning['capability_due']),
        'unsupported'=>count($planning['unsupported'])
    ], JSON_THROW_ON_ERROR) . "\n";

    $session = new IntegrationVmwareBatchSession($runtime, [$ctx,'current'], null, 128, 120);
    $wire = $session->run(function (IntegrationVmwareBatchSession $s) use (&$stage, $roster, $planning, $capabilities, $started): array {
        $stage = 'properties';
        $inventory = (new IntegrationVmwareBatchInventory($s, 50))->read($roster['entities']);

        $unobserved = [];
        foreach ($inventory['objects'] as $id => $record) {
            if (($record['state'] ?? null) === 'unobserved' && in_array($record['kind'] ?? null, ['vm','host'], true)) {
                $unobserved[$id] = ['kind'=>$record['kind'],'id'=>$id,'state'=>'not_returned',
                    'attempted_at'=>$started,'series'=>[]];
            }
        }

        $perf = new IntegrationVmwareBatchPerformance($s, 25);
        $stage = 'counter_registry';
        $defs = $perf->definitions();

        $cache = $capabilities;
        $plans = array_values(array_filter($planning['plans'], static fn(array $plan): bool =>
            !isset($unobserved[$plan['id'] ?? ''])));
        $unsupported = array_values(array_filter($planning['unsupported'], static fn(array $row): bool =>
            !isset($unobserved[$row['id'] ?? ''])));
        $revalidated = 0;
        $stillDue = [];

        foreach ($planning['capability_due'] as $entity) {
            $id = $entity['id'];
            if (isset($unobserved[$id])) { $stillDue[] = $entity; continue; }
            if ($revalidated >= 12) { $stillDue[] = $entity; continue; }
            ++$revalidated;

            $stage = 'capability_provider_' . $revalidated;
            try { $provider = $perf->provider($entity['kind'], $id); }
            catch (Throwable $e) {
                if ($e->getMessage() !== 'batch_managed_object_not_found') throw $e;
                $unobserved[$id] = ['kind'=>$entity['kind'],'id'=>$id,'state'=>'not_returned',
                    'attempted_at'=>$started,'series'=>[]];
                $stillDue[] = $entity;
                continue;
            }
            if (($provider['current_supported'] ?? null) !== true) {
                $cache = IntegrationVmwareCapabilityCache::put($cache, $entity['kind'], $id,
                    ['current_supported'=>false,'refresh_rate'=>null,'counters'=>[]], $started, 900);
                $unsupported[] = ['kind'=>$entity['kind'],'id'=>$id,'reason'=>'provider_not_realtime'];
                continue;
            }
            $interval = $provider['interval_id'] ?? null;
            if (!is_int($interval)) throw new RuntimeException('shadow_provider_interval_invalid');

            $stage = 'capability_availability_' . $revalidated;
            try { $availability = $perf->availability($entity['kind'], $id, $interval); }
            catch (Throwable $e) {
                if ($e->getMessage() !== 'batch_managed_object_not_found') throw $e;
                $unobserved[$id] = ['kind'=>$entity['kind'],'id'=>$id,'state'=>'not_returned',
                    'attempted_at'=>$started,'series'=>[]];
                $stillDue[] = $entity;
                continue;
            }
            $statuses = [];
            foreach (['cpu.usage.average','mem.usage.average','disk.usage.average'] as $counter) {
                $statuses[$counter] = !empty($availability['aggregate_available'][$counter]) ? 'available' : 'aggregate_unavailable';
            }
            $cache = IntegrationVmwareCapabilityCache::put($cache, $entity['kind'], $id,
                ['current_supported'=>true,'refresh_rate'=>$interval,'counters'=>$statuses], $started, 900);
            $selected = [];
            foreach ($statuses as $counter=>$status) if ($status === 'available') $selected[] = $counter;
            if ($selected !== []) $plans[] = ['kind'=>$entity['kind'],'id'=>$id,'interval_id'=>$interval,'counters'=>$selected];
            else $unsupported[] = ['kind'=>$entity['kind'],'id'=>$id,'reason'=>'aggregate_metrics_unavailable'];
        }

        $plans = array_values(array_filter($plans, static fn(array $plan): bool =>
            !isset($unobserved[$plan['id'] ?? ''])));
        $unsupported = array_values(array_filter($unsupported, static fn(array $row): bool =>
            !isset($unobserved[$row['id'] ?? ''])));

        $metricObjects = [];
        $batches = array_chunk($plans, 25);
        foreach ($batches as $index => $batch) {
            $stage = 'metrics_batch_' . ($index + 1) . '_of_' . count($batches);
            $result = $perf->read($batch, 6);
            foreach ($result['objects'] as $id => $object) $metricObjects[$id] = $object;
        }

        $stage = 'callback_done';
        return [
            'inventory'=>$inventory,
            'metric_batch'=>['schema'=>1,'objects'=>$metricObjects],
            'plans'=>$plans,
            'unsupported'=>$unsupported,
            'unobserved_metrics'=>array_values($unobserved),
            'capabilities'=>$cache,
            'counter_definitions'=>count($defs),
            'capability_revalidated'=>$revalidated,
            'capability_still_due'=>count($stillDue)
        ];
    });

    $stage = 'post_logout';
    $stats = $session->statistics();
    $now = time();

    $stage = 'properties_merge_dry_run';
    $nextProperties = IntegrationVmwareFastDomain::mergeProperties($oldProperties, $wire['inventory'], $now);

    $stage = 'metrics_normalize_dry_run';
    $metricRecords = IntegrationVmwareShadowPlanner::normalizeMetrics($wire['metric_batch'], $wire['plans'], $now);
    $metricRecords = array_merge($metricRecords, $wire['unobserved_metrics'],
        IntegrationVmwareShadowPlanner::unsupportedRecords($wire['unsupported'], $now));

    $stage = 'metrics_merge_dry_run';
    $nextMetrics = IntegrationVmwareFastDomain::mergeMetrics($oldMetrics, $metricRecords, $now);

    $stage = 'generation_recheck';
    $after = $store->read('current');
    $generationUnchanged = is_array($after) && (($after['generation'] ?? null) === ($current['generation'] ?? null));

    print 'PHASE5H_POSTSTALE_RESULT=' . json_encode([
        'property_objects'=>count($wire['inventory']['objects']),
        'property_unobserved'=>count($wire['unobserved_metrics']),
        'counter_definitions'=>$wire['counter_definitions'],
        'metric_plans'=>count($wire['plans']),
        'metric_objects'=>count($wire['metric_batch']['objects']),
        'capability_revalidated'=>$wire['capability_revalidated'],
        'capability_still_due'=>$wire['capability_still_due'],
        'unsupported'=>count($wire['unsupported']),
        'requests'=>$stats['requests'],
        'response_bytes'=>$stats['response_bytes'],
        'properties_after_objects'=>count($nextProperties['objects']),
        'metrics_after_objects'=>count($nextMetrics['objects']),
        'wall_ms'=>(int)round((microtime(true)-$startedWall)*1000),
        'lock_wait_ms'=>$lockWaitMs,
        'active_generation_unchanged'=>$generationUnchanged,
        'shadow_written'=>false
    ], JSON_THROW_ON_ERROR) . "\n";
    print "PHASE5H_POSTSTALE_EXIT=0\n";
    $rc = 0;
} catch (Throwable $e) {
    fwrite(STDERR, 'PHASE5H_POSTSTALE_STAGE=' . $stage . "\n");
    fwrite(STDERR, 'PHASE5H_POSTSTALE_ERROR=' . $clean($e) . "\n");
    fwrite(STDERR, "PHASE5H_POSTSTALE_EXIT=1\n");
    $rc = 1;
} finally {
    if ($lockHeld && is_resource($productionLock)) flock($productionLock, LOCK_UN);
    if (is_resource($productionLock)) fclose($productionLock);
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
    if (is_string($key)) sodium_memzero($key);
}

exit($rc);
