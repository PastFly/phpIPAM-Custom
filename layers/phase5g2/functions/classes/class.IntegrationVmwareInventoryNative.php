<?php
/** Adapt local normalized records to retained native contracts. No transport or credential factory. */
final class IntegrationVmwareInventoryNative
{
    private IntegrationVmwareInventoryReader $reader;
    private $links;
    public const OPERATIONS = ['workspace', 'host_folders', 'object_folders', 'search', 'status',
        'vm', 'host', 'annotation', 'host_network', 'network_path', 'guest_facts', 'performance', 'storage_performance'];
    public const JSON_OPERATIONS = ['workspace', 'host_folders', 'object_folders', 'search', 'status', 'guest_facts'];

    public function __construct(IntegrationVmwareInventoryReader $reader, $addressLinks = null)
    {
        $this->reader = $reader; $this->links = $addressLinks;
    }
    public static function text(array $input, string $key, string $default = ''): string
    {
        $value = $input[$key] ?? $default;
        if ((!is_string($value) && !is_int($value)) || strlen((string)$value) > 256 ||
            preg_match('/[\x00-\x1f\x7f]/', (string)$value)) throw new InvalidArgumentException('invalid_request');
        return trim((string)$value);
    }
    public static function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function stamp(?int $value): string { return $value === null ? 'нет данных' : gmdate('Y-m-d H:i:s', $value) . ' UTC'; }
    /** Freshness is exposed by the single global status popover; cards must not duplicate it inline. */
    public static function freshness(array $entry, ?int $observed = null): string
    {
        return '';
    }
    private static function section(array $record, string $name): ?array
    {
        $part = $record['sections'][$name] ?? null;
        return is_array($part) && in_array($part['state'] ?? '', ['ok', 'stale'], true) && is_array($part['data'] ?? null) ? $part : null;
    }
    private static function facts(array $facts, string $id): array
    {
        $friendly = null; $source = null;
        foreach (['guest_guest_full_name' => 'guest.guestFullName', 'config_guest_full_name' => 'config.guestFullName',
            'guest_guest_id' => 'guest.guestId', 'config_guest_id' => 'config.guestId'] as $field => $origin) {
            if (is_string($facts[$field] ?? null) && trim($facts[$field]) !== '') { $friendly = trim($facts[$field]); $source = $origin; break; }
        }
        return ['ok' => true, 'vm_external_id' => $id, 'friendly_guest_os' => $friendly, 'friendly_guest_os_source' => $source] +
            array_intersect_key($facts, array_flip(['name', 'guest_guest_id', 'config_guest_id', 'guest_guest_full_name',
                'config_guest_full_name', 'memory_mb', 'num_cpu', 'hardware_version']));
    }
    /** Returning a value never schedules or performs collection, including force_refresh=1. */
    public function response(string $operation, array $input): array
    {
        if (!in_array($operation, self::OPERATIONS, true)) throw new InvalidArgumentException('invalid_operation');
        $json = in_array($operation, self::JSON_OPERATIONS, true);
        $id = ''; $view = $operation;
        if (in_array($operation, ['vm', 'annotation', 'network_path', 'guest_facts', 'performance'], true)) {
            $view = 'vm'; $id = self::text($input, 'vm_id');
        } elseif (in_array($operation, ['host', 'host_network'], true)) {
            $view = 'host'; $id = self::text($input, 'host_id');
        } elseif ($operation === 'storage_performance') {
            $view = self::text($input, 'entity_type'); $id = self::text($input, 'entity_id');
            if (!in_array($view, ['vm', 'host'], true)) throw new InvalidArgumentException('invalid_entity');
        } elseif ($operation === 'workspace') $view = 'core';
        $mode = self::text($input, 'mode', 'graph');
        if ($operation === 'object_folders') {
            if (!in_array($mode, ['graph', 'datastores'], true)) throw new InvalidArgumentException('invalid_mode');
            if ($mode === 'datastores') $view = 'core';
        }
        $entry = $this->reader->query($view, $id);
        $meta = array_diff_key($entry, array_flip(['data', 'metadata']));
        $meta['vcenter_requests'] = 0; $meta['inventory_files_written'] = false;
        $cache = ['state' => empty($entry['has_inventory']) ? 'initializing' : (!empty($entry['stale']) ? 'stale' : 'fresh'), 'age_seconds' => $entry['age_seconds'],
            'refresh_required' => false, 'retry_after_seconds' => 60, 'cache_snapshot_written' => false];
        $response = ['format' => $json ? 'json' : 'html', 'status' => 200, 'body' => null];
        $initial = empty($entry['has_inventory']);
        $message = $initial ? 'Первичная синхронизация ещё не завершена. Данные собирает фоновая служба.' : '';
        $prefix = self::freshness($entry);
        if ($operation === 'status') {
            $body = $meta; $body['cache'] = $cache;
        } elseif ($initial) {
            if ($operation === 'workspace') $body = ['ok' => true, 'tree_html' => '<div class="text-muted">' . $message . '</div>',
                'content_html' => '<div class="alert alert-info">' . $message . '</div>', 'summary_html' => $prefix];
            elseif ($json) $body = ['ok' => false, 'error' => 'inventory_initializing', 'message' => $message];
            else $body = '<div class="alert alert-info phase5g2-initializing">' . $message . '</div>';
        } elseif ($operation === 'workspace') {
            $data = $entry['data'];
            $projection = (new IntegrationVmwareVcenterExplorer(100))->build($data['topology'], $data['datastores']);
            $scope = self::text($input, 'scope'); $scopeId = self::text($input, 'scope_id');
            $display = self::text($input, 'view', 'overview'); $search = self::text($input, 'search');
            if (!in_array($scope, ['', 'datacenter', 'cluster', 'host'], true) || !in_array($display, ['overview', 'vms', 'datastores'], true)) {
                throw new InvalidArgumentException('invalid_scope');
            }
            if ($scope !== '' && !isset($projection[['datacenter' => 'datacenters', 'cluster' => 'clusters', 'host' => 'hosts'][$scope]][$scopeId])) {
                throw new InvalidArgumentException('unknown_scope');
            }
            if ($scope === '') $scopeId = '';
            $page = self::text($input, 'page', '1'); $size = self::text($input, 'page_size', '50');
            if (!preg_match('/^[1-9][0-9]{0,5}$/D', $page) || !preg_match('/^[1-9][0-9]{0,2}$/D', $size) || (int)$size > 100) {
                throw new InvalidArgumentException('invalid_pagination');
            }
            $content = match ($display) {
                'overview' => \PhpIpamMirrorViews\phase5e_result_render_overview($projection, $data['topology']),
                'vms' => \PhpIpamMirrorViews\phase5e_result_render_vms($projection, $scope, $scopeId, $search, (int)$page, (int)$size),
                'datastores' => \PhpIpamMirrorViews\phase5e_result_render_datastores($projection, $scope, $scopeId, $search, (int)$page, (int)$size),
            };
            $body = ['ok' => true, 'tree_html' => \PhpIpamMirrorViews\phase5e_result_render_tree($projection, $scope, $scopeId),
                'content_html' => $content, 'summary_html' => $prefix];
        } elseif ($operation === 'host_folders') $body = ['ok' => true, 'inventory' => $entry['data']];
        elseif ($operation === 'object_folders' && $mode === 'datastores') $body = ['ok' => true, 'datastores' =>
            array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['external_id', 'name', 'type', 'capacity_bytes', 'free_space_bytes'])),
                $entry['data']['datastores']['datastores'])];
        elseif (in_array($operation, ['object_folders', 'search'], true)) $body = $entry['data'];
        else {
            $record = $entry['data']; $sections = $record['sections'] ?? [];
            if ($operation === 'vm') {
                $detail = $record['detail']; $fact = self::section($record, 'guest_facts');
                if ($fact !== null) {
                    $facts = self::facts($fact['data'], $id);
                    if ($facts['friendly_guest_os'] !== null) $detail['guest_identity']['guest_os'] = $facts['friendly_guest_os'];
                    foreach (['memory_mb' => 'memory_size_mib', 'num_cpu' => 'cpu_count', 'hardware_version' => 'hardware_version'] as $from => $to) {
                        if (isset($facts[$from])) $detail['vm'][$to] = $facts[$from];
                    }
                    $prefix .= self::freshness(array_replace($entry, ['stale' => !empty($entry['stale']) || $fact['state'] === 'stale']), $fact['observed_at'] ?? null);
                }
                $guest = self::section($record, 'guest_network');
                $guestVm = $guest['data']['vms'][0] ?? ['status' => 'unavailable', 'interfaces' => [], 'ip_count' => 0];
                $tags = $entry['metadata']['data']['tags'] ?? [];
                $tagRaw = self::text($input, 'include_tags', '0');
                if (!in_array($tagRaw, ['0', '1'], true)) throw new InvalidArgumentException('invalid_options');
                $body = $prefix . \PhpIpamMirrorViews\render_vm($detail, $record['context'], $guestVm,
                    ['tags' => $tags, 'tag_count' => count($tags)], $id, $tagRaw === '1', $this->links);
            } elseif ($operation === 'host') {
                $performance = self::section($record, 'performance');
                $body = $prefix . \PhpIpamMirrorViews\render_host($record['summary'], $performance['data'] ?? [], $id);
                if ($performance === null) $body .= '<div class="text-muted small">Измерения CPU/RAM ещё не собраны фоновым сборщиком.</div>';
            } else {
                $section = ['annotation' => 'annotation', 'host_network' => 'network_hints', 'network_path' => 'network_path',
                    'guest_facts' => 'guest_facts', 'performance' => 'performance', 'storage_performance' => 'storage_performance'][$operation];
                $part = self::section($record, $section);
                if ($part === null) {
                    $body = $json ? ['ok' => false, 'error' => 'optional_snapshot_unavailable', 'vm_external_id' => $id] :
                        '<div class="text-muted small">Дополнительные сведения отсутствуют в сохранённом снимке. Опрос при открытии не запускается.</div>';
                } elseif ($operation === 'guest_facts') {
                    $body = self::facts($part['data'], $id); $body['collected_at'] = $part['observed_at'] ?? null;
                    $body['section_state'] = $part['state'];
                } else {
                    $render = '\\PhpIpamMirrorViews\\render_' . $operation;
                    $body = self::freshness(array_replace($entry, ['stale' => !empty($entry['stale']) || $part['state'] === 'stale']), $part['observed_at'] ?? null) . $render($part['data']);
                }
            }
        }
        if ($json) {
            $body['inventory_status'] = $meta; $body['cache'] = $cache;
            $body['cache_enabled'] = true; $body['cache_snapshot_written'] = false; $body['verify_tls'] = true;
            $body['vcenter_requests'] = 0; $body['inventory_db_written'] = false;
            $body['phpipam_objects_modified'] = false; $body['vcenter_objects_modified'] = false;
        }
        $response['body'] = $body; return $response;
    }
}
