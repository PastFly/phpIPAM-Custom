<?php
/**
 * Pure fast-domain merge policy for independently dated local publication.
 * No transport, credentials, HTTP, DB writes or deletion inference.
 */
final class IntegrationVmwareFastDomain
{
    public static function mergeProperties(?array $previous, array $batch, int $now): array
    {
        if (($batch['schema'] ?? null) !== 1 || ($batch['authoritative_membership'] ?? null) !== false ||
            !is_array($batch['objects'] ?? null) || $now < 1) {
            throw new InvalidArgumentException('fast_property_batch_invalid');
        }
        $state = self::base($previous, 'properties');
        foreach ($batch['objects'] as $id => $record) {
            if (!is_string($id) || !is_array($record) || ($record['id'] ?? null) !== $id ||
                !in_array($record['kind'] ?? null, ['vm', 'host', 'datastore'], true) ||
                !in_array($record['state'] ?? null, ['returned', 'unobserved'], true)) {
                throw new RuntimeException('fast_property_record_invalid');
            }
            $old = $state['objects'][$id] ?? ['kind' => $record['kind'], 'id' => $id, 'fields' => []];
            if (($old['kind'] ?? $record['kind']) !== $record['kind']) throw new RuntimeException('fast_property_kind_changed');
            if ($record['state'] === 'unobserved') {
                $old['object_state'] = 'stale';
                $old['last_attempt_at'] = $now;
                $old['last_attempt_result'] = 'unobserved';
                $state['objects'][$id] = $old;
                continue;
            }
            $values = $record['values'] ?? null;
            $statuses = $record['property_states'] ?? null;
            $collected = $record['collected_at'] ?? null;
            if (!is_array($values) || !is_array($statuses) || !is_int($collected) || $collected < 1 || $collected > $now + 60) {
                throw new RuntimeException('fast_property_record_invalid');
            }
            $old['object_state'] = 'fresh';
            $old['last_attempt_at'] = $collected;
            $old['last_attempt_result'] = 'returned';
            foreach ($statuses as $field => $status) {
                if (!is_string($field) || !in_array($status, ['available', 'unavailable', 'property_fault', 'not_reported'], true)) {
                    throw new RuntimeException('fast_property_status_invalid');
                }
                $prior = $old['fields'][$field] ?? null;
                if ($status === 'available') {
                    if (!array_key_exists($field, $values)) throw new RuntimeException('fast_property_value_missing');
                    $old['fields'][$field] = ['state' => 'fresh', 'value' => $values[$field],
                        'observed_at' => null, 'collected_at' => $collected, 'last_attempt_at' => $collected, 'error' => null];
                } elseif ($status === 'unavailable') {
                    $old['fields'][$field] = ['state' => 'unavailable', 'value' => null,
                        'observed_at' => null, 'collected_at' => $collected, 'last_attempt_at' => $collected, 'error' => null];
                } else {
                    if (is_array($prior) && array_key_exists('value', $prior)) {
                        $prior['state'] = 'stale';
                        $prior['last_attempt_at'] = $collected;
                        $prior['error'] = $status;
                        $old['fields'][$field] = $prior;
                    } else {
                        $old['fields'][$field] = ['state' => 'missing', 'value' => null,
                            'observed_at' => null, 'collected_at' => null, 'last_attempt_at' => $collected, 'error' => $status];
                    }
                }
            }
            $state['objects'][$id] = $old;
        }
        $state['published_at'] = $now;
        $state['source_started_at'] = self::boundedTime($batch['started_at'] ?? null, $now);
        $state['source_completed_at'] = self::boundedTime($batch['completed_at'] ?? null, $now);
        $state['authoritative_membership'] = false;
        return $state;
    }

