#!/usr/bin/env php
<?php
/**
 * Aggregate read-only inspection of the encrypted Phase5H shadow domains.
 * No object identifiers/names/values are emitted and no provider call is made.
 */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H5_INSPECT_ERROR=identity\n"); exit(1);
}

const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const SHADOW = '/opt/phpipam-custom/vmware-fast-shadow';
const SHADOW_ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';

$key = null;
try {
    require_once APP . '/functions/functions.php';
    require_once APP . '/functions/classes/class.IntegrationVmwareSnapshotService.php';
    foreach (['Store','Context'] as $name) require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    require_once SHADOW . '/layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';

    foreach ([SHADOW_ROOT, SHADOW_ROOT . '/properties', SHADOW_ROOT . '/metrics', SHADOW_ROOT . '/capabilities', SHADOW_ROOT . '/roster'] as $dir) {
        if (!is_dir($dir) || is_link($dir)) throw new RuntimeException('shadow_store_boundary');
        $st = stat($dir);
        if (!is_array($st) || $st['uid'] !== 33 || $st['gid'] !== 33 || ($st['mode'] & 0777) !== 0700) throw new RuntimeException('shadow_store_boundary');
    }

    $db = new Database_PDO();
    $context = new IntegrationVmwareInventoryContext($db, APP, 1);
    if (!$context->current()) throw new RuntimeException('binding_changed');
    $rows = $db->getObjectsQuery('no_html_escape',
        'SELECT `id`,`provider`,`enabled`,`endpoint`,`username`,`verify_tls`,`secret_envelope`,`secret_key_id` FROM `custom_integrations` WHERE `id` = ? AND `provider` = ?;',
        [1, 'vmware_vcenter']);
    if (!is_array($rows) || count($rows) !== 1) throw new RuntimeException('connector_unavailable');
    $row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
    if (!is_array($row)) throw new RuntimeException('connector_unavailable');
    $key = IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-cache.key', 32, 0, 0640, posix_getegid());
    $binding = IntegrationVmwareInventoryStore::binding($key, APP, $row);
    $fast = new IntegrationVmwareFastStore(SHADOW_ROOT, $key, $binding);

    $properties = $fast->read('properties');
    $metrics = $fast->read('metrics');
    $capabilities = $fast->read('capabilities');
    $now = time();

    $validStamp = static function ($value, int $upper) {
        return is_int($value) && $value > 0 && $value <= $upper + 60 ? $value : null;
    };
    $spread = static function (array $values): array {
        if ($values === []) return ['count'=>0,'min'=>null,'max'=>null,'avg'=>null];
        sort($values, SORT_NUMERIC);
        return ['count'=>count($values),'min'=>$values[0],'max'=>$values[count($values)-1],
            'avg'=>(int)round(array_sum($values)/count($values))];
    };

    $propertySummary = ['present'=>false];
    if (is_array($properties)) {
        if (($properties['schema'] ?? null) !== 1 || ($properties['domain'] ?? null) !== 'properties' ||
            !is_array($properties['objects'] ?? null) || ($properties['authoritative_membership'] ?? null) !== false) {
            throw new RuntimeException('properties_shape');
        }
        $pub = $validStamp($properties['published_at'] ?? null, $now);
        $sourceStart = $validStamp($properties['source_started_at'] ?? null, $now);
        $sourceComplete = $validStamp($properties['source_completed_at'] ?? null, $now);
        if ($pub === null) throw new RuntimeException('properties_time');
        $objects = ['fresh'=>0,'stale'=>0,'other'=>0];
        $fields = ['fresh'=>0,'stale'=>0,'unavailable'=>0,'missing'=>0,'other'=>0];
        $lags = [];
        foreach ($properties['objects'] as $record) {
            if (!is_array($record)) throw new RuntimeException('properties_shape');
            $state = $record['object_state'] ?? 'other';
            isset($objects[$state]) ? ++$objects[$state] : ++$objects['other'];
            foreach (($record['fields'] ?? []) as $field) {
                if (!is_array($field)) throw new RuntimeException('properties_shape');
                $fieldState = $field['state'] ?? 'other';
                isset($fields[$fieldState]) ? ++$fields[$fieldState] : ++$fields['other'];
                if ($fieldState === 'fresh') {
                    $collected = $validStamp($field['collected_at'] ?? null, $pub);
                    if ($collected !== null && $collected <= $pub) $lags[] = $pub - $collected;
                }
            }
        }
        $propertySummary = ['present'=>true,'published_at'=>$pub,'publication_age_seconds'=>max(0,$now-$pub),
            'source_started_at'=>$sourceStart,'source_completed_at'=>$sourceComplete,
            'source_window_seconds'=>$sourceStart !== null && $sourceComplete !== null && $sourceComplete >= $sourceStart ? $sourceComplete-$sourceStart : null,
            'source_completed_to_visible_seconds'=>$sourceComplete !== null && $sourceComplete <= $pub ? $pub-$sourceComplete : null,
            'objects'=>$objects,'fields'=>$fields,'fresh_field_collected_to_visible_seconds'=>$spread($lags)];
    }

    $metricSummary = ['present'=>false];
    if (is_array($metrics)) {
        if (($metrics['schema'] ?? null) !== 1 || ($metrics['domain'] ?? null) !== 'metrics' ||
            !is_array($metrics['objects'] ?? null) || ($metrics['authoritative_membership'] ?? null) !== false) {
            throw new RuntimeException('metrics_shape');
        }
        $pub = $validStamp($metrics['published_at'] ?? null, $now);
        if ($pub === null) throw new RuntimeException('metrics_time');
        $objects = ['fresh'=>0,'partial'=>0,'stale'=>0,'unsupported'=>0,'other'=>0];
        $series = ['fresh'=>0,'stale'=>0,'unsupported'=>0,'other'=>0];
        $observedLags = []; $collectedLags = [];
        foreach ($metrics['objects'] as $record) {
            if (!is_array($record)) throw new RuntimeException('metrics_shape');
            $state = $record['object_state'] ?? 'other';
            isset($objects[$state]) ? ++$objects[$state] : ++$objects['other'];
            foreach (($record['series'] ?? []) as $row) {
                if (!is_array($row)) throw new RuntimeException('metrics_shape');
                $seriesState = $row['state'] ?? 'other';
                isset($series[$seriesState]) ? ++$series[$seriesState] : ++$series['other'];
                if ($seriesState === 'fresh') {
                    $observed = $validStamp($row['observed_at'] ?? null, $pub);
                    $collected = $validStamp($row['collected_at'] ?? null, $pub);
                    if ($observed !== null && $observed <= $pub) $observedLags[] = $pub - $observed;
                    if ($collected !== null && $collected <= $pub) $collectedLags[] = $pub - $collected;
                }
            }
        }
        $metricSummary = ['present'=>true,'published_at'=>$pub,'publication_age_seconds'=>max(0,$now-$pub),
            'objects'=>$objects,'series'=>$series,
            'fresh_series_observed_to_visible_seconds'=>$spread($observedLags),
            'fresh_series_collected_to_visible_seconds'=>$spread($collectedLags)];
    }

    $capSummary = ['present'=>false];
    if (is_array($capabilities)) {
        if (($capabilities['schema'] ?? null) !== 1 || ($capabilities['domain'] ?? null) !== 'capabilities' || !is_array($capabilities['entries'] ?? null)) {
            throw new RuntimeException('capabilities_shape');
        }
        $valid=0; $expired=0; $supported=0; $unsupported=0;
        foreach ($capabilities['entries'] as $entry) {
            if (!is_array($entry)) throw new RuntimeException('capabilities_shape');
            $expires = $entry['expires_at'] ?? null;
            if (is_int($expires) && $expires >= $now) ++$valid; else ++$expired;
            if (($entry['current_supported'] ?? null) === true) ++$supported;
            elseif (($entry['current_supported'] ?? null) === false) ++$unsupported;
        }
        $capSummary = ['present'=>true,'entries'=>count($capabilities['entries']),'valid_now'=>$valid,'expired_now'=>$expired,
            'supported'=>$supported,'unsupported'=>$unsupported,'published_at'=>$validStamp($capabilities['published_at'] ?? null,$now)];
    }

    print 'PHASE5H5_SHADOW_INSPECT=' . json_encode(['now'=>$now,'properties'=>$propertySummary,'metrics'=>$metricSummary,'capabilities'=>$capSummary,
        'website_served_from_shadow'=>false,'shadow_read_only_inspection'=>true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    print "PHASE5H5_SHADOW_INSPECT_EXIT=0\n";
    $exit = 0;
} catch (Throwable $e) {
    fwrite(STDERR, "PHASE5H5_SHADOW_INSPECT_ERROR=operation\nPHASE5H5_SHADOW_INSPECT_EXIT=1\n");
    $exit = 1;
} finally {
    if (is_string($key)) sodium_memzero($key);
}
exit($exit);
