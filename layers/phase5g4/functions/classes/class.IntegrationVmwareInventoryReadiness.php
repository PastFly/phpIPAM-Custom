<?php
/** Deployment-only, pure readiness validation. No credentials, network, files or timers. */
final class IntegrationVmwareInventoryReadiness
{
    public static function inspect(?array $current, callable $metricRead, int $now): array
    {
        $out = ['ready' => false, 'state' => 'initializing', 'generation' => null,
            'inventory_counts' => ['vm' => 0, 'host' => 0, 'datastore' => 0],
            'progress' => ['completed' => 0, 'total' => null], 'metric_sections' => 0,
            'metric_expected' => 0, 'metric_unavailable' => 0, 'optional_unavailable' => 0,
            'published_at' => null, 'oldest_collection_at' => null];
        if ($current === null) return $out;
        if (!empty($current['blocked'])) { $out['state'] = 'access_denied'; return $out; }
        foreach (['completed', 'total'] as $key) {
            $value = $current['progress'][$key] ?? null;
            if (is_int($value) && $value >= 0 && $value <= 10000) $out['progress'][$key] = $value;
        }
        $inventory = $current['inventory'] ?? null;
        if (!is_array($inventory)) {
            $out['state'] = ($current['state'] ?? '') === 'error' ? 'collection_error' : 'initializing';
            return $out;
        }
        // Completeness is not the timestamp of a successful TCP/TLS connection test.
        if (!is_string($current['generation'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $current['generation']) ||
            !is_int($current['started_at'] ?? null) || !is_int($current['published_at'] ?? null) ||
            $current['published_at'] < $current['started_at'] || $current['published_at'] > $now + 60 ||
            $current['started_at'] > $now + 60 || $now - $current['started_at'] > 14400 ||
            ($inventory['core']['ok'] ?? null) !== true) throw new RuntimeException('readiness_invalid_generation');
        $out['generation'] = $current['generation']; $out['published_at'] = $current['published_at'];
        $out['oldest_collection_at'] = $current['started_at'];
        $sets = [];
        foreach (['vm' => 1000, 'host' => 1000, 'datastore' => 2000] as $kind => $max) {
            $records = $inventory['objects'][$kind] ?? null;
            if (!is_array($records) || count($records) > $max) throw new RuntimeException('readiness_invalid_objects');
            $out['inventory_counts'][$kind] = count($records); $sets[$kind] = array_keys($records);
            sort($sets[$kind], SORT_STRING);
            foreach ($records as $id => $record) {
                if (!is_string($id) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id) ||
                    ($record['data']['ok'] ?? null) !== true || ($record['data']['id'] ?? null) !== $id ||
                    !empty($record['data']['partial']) || !is_int($record['collected_at'] ?? null) ||
                    $record['collected_at'] < $current['started_at'] || $record['collected_at'] > $now + 60) {
                    throw new RuntimeException('readiness_invalid_record');
                }
                $data = $record['data'];
                if ($kind === 'vm' && (!is_array($data['detail']['vm'] ?? null) || !is_array($data['context'] ?? null))) {
                    throw new RuntimeException('readiness_vm_detail_missing');
                }
                if ($kind === 'host' && !is_array($data['summary'] ?? null)) throw new RuntimeException('readiness_host_detail_missing');
                if ($kind === 'datastore' && !is_array($data['detail']['datastore'] ?? null)) throw new RuntimeException('readiness_datastore_detail_missing');
                foreach ($data['sections'] ?? [] as $section) {
                    if (($section['state'] ?? '') !== 'ok') $out['optional_unavailable']++;
                }
                if ($kind === 'datastore') continue;
                $parts = $metricRead($id, $current['access_epoch'] ?? 'initial');
                foreach (['performance', 'storage_performance'] as $name) {
                    $out['metric_expected']++; $part = $parts[$name] ?? null;
                    if (!is_array($part) || ($part['state'] ?? '') !== 'ok' ||
                        !is_int($part['collected_at'] ?? null) || $part['collected_at'] > $now + 60 ||
                        $now - $part['collected_at'] > 14400 ||
                        ($part['data']['entity_external_id'] ?? '') !== $id ||
                        ($part['data']['entity_type'] ?? '') !== $kind || ($part['data']['verify_tls'] ?? null) !== true ||
                        !is_array($part['data']['samples'] ?? null)) {
                        $out['metric_unavailable']++; continue;
                    }
                    // An explicit unsupported/empty series is valid; a caught sample error is not.
                    $out['metric_sections']++;
                }
            }
        }
        foreach (['host_folders', 'object_folders', 'search'] as $domain) {
            if (!is_array($inventory[$domain]['data'] ?? null) ||
                ($inventory[$domain]['data']['ok'] ?? null) !== true || !empty($inventory[$domain]['data']['partial'])) {
                throw new RuntimeException('readiness_domain_incomplete');
            }
        }
        $core = $inventory['core'];
        foreach (['vm' => $core['topology']['vms'] ?? null, 'host' => $core['topology']['hosts'] ?? null,
            'datastore' => $core['datastores']['datastores'] ?? null] as $kind => $rows) {
            if (!is_array($rows)) throw new RuntimeException('readiness_core_incomplete');
            $ids = array_column($rows, 'external_id'); sort($ids, SORT_STRING);
            if ($ids !== $sets[$kind]) throw new RuntimeException('readiness_membership_mismatch');
        }
        $search = array_column($inventory['search']['data']['rows'] ?? [], 'vm_external_id'); sort($search, SORT_STRING);
        if ($search !== $sets['vm']) throw new RuntimeException('readiness_search_mismatch');
        // This rollout is for the already populated connector #1, not permission to replace it by an empty discovery.
        if ($sets['vm'] === [] || $sets['host'] === [] || $sets['datastore'] === [] || empty($core['topology']['datacenters'])) {
            $out['state'] = 'unexpected_empty_inventory'; return $out;
        }
        $out['state'] = $out['metric_unavailable'] === 0 ? 'ready' : 'sampling';
        $out['ready'] = $out['state'] === 'ready';
        return $out;
    }
}
