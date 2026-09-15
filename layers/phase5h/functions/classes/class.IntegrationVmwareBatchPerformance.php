<?php
/** Multi-entity QueryPerf over explicit query plans. No assumed provider interval or counter IDs. */
final class IntegrationVmwareBatchPerformance
{
    private ?array $definitions = null;
    private const UNITS = ['cpu.usage.average' => 'percent', 'mem.usage.average' => 'percent', 'disk.usage.average' => 'kiloBytesPerSecond'];
    public function __construct(private IntegrationVmwareBatchSession $session, private int $batchSize = 25)
    {
        if ($batchSize < 1 || $batchSize > 50) throw new InvalidArgumentException('batch_size_invalid');
    }
    /** Counter metadata is fetched once per collector/session, never once per VM or metric section. */
    public function definitions(): array
    {
        $manager = $this->session->manager('perfManager'); // Also rejects use outside the session.
        if ($this->definitions !== null) return $this->definitions;
        $r = $this->session->read('RetrieveProperties', '<_this type="PropertyCollector">' .
            IntegrationVmwareBatchSession::xml($this->session->manager('propertyCollector')) . '</_this><specSet>' .
            '<propSet><type>PerformanceManager</type><all>false</all><pathSet>perfCounter</pathSet></propSet>' .
            '<objectSet><obj type="PerformanceManager">' . IntegrationVmwareBatchSession::xml($manager) . '</obj><skip>false</skip></objectSet></specSet>');
        $row = IntegrationVmwareBatchSession::one($r, 'returnval');
        $object = IntegrationVmwareBatchSession::one($row, 'obj');
        if ($object->getAttribute('type') !== 'PerformanceManager' || IntegrationVmwareBatchSession::text($object) !== $manager ||
            IntegrationVmwareBatchSession::children($row, 'missingSet') !== []) throw new RuntimeException('batch_counter_property_invalid');
        $property = IntegrationVmwareBatchSession::one($row, 'propSet');
        if (IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($property, 'name')) !== 'perfCounter') throw new RuntimeException('batch_counter_property_invalid');
        $value = IntegrationVmwareBatchSession::one($property, 'val');
        $list = IntegrationVmwareBatchSession::children($value, 'PerfCounterInfo');
        if (count($list) < 1 || count($list) > 10000) throw new RuntimeException('batch_counter_registry_invalid');
        $names = []; $ids = []; $defs = [];
        foreach ($list as $counter) {
            $id = self::integer(IntegrationVmwareBatchSession::one($counter, 'key'), 1, 2147483647);
            $group = self::nested($counter, 'groupInfo'); $name = self::nested($counter, 'nameInfo');
            $unit = self::nested($counter, 'unitInfo');
            $rollup = IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($counter, 'rollupType'));
            foreach ([$group, $name, $unit, $rollup] as $token) if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/D', $token)) throw new RuntimeException('batch_counter_registry_invalid');
            $canonical = $group . '.' . $name . '.' . $rollup;
            if (isset(self::UNITS[$canonical]) && self::UNITS[$canonical] !== $unit) throw new RuntimeException('batch_counter_unit_invalid');
            $definition = ['id' => $id, 'unit' => $unit];
            // Some vCenter versions return the same PerfCounterInfo more than once. An operationally
            // equivalent duplicate is harmless for this collector; conflicting ID/name/unit mappings
            // remain fail-closed so QueryPerf can never be issued against an ambiguous registry.
            if (isset($names[$canonical]) || isset($ids[$id])) {
                if (($names[$canonical] ?? null) !== $definition || ($ids[$id] ?? null) !== ['canonical' => $canonical, 'unit' => $unit]) {
                    throw new RuntimeException('batch_counter_duplicate');
                }
                continue;
            }
            $names[$canonical] = $definition; $ids[$id] = ['canonical' => $canonical, 'unit' => $unit];
            if (isset(self::UNITS[$canonical])) $defs[$canonical] = $definition;
        }
        return $this->definitions = $defs;
    }
    /** Use for an unknown provider; callers must not substitute a hardcoded real-time refresh rate. */
    public function provider(string $kind, string $id): array
    {
        $this->entity($kind, $id);
        $r = $this->session->read('QueryPerfProviderSummary', $this->managerXml() . $this->entityXml($kind, $id));
        $value = IntegrationVmwareBatchSession::one($r, 'returnval');
        $flag = IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($value, 'currentSupported'));
        if (!in_array($flag, ['true', '1', 'false', '0'], true)) throw new RuntimeException('batch_capability_invalid');
        $supported = in_array($flag, ['true', '1'], true);
        $interval = $supported ? self::integer(IntegrationVmwareBatchSession::one($value, 'refreshRate'), 1, 300) : null;
        return ['current_supported' => $supported, 'interval_id' => $interval, 'checked_at' => time()];
    }
    /** A missing series alone is not unsupported. This explicit capability check can resolve a gap. */
    public function availability(string $kind, string $id, int $interval): array
    {
        $this->entity($kind, $id); self::interval($interval); $defs = $this->definitions();
        $r = $this->session->read('QueryAvailablePerfMetric', $this->managerXml() . $this->entityXml($kind, $id) . '<intervalId>' . $interval . '</intervalId>');
        $available = []; $seen = [];
        $rows = IntegrationVmwareBatchSession::children($r, 'returnval');
        if (count($rows) > 10000) throw new RuntimeException('batch_available_limit');
        foreach ($rows as $row) {
            $counter = self::integer(IntegrationVmwareBatchSession::one($row, 'counterId'), 1, 2147483647);
            $instances = IntegrationVmwareBatchSession::children($row, 'instance');
            if (count($instances) > 1) throw new RuntimeException('batch_instance_invalid');
            $instance = $instances === [] ? '' : IntegrationVmwareBatchSession::text($instances[0]);
            if (strlen($instance) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $instance)) throw new RuntimeException('batch_instance_invalid');
            $key = $counter . ':' . $instance;
            if (isset($seen[$key])) throw new RuntimeException('batch_available_duplicate');
            $seen[$key] = true;
            if ($instance === '') $available[$counter] = true;
        }
        $out = [];
        foreach ($defs as $name => $definition) $out[$name] = isset($available[$definition['id']]);
        return ['aggregate_available' => $out, 'checked_at' => time()];
    }
    /** Plans carry a source-derived interval and selected canonical aggregate counters; never CSV or wildcard instances. */
    public function read(array $plans, int $maxSamples = 60): array
    {
        if ($maxSamples < 1 || $maxSamples > 60) throw new InvalidArgumentException('batch_sample_limit');
        IntegrationVmwareBatchInventory::roster($plans); $validated = [];
        foreach ($plans as $plan) {
            $this->entity($plan['kind'], $plan['id']);
            if (!is_int($plan['interval_id'] ?? null)) throw new InvalidArgumentException('batch_interval_required');
            self::interval($plan['interval_id']);
            $names = $plan['counters'] ?? null;
            if (!is_array($names) || !array_is_list($names) || count($names) < 1 || count($names) > 3) throw new InvalidArgumentException('batch_counters_invalid');
            $seen = [];
            foreach ($names as $name) {
                if (!is_string($name) || !isset(self::UNITS[$name]) || isset($seen[$name])) throw new InvalidArgumentException('batch_counters_invalid');
                $seen[$name] = true;
            }
            $validated[$plan['id']] = ['kind' => $plan['kind'], 'interval_id' => $plan['interval_id'], 'counters' => $names];
        }
        $defs = $this->definitions();
        foreach ($validated as $plan) foreach ($plan['counters'] as $name) if (!isset($defs[$name])) throw new RuntimeException('batch_counter_definition_missing');
        $result = [];
        foreach (array_chunk($validated, $this->batchSize, true) as $batch) {
            $query = $this->managerXml();
            foreach ($batch as $id => $plan) {
                $query .= '<querySpec>' . $this->entityXml($plan['kind'], $id) . '<maxSample>' . $maxSamples . '</maxSample>';
                foreach ($plan['counters'] as $name) $query .= '<metricId><counterId>' . $defs[$name]['id'] . '</counterId><instance></instance></metricId>';
                $query .= '<intervalId>' . $plan['interval_id'] . '</intervalId><format>normal</format></querySpec>';
            }
            $response = $this->session->read('QueryPerf', $query); $seen = []; $received = time();
            foreach (IntegrationVmwareBatchSession::children($response, 'returnval') as $row) {
                $object = IntegrationVmwareBatchSession::one($row, 'entity'); $id = IntegrationVmwareBatchSession::text($object);
                if (!isset($batch[$id]) || isset($seen[$id]) || $object->getAttribute('type') !== IntegrationVmwareBatchInventory::TYPES[$batch[$id]['kind']]) throw new RuntimeException('batch_metric_entity_invalid');
                $seen[$id] = true; $plan = $batch[$id]; $stamps = []; $last = null;
                foreach (IntegrationVmwareBatchSession::children($row, 'sampleInfo') as $sample) {
                    $text = IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($sample, 'timestamp'));
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $text) ||
                        ($stamp = strtotime($text)) === false || $stamp > $received + 60 || ($last !== null && $stamp <= $last)) throw new RuntimeException('batch_sample_time_invalid');
                    $date = date_parse($text);
                    if ($date['warning_count'] || $date['error_count']) throw new RuntimeException('batch_sample_time_invalid');
                    $last = $stamp;
                    $stamps[] = ['timestamp' => $text, 'observed_at' => $stamp,
                        'interval_seconds' => self::integer(IntegrationVmwareBatchSession::one($sample, 'interval'), 1, 86400)];
                    if (count($stamps) > $maxSamples) throw new RuntimeException('batch_samples_limit');
                }
                $selected = [];
                foreach ($plan['counters'] as $name) $selected[$defs[$name]['id']] = $name;
                $series = [];
                foreach (IntegrationVmwareBatchSession::children($row, 'value') as $metric) {
                    $metricId = IntegrationVmwareBatchSession::one($metric, 'id');
                    $counter = self::integer(IntegrationVmwareBatchSession::one($metricId, 'counterId'), 1, 2147483647);
                    if (!isset($selected[$counter]) || isset($series[$selected[$counter]]) ||
                        IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($metricId, 'instance')) !== '') throw new RuntimeException('batch_metric_scope');
                    $values = [];
                    foreach (IntegrationVmwareBatchSession::children($metric, 'value') as $point) {
                        $value = self::integer($point, PHP_INT_MIN, PHP_INT_MAX);
                        $values[] = $value < 0 ? null : (self::UNITS[$selected[$counter]] === 'percent' ? $value / 100.0 : $value);
                        if (count($values) > $maxSamples) throw new RuntimeException('batch_samples_limit');
                    }
                    if (count($values) !== count($stamps)) throw new RuntimeException('batch_sample_alignment');
                    $series[$selected[$counter]] = $values;
                }
                $missing = array_values(array_diff($plan['counters'], array_keys($series))); $samples = [];
                foreach ($stamps as $i => $stamp) {
                    $values = [];
                    foreach ($plan['counters'] as $name) $values[$name] = $series[$name][$i] ?? null;
                    $samples[] = $stamp + ['values' => $values];
                }
                $result[$id] = ['kind' => $plan['kind'], 'id' => $id,
                    'state' => $missing !== [] ? 'partial' : ($samples === [] ? 'empty' : 'returned'),
                    'missing_counters' => $missing, 'samples' => $samples,
                    'observed_at' => $series === [] ? null : $last, 'collected_at' => $received];
            }
            foreach ($batch as $id => $plan) if (!isset($seen[$id])) $result[$id] = ['kind' => $plan['kind'], 'id' => $id,
                'state' => 'not_returned', 'missing_counters' => $plan['counters'], 'samples' => [], 'observed_at' => null, 'collected_at' => null];
        }
        return ['schema' => 1, 'definitions' => $defs, 'objects' => $result, 'capability_inferred_from_missing_samples' => false];
    }
    private static function nested(DOMElement $node, string $name): string
    {
        return IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one(IntegrationVmwareBatchSession::one($node, $name), 'key'));
    }
    private function entity(string $kind, string $id): void
    {
        if (!in_array($kind, ['vm', 'host'], true) || !preg_match('/^' . $kind . '-[0-9]{1,20}$/D', $id)) throw new InvalidArgumentException('batch_metric_entity_invalid');
    }
    private static function interval(int $value): void
    {
        if ($value < 1 || $value > 300) throw new InvalidArgumentException('batch_interval_invalid');
    }
    private function entityXml(string $kind, string $id): string { return '<entity type="' . IntegrationVmwareBatchInventory::TYPES[$kind] . '">' . $id . '</entity>'; }
    private function managerXml(): string { return '<_this type="PerformanceManager">' . IntegrationVmwareBatchSession::xml($this->session->manager('perfManager')) . '</_this>'; }
    private static function integer(DOMElement $node, int $min, int $max): int
    {
        $raw = IntegrationVmwareBatchSession::text($node);
        if (!preg_match('/^-?[0-9]+$/D', $raw)) throw new RuntimeException('batch_integer_invalid');
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) throw new RuntimeException('batch_integer_invalid');
        return $value;
    }
}
