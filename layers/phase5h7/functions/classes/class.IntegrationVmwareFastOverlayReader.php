<?php
/**
 * Reversible local-only overlay over the existing Phase5G read-model contract.
 *
 * The base query remains authoritative for the rich saved card. Phase5H only replaces fields
 * backed by currently fresh fast-domain evidence and a reconciled present roster membership.
 * No provider/network/write dependency is allowed here.
 */
final class IntegrationVmwareFastOverlayReader
{
    private $baseQuery;
    private $authorized;
    private $clock;

    public function __construct(
        callable $baseQuery,
        private IntegrationVmwareFastReader $fast,
        callable $authorized,
        private bool $enabled = false,
        ?callable $clock = null
    ) {
        $this->baseQuery = $baseQuery;
        $this->authorized = $authorized;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Same call shape as IntegrationVmwareInventoryReader::query(). */
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        $entry = ($this->baseQuery)($view, $id, $search, $page, $pageSize);
        if (!$this->enabled || $view !== 'vm' || empty($entry['has_inventory']) || !is_array($entry['data'] ?? null)) {
            return $entry;
        }
        $this->guard();
        try {
            $fast = $this->fast->object('vm', $id, ($this->clock)());
        } catch (Throwable $e) {
            $this->guard();
            $entry['phase5h_overlay'] = self::overlayMeta('unavailable', null, [], null);
            return $entry;
        }
        $this->guard();

        $membership = $fast['membership_state'] ?? 'unknown';
        if ($membership === 'absent_confirmed') throw new RuntimeException('mirror_object_not_found');
        if ($membership !== 'present') {
            $entry['phase5h_overlay'] = self::overlayMeta('membership_unknown', $membership, [], $fast);
            return $entry;
        }

        $applied = [];
        if (!is_array($entry['data']['detail']['vm'] ?? null) || !is_array($entry['data']['detail']['guest_identity'] ?? null)) {
            $entry['phase5h_overlay'] = self::overlayMeta('base_contract_unavailable', $membership, [], $fast);
            return $entry;
        }
        $data =& $entry['data'];
        $vm =& $data['detail']['vm'];
        $guest =& $data['detail']['guest_identity'];

        $name = self::freshProperty($fast, 'name');
        if (is_string($name) && trim($name) !== '' && strlen($name) <= 1024) {
            $data['name'] = $name; $vm['name'] = $name; $applied[] = 'name';
        }
        $power = self::freshProperty($fast, 'runtime.powerState');
        $normalizedPower = is_string($power) ? (['poweredOn' => 'powered_on', 'poweredOff' => 'powered_off', 'suspended' => 'suspended'][$power] ?? null) : null;
        if ($normalizedPower !== null) { $vm['power_state'] = $normalizedPower; $applied[] = 'runtime.powerState'; }

        $host = self::freshProperty($fast, 'runtime.host');
        if (is_string($host) && preg_match('/^host-[0-9]{1,20}$/D', $host)) {
            $vm['host_external_id'] = $host; $applied[] = 'runtime.host';
        }
        $cpu = self::freshProperty($fast, 'config.hardware.numCPU');
        if (is_int($cpu) && $cpu > 0 && $cpu <= 4096) { $vm['cpu_count'] = $cpu; $applied[] = 'config.hardware.numCPU'; }
        $memory = self::freshProperty($fast, 'config.hardware.memoryMB');
        if (is_int($memory) && $memory > 0) { $vm['memory_size_mib'] = $memory; $applied[] = 'config.hardware.memoryMB'; }

        $ip = self::freshProperty($fast, 'guest.ipAddress');
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $guest['primary_ip'] = $ip; $applied[] = 'guest.ipAddress';
        }
        $annotation = self::freshPropertyEntry($fast, 'config.annotation');
        if ($annotation !== null && is_string($annotation['value'] ?? null) && strlen($annotation['value']) <= 262144) {
            $data['sections']['annotation'] = [
                'state' => 'ok',
                'observed_at' => $annotation['collected_at'] ?? null,
                'data' => ['annotation' => $annotation['value']],
            ];
            $applied[] = 'config.annotation';
        }

        // Renderer-compatible metric sections are projected only from complete fresh evidence.
        // Stale/partial/unsupported fast metric evidence therefore leaves the Phase5G section untouched.
        foreach (IntegrationVmwareFastMetricCompat::sections($fast, 'vm', $id) as $section => $projected) {
            $data['sections'][$section] = $projected;
            $applied[] = 'metrics.' . $section;
        }
        unset($vm, $guest, $data);

        $entry['phase5h_overlay'] = self::overlayMeta($applied === [] ? 'no_fresh_fields' : 'applied', $membership, $applied, $fast);
        return $entry;
    }

    private function guard(): void
    {
        if (($this->authorized)() !== true) throw new RuntimeException('mirror_access_denied');
    }

    private static function freshProperty(array $fast, string $name): mixed
    {
        $entry = self::freshPropertyEntry($fast, $name);
        return $entry['value'] ?? null;
    }

    private static function freshPropertyEntry(array $fast, string $name): ?array
    {
        $entry = $fast['properties'][$name] ?? null;
        return is_array($entry) && ($entry['state'] ?? null) === 'fresh' ? $entry : null;
    }

    private static function overlayMeta(string $state, ?string $membership, array $applied, ?array $fast): array
    {
        return [
            'enabled' => true,
            'state' => $state,
            'membership_state' => $membership,
            'applied_fields' => array_values($applied),
            'roster_published_at' => $fast['roster_published_at'] ?? null,
            'property_domain_published_at' => $fast['property_domain_published_at'] ?? null,
            'metric_domain_published_at' => $fast['metric_domain_published_at'] ?? null,
        ];
    }
}
