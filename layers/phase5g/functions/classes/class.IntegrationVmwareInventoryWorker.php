<?php
/** Resume collection without publishing incomplete inventory or doing any web work. */
interface IntegrationVmwareInventoryProvider
{
    public function discover(): array;
    public function collect(string $kind, string $id, array $seed): array;
}
final class IntegrationVmwareInventoryWorker
{
    private IntegrationVmwareInventoryStore $store;
    private IntegrationVmwareInventoryProvider $provider;
    private $authorized;
    private $clock;
    public function __construct(IntegrationVmwareInventoryStore $store, IntegrationVmwareInventoryProvider $provider,
        callable $authorized, ?callable $clock = null)
    {
        $this->store = $store; $this->provider = $provider; $this->authorized = $authorized;
        $this->clock = $clock ?? static fn(): int => time();
    }
    private function guard(): void
    {
        if (($this->authorized)() !== true) throw new RuntimeException('mirror_binding_changed');
    }
    public static function denied(Throwable $e): bool
    {
        return preg_match('/(?:http[ _]*(?:401|403)|notauthenticated|nopermission|forbidden|authentication|authorization|access_denied)/i', $e->getMessage()) === 1;
    }
    private static function category(Throwable $e): string
    {
        if (self::denied($e)) return 'access_denied';
        foreach (['binding_changed', 'incomplete', 'membership_changed', 'size_limit', 'space_low', 'generation_expired'] as $v) {
            if (str_contains($e->getMessage(), $v)) return $v;
        }
        if (preg_match('/certificate|ssl|tls/i', $e->getMessage())) return 'tls';
        if (preg_match('/timeout|timed out|deadline/i', $e->getMessage())) return 'timeout';
        return 'collection_failed'; // Never persist or emit upstream error text.
    }
    private static function validateIds(array $seed): array
    {
        if (($seed['ok'] ?? null) !== true || !empty($seed['partial']) || !is_array($seed['ids'] ?? null)) {
            throw new RuntimeException('mirror_discovery_incomplete');
        }
        $out = [];
        foreach (['vm' => 1000, 'host' => 1000, 'datastore' => 2000] as $kind => $max) {
            $ids = $seed['ids'][$kind] ?? null;
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > $max) throw new RuntimeException('mirror_discovery_incomplete');
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id)) throw new RuntimeException('mirror_discovery_incomplete');
            }
            if (count(array_unique($ids)) !== count($ids)) throw new RuntimeException('mirror_discovery_incomplete');
            sort($ids, SORT_STRING); $out[$kind] = $ids;
        }
        return $out;
    }
    /** Serializable public values only. Credentials cannot become snapshot fields. */
    public static function safePayload(array $value): array
    {
        $walk = static function ($v, int $depth = 0) use (&$walk) {
            if ($depth > 48 || is_object($v) || is_resource($v)) throw new RuntimeException('mirror_payload_invalid');
            if (is_string($v) && strlen($v) > 262144) throw new RuntimeException('mirror_size_limit');
            if (!is_array($v)) return $v;
            $out = [];
            foreach ($v as $k => $child) {
                if (is_string($k) && preg_match('/^(endpoint|username|password|passwd|secret_envelope|secret_key_id|credentials?|headers?|cookies?|session(_id|_token)?|authorization|access_token|refresh_token|api_key|secret|private_key)$/iD', $k)) continue;
                $out[$k] = $walk($child, $depth + 1);
            }
            return $out;
        };
        return $walk($value);
    }
    public function tick(int $maxUnits = 10, int $seconds = 45, int $refreshSeconds = 300): array
    {
        if ($maxUnits < 1 || $maxUnits > 100 || $seconds < 1 || $seconds > 300 || $refreshSeconds < 30 || $refreshSeconds > 86400) {
            throw new InvalidArgumentException('mirror_worker_limits');
        }
        $this->guard();
        return $this->store->exclusive(function () use ($maxUnits, $seconds, $refreshSeconds): array {
            $this->guard(); $now = ($this->clock)(); $current = $this->store->read('current') ?? [];
            if (($current['retry_at'] ?? 0) > $now) return ['state' => 'backoff', 'retry_at' => $current['retry_at'], 'work_done' => 0];
            $pending = $this->store->read('pending');
            if ($pending === null && !empty($current['inventory']) && empty($current['blocked']) &&
                ($current['published_at'] ?? 0) + $refreshSeconds > $now) return ['state' => 'idle', 'work_done' => 0];
            $work = 0; $deadline = hrtime(true) + $seconds * 1000000000;
            try {
                if ($pending !== null && (!is_int($pending['started_at'] ?? null) ||
                    $now - $pending['started_at'] > 14400 || $pending['started_at'] > $now + 60)) {
                    $this->store->discardPending(); throw new RuntimeException('mirror_generation_expired');
                }
                if ($pending === null) {
                    $this->guard(); $seed = self::safePayload($this->provider->discover());
                    $ids = self::validateIds($seed); $seed['ids'] = $ids;
                    $jobs = [['domain', 'host_folders'], ['domain', 'object_folders'], ['domain', 'search']];
                    foreach ($ids as $kind => $set) foreach ($set as $id) $jobs[] = [$kind, $id];
                    $pending = ['generation' => bin2hex(random_bytes(16)), 'started_at' => $now, 'seed' => $seed,
                        'jobs' => $jobs, 'cursor' => 0, 'inventory' => ['core' => $seed['core'], 'objects' => ['vm' => [], 'host' => [], 'datastore' => []]]];
                    $this->store->write('pending', $pending); $work++;
                }
                $total = count($pending['jobs']);
                while ($pending['cursor'] < $total && $work < $maxUnits && hrtime(true) < $deadline) {
                    $this->guard(); [$kind, $id] = $pending['jobs'][$pending['cursor']];
                    $data = self::safePayload($this->provider->collect($kind, $id, $pending['seed']));
                    if (($data['ok'] ?? null) !== true || !empty($data['partial'])) throw new RuntimeException('mirror_unit_incomplete');
                    $this->guard();
                    // Preserve dated last-good optional sections on transient enrichment failure.
                    // A denial never reaches here: it aborts the entire attempt and blocks reads.
                    if ($kind !== 'domain' && isset($data['sections'])) {
                        foreach ($data['sections'] as $section => &$part) {
                            $old = $current['inventory']['objects'][$kind][$id]['data']['sections'][$section] ?? null;
                            if (($part['state'] ?? '') === 'unavailable' && is_array($old) &&
                                in_array($old['state'] ?? '', ['ok', 'stale'], true) && isset($old['observed_at'])) {
                                $part = $old; $part['state'] = 'stale'; $part['error'] = 'optional_read_failed';
                            }
                        }
                        unset($part);
                    }
                    $record = ['collected_at' => ($this->clock)(), 'data' => $data];
                    if ($kind === 'domain') $pending['inventory'][$id] = $record;
                    else $pending['inventory']['objects'][$kind][$id] = $record;
                    $pending['cursor']++; $work++;
                    // Checkpoint after each complete unit; a restart repeats at most one unit.
                    $this->store->write('pending', $pending);
                }
                if ($pending['cursor'] === $total && $work < $maxUnits && hrtime(true) < $deadline) {
                    $this->guard(); $end = self::safePayload($this->provider->discover()); $work++;
                    if (self::validateIds($end) !== $pending['seed']['ids']) {
                        $this->store->discardPending(); throw new RuntimeException('mirror_membership_changed');
                    }
                    // Publication is atomic locally, not an instantaneous snapshot of all vCenter properties.
                    // Keep the initial core; every record exposes its own collection timestamp.
                    $this->guard();
                    $current = ['state' => 'ready', 'blocked' => false, 'last_error' => null, 'failures' => 0, 'retry_at' => 0,
                        'generation' => $pending['generation'], 'started_at' => $pending['started_at'], 'published_at' => ($this->clock)(),
                        'progress' => ['completed' => $total, 'total' => $total], 'inventory' => $pending['inventory']];
                    $this->store->write('current', $current); $this->store->discardPending();
                    return ['state' => 'ready', 'work_done' => $work, 'generation' => $current['generation'], 'published_at' => $current['published_at']];
                }
                $current['state'] = 'collecting';
                $current['progress'] = ['completed' => $pending['cursor'], 'total' => $total];
                $current['attempt_started_at'] = $pending['started_at'];
                $current['heartbeat_at'] = ($this->clock)();
                // In particular, do NOT clear a previous known denial while a new attempt is incomplete.
                $this->store->write('current', $current);
                return ['state' => 'collecting', 'work_done' => $work, 'progress' => $current['progress']];
            } catch (Throwable $error) {
                $category = self::category($error); $current['last_error'] = $category;
                $current['failures'] = min(10, (int)($current['failures'] ?? 0) + 1);
                $current['retry_at'] = ($this->clock)() + min(900, 30 * (2 ** min(5, $current['failures'] - 1)));
                $current['state'] = 'error'; $current['heartbeat_at'] = ($this->clock)();
                if ($category === 'membership_changed') $this->store->discardPending();
                if (self::denied($error) || $category === 'binding_changed') {
                    $current['blocked'] = true; $this->store->discardPending();
                }
                $this->store->write('current', $current);
                return ['state' => 'error', 'error' => $category, 'retry_at' => $current['retry_at'], 'work_done' => $work];
            }
        });
    }
}
