<?php
interface IntegrationVmwareInventoryMetricProvider
{
    public function sample(string $kind, string $id, string $section): array;
}
/** CLI-only scheduling is outside this state machine. No browser can instantiate its provider. */
final class IntegrationVmwareInventorySampler
{
    private IntegrationVmwareInventoryStore $inventory;
    private IntegrationVmwareInventoryTelemetryStore $metrics;
    private IntegrationVmwareInventoryMetricProvider $source;
    private $authorized;
    private $clock;
    public function __construct(IntegrationVmwareInventoryStore $inventory, IntegrationVmwareInventoryTelemetryStore $metrics,
        IntegrationVmwareInventoryMetricProvider $source, callable $authorized, ?callable $clock = null)
    {
        $this->inventory = $inventory; $this->metrics = $metrics; $this->source = $source;
        $this->authorized = $authorized; $this->clock = $clock ?? static fn(): int => time();
    }
    private function guard(): void
    {
        if (($this->authorized)() !== true) throw new RuntimeException('mirror_binding_changed');
    }
    public function tick(int $units = 2, int $seconds = 25, int $interval = 300): array
    {
        if ($units < 1 || $units > 20 || $seconds < 1 || $seconds > 60 || $interval < 60 || $interval > 3600) {
            throw new InvalidArgumentException('mirror_metric_limits');
        }
        $this->guard();
        // Same connector lock as inventory collection: never overlap upstream work or denial publication.
        return $this->inventory->exclusive(function () use ($units, $seconds, $interval): array {
            $this->guard(); $current = $this->inventory->read('current') ?? [];
            if (!empty($current['blocked'])) return ['state' => 'blocked', 'work_done' => 0];
            if (!is_array($current['inventory'] ?? null)) return ['state' => 'waiting_inventory', 'work_done' => 0];
            $ids = []; $jobs = [];
            foreach (['vm', 'host'] as $kind) {
                $set = array_keys($current['inventory']['objects'][$kind] ?? []); sort($set, SORT_STRING);
                if (count($set) > 1000) throw new RuntimeException('mirror_metric_size_limit');
                foreach ($set as $id) {
                    if (!preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id)) throw new RuntimeException('mirror_metric_id_invalid');
                    $ids[] = $id;
                    foreach (['performance', 'storage_performance'] as $section) $jobs[] = [$kind, $id, $section];
                }
            }
            $epoch = $current['access_epoch'] ?? 'initial';
            return $this->metrics->exclusive(function () use ($units, $seconds, $interval, $current, $ids, $jobs, $epoch): array {
                $this->guard(); $state = $this->metrics->state(); $now = ($this->clock)();
                $roster = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR) . ':' . $epoch);
                if (($state['roster'] ?? '') !== $roster) {
                    $this->metrics->prune($ids);
                    $valid = [];
                    foreach ($jobs as [, $id, $section]) $valid[$id . ':' . $section] = true;
                    $sameEpoch = ($state['epoch'] ?? '') === $epoch;
                    $state = ['roster' => $roster, 'epoch' => $epoch,
                        'cursor' => $sameEpoch && $jobs !== [] ? (int)($state['cursor'] ?? 0) % count($jobs) : 0,
                        'due' => $sameEpoch ? array_intersect_key($state['due'] ?? [], $valid) : [],
                        'completed' => 0, 'total' => count($jobs),
                        'cycle_started_at' => $now, 'last_error' => null, 'state' => 'sampling'];
                }
                $deadline = hrtime(true) + $seconds * 1000000000; $work = 0; $visited = 0;
                while ($jobs !== [] && $visited < count($jobs) && $work < $units && hrtime(true) < $deadline) {
                    $cursor = (int)($state['cursor'] ?? 0) % count($jobs); [$kind, $id, $section] = $jobs[$cursor];
                    $visited++; $jobKey = $id . ':' . $section; $collected = false;
                    // Each entity/section owns its due time, so one unsupported VM cannot stall other graphs.
                    if (($state['due'][$jobKey] ?? 0) <= $now) {
                        $parts = $this->metrics->read($id, $epoch); $old = $parts[$section] ?? [];
                        $this->guard(); $work++; $collected = true;
                        try {
                            $data = IntegrationVmwareInventoryWorker::safePayload($this->source->sample($kind, $id, $section));
                            $this->guard();
                            if (($data['entity_type'] ?? '') !== $kind || ($data['entity_external_id'] ?? '') !== $id ||
                                ($data['verify_tls'] ?? null) !== true || !is_array($data['samples'] ?? null) ||
                                count($data['samples']) > 60 || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 120000) {
                                throw new RuntimeException('mirror_metric_payload_invalid');
                            }
                            $sampleTimes = [];
                            foreach ($data['samples'] as $point) {
                                $stamp = $point['timestamp'] ?? null;
                                if (!is_string($stamp) || strlen($stamp) > 40 ||
                                    !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $stamp) ||
                                    ($time = strtotime($stamp)) === false || $time > ($this->clock)() + 60) throw new RuntimeException('mirror_metric_timestamp_invalid');
                                $sampleTimes[] = $time;
                            }
                            // Reading an old vCenter series now must not make its measurements appear fresh.
                            $observed = $sampleTimes === [] ? ($this->clock)() : max($sampleTimes);
                            $parts[$section] = ['state' => 'ok', 'observed_at' => $observed, 'collected_at' => ($this->clock)(), 'attempted_at' => $now,
                                'data' => $data, 'failures' => 0, 'retry_at' => 0];
                            $state['last_success_at'] = ($this->clock)(); $state['last_error'] = null;
                        } catch (Throwable $error) {
                            if (IntegrationVmwareInventoryWorker::denied($error) || $error->getMessage() === 'mirror_binding_changed') {
                                // A swallowed Logout denial must also invalidate every locally served old sample.
                                $current['blocked'] = true; $current['access_epoch'] = bin2hex(random_bytes(16));
                                $current['state'] = 'error'; $current['last_error'] = 'access_denied';
                                $current['retry_at'] = 0; $current['heartbeat_at'] = ($this->clock)();
                                $this->inventory->write('current', $current); $this->inventory->discardPending();
                                $state['state'] = 'blocked'; $state['last_error'] = 'access_denied';
                                $this->metrics->saveState($state);
                                return ['state' => 'error', 'error' => 'access_denied', 'work_done' => $work];
                            }
                            if ($error->getMessage() === 'mirror_process_deadline') throw $error;
                            $category = preg_match('/certificate|ssl|tls/i', $error->getMessage()) ? 'tls' : 'sample_unavailable';
                            $failures = min(10, (int)($old['failures'] ?? 0) + 1);
                            $parts[$section] = ['state' => isset($old['data']) ? 'stale' : 'unavailable',
                                'observed_at' => $old['observed_at'] ?? null, 'data' => $old['data'] ?? null,
                                'attempted_at' => $now, 'error' => $category, 'failures' => $failures,
                                'retry_at' => $now + min(900, 30 * (2 ** min(5, $failures - 1)))];
                            $state['last_error'] = $category;
                        }
                        $this->guard(); $this->metrics->write($id, $epoch, $parts);
                        $state['due'][$jobKey] = max($now + $interval, (int)($parts[$section]['retry_at'] ?? 0));
                    }
                    $state['cursor'] = ($cursor + 1) % count($jobs);
                    $state['completed'] = $cursor + 1;
                    if ($state['cursor'] === 0) { $state['cycle_completed_at'] = ($this->clock)(); $state['cycle_started_at'] = ($this->clock)(); }
                    $state['state'] = 'sampling'; $state['heartbeat_at'] = ($this->clock)();
                    // Cursor checkpoint follows a complete shard update; interruption repeats at most one unit.
                    if ($collected) $this->metrics->saveState($state);
                }
                $state['state'] = $work === 0 ? 'idle' : 'sampling'; $state['heartbeat_at'] = ($this->clock)();
                $this->metrics->saveState($state);
                return ['work_done' => $work] + IntegrationVmwareInventoryTelemetryStore::summary($state);
            });
        });
    }
}
