<?php
/** Pure two-pass membership reconciliation. Partial discovery never authorizes deletion. */
final class IntegrationVmwareRosterReconciler
{
    public static function observe(?array $state, array $candidate, bool $complete, int $now): array
    {
        if ($now < 1) throw new InvalidArgumentException('roster_time_invalid');
        $state = self::state($state);
        if (!$complete) {
            $state['last_attempt_at'] = $now;
            $state['last_attempt_complete'] = false;
            $state['pending'] = null;
            return $state;
        }
        $normalized = self::normalize($candidate);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        $pending = $state['pending'];
        if (is_array($pending) && ($pending['hash'] ?? '') === $hash) {
            $pending['confirmations']++;
            $pending['last_seen_at'] = $now;
        } else {
            $pending = ['hash' => $hash, 'roster' => $normalized, 'confirmations' => 1,
                'first_seen_at' => $now, 'last_seen_at' => $now];
        }
        if ($pending['confirmations'] >= 2) {
            $state['authoritative'] = ['hash' => $hash, 'roster' => $normalized, 'confirmed_at' => $now];
            $state['pending'] = null;
        } else {
            $state['pending'] = $pending;
        }
        $state['last_attempt_at'] = $now;
        $state['last_attempt_complete'] = true;
        return $state;
    }

    public static function membership(array $state, string $kind, string $id): string
    {
        $state = self::state($state);
        if (!in_array($kind, ['vm', 'host', 'datastore'], true) ||
            !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id)) throw new InvalidArgumentException('roster_identity_invalid');
        $authoritative = $state['authoritative'];
        if (!is_array($authoritative)) return 'unknown';
        return in_array($id, $authoritative['roster'][$kind], true) ? 'present' : 'absent_confirmed';
    }

    /** Mark absent objects as tombstones; never physically erase fast-domain history here. */
    public static function mark(array $fastDomain, array $state, int $now): array
    {
        $state = self::state($state);
        if (($fastDomain['schema'] ?? null) !== 1 || !is_array($fastDomain['objects'] ?? null)) {
            throw new RuntimeException('roster_fast_domain_invalid');
        }
        if (!is_array($state['authoritative'])) return $fastDomain;
        foreach ($fastDomain['objects'] as $id => &$record) {
            $kind = $record['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, ['vm', 'host', 'datastore'], true) ||
                !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', (string)$id)) throw new RuntimeException('roster_fast_record_invalid');
            if (!in_array($id, $state['authoritative']['roster'][$kind], true)) {
                $record['membership_state'] = 'absent_confirmed';
                $record['membership_confirmed_at'] = $state['authoritative']['confirmed_at'];
            } else {
                $record['membership_state'] = 'present';
                $record['membership_confirmed_at'] = $state['authoritative']['confirmed_at'];
            }
        }
        unset($record);
        $fastDomain['membership_overlay_at'] = $now;
        return $fastDomain;
    }

    private static function normalize(array $candidate): array
    {
        $out = [];
        foreach (['vm', 'host', 'datastore'] as $kind) {
            $set = $candidate[$kind] ?? null;
            if (!is_array($set) || !array_is_list($set) || count($set) > 2000) throw new InvalidArgumentException('roster_candidate_invalid');
            $seen = [];
            foreach ($set as $id) {
                if (!is_string($id) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id) || isset($seen[$id])) {
                    throw new InvalidArgumentException('roster_candidate_invalid');
                }
                $seen[$id] = true;
            }
            $out[$kind] = array_keys($seen);
            sort($out[$kind], SORT_STRING);
        }
        return $out;
    }

    private static function state(?array $state): array
    {
        if ($state === null) return ['schema' => 1, 'authoritative' => null, 'pending' => null,
            'last_attempt_at' => null, 'last_attempt_complete' => false];
        if (($state['schema'] ?? null) !== 1 || !array_key_exists('authoritative', $state) || !array_key_exists('pending', $state)) {
            throw new RuntimeException('roster_state_invalid');
        }
        if (is_array($state['authoritative'])) self::normalize($state['authoritative']['roster'] ?? []);
        if (is_array($state['pending'])) self::normalize($state['pending']['roster'] ?? []);
        return $state;
    }
}
