#!/usr/bin/env php
<?php
/** Aggregate-only diagnosis of existing inventory. No collectors, credential decryption or writes. */
final class Phase5G4Diagnostic
{
    private static function label($value, array $allowed): string
    {
        if ($value === null) return 'none';
        return is_string($value) && in_array($value, $allowed, true) ? $value : 'other';
    }
    public static function metricReason($part, string $kind, string $id, int $now): string
    {
        if (!is_array($part)) return 'missing';
        if (($part['state'] ?? '') !== 'ok') return 'state_' . self::label($part['state'] ?? null, ['stale', 'unavailable']);
        $time = $part['collected_at'] ?? null;
        if (!is_int($time)) return 'receipt_time_missing';
        if ($time > $now + 60) return 'receipt_time_future';
        if ($now - $time > 14400) return 'receipt_time_expired';
        $data = $part['data'] ?? null;
        if (!is_array($data) || ($data['entity_external_id'] ?? '') !== $id ||
            ($data['entity_type'] ?? '') !== $kind || ($data['verify_tls'] ?? null) !== true ||
            !is_array($data['samples'] ?? null)) return 'payload_invalid';
        return 'ready';
    }
    public static function summarize(?array $current, callable $read, array $state, int $now): array
    {
        if (!empty($current['blocked'])) return ['state' => 'access_denied', 'object_data_emitted' => false];
        $out = ['state' => 'diagnosed', 'timestamp' => $now, 'has_inventory' => is_array($current['inventory'] ?? null),
            'collection_state' => self::label($current['state'] ?? null, ['collecting', 'ready', 'error']),
            'inventory_counts' => [], 'metric_expected' => 0, 'metric_ready' => 0, 'metric_missing' => 0,
            'metric_not_ready' => 0, 'metric_groups' => [], 'optional_not_ok' => 0, 'optional_groups' => [],
            'sampler' => [], 'collector_invoked' => false, 'object_data_emitted' => false];
        foreach (['cursor', 'completed', 'total', 'cycle_started_at', 'cycle_completed_at', 'heartbeat_at', 'last_success_at'] as $key) {
            if (is_int($state[$key] ?? null) && $state[$key] >= 0) $out['sampler'][$key] = $state[$key];
        }
        $out['sampler']['last_error'] = self::label($state['last_error'] ?? null, ['tls', 'sample_unavailable', 'access_denied']);
        $out['sampler']['state'] = self::label($state['state'] ?? null, ['idle', 'sampling', 'blocked']);
        if (!$out['has_inventory']) return $out;
        $epoch = $current['access_epoch'] ?? 'initial';
        foreach (['vm' => 1000, 'host' => 1000, 'datastore' => 2000] as $kind => $limit) {
            $records = $current['inventory']['objects'][$kind] ?? null;
            if (!is_array($records) || count($records) > $limit) throw new RuntimeException('diagnostic_inventory_shape');
            $out['inventory_counts'][$kind] = count($records);
            foreach ($records as $id => $record) {
                if (!is_string($id) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id)) throw new RuntimeException('diagnostic_inventory_shape');
                foreach ($record['data']['sections'] ?? [] as $name => $optional) {
                    if (($optional['state'] ?? '') === 'ok') continue;
                    $key = $kind . '/' . self::label($name, ['guest_network', 'guest_facts', 'annotation', 'network_path', 'network_hints'])
                        . '/' . self::label($optional['state'] ?? null, ['stale', 'unavailable'])
                        . '/' . self::label($optional['error'] ?? null, ['optional_read_failed']);
                    $out['optional_groups'][$key] = ($out['optional_groups'][$key] ?? 0) + 1; $out['optional_not_ok']++;
                }
                if ($kind === 'datastore') continue;
                $parts = $read($id, $epoch);
                foreach (['performance', 'storage_performance'] as $name) {
                    $out['metric_expected']++; $part = $parts[$name] ?? null;
                    $reason = self::metricReason($part, $kind, $id, $now);
                    if ($reason === 'ready') { $out['metric_ready']++; continue; }
                    $out['metric_not_ready']++;
                    if ($reason === 'missing') $out['metric_missing']++;
                    $error = self::label($part['error'] ?? null, ['tls', 'sample_unavailable']);
                    $key = $kind . '/' . $name . '/' . $reason . '/' . $error;
                    if (!isset($out['metric_groups'][$key])) $out['metric_groups'][$key] = [
                        'count' => 0, 'attempted' => 0, 'retry_in_future' => 0, 'due_in_future' => 0,
                        'failures_min' => null, 'failures_max' => null,
                        'attempt_age_min_seconds' => null, 'attempt_age_max_seconds' => null];
                    $group = &$out['metric_groups'][$key]; $group['count']++;
                    $failed = $part['failures'] ?? null;
                    if (is_int($failed) && $failed >= 0 && $failed <= 10) {
                        $group['failures_min'] = $group['failures_min'] === null ? $failed : min($group['failures_min'], $failed);
                        $group['failures_max'] = max($group['failures_max'] ?? 0, $failed);
                    }
                    if (is_int($part['attempted_at'] ?? null)) {
                        $group['attempted']++; $age = max(0, $now - $part['attempted_at']);
                        $group['attempt_age_min_seconds'] = $group['attempt_age_min_seconds'] === null ? $age : min($group['attempt_age_min_seconds'], $age);
                        $group['attempt_age_max_seconds'] = max($group['attempt_age_max_seconds'] ?? 0, $age);
                    }
                    if (is_int($part['retry_at'] ?? null) && $part['retry_at'] > $now) $group['retry_in_future']++;
                    $due = $state['due'][$id . ':' . $name] ?? null;
                    if (is_int($due) && $due > $now) $group['due_in_future']++;
                    unset($group);
                }
            }
        }
        ksort($out['metric_groups']); ksort($out['optional_groups']);
        return $out;
    }
}

