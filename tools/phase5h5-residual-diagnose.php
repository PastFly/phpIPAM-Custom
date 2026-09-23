#!/usr/bin/env php
<?php
/**
 * Read-only aggregate diagnostic for residual Phase5H freshness/capability gaps.
 * Emits no MORs, names, metric values, credentials or raw provider payloads.
 */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H5_RESIDUAL_ERROR=identity\n"); exit(1);
}
const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const SHADOW = '/opt/phpipam-custom/vmware-fast-shadow';
const SHADOW_ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';

$runtime = null; $key = null;
try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService','IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterDirectTransport'] as $name) {
        require_once APP . '/functions/classes/class.' . $name . '.php';
    }
    foreach (['Store','Context'] as $name) require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    require_once ACTIVE . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php';
    foreach (['Session','Inventory','Performance'] as $name) require_once SHADOW . '/layers/phase5h/functions/classes/class.IntegrationVmwareBatch' . $name . '.php';
    require_once SHADOW . '/layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';
    require_once SHADOW . '/layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
    require_once SHADOW . '/layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';

    $db = new Database_PDO();
    $context = new IntegrationVmwareInventoryContext($db, APP, 1);
    if (!$context->current()) throw new RuntimeException('binding_changed');
    $store = $context->store();
    $current = $store->read('current');
    if (!is_array($current) || !is_array($current['inventory'] ?? null) || !empty($current['blocked'])) throw new RuntimeException('active_inventory_unavailable');
    $telemetry = $context->telemetry();
    if ($telemetry === null) throw new RuntimeException('active_telemetry_unavailable');

    $rows = $db->getObjectsQuery('no_html_escape',
        'SELECT `id`,`provider`,`enabled`,`endpoint`,`username`,`verify_tls`,`secret_envelope`,`secret_key_id` FROM `custom_integrations` WHERE `id` = ? AND `provider` = ?;',
        [1, 'vmware_vcenter']);
    if (!is_array($rows) || count($rows) !== 1) throw new RuntimeException('connector_unavailable');
    $row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
    if (!is_array($row)) throw new RuntimeException('connector_unavailable');
    $key = IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-cache.key', 32, 0, 0640, posix_getegid());
    $binding = IntegrationVmwareInventoryStore::binding($key, APP, $row);
    $fast = new IntegrationVmwareFastStore(SHADOW_ROOT, $key, $binding);

    $now = time();
    $metrics = $fast->read('metrics');
    $properties = $fast->read('properties');
    $capabilities = $fast->read('capabilities');
    if (!is_array($metrics) || ($metrics['schema'] ?? null) !== 1 || ($metrics['domain'] ?? null) !== 'metrics' || !is_array($metrics['objects'] ?? null)) {
        throw new RuntimeException('metrics_shape');
    }
    if (!is_array($properties) || ($properties['schema'] ?? null) !== 1 || ($properties['domain'] ?? null) !== 'properties' || !is_array($properties['objects'] ?? null)) {
        throw new RuntimeException('properties_shape');
    }
    if (!is_array($capabilities) || ($capabilities['schema'] ?? null) !== 1 || ($capabilities['domain'] ?? null) !== 'capabilities' || !is_array($capabilities['entries'] ?? null)) {
        throw new RuntimeException('capabilities_shape');
    }
    $published = $metrics['published_at'] ?? null;
    if (!is_int($published) || $published < 1 || $published > $now + 60) throw new RuntimeException('metrics_time');

    $bins = ['le60'=>0,'61_300'=>0,'301_900'=>0,'901_3600'=>0,'gt3600'=>0,'missing'=>0];
    $freshSeries = 0; $objectsOver300 = 0; $objectsOver3600 = 0; $maxLag = null; $lags = [];
    foreach ($metrics['objects'] as $record) {
        if (!is_array($record)) throw new RuntimeException('metrics_shape');
        $objectOver300 = false; $objectOver3600 = false;
        foreach (($record['series'] ?? []) as $series) {
            if (!is_array($series) || ($series['state'] ?? null) !== 'fresh') continue;
            ++$freshSeries;
            $observed = $series['observed_at'] ?? null;
            if (!is_int($observed) || $observed < 1 || $observed > $published + 60) { ++$bins['missing']; continue; }
            $lag = max(0, $published - $observed); $lags[] = $lag;
            $maxLag = $maxLag === null ? $lag : max($maxLag, $lag);
            if ($lag <= 60) ++$bins['le60'];
            elseif ($lag <= 300) ++$bins['61_300'];
            elseif ($lag <= 900) ++$bins['301_900'];
            elseif ($lag <= 3600) ++$bins['901_3600'];
            else ++$bins['gt3600'];
            if ($lag > 300) $objectOver300 = true;
            if ($lag > 3600) $objectOver3600 = true;
        }
        if ($objectOver300) ++$objectsOver300;
        if ($objectOver3600) ++$objectsOver3600;
    }
    sort($lags, SORT_NUMERIC);
    $pct = static function(array $values, float $q): ?int {
        if ($values === []) return null;
        $i = (int)ceil(count($values) * $q) - 1;
        return $values[max(0, min(count($values)-1, $i))];
    };

    $epoch = $current['access_epoch'] ?? 'initial';
    if (!is_string($epoch) || $epoch === '') throw new RuntimeException('active_epoch_invalid');
    $telemetryRead = static fn(string $id): array => $telemetry->read($id, $epoch);
    $roster = IntegrationVmwareShadowPlanner::roster($current);
    $metricEntities = array_values(array_filter($roster['entities'], static fn(array $e): bool => in_array($e['kind'], ['vm','host'], true)));
    $planning = IntegrationVmwareShadowPlanner::metricPlans($metricEntities, $telemetryRead, $capabilities, $now);
    $due = $planning['capability_due'];
    if (!is_array($due) || !array_is_list($due)) throw new RuntimeException('planning_shape');

    $cacheAbsent=0; $cacheExpired=0; $duePropertyStale=0;
    foreach ($due as $entity) {
        $kind=$entity['kind'] ?? null; $id=$entity['id'] ?? null;
        if (!is_string($kind) || !is_string($id)) throw new RuntimeException('planning_shape');
        $entry=$capabilities['entries'][$kind.':'.$id] ?? null;
        if (!is_array($entry)) ++$cacheAbsent;
        elseif (!is_int($entry['expires_at'] ?? null) || $entry['expires_at'] < $now) ++$cacheExpired;
        $property=$properties['objects'][$id] ?? null;
        if (is_array($property) && ($property['object_state'] ?? null) === 'stale') ++$duePropertyStale;
    }

    $runtime = $context->workerRuntime(IntegrationSecretCipher::from_keyring_file());
    $authorized = [$context, 'current'];
    $session = new IntegrationVmwareBatchSession($runtime, $authorized, null, 128, 120);
    $outcomes = $session->run(function(IntegrationVmwareBatchSession $session) use ($due): array {
        $performance = new IntegrationVmwareBatchPerformance($session, 25);
        $o=['provider_supported'=>0,'provider_not_realtime'=>0,'provider_stale_mor'=>0,
            'availability_stale_mor'=>0,'availability_any_selected'=>0,'availability_none_selected'=>0];
        foreach ($due as $entity) {
            try { $provider=$performance->provider($entity['kind'],$entity['id']); }
            catch (Throwable $e) {
                if ($e->getMessage()==='batch_managed_object_not_found') { ++$o['provider_stale_mor']; continue; }
                throw $e;
            }
            if (($provider['current_supported'] ?? null) !== true) { ++$o['provider_not_realtime']; continue; }
            ++$o['provider_supported'];
            $interval=$provider['interval_id'] ?? null;
            if (!is_int($interval)) throw new RuntimeException('provider_interval_invalid');
            try { $availability=$performance->availability($entity['kind'],$entity['id'],$interval); }
            catch (Throwable $e) {
                if ($e->getMessage()==='batch_managed_object_not_found') { ++$o['availability_stale_mor']; continue; }
                throw $e;
            }
            $selected=0;
            foreach (['cpu.usage.average','mem.usage.average','disk.usage.average'] as $name) if (!empty($availability['aggregate_available'][$name])) ++$selected;
            if ($selected > 0) ++$o['availability_any_selected']; else ++$o['availability_none_selected'];
        }
        return $o;
    });
    $stats=$session->statistics();

    $result=[
        'metric_fresh_series'=>$freshSeries,
        'metric_lag_bins_seconds'=>$bins,
        'metric_objects_over_300_seconds'=>$objectsOver300,
        'metric_objects_over_3600_seconds'=>$objectsOver3600,
        'metric_lag_p50_seconds'=>$pct($lags,0.50),
        'metric_lag_p95_seconds'=>$pct($lags,0.95),
        'metric_lag_p99_seconds'=>$pct($lags,0.99),
        'metric_lag_max_seconds'=>$maxLag,
        'capability_entries'=>count($capabilities['entries']),
        'capability_plans_from_cache_or_telemetry'=>count($planning['plans']),
        'capability_unsupported_from_cache_or_telemetry'=>count($planning['unsupported']),
        'capability_due_initial'=>count($due),
        'capability_due_cache_absent'=>$cacheAbsent,
        'capability_due_cache_expired'=>$cacheExpired,
        'capability_due_property_stale'=>$duePropertyStale,
        'capability_probe_outcomes'=>$outcomes,
        'requests'=>$stats['requests'],
        'response_bytes'=>$stats['response_bytes'],
        'website_served_from_shadow'=>false,
        'shadow_written'=>false,
    ];
    print 'PHASE5H5_RESIDUAL_RESULT=' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    print "PHASE5H5_RESIDUAL_EXIT=0\n";
    $exit=0;
} catch (Throwable $e) {
    $category = match ($e->getMessage()) {
        'batch_access_denied','binding_changed','batch_binding_changed','mirror_connector_unavailable' => 'access',
        'batch_budget_exhausted' => 'budget',
        'batch_transport_failed','batch_http_failed' => 'transport',
        default => 'operation',
    };
    fwrite(STDERR, 'PHASE5H5_RESIDUAL_ERROR=' . $category . "\nPHASE5H5_RESIDUAL_EXIT=1\n");
    $exit=1;
} finally {
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
    if (is_string($key)) sodium_memzero($key);
}
exit($exit);
