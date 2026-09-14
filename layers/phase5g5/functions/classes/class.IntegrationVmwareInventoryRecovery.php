<?php
/** Bootstrap policy and bounded enrichment repair. No HTTP route imports this class. */
final class IntegrationVmwareInventoryRecovery
{
    public static function usable(?array $current, int $now): bool
    {
        return is_array($current['inventory'] ?? null) && empty($current['blocked']) &&
            is_int($current['started_at'] ?? null) && $current['started_at'] <= $now + 60 &&
            $now - $current['started_at'] <= 14400 && is_int($current['published_at'] ?? null);
    }
    public static function metricReady($part, string $kind, string $id, int $now): bool
    {
        return is_array($part) && ($part['state'] ?? '') === 'ok' &&
            is_int($part['collected_at'] ?? null) && $part['collected_at'] <= $now + 60 &&
            $now - $part['collected_at'] <= 14400 &&
            ($part['data']['entity_type'] ?? '') === $kind &&
            ($part['data']['entity_external_id'] ?? '') === $id &&
            ($part['data']['verify_tls'] ?? null) === true && is_array($part['data']['samples'] ?? null);
    }
    /** Stable order by missing/failed/old, then oldest actual attempt; never by guest name. */
    public static function order(array $jobs, callable $read, int $now): array
    {
        $ranked = []; $cache = [];
        foreach ($jobs as $index => $job) {
            [$kind, $id, $section] = $job;
            if (!isset($cache[$id])) $cache[$id] = $read($id);
            $part = $cache[$id][$section] ?? null;
            $rank = $part === null ? 0 : (self::metricReady($part, $kind, $id, $now) ? 3 : 1);
            $ranked[] = [$rank, (int)($part['attempted_at'] ?? 0), $index, $job];
        }
        usort($ranked, static fn(array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]));
        return array_column($ranked, 3);
    }
    public static function inspect(?array $current, callable $read, int $now): array
    {
        if (!empty($current['blocked'])) return ['ready' => false, 'state' => 'access_denied'];
        // Expiration requires genuine collection; never reset timestamps or weaken the four-hour guard.
        if (is_array($current['inventory'] ?? null) && !self::usable($current, $now)) {
            return ['ready' => false, 'state' => 'inventory_refresh_required',
                'refresh_progress' => $current['progress'] ?? null, 'published_at' => $current['published_at'] ?? null];
        }
        $result = IntegrationVmwareInventoryReadiness::inspect($current, $read, $now);
        $result['refresh_progress'] = $result['progress'];
        $result['publication_complete'] = is_array($current['inventory'] ?? null);
        if ($result['publication_complete']) {
            $total = array_sum($result['inventory_counts']) + 3;
            $result['progress'] = ['completed' => $total, 'total' => $total];
        }
        $missing = 0; $attempted = 0;
        foreach ($current['inventory']['objects']['vm'] ?? [] as $record) {
            $part = $record['data']['sections']['guest_network'] ?? null;
            if (!is_array($part) || ($part['state'] ?? '') !== 'ok' || !is_array($part['data'] ?? null)) {
                ++$missing;
                if (($part['recovery_attempts'] ?? 0) > 0) ++$attempted;
            }
        }
        $result['guest_sections_pending'] = $missing;
        $result['guest_sections_attempted'] = $attempted;
        if ($missing !== 0 && $result['ready']) { $result['ready'] = false; $result['state'] = 'repairing_guest_network'; }
        return $result;
    }
    /** Copy-on-write one completed guest section at a time; preserve publication dates and the access epoch. */
    public static function repairGuests(IntegrationVmwareInventoryStore $store, callable $readGuest,
        callable $authorized, int $limit = 2, ?callable $clock = null): array
    {
        if ($limit < 1 || $limit > 5) throw new InvalidArgumentException('repair_limits');
        $clock = $clock ?? static fn(): int => time();
        $guard = static function () use ($authorized): void {
            if ($authorized() !== true) throw new RuntimeException('mirror_binding_changed');
        };
        $guard();
        return $store->exclusive(static function () use ($store, $readGuest, $guard, $limit, $clock): array {
            $guard(); $current = $store->read('current'); $now = $clock();
            if (!self::usable($current, $now)) return ['state' => 'waiting_inventory', 'work_done' => 0];
            $work = 0; $deadline = hrtime(true) + 8000000000;
            foreach ($current['inventory']['objects']['vm'] as $id => &$record) {
                $part = $record['data']['sections']['guest_network'] ?? [];
                if (($part['state'] ?? '') === 'ok' && is_array($part['data'] ?? null)) continue;
                if (($part['retry_at'] ?? 0) > $now) continue;
                if ($work >= $limit || hrtime(true) >= $deadline) break;
                ++$work; $attempts = min(10, (int)($part['recovery_attempts'] ?? 0) + 1);
                try {
                    $guard(); $data = IntegrationVmwareInventoryWorker::safePayload($readGuest($id)); $guard();
                    if (($data['ok'] ?? null) !== true || ($data['verify_tls'] ?? null) !== true ||
                        ($data['vm_count'] ?? null) !== 1 || ($data['vms'][0]['vm_external_id'] ?? '') !== $id) {
                        throw new RuntimeException('guest_repair_invalid');
                    }
                    $record['data']['sections']['guest_network'] = ['state' => 'ok', 'data' => $data,
                        'observed_at' => $clock(), 'recovery_attempts' => $attempts, 'retry_at' => 0];
                } catch (Throwable $error) {
                    if ($error->getMessage() === 'mirror_process_deadline') throw $error;
                    if (IntegrationVmwareInventoryWorker::denied($error) || $error->getMessage() === 'mirror_binding_changed') {
                        $current['blocked'] = true; $current['access_epoch'] = bin2hex(random_bytes(16));
                        $current['state'] = 'error'; $current['last_error'] = 'access_denied';
                        $store->write('current', $current); $store->discardPending();
                        return ['state' => 'error', 'error' => 'access_denied', 'work_done' => $work];
                    }
                    // Real parse/transport failures remain failures and cannot satisfy readiness.
                    $record['data']['sections']['guest_network'] = array_replace($part, [
                        'state' => isset($part['data']) ? 'stale' : 'unavailable',
                        'error' => 'optional_read_failed', 'recovery_attempts' => $attempts, 'retry_at' => $clock() + 300]);
                }
                $guard(); $current['heartbeat_at'] = $clock();
                $store->write('current', $current);
            }
            unset($record);
            return ['state' => 'repaired_batch', 'work_done' => $work];
        });
    }
}
