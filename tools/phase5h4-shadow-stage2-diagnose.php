#!/usr/bin/env php
<?php
/**
 * Bounded non-writing diagnostic for a live Phase5H shadow pass after counter-registry compatibility.
 * Uses the same active 5G.5 context plus the reviewed Phase5H batch/planner classes,
 * but never publishes any fast domain. It shares the exact 5G.5 connector worker mutex so a
 * production inventory/telemetry tick and this diagnostic can never overlap upstream SOAP work.
 */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H_STAGE2_ERROR=identity\nPHASE5H_STAGE2_EXIT=1\n"); exit(1);
}
const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const SHADOW_ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';
const ACTIVE_WORKER_LOCK = '/var/lib/phpipam-custom/vmware-inventory-recovery5g5/connector-1/worker.lock';
$stage = 'bootstrap'; $runtime = null; $key = null; $productionLock = null; $lockHeld = false; $lockWaitStarted = null;
$startedWall = microtime(true);
$clean = static function (Throwable $e): string {
    $m = $e->getMessage();
    return preg_match('/^(?:batch|shadow|mirror|fast|active)_[a-z0-9_:-]{1,90}$/D', $m) ? $m : 'unexpected';
};
try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService','IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterDirectTransport'] as $name)
        require_once APP . '/functions/classes/class.' . $name . '.php';
    foreach (['Store','Context'] as $name) require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    require_once ACTIVE . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php';
    $root = dirname(__DIR__);
    foreach (['Session','Inventory','Performance'] as $name) require_once $root . '/layers/phase5h/functions/classes/class.IntegrationVmwareBatch' . $name . '.php';
    require_once $root . '/layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';
    require_once $root . '/layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
    require_once $root . '/layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';

    $stage = 'context';
    $db = new Database_PDO(); $ctx = new IntegrationVmwareInventoryContext($db, APP, 1); $store = $ctx->store();

    // The active 5G.5 inventory worker and telemetry sampler both serialize all upstream work on
    // this exact connector worker.lock via IntegrationVmwareInventoryStore::exclusive(). Take the
    // same lock before reading the active generation and hold it through SOAP Logout. The timer may
    // fire while we own it, but its tick returns busy instead of opening a second upstream session.
    $stage = 'production_lock';
    IntegrationVmwareInventoryStore::noLinks(ACTIVE_WORKER_LOCK);
    clearstatcache(true, ACTIVE_WORKER_LOCK); $st = @lstat(ACTIVE_WORKER_LOCK);
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
    $lockWaitStarted = microtime(true); $announced = false;
    while (!flock($productionLock, LOCK_EX | LOCK_NB)) {
        if (!$announced) { print "PHASE5H_STAGE2_LOCK_WAIT=1\n"; $announced = true; }
        if (microtime(true) - $lockWaitStarted >= 600) throw new RuntimeException('active_worker_busy');
        usleep(250000);
        clearstatcache(true, ACTIVE_WORKER_LOCK); $now = @lstat(ACTIVE_WORKER_LOCK);
        if (!$now || $now['ino'] !== $st['ino'] || $now['dev'] !== $st['dev'] || $now['size'] !== 0) {
            throw new RuntimeException('active_worker_lock_changed');
        }
    }
    $lockHeld = true;
    $lockWaitMs = (int)round((microtime(true) - $lockWaitStarted) * 1000);
    print 'PHASE5H_STAGE2_LOCK_ACQUIRED=1' . "\n";
    print 'PHASE5H_STAGE2_LOCK_WAIT_MS=' . $lockWaitMs . "\n";

    $stage = 'context_locked';
    if (!$ctx->current()) throw new RuntimeException('mirror_access_denied');
    $current = $store->read('current');
    if (!is_array($current) || !is_array($current['inventory'] ?? null) || !empty($current['blocked'])) throw new RuntimeException('shadow_inventory_unavailable');
    $telemetry = $ctx->telemetry(); if ($telemetry === null) throw new RuntimeException('active_telemetry_unavailable');
    $epoch = $current['access_epoch'] ?? 'initial'; if (!is_string($epoch) || $epoch === '') throw new RuntimeException('shadow_epoch_invalid');
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

    $stage = 'planning';
    $roster = IntegrationVmwareShadowPlanner::roster($current);
    $entities = array_values(array_filter($roster['entities'], static fn(array $e): bool => in_array($e['kind'], ['vm','host'], true)));
    $plan = IntegrationVmwareShadowPlanner::metricPlans($entities,
        static fn(string $id): array => $telemetry->read($id, $epoch), $capabilities, time());
    printf("PHASE5H_STAGE2_COUNTS=%s\n", json_encode([
        'vm'=>count($roster['membership']['vm']), 'host'=>count($roster['membership']['host']),
        'datastore'=>count($roster['membership']['datastore']), 'plans'=>count($plan['plans']),
        'due'=>count($plan['capability_due']), 'unsupported'=>count($plan['unsupported'])
    ], JSON_THROW_ON_ERROR));

    $session = new IntegrationVmwareBatchSession($runtime, [$ctx,'current'], null, 128, 120);
    $wire = $session->run(function (IntegrationVmwareBatchSession $s) use (&$stage, $roster, $plan, $capabilities): array {
        $stage = 'properties';
        $inventory = (new IntegrationVmwareBatchInventory($s, 50))->read($roster['entities']);
        $perf = new IntegrationVmwareBatchPerformance($s, 25);
        $stage = 'counter_registry'; $defs = $perf->definitions();

        $cache = $capabilities; $plans = $plan['plans']; $unsupportedCount = count($plan['unsupported']);
        $revalidated = 0; $stillDue = 0;
        foreach ($plan['capability_due'] as $entity) {
            if ($revalidated >= 12) { ++$stillDue; continue; }
            ++$revalidated;
            $stage = 'capability_provider_' . $revalidated;
            $provider = $perf->provider($entity['kind'], $entity['id']);
            if (($provider['current_supported'] ?? null) !== true) {
                $cache = IntegrationVmwareCapabilityCache::put($cache, $entity['kind'], $entity['id'],
                    ['current_supported'=>false,'refresh_rate'=>null,'counters'=>[]], time(), 900);
                ++$unsupportedCount; continue;
            }
            $interval = $provider['interval_id'] ?? null;
            if (!is_int($interval)) throw new RuntimeException('shadow_provider_interval_invalid');
            $stage = 'capability_availability_' . $revalidated;
            $availability = $perf->availability($entity['kind'], $entity['id'], $interval);
            $statuses = [];
            foreach (['cpu.usage.average','mem.usage.average','disk.usage.average'] as $counter)
                $statuses[$counter] = !empty($availability['aggregate_available'][$counter]) ? 'available' : 'aggregate_unavailable';
            $cache = IntegrationVmwareCapabilityCache::put($cache, $entity['kind'], $entity['id'],
                ['current_supported'=>true,'refresh_rate'=>$interval,'counters'=>$statuses], time(), 900);
            $selected = [];
            foreach ($statuses as $counter=>$status) if ($status === 'available') $selected[] = $counter;
            if ($selected !== []) $plans[] = ['kind'=>$entity['kind'],'id'=>$entity['id'],'interval_id'=>$interval,'counters'=>$selected];
            else ++$unsupportedCount;
        }

        $metricObjects = 0; $partial = 0; $notReturned = 0; $batches = array_chunk($plans, 25);
        foreach ($batches as $index => $batch) {
            $stage = 'metrics_batch_' . ($index + 1) . '_of_' . count($batches);
            $out = $perf->read($batch, 6);
            foreach ($out['objects'] as $object) {
                ++$metricObjects;
                if (($object['state'] ?? '') === 'partial') ++$partial;
                if (($object['state'] ?? '') === 'not_returned') ++$notReturned;
            }
        }
        $stage = 'callback_done';
        return ['property_objects'=>count($inventory['objects']), 'counter_definitions'=>count($defs),
            'metric_plans'=>count($plans), 'metric_objects'=>$metricObjects, 'metric_partial'=>$partial,
            'metric_not_returned'=>$notReturned, 'capability_revalidated'=>$revalidated,
            'capability_still_due'=>$stillDue, 'unsupported'=>$unsupportedCount];
    });
    $stage = 'post_logout';
    $stats = $session->statistics();
    $generationUnchanged = ($store->read('current')['generation'] ?? null) === ($current['generation'] ?? null);
    print 'PHASE5H_STAGE2_RESULT=' . json_encode($wire + [
        'requests'=>$stats['requests'], 'response_bytes'=>$stats['response_bytes'],
        'wall_ms'=>(int)round((microtime(true)-$startedWall)*1000), 'lock_wait_ms'=>$lockWaitMs,
        'active_generation_unchanged'=>$generationUnchanged, 'shadow_written'=>false
    ], JSON_THROW_ON_ERROR) . "\n";
    print "PHASE5H_STAGE2_EXIT=0\n"; $rc=0;
} catch (Throwable $e) {
    fwrite(STDERR, 'PHASE5H_STAGE2_STAGE=' . $stage . "\nPHASE5H_STAGE2_ERROR=" . $clean($e) . "\nPHASE5H_STAGE2_EXIT=1\n"); $rc=1;
} finally {
    if ($lockHeld && is_resource($productionLock)) flock($productionLock, LOCK_UN);
    if (is_resource($productionLock)) fclose($productionLock);
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
    if (is_string($key)) sodium_memzero($key);
}
exit($rc);
