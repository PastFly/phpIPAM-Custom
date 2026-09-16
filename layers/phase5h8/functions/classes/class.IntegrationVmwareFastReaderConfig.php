<?php
/** Pure disabled-by-default configuration contract; no file/environment loading here. */
final class IntegrationVmwareFastReaderConfig
{
    private const ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';

    private function __construct(
        private bool $enabled,
        private ?int $connectorId,
        private string $root,
        private int $maxPropertyAge,
        private int $maxMetricAge,
        private int $maxRosterAge
    ) {}

    public static function disabled(): self
    {
        return new self(false, null, self::ROOT, 300, 300, 300);
    }

    public static function fromArray(array $config): self
    {
        $keys = array_keys($config); sort($keys, SORT_STRING);
        $expected = ['connector_id','enabled','max_metric_age','max_property_age','max_roster_age','root','schema'];
        if ($keys !== $expected || ($config['schema'] ?? null) !== 1 || !is_bool($config['enabled'] ?? null) ||
            !is_int($config['connector_id'] ?? null) || $config['connector_id'] < 1 || $config['connector_id'] > 2147483647 ||
            ($config['root'] ?? null) !== self::ROOT) {
            throw new InvalidArgumentException('fast_reader_config_invalid');
        }
        foreach (['max_property_age','max_metric_age','max_roster_age'] as $name) {
            if (!is_int($config[$name] ?? null) || $config[$name] < 60 || $config[$name] > 300) {
                throw new InvalidArgumentException('fast_reader_config_invalid');
            }
        }
        return new self($config['enabled'], $config['connector_id'], $config['root'],
            $config['max_property_age'], $config['max_metric_age'], $config['max_roster_age']);
    }

    public function enabledFor(int $connectorId): bool
    {
        if ($connectorId < 1 || $connectorId > 2147483647) throw new InvalidArgumentException('fast_reader_connector_invalid');
        return $this->enabled && $this->connectorId === $connectorId;
    }

    public function root(): string { return $this->root; }
    public function maxPropertyAge(): int { return $this->maxPropertyAge; }
    public function maxMetricAge(): int { return $this->maxMetricAge; }
    public function maxRosterAge(): int { return $this->maxRosterAge; }
}
