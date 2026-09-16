<?php
/**
 * Read-only Phase5H fast-domain projection for future local/native readers.
 *
 * This class never talks to vCenter and never mutates a fast domain. Object membership is
 * governed only by the separately reconciled roster. Retained property/metric history for an
 * object that is unknown or confirmed absent is therefore never exposed as current membership.
 */
final class IntegrationVmwareFastReader
{
    public function __construct(
        private IntegrationVmwareFastStore $store,
        private int $maxPropertyAge = 300,
        private int $maxMetricAge = 300
    ) {
        self::validateAgeLimit($maxPropertyAge);
        self::validateAgeLimit($maxMetricAge);
    }

    public function object(string $kind, string $id, ?int $now = null): array
    {
        $now ??= time();
        return self::project(
            $this->store->read('roster'),
            $this->store->read('properties'),
            $this->store->read('metrics'),
            $kind,
            $id,
            $now,
            $this->maxPropertyAge,
            $this->maxMetricAge
        );
    }

    /**
     * Pure projection used by readers and contract tests. No transport/provider dependency.
     */
    public static function project(
        ?array $roster,
        ?array $properties,
        ?array $metrics,
        string $kind,
        string $id,
        int $now,
        int $maxPropertyAge = 300,
        int $maxMetricAge = 300
    ): array {
        self::validateIdentity($kind, $id);
        if ($now < 1) throw new InvalidArgumentException('fast_reader_time_invalid');
        self::validateAgeLimit($maxPropertyAge);
        self::validateAgeLimit($maxMetricAge);

        $result = [
            'schema' => 1,
            'source' => 'phase5h_fast',
            'local_only' => true,
            'kind' => $kind,
            'id' => $id,
            'membership_state' => 'unknown',
            'membership_confirmed_at' => null,
            'roster_published_at' => null,
            'roster_age_seconds' => null,
            'property_domain_published_at' => null,
            'property_domain_age_seconds' => null,
            'property_object_state' => 'unavailable',
            'properties' => [],
            'metric_domain_published_at' => null,
            'metric_domain_age_seconds' => null,
            'metric_object_state' => $kind === 'datastore' ? 'not_applicable' : 'unavailable',
            'metrics' => [],
        ];

        // Without a reconciled roster, retained fast-domain values must not establish membership.
        if ($roster === null) return $result;
        self::validateRoster($roster, $now);
        $membership = IntegrationVmwareRosterReconciler::membership($roster, $kind, $id);
        $result['membership_state'] = $membership;
        $result['roster_published_at'] = $roster['published_at'] ?? null;
        $result['roster_age_seconds'] = self::age($result['roster_published_at'], $now);
        if (is_array($roster['authoritative'] ?? null)) {
            $result['membership_confirmed_at'] = $roster['authoritative']['confirmed_at'];
        }
        if ($membership !== 'present') return $result;

        self::projectProperties($result, $properties, $kind, $id, $now, $maxPropertyAge);
        if ($kind !== 'datastore') self::projectMetrics($result, $metrics, $kind, $id, $now, $maxMetricAge);
        return $result;
    }

    private static function projectProperties(array &$result, ?array $domain, string $kind, string $id, int $now, int $maxAge): void
    {
        if ($domain === null) return;
        self::validateDomain($domain, 'properties', $now);
        $result['property_domain_published_at'] = $domain['published_at'];
        $result['property_domain_age_seconds'] = self::age($domain['published_at'], $now);
        $record = $domain['objects'][$id] ?? null;
        if ($record === null) {
            $result['property_object_state'] = 'missing';
            return;
        }
        if (!is_array($record) || ($record['id'] ?? $id) !== $id || ($record['kind'] ?? null) !== $kind ||
            !in_array($record['object_state'] ?? null, ['fresh', 'stale'], true) || !is_array($record['fields'] ?? null)) {
            throw new RuntimeException('fast_reader_property_record_invalid');
        }
        $states = [];
        foreach ($record['fields'] as $field => $entry) {
            if (!is_string($field) || $field === '' || strlen($field) > 256 || !is_array($entry)) {
                throw new RuntimeException('fast_reader_property_field_invalid');
            }
            $state = $entry['state'] ?? null;
            if (!in_array($state, ['fresh', 'stale', 'unavailable', 'missing'], true)) {
                throw new RuntimeException('fast_reader_property_field_invalid');
            }
            $collected = $entry['collected_at'] ?? null;
            $lastAttempt = $entry['last_attempt_at'] ?? null;
            self::validateOptionalTime($collected, $now);
            self::validateOptionalTime($lastAttempt, $now);
            $age = self::age($collected, $now);
            if ($state === 'fresh' && ($age === null || $age > $maxAge)) $state = 'stale';
            if ($state === 'unavailable' && (($entry['value'] ?? null) !== null)) {
                throw new RuntimeException('fast_reader_property_field_invalid');
            }
            $result['properties'][$field] = [
                'state' => $state,
                'value' => $entry['value'] ?? null,
                'age_seconds' => $age,
                'collected_at' => $collected,
                'last_attempt_at' => $lastAttempt,
                'error' => $entry['error'] ?? null,
            ];
            $states[] = $state;
        }
        $result['property_object_state'] = self::propertyRollup($record['object_state'], $states);
    }

