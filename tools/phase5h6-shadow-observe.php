#!/usr/bin/env php
<?php
/** Read-only aggregate observer for autonomous Phase5H publications. No provider call, IDs, names or values. */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H6_OBSERVE_ERROR=identity\n"); exit(1);
}

const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const SHADOW = '/opt/phpipam-custom/vmware-fast-shadow';
const ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';
$key = null;

try {
    require_once APP . '/functions/functions.php';
    require_once APP . '/functions/classes/class.IntegrationVmwareSnapshotService.php';
    foreach (['Store','Context'] as $name) require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    require_once SHADOW . '/layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';

    $database = new Database_PDO();
    $context = new IntegrationVmwareInventoryContext($database, APP, 1);
    if (!$context->current()) throw new RuntimeException('binding_changed');
    $rows = $database->getObjectsQuery('no_html_escape',
        'SELECT `id`,`provider`,`enabled`,`endpoint`,`username`,`verify_tls`,`secret_envelope`,`secret_key_id` FROM `custom_integrations` WHERE `id` = ? AND `provider` = ?;',
        [1, 'vmware_vcenter']);
    if (!is_array($rows) || count($rows) !== 1) throw new RuntimeException('connector_unavailable');
    $row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
    if (!is_array($row)) throw new RuntimeException('connector_unavailable');
    $key = IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-cache.key', 32, 0, 0640, posix_getegid());
    $binding = IntegrationVmwareInventoryStore::binding($key, APP, $row);
    $fast = new IntegrationVmwareFastStore(ROOT, $key, $binding);
    $properties = $fast->read('properties');
    $metrics = $fast->read('metrics');
    $capabilities = $fast->read('capabilities');
    $now = time();

    $valid = static fn($v, int $upper): ?int => is_int($v) && $v > 0 && $v <= $upper + 60 ? $v : null;
    $maxOrNull = static fn(array $v): ?int => $v === [] ? null : max($v);

    if (!is_array($properties) || ($properties['domain'] ?? null) !== 'properties' || !is_array($properties['objects'] ?? null)) {
        throw new RuntimeException('properties_shape');
    }
    if (!is_array($metrics) || ($metrics['domain'] ?? null) !== 'metrics' || !is_array($metrics['objects'] ?? null)) {
        throw new RuntimeException('metrics_shape');
    }
    $pp = $valid($properties['published_at'] ?? null, $now);
    $mp = $valid($metrics['published_at'] ?? null, $now);
    if ($pp === null || $mp === null) throw new RuntimeException('publication_time');

    $propertyLags = [];
    $propertyStates = ['fresh'=>0,'stale'=>0,'unavailable'=>0,'missing'=>0,'other'=>0];
    foreach ($properties['objects'] as $obj) {
        if (!is_array($obj)) throw new RuntimeException('properties_shape');
        foreach (($obj['fields'] ?? []) as $field) {
            if (!is_array($field)) throw new RuntimeException('properties_shape');
            $state = $field['state'] ?? 'other';
            isset($propertyStates[$state]) ? ++$propertyStates[$state] : ++$propertyStates['other'];
            if ($state === 'fresh') {
                $collected = $valid($field['collected_at'] ?? null, $pp);
                if ($collected !== null && $collected <= $pp) $propertyLags[] = $pp - $collected;
            }
        }
    }

    $metricObserved = []; $metricCollected = [];
    $metricObjects = ['fresh'=>0,'partial'=>0,'stale'=>0,'unsupported'=>0,'other'=>0];
    $metricSeries = ['fresh'=>0,'stale'=>0,'unsupported'=>0,'other'=>0];
    foreach ($metrics['objects'] as $obj) {
        if (!is_array($obj)) throw new RuntimeException('metrics_shape');
        $os = $obj['object_state'] ?? 'other';
        isset($metricObjects[$os]) ? ++$metricObjects[$os] : ++$metricObjects['other'];
        foreach (($obj['series'] ?? []) as $series) {
            if (!is_array($series)) throw new RuntimeException('metrics_shape');
            $ss = $series['state'] ?? 'other';
            isset($metricSeries[$ss]) ? ++$metricSeries[$ss] : ++$metricSeries['other'];
            if ($ss !== 'fresh') continue;
            $observed = $valid($series['observed_at'] ?? null, $mp);
            $collected = $valid($series['collected_at'] ?? null, $mp);
            if ($observed !== null && $observed <= $mp) $metricObserved[] = $mp - $observed;
            if ($collected !== null && $collected <= $mp) $metricCollected[] = $mp - $collected;
        }
    }

    $cap = ['entries'=>null,'valid_now'=>null,'expired_now'=>null];
    if (is_array($capabilities) && is_array($capabilities['entries'] ?? null)) {
        $validNow = 0; $expiredNow = 0;
        foreach ($capabilities['entries'] as $entry) {
            $expires = is_array($entry) ? ($entry['expires_at'] ?? null) : null;
            is_int($expires) && $expires >= $now ? ++$validNow : ++$expiredNow;
        }
        $cap = ['entries'=>count($capabilities['entries']),'valid_now'=>$validNow,'expired_now'=>$expiredNow];
    }

    $result = [
        'now'=>$now,
        'property_published_at'=>$pp,
        'metric_published_at'=>$mp,
        'property_publication_age_seconds'=>max(0,$now-$pp),
        'metric_publication_age_seconds'=>max(0,$now-$mp),
        'property_collected_to_visible_max_seconds'=>$maxOrNull($propertyLags),
        'metric_observed_to_visible_max_seconds'=>$maxOrNull($metricObserved),
        'metric_collected_to_visible_max_seconds'=>$maxOrNull($metricCollected),
        'property_fields_state'=>$propertyStates,
        'metric_objects_state'=>$metricObjects,
        'metric_series_state'=>$metricSeries,
        'capabilities'=>$cap,
        'shadow_read_only_observation'=>true,
        'website_served_from_shadow'=>false,
    ];
    echo 'PHASE5H6_OBSERVE=' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    echo "PHASE5H6_OBSERVE_EXIT=0\n";
    $exit = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "PHASE5H6_OBSERVE_ERROR=operation\nPHASE5H6_OBSERVE_EXIT=1\n");
    $exit = 1;
} finally {
    if (is_string($key)) sodium_memzero($key);
}
exit($exit);
