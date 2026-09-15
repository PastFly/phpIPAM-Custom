<?php
/** Bounded scalar-property sweep over an explicit known roster. Never infers discovery/deletion. */
final class IntegrationVmwareBatchInventory
{
    public const TYPES = ['vm' => 'VirtualMachine', 'host' => 'HostSystem', 'datastore' => 'Datastore'];
    private const FIELDS = [
        'vm' => ['name' => 'text', 'runtime.powerState' => 'power', 'runtime.host' => 'host',
            'config.hardware.numCPU' => 'positive', 'config.hardware.memoryMB' => 'positive',
            'config.annotation' => 'text', 'guest.hostName' => 'text', 'guest.ipAddress' => 'ip',
            'guest.toolsRunningStatus' => 'tools'],
        'host' => ['name' => 'text', 'runtime.connectionState' => 'connection', 'runtime.inMaintenanceMode' => 'bool',
            'summary.quickStats.overallCpuUsage' => 'usage', 'summary.quickStats.overallMemoryUsage' => 'usage'],
        'datastore' => ['name' => 'text', 'summary.capacity' => 'nonnegative', 'summary.freeSpace' => 'nonnegative', 'summary.accessible' => 'bool']
    ];
    public function __construct(private IntegrationVmwareBatchSession $session, private int $batchSize = 50)
    {
        if ($batchSize < 1 || $batchSize > 100) throw new InvalidArgumentException('batch_size_invalid');
    }
    public static function roster(array $entities): array
    {
        if (!array_is_list($entities) || count($entities) < 1 || count($entities) > 2000) throw new InvalidArgumentException('batch_roster_invalid');
        $out = [];
        foreach ($entities as $entity) {
            if (!is_array($entity) || !is_string($entity['kind'] ?? null) || !isset(self::TYPES[$entity['kind']]) ||
                !is_string($entity['id'] ?? null) || !preg_match('/^' . $entity['kind'] . '-[0-9]{1,20}$/D', $entity['id'])) {
                throw new InvalidArgumentException('batch_entity_invalid');
            }
            if (isset($out[$entity['id']])) throw new InvalidArgumentException('batch_entity_duplicate');
            $out[$entity['id']] = $entity['kind'];
        }
        return $out;
    }
    public function read(array $entities): array
    {
        $roster = self::roster($entities); $records = []; $start = time();
        $objectCoverage = true; $fieldCoverage = true; $recoveryReads = 0;
        foreach (array_chunk($roster, $this->batchSize, true) as $batch) {
            $this->readBatch($batch, $records, $objectCoverage, $fieldCoverage, $recoveryReads);
        }
        return ['schema' => 1, 'method' => 'RetrieveProperties', 'started_at' => $start, 'completed_at' => time(),
            'object_coverage_complete' => $objectCoverage, 'all_requested_fields_available' => $fieldCoverage,
            'authoritative_membership' => false, 'objects' => $records];
    }
    private function readBatch(array $batch, array &$records, bool &$objectCoverage, bool &$fieldCoverage, int &$recoveryReads): void
    {
        try { $response = $this->session->read('RetrieveProperties', $this->requestBody($batch)); }
        catch (Throwable $e) {
            if ($e->getMessage() !== 'batch_managed_object_not_found') throw $e;
            $objectCoverage = false; $fieldCoverage = false;
            if (count($batch) === 1) {
                $id = array_key_first($batch); $kind = $batch[$id];
                $records[$id] = ['kind' => $kind, 'id' => $id, 'state' => 'unobserved', 'values' => [],
                    'property_states' => [], 'collected_at' => null, 'source_observed_at' => null];
                return;
            }
            $size = intdiv(count($batch) + 1, 2);
            $parts = array_chunk($batch, $size, true);
            foreach ($parts as $part) {
                if (++$recoveryReads > 32) throw new RuntimeException('batch_missing_split_limit');
                $this->readBatch($part, $records, $objectCoverage, $fieldCoverage, $recoveryReads);
            }
            return;
        }

        $seen = []; $received = time();
        foreach (IntegrationVmwareBatchSession::children($response, 'returnval') as $row) {
            $object = IntegrationVmwareBatchSession::one($row, 'obj'); $id = IntegrationVmwareBatchSession::text($object);
            if (!isset($batch[$id]) || $object->getAttribute('type') !== self::TYPES[$batch[$id]] || isset($seen[$id])) {
                throw new RuntimeException('batch_unexpected_or_duplicate_object');
            }
            $seen[$id] = true; $kind = $batch[$id]; $values = []; $states = []; $observed = [];
            foreach (IntegrationVmwareBatchSession::children($row, 'propSet') as $property) {
                $path = IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($property, 'name'));
                if (!isset(self::FIELDS[$kind][$path]) || isset($observed[$path])) throw new RuntimeException('batch_property_scope');
                $observed[$path] = true;
                $node = IntegrationVmwareBatchSession::one($property, 'val');
                $value = self::value($node, self::FIELDS[$kind][$path]);
                $values[$path] = $value;
                $states[$path] = $value === null ? 'unavailable' : 'available';
            }
            foreach (IntegrationVmwareBatchSession::children($row, 'missingSet') as $missing) {
                $path = IntegrationVmwareBatchSession::text(IntegrationVmwareBatchSession::one($missing, 'path'));
                if (!isset(self::FIELDS[$kind][$path]) || isset($observed[$path])) throw new RuntimeException('batch_property_scope');
                IntegrationVmwareBatchSession::one($missing, 'fault');
                $observed[$path] = true; $values[$path] = null; $states[$path] = 'property_fault';
            }
            foreach (self::FIELDS[$kind] as $path => $_) {
                if (!isset($observed[$path])) { $values[$path] = null; $states[$path] = 'not_reported'; }
                if ($states[$path] !== 'available') $fieldCoverage = false;
            }
            $records[$id] = ['kind' => $kind, 'id' => $id, 'state' => 'returned', 'values' => $values,
                'property_states' => $states, 'collected_at' => $received, 'source_observed_at' => null];
        }
        foreach ($batch as $id => $kind) if (!isset($seen[$id])) {
            $objectCoverage = false; $fieldCoverage = false;
            $records[$id] = ['kind' => $kind, 'id' => $id, 'state' => 'unobserved', 'values' => [],
                'property_states' => [], 'collected_at' => null, 'source_observed_at' => null];
        }
    }
    private function requestBody(array $batch): string
    {
        $filter = '';
        foreach (array_unique(array_values($batch)) as $kind) {
            $filter .= '<propSet><type>' . self::TYPES[$kind] . '</type><all>false</all>';
            foreach (self::FIELDS[$kind] as $path => $_) $filter .= '<pathSet>' . $path . '</pathSet>';
            $filter .= '</propSet>';
        }
        foreach ($batch as $id => $kind) $filter .= '<objectSet><obj type="' . self::TYPES[$kind] . '">' . $id . '</obj><skip>false</skip></objectSet>';
        return '<_this type="PropertyCollector">' . IntegrationVmwareBatchSession::xml($this->session->manager('propertyCollector')) . '</_this><specSet>' . $filter . '</specSet>';
    }
    private static function value(DOMElement $node, string $type): mixed
    {
        $raw = IntegrationVmwareBatchSession::text($node);
        $nil = $node->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil');
        if (!in_array($nil, ['', 'false', '0', 'true', '1'], true)) throw new RuntimeException('batch_nil_invalid');
        if (in_array($nil, ['true', '1'], true)) {
            if ($raw !== '') throw new RuntimeException('batch_nil_invalid');
            return null;
        }
        if (strlen($raw) > 262144) throw new RuntimeException('batch_property_limit');
        if ($type === 'text') return $raw;
        if ($type === 'host') {
            if ($node->getAttribute('type') !== 'HostSystem' || !preg_match('/^host-[0-9]{1,20}$/D', $raw)) throw new RuntimeException('batch_host_invalid');
            return $raw;
        }
        if ($type === 'ip') {
            if ($raw === '') return null;
            if (filter_var($raw, FILTER_VALIDATE_IP) === false) throw new RuntimeException('batch_ip_invalid');
            return inet_ntop(inet_pton($raw));
        }
        $enums = ['power' => ['poweredOn', 'poweredOff', 'suspended'],
            'connection' => ['connected', 'disconnected', 'notResponding'],
            'tools' => ['guestToolsRunning', 'guestToolsNotRunning', 'guestToolsExecutingScripts']];
        if (isset($enums[$type])) {
            if (!in_array($raw, $enums[$type], true)) throw new RuntimeException('batch_enum_invalid');
            return $raw;
        }
        if ($type === 'bool') {
            if (!in_array($raw, ['true', '1', 'false', '0'], true)) throw new RuntimeException('batch_bool_invalid');
            return in_array($raw, ['true', '1'], true);
        }
        $int = filter_var($raw, FILTER_VALIDATE_INT);
        if ($type === 'usage' && $int === -1) return null;
        if ($int === false || $int < ($type === 'positive' ? 1 : 0)) throw new RuntimeException('batch_integer_invalid');
        return $int;
    }
}