    private static function projectMetrics(array &$result, ?array $domain, string $kind, string $id, int $now, int $maxAge): void
    {
        if ($domain === null) return;
        self::validateDomain($domain, 'metrics', $now);
        $result['metric_domain_published_at'] = $domain['published_at'];
        $result['metric_domain_age_seconds'] = self::age($domain['published_at'], $now);
        $record = $domain['objects'][$id] ?? null;
        if ($record === null) {
            $result['metric_object_state'] = 'missing';
            return;
        }
        if (!is_array($record) || ($record['id'] ?? null) !== $id || ($record['kind'] ?? null) !== $kind ||
            !in_array($record['object_state'] ?? null, ['fresh', 'partial', 'stale', 'unsupported'], true) ||
            !is_array($record['series'] ?? null)) {
            throw new RuntimeException('fast_reader_metric_record_invalid');
        }
        $states = [];
        foreach ($record['series'] as $name => $series) {
            if (!in_array($name, ['cpu_percent', 'memory_percent', 'disk_kib_per_second'], true) || !is_array($series) ||
                !in_array($series['state'] ?? null, ['fresh', 'stale', 'unsupported'], true) || !is_array($series['points'] ?? null) ||
                !array_is_list($series['points']) || count($series['points']) > 60) {
                throw new RuntimeException('fast_reader_metric_series_invalid');
            }
            $collected = $series['collected_at'] ?? null;
            $observed = $series['observed_at'] ?? null;
            self::validateOptionalTime($collected, $now);
            self::validateOptionalTime($observed, $now);
            $points = [];
            foreach ($series['points'] as $point) {
                if (!is_array($point) || !is_int($point['observed_at'] ?? null) || $point['observed_at'] < 1 ||
                    $point['observed_at'] > $now + 60 || (!is_null($point['value'] ?? null) && !is_numeric($point['value']))) {
                    throw new RuntimeException('fast_reader_metric_point_invalid');
                }
                $points[] = ['observed_at' => $point['observed_at'], 'value' => $point['value'] ?? null];
            }
            $age = self::age($observed ?? $collected, $now);
            $state = $series['state'];
            if ($state === 'fresh' && ($age === null || $age > $maxAge)) $state = 'stale';
            $result['metrics'][$name] = [
                'state' => $state,
                'points' => $points,
                'age_seconds' => $age,
                'observed_at' => $observed,
                'collected_at' => $collected,
            ];
            $states[] = $state;
        }
        $result['metric_object_state'] = self::metricRollup($record['object_state'], $states);
    }

    private static function propertyRollup(string $sourceState, array $states): string
    {
        if ($sourceState === 'stale') return 'stale';
        if ($states === []) return 'missing';
        $fresh = in_array('fresh', $states, true);
        $stale = in_array('stale', $states, true) || in_array('missing', $states, true);
        if ($fresh && $stale) return 'partial';
        if ($stale) return 'stale';
        if ($fresh) return 'fresh';
        return 'unavailable';
    }

    private static function metricRollup(string $sourceState, array $states): string
    {
        if ($sourceState === 'unsupported') return 'unsupported';
        if ($sourceState === 'stale') return 'stale';
        if ($states === []) return $sourceState === 'partial' ? 'partial' : 'missing';
        $fresh = in_array('fresh', $states, true);
        $stale = in_array('stale', $states, true);
        $unsupported = in_array('unsupported', $states, true);
        if ($sourceState === 'partial' || ($fresh && ($stale || $unsupported))) return 'partial';
        if ($fresh) return 'fresh';
        if ($stale) return 'stale';
        if ($unsupported) return 'unsupported';
        return 'missing';
    }

    private static function validateRoster(array $roster, int $now): void
    {
        if (($roster['schema'] ?? null) !== 1 || ($roster['domain'] ?? null) !== 'roster' ||
            !array_key_exists('authoritative', $roster) || !array_key_exists('pending', $roster)) {
            throw new RuntimeException('fast_reader_roster_invalid');
        }
        self::validateOptionalTime($roster['published_at'] ?? null, $now);
        if (is_array($roster['authoritative'] ?? null)) {
            $published = $roster['published_at'] ?? null;
            $confirmed = $roster['authoritative']['confirmed_at'] ?? null;
            $hash = $roster['authoritative']['hash'] ?? null;
            if (!is_int($published) || $published < 1 || !is_int($confirmed) || $confirmed < 1 || $confirmed > $now + 60 ||
                !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('fast_reader_roster_invalid');
            }
        }
    }

    private static function validateDomain(array $domain, string $expected, int $now): void
    {
        if (($domain['schema'] ?? null) !== 1 || ($domain['domain'] ?? null) !== $expected ||
            ($domain['authoritative_membership'] ?? null) !== false || !is_array($domain['objects'] ?? null) ||
            !is_int($domain['published_at'] ?? null) || $domain['published_at'] < 1 || $domain['published_at'] > $now + 60) {
            throw new RuntimeException('fast_reader_domain_invalid');
        }
    }

    private static function validateIdentity(string $kind, string $id): void
    {
        if (!in_array($kind, ['vm', 'host', 'datastore'], true) ||
            !preg_match('/^' . preg_quote($kind, '/') . '-[0-9]{1,20}$/D', $id)) {
            throw new InvalidArgumentException('fast_reader_identity_invalid');
        }
    }

    private static function validateAgeLimit(int $value): void
    {
        if ($value < 1 || $value > 3600) throw new InvalidArgumentException('fast_reader_age_limit_invalid');
    }

    private static function validateOptionalTime(mixed $value, int $now): void
    {
        if ($value !== null && (!is_int($value) || $value < 1 || $value > $now + 60)) {
            throw new RuntimeException('fast_reader_timestamp_invalid');
        }
    }

    private static function age(?int $stamp, int $now): ?int
    {
        return is_int($stamp) && $stamp > 0 && $stamp <= $now + 60 ? max(0, $now - $stamp) : null;
    }
}
