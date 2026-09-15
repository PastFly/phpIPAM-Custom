<?php
/** Pure capability-cache policy. Persistence/binding isolation is supplied by IntegrationVmwareFastStore. */
final class IntegrationVmwareCapabilityCache
{
    public static function empty(int $now): array
    {
        if ($now < 1) throw new InvalidArgumentException('capability_time_invalid');
        return ['schema' => 1, 'domain' => 'capabilities', 'published_at' => $now, 'entries' => []];
    }

    public static function put(?array $state, string $kind, string $id, array $capability, int $now, int $ttl = 900): array
    {
        self::identity($kind, $id);
        if ($ttl < 60 || $ttl > 3600 || $now < 1) throw new InvalidArgumentException('capability_limits_invalid');
        $state = self::state($state, $now);
        $currentSupported = $capability['current_supported'] ?? null;
        $refreshRate = $capability['refresh_rate'] ?? null;
        if (!is_bool($currentSupported) || ($currentSupported && (!is_int($refreshRate) || $refreshRate < 1 || $refreshRate > 300)) ||
            (!$currentSupported && $refreshRate !== null && (!is_int($refreshRate) || $refreshRate < 1 || $refreshRate > 300))) {
            throw new RuntimeException('capability_payload_invalid');
        }
        $counters = $capability['counters'] ?? [];
        if (!is_array($counters) || count($counters) > 16) throw new RuntimeException('capability_payload_invalid');
        $normalized = [];
        foreach ($counters as $name => $status) {
            if (!in_array($name, ['cpu.usage.average', 'mem.usage.average', 'disk.usage.average'], true) ||
                !in_array($status, ['available', 'aggregate_unavailable', 'counter_unavailable'], true)) {
                throw new RuntimeException('capability_payload_invalid');
            }
            $normalized[$name] = $status;
        }
        ksort($normalized);
        $key = $kind . ':' . $id;
        $state['entries'][$key] = ['kind' => $kind, 'id' => $id, 'current_supported' => $currentSupported,
            'refresh_rate' => $refreshRate, 'counters' => $normalized, 'validated_at' => $now, 'expires_at' => $now + $ttl];
        $state['published_at'] = $now;
        return $state;
    }

    public static function get(?array $state, string $kind, string $id, int $now): ?array
    {
        self::identity($kind, $id);
        if ($state === null) return null;
        $state = self::state($state, $now);
        $entry = $state['entries'][$kind . ':' . $id] ?? null;
        if (!is_array($entry)) return null;
        if (!is_int($entry['validated_at'] ?? null) || !is_int($entry['expires_at'] ?? null) ||
            $entry['validated_at'] < 1 || $entry['expires_at'] < $entry['validated_at'] || $entry['expires_at'] < $now) return null;
        return $entry;
    }

    public static function prune(?array $state, array $membership, int $now): array
    {
        $state = self::state($state, $now);
        $keep = [];
        foreach (['vm','host'] as $kind) {
            $ids = $membership[$kind] ?? null;
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 2000) throw new InvalidArgumentException('capability_membership_invalid');
            foreach ($ids as $id) { self::identity($kind, $id); $keep[$kind . ':' . $id] = true; }
        }
        foreach (array_keys($state['entries']) as $key) if (!isset($keep[$key])) unset($state['entries'][$key]);
        $state['published_at'] = $now;
        return $state;
    }

    public static function due(?array $state, array $entities, int $now): array
    {
        if (!array_is_list($entities) || count($entities) > 2000) throw new InvalidArgumentException('capability_entities_invalid');
        $out = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) throw new InvalidArgumentException('capability_entities_invalid');
            $kind = $entity['kind'] ?? null; $id = $entity['id'] ?? null;
            if (!is_string($kind) || !is_string($id)) throw new InvalidArgumentException('capability_entities_invalid');
            if (self::get($state, $kind, $id, $now) === null) $out[] = ['kind' => $kind, 'id' => $id];
        }
        return $out;
    }

    private static function state(?array $state, int $now): array
    {
        if ($state === null) return self::empty($now);
        if (($state['schema'] ?? null) !== 1 || ($state['domain'] ?? null) !== 'capabilities' || !is_array($state['entries'] ?? null)) {
            throw new RuntimeException('capability_state_invalid');
        }
        foreach ($state['entries'] as $key => $entry) {
            if (!is_string($key) || !is_array($entry) || !is_string($entry['kind'] ?? null) || !is_string($entry['id'] ?? null)) {
                throw new RuntimeException('capability_state_invalid');
            }
            self::identity($entry['kind'], $entry['id']);
            if ($key !== $entry['kind'] . ':' . $entry['id']) throw new RuntimeException('capability_state_invalid');
        }
        return $state;
    }

    private static function identity(string $kind, string $id): void
    {
        if (!in_array($kind, ['vm','host'], true) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id)) {
            throw new InvalidArgumentException('capability_identity_invalid');
        }
    }
}
