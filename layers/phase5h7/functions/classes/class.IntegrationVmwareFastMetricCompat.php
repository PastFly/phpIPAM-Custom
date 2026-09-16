<?php
/**
 * Convert normalized Phase5H fast metric series to the retained Phase5G renderer section contract.
 * Pure projection only: no provider, transport, persistence, scheduling or fallback mutation.
 */
final class IntegrationVmwareFastMetricCompat
{
    public static function sections(array $fast, string $kind, string $id): array
    {
        self::identity($kind, $id);
        if (($fast['kind'] ?? null) !== $kind || ($fast['id'] ?? null) !== $id ||
            ($fast['membership_state'] ?? null) !== 'present' || !is_array($fast['metrics'] ?? null)) {
            return [];
        }

        $out = [];
        $cpu = self::freshSeries($fast['metrics']['cpu_percent'] ?? null, 'percent');
        $memory = self::freshSeries($fast['metrics']['memory_percent'] ?? null, 'percent');
        if ($cpu !== null && $memory !== null && self::aligned($cpu['points'], $memory['points'])) {
            $samples = [];
            $interval = self::stableInterval($cpu['points']);
            foreach ($cpu['points'] as $i => $point) {
                $samples[] = [
                    'timestamp' => self::timestamp($point['observed_at']),
                    'interval_seconds' => $interval,
                    'cpu_percent' => $point['value'],
                    'memory_percent' => $memory['points'][$i]['value'],
                ];
            }
            $out['performance'] = [
                'state' => 'ok',
                'observed_at' => self::latest($cpu['points']),
                'collected_at' => max((int)$cpu['collected_at'], (int)$memory['collected_at']),
                'data' => [
                    'entity_type' => $kind,
                    'entity_external_id' => $id,
                    'provider' => ['current_supported' => true, 'refresh_rate' => $interval],
                    'samples' => $samples,
                    'sample_count' => count($samples),
                    'verify_tls' => true,
                ],
            ];
        }

        $disk = self::freshSeries($fast['metrics']['disk_kib_per_second'] ?? null, 'disk');
        if ($disk !== null) {
            $samples = [];
            $interval = self::stableInterval($disk['points']);
            foreach ($disk['points'] as $point) {
                // The retained storage collector omits unavailable points instead of turning them into zero.
                if ($point['value'] === null) continue;
                $samples[] = [
                    'timestamp' => self::timestamp($point['observed_at']),
                    'interval_seconds' => $interval,
                    'disk_kib_per_second' => $point['value'],
                    'disk_mib_per_second' => $point['value'] / 1024.0,
                ];
            }
            $supported = count($samples) >= 2;
            $out['storage_performance'] = [
                'state' => 'ok',
                'observed_at' => self::latest($disk['points']),
                'collected_at' => (int)$disk['collected_at'],
                'data' => [
                    'supported' => $supported,
                    'reason' => $supported ? null : 'insufficient_samples',
                    'entity_type' => $kind,
                    'entity_external_id' => $id,
                    'refresh_rate' => $interval,
                    'samples' => $samples,
                    'sample_count' => count($samples),
                    'verify_tls' => true,
                ],
            ];
        }
        return $out;
    }

    private static function freshSeries(mixed $series, string $type): ?array
    {
        if (!is_array($series) || ($series['state'] ?? null) !== 'fresh' || !is_array($series['points'] ?? null) ||
            !array_is_list($series['points']) || count($series['points']) > 60 || !is_int($series['collected_at'] ?? null) ||
            $series['collected_at'] < 1) return null;
        $points = [];
        $previous = null;
        foreach ($series['points'] as $point) {
            if (!is_array($point) || !is_int($point['observed_at'] ?? null) || $point['observed_at'] < 1 ||
                ($previous !== null && $point['observed_at'] <= $previous) || !array_key_exists('value', $point)) return null;
            $value = $point['value'];
            if ($value !== null && !is_int($value) && !is_float($value)) return null;
            if (is_float($value) && !is_finite($value)) return null;
            if ($value !== null && ($value < 0 || ($type === 'percent' && $value > 100))) return null;
            $points[] = ['observed_at' => $point['observed_at'], 'value' => $value];
            $previous = $point['observed_at'];
        }
        return ['points' => $points, 'collected_at' => $series['collected_at']];
    }

    private static function aligned(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        foreach ($a as $i => $point) if (($b[$i]['observed_at'] ?? null) !== $point['observed_at']) return false;
        return true;
    }

    /** Infer a renderer hint only from an exactly uniform series; otherwise preserve unknown interval as null. */
    private static function stableInterval(array $points): ?int
    {
        if (count($points) < 2) return null;
        $interval = $points[1]['observed_at'] - $points[0]['observed_at'];
        if ($interval < 1 || $interval > 86400) return null;
        for ($i = 2, $n = count($points); $i < $n; $i++) {
            if ($points[$i]['observed_at'] - $points[$i - 1]['observed_at'] !== $interval) return null;
        }
        return $interval;
    }

    private static function latest(array $points): ?int
    {
        return $points === [] ? null : $points[count($points) - 1]['observed_at'];
    }

    private static function timestamp(int $value): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $value);
    }

    private static function identity(string $kind, string $id): void
    {
        if (!in_array($kind, ['vm', 'host'], true) ||
            !preg_match('/^' . preg_quote($kind, '/') . '-[0-9]{1,20}$/D', $id)) {
            throw new InvalidArgumentException('fast_metric_compat_identity_invalid');
        }
    }
}