    /** Merge normalized metric records; partial results may refresh only the series actually returned. */
    public static function mergeMetrics(?array $previous, array $records, int $now): array
    {
        if (!array_is_list($records) || $now < 1) throw new InvalidArgumentException('fast_metric_batch_invalid');
        $state = self::base($previous, 'metrics');
        foreach ($records as $record) {
            if (!is_array($record) || !is_string($record['id'] ?? null) ||
                !preg_match('/^(vm|host)-[0-9]{1,20}$/D', $record['id']) ||
                !in_array($record['kind'] ?? null, ['vm', 'host'], true) ||
                !in_array($record['state'] ?? null, ['ok', 'partial', 'not_returned', 'stale_source', 'capability_pending', 'unsupported'], true)) {
                throw new RuntimeException('fast_metric_record_invalid');
            }
            $id = $record['id'];
            $old = $state['objects'][$id] ?? ['id' => $id, 'kind' => $record['kind'], 'series' => []];
            if (($old['kind'] ?? null) !== $record['kind']) throw new RuntimeException('fast_metric_kind_changed');
            $attempt = self::boundedTime($record['attempted_at'] ?? $now, $now) ?? $now;
            if (in_array($record['state'], ['not_returned', 'stale_source', 'capability_pending'], true)) {
                $old['object_state'] = 'stale';
                $old['last_attempt_at'] = $attempt;
                $old['last_attempt_result'] = $record['state'];
                foreach ($old['series'] as &$priorSeries) if (is_array($priorSeries)) $priorSeries['state'] = 'stale';
                unset($priorSeries);
                $state['objects'][$id] = $old;
                continue;
            }
            $series = $record['series'] ?? null;
            if (!is_array($series)) throw new RuntimeException('fast_metric_series_invalid');
            $old['object_state'] = $record['state'] === 'unsupported' ? 'unsupported' : ($record['state'] === 'partial' ? 'partial' : 'fresh');
            $old['last_attempt_at'] = $attempt;
            $old['last_attempt_result'] = $record['state'];
            $updated = [];
            foreach ($series as $name => $points) {
                if (!in_array($name, ['cpu_percent', 'memory_percent', 'disk_kib_per_second'], true) || !is_array($points) || count($points) > 60) {
                    throw new RuntimeException('fast_metric_series_invalid');
                }
                $normalized = [];
                foreach ($points as $point) {
                    if (!is_array($point) || !is_int($point['observed_at'] ?? null) || $point['observed_at'] < 1 ||
                        $point['observed_at'] > $now + 60 || (!is_null($point['value'] ?? null) && !is_numeric($point['value']))) {
                        throw new RuntimeException('fast_metric_point_invalid');
                    }
                    $normalized[] = ['observed_at' => $point['observed_at'], 'value' => $point['value'] ?? null];
                }
                $old['series'][$name] = ['state' => $record['state'] === 'unsupported' ? 'unsupported' : 'fresh', 'points' => $normalized,
                    'collected_at' => $attempt, 'observed_at' => $normalized === [] ? null : max(array_column($normalized, 'observed_at'))];
                $updated[$name] = true;
            }
            if (in_array($record['state'], ['ok', 'partial'], true)) {
                // A successful current plan is positive evidence only for the series it actually returned.
                // Preserve last-good points from counters no longer present in the plan, but never leave them fresh.
                foreach ($old['series'] as $name => &$priorSeries) {
                    if (!isset($updated[$name]) && is_array($priorSeries)) $priorSeries['state'] = 'stale';
                }
                unset($priorSeries);
            } elseif ($record['state'] === 'unsupported') {
                // Unsupported is terminal evidence for this capability TTL. Preserve any last-good points,
                // but never leave prior series marked fresh inside an unsupported object.
                foreach ($old['series'] as $name => &$priorSeries) {
                    if (!isset($updated[$name]) && is_array($priorSeries)) $priorSeries['state'] = 'unsupported';
                }
                unset($priorSeries);
            }
            $state['objects'][$id] = $old;
        }
        $state['published_at'] = $now;
        $state['authoritative_membership'] = false;
        return $state;
    }

    public static function age(?int $observedAt, ?int $collectedAt, int $now): ?int
    {
        $stamp = $observedAt ?? $collectedAt;
        return is_int($stamp) && $stamp > 0 && $stamp <= $now + 60 ? max(0, $now - $stamp) : null;
    }

    private static function base(?array $previous, string $domain): array
    {
        if ($previous === null) return ['schema' => 1, 'domain' => $domain, 'published_at' => null,
            'authoritative_membership' => false, 'objects' => []];
        if (($previous['schema'] ?? null) !== 1 || ($previous['domain'] ?? null) !== $domain ||
            !is_array($previous['objects'] ?? null) || ($previous['authoritative_membership'] ?? false) !== false) {
            throw new RuntimeException('fast_previous_invalid');
        }
        return $previous;
    }

    private static function boundedTime($value, int $now): ?int
    {
        if ($value === null) return null;
        if (!is_int($value) || $value < 1 || $value > $now + 60) throw new RuntimeException('fast_timestamp_invalid');
        return $value;
    }
}
