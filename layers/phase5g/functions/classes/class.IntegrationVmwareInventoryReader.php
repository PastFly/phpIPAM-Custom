<?php
/** Local read model: no provider argument, network call, writes, TTL refresh or prefetch. */
final class IntegrationVmwareInventoryReader
{
    private IntegrationVmwareInventoryStore $store;
    private $authorized;
    private $clock;
    private ?IntegrationVmwareInventoryTelemetryStore $telemetry;
    public function __construct(IntegrationVmwareInventoryStore $store, callable $authorized, ?callable $clock = null, ?IntegrationVmwareInventoryTelemetryStore $telemetry = null)
    {
        $this->telemetry = $telemetry;
        $this->store = $store; $this->authorized = $authorized; $this->clock = $clock ?? static fn(): int => time();
    }
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        if (($this->authorized)() !== true) throw new RuntimeException('mirror_access_denied');
        if (!in_array($view, ['status', 'core', 'host_folders', 'object_folders', 'search', 'vm', 'host', 'datastore', 'vms', 'hosts', 'datastores'], true) ||
            $page < 1 || $page > 100000 || $pageSize < 1 || $pageSize > 100 || strlen($search) > 256 ||
            preg_match('/[\x00-\x1f\x7f]/', $search)) throw new InvalidArgumentException('mirror_invalid_query');
        if (in_array($view, ['vm', 'host', 'datastore'], true) && !preg_match('/^' . $view . '-[0-9]{1,20}$/D', $id)) {
            throw new InvalidArgumentException('mirror_invalid_id');
        }
        $current = $this->store->read('current');
        // Permission/binding can change while local I/O runs. Recheck without contacting vCenter.
        if (($this->authorized)() !== true || !empty($current['blocked'])) throw new RuntimeException('mirror_access_denied');
        $inventory = $current['inventory'] ?? null; $now = ($this->clock)();
        $out = ['ok' => true, 'state' => $current['state'] ?? 'initializing',
            'has_inventory' => is_array($inventory), 'last_error' => $current['last_error'] ?? null,
            'progress' => $current['progress'] ?? ['completed' => 0, 'total' => null],
            'generation' => $current['generation'] ?? null, 'published_at' => $current['published_at'] ?? null,
            'collection_started_at' => $current['started_at'] ?? null,
            'age_seconds' => isset($current['started_at']) ? max(0, $now - $current['started_at']) : null,
            'retry_at' => $current['retry_at'] ?? null, 'vcenter_requests' => 0, 'inventory_files_written' => false,
            'data' => null];
        // Status and initial-empty views carry the same age/error/heartbeat semantics as cards.
        $out['clock_skew'] = isset($current['started_at']) && $current['started_at'] > $now + 60;
        $out['stale'] = $out['clock_skew'] || $out['age_seconds'] === null || $out['age_seconds'] > 300 || $out['last_error'] !== null;
        $out['heartbeat_at'] = $current['heartbeat_at'] ?? null;
        $out['worker_silent'] = !isset($current['heartbeat_at']) || $now - $current['heartbeat_at'] > 300;
        $out['telemetry'] = $this->telemetry === null ? ['state' => 'not_provisioned'] :
            IntegrationVmwareInventoryTelemetryStore::summary($this->telemetry->state());
        if ($view === 'status' || !is_array($inventory)) {
            if (($this->authorized)() !== true) throw new RuntimeException('mirror_access_denied');
            return $out;
        }
        if ($view === 'core') $out['data'] = $inventory['core'];
        elseif (in_array($view, ['host_folders', 'object_folders', 'search'], true)) {
            $out['data'] = $inventory[$view]['data']; $out['collected_at'] = $inventory[$view]['collected_at'];
        } elseif (in_array($view, ['vm', 'host', 'datastore'], true)) {
            if (!isset($inventory['objects'][$view][$id])) throw new RuntimeException('mirror_object_not_found');
            $out['data'] = $inventory['objects'][$view][$id]['data'];
            $out['collected_at'] = $inventory['objects'][$view][$id]['collected_at'];
            if ($this->telemetry !== null && in_array($view, ['vm', 'host'], true)) {
                foreach ($this->telemetry->read($id, $current['access_epoch'] ?? 'initial') as $name => $section) {
                    if (!in_array($name, ['performance', 'storage_performance'], true)) continue;
                    if (($section['state'] ?? '') === 'ok' && (!isset($section['observed_at']) ||
                        $section['observed_at'] > $now + 60 || $now - $section['observed_at'] > 300)) $section['state'] = 'stale';
                    $out['data']['sections'][$name] = $section;
                }
            }
            if ($view === 'vm') {
                foreach ($inventory['search']['data']['rows'] ?? [] as $row) {
                    if (($row['vm_external_id'] ?? '') !== $id) continue;
                    $out['metadata'] = ['collected_at' => $inventory['search']['collected_at'],
                        'data' => array_intersect_key($row, array_flip(['tags', 'friendly_guest_os', 'guest_hostname',
                            'mac_addresses', 'ip_addresses', 'network_names']))];
                    break;
                }
            }
        } else {
            $kind = ['vms' => 'vm', 'hosts' => 'host', 'datastores' => 'datastore'][$view];
            $lower = static fn(string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
            $needle = $lower(trim($search)); $rows = [];
            foreach ($inventory['objects'][$kind] as $objectId => $record) {
                // List response excludes full card payloads; they are fetched locally by stable id.
                $name = (string)($record['data']['name'] ?? $objectId);
                if ($needle !== '' && !str_contains($lower($name . ' ' . $objectId), $needle)) continue;
                $rows[] = ['id' => (string)$objectId, 'name' => $name, 'collected_at' => $record['collected_at']];
            }
            usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
            $out['total'] = count($rows); $out['page'] = $page; $out['page_size'] = $pageSize;
            $out['data'] = array_slice($rows, ($page - 1) * $pageSize, $pageSize);
        }
        if (($this->authorized)() !== true) throw new RuntimeException('mirror_access_denied');
        return $out;
    }
}