function phase5g4_diagnostic_main(): int
{
    if (PHP_SAPI !== 'cli' || ($_SERVER['argc'] ?? 0) !== 1 || !function_exists('posix_geteuid') ||
        posix_geteuid() !== 33 || posix_getegid() !== 33) { fwrite(STDERR, "DIAGNOSTIC_IDENTITY_OR_ARGUMENTS_INVALID=1\n"); return 1; }
    $lock = null; $held = false;
    try {
        $app = '/var/www/html'; $release = '/opt/phpipam-custom/inventory';
        require_once($app . '/functions/functions.php');
        require_once($app . '/functions/classes/class.IntegrationVmwareSnapshotService.php');
        foreach (['Store', 'Context'] as $name) require_once($release . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php');
        require_once($release . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php');
        $context = new IntegrationVmwareInventoryContext(new Database_PDO(), $app, 1);
        // Read an existing lock only; do not call Store::exclusive (which can create/clean files).
        $path = '/var/lib/phpipam-custom/vmware-inventory/connector-1/worker.lock';
        IntegrationVmwareInventoryStore::noLinks($path);
        $before = lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0777) !== 0600 ||
            $before['uid'] !== 33 || $before['nlink'] !== 1 || $before['size'] !== 0) throw new RuntimeException('diagnostic_lock');
        $lock = fopen($path, 'rb'); $after = fstat($lock);
        if (!$after || $after['ino'] !== $before['ino'] || $after['dev'] !== $before['dev']) throw new RuntimeException('diagnostic_lock');
        for ($i = 0; $i < 20; $i++) {
            if (flock($lock, LOCK_SH | LOCK_NB)) { $held = true; break; }
            usleep(1000000);
        }
        if (!$held) { print "LOCAL_DIAGNOSTIC={\"state\":\"busy\",\"collector_invoked\":false}\n"; return 2; }
        if (!$context->current()) throw new RuntimeException('diagnostic_binding');
        $store = $context->store(); $telemetry = $context->telemetry();
        if ($telemetry === null) throw new RuntimeException('diagnostic_telemetry');
        $current = $store->read('current');
        if (!empty($current['blocked'])) $result = ['state' => 'access_denied', 'object_data_emitted' => false];
        else $result = Phase5G4Diagnostic::summarize($current, [$telemetry, 'read'], $telemetry->state(), time());
        if (!$context->current()) throw new RuntimeException('diagnostic_binding');
        print 'LOCAL_DIAGNOSTIC=' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        return 0;
    } catch (Throwable $error) {
        // Never echo database/provider exception text, identifiers, names or data payloads.
        fwrite(STDERR, "LOCAL_DIAGNOSTIC_FAILED=1\n"); return 1;
    } finally {
        if (is_resource($lock)) { if ($held) flock($lock, LOCK_UN); fclose($lock); }
    }
}
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(phase5g4_diagnostic_main());
