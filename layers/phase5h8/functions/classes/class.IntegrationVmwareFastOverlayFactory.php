<?php
/**
 * Compose the local Phase5H reader/overlay behind a disabled-by-default lazy selector.
 * This class never loads credentials or talks to a provider; Context owns authorization/binding.
 */
final class IntegrationVmwareFastOverlayFactory
{
    private $clock;

    public function __construct(
        private IntegrationVmwareInventoryContext $context,
        private IntegrationVmwareFastReaderConfig $config,
        private int $connectorId,
        ?callable $clock = null
    ) {
        if ($connectorId < 1 || $connectorId > 2147483647) {
            throw new InvalidArgumentException('fast_reader_connector_invalid');
        }
        $this->clock = $clock;
    }

    public function selector($base): IntegrationVmwareInventoryQuerySelector
    {
        $enabled = $this->config->enabledFor($this->connectorId);
        return new IntegrationVmwareInventoryQuerySelector($base, function ($base) {
            $store = $this->context->fastStore($this->config->root());
            $fast = new IntegrationVmwareFastReader(
                $store,
                $this->config->maxPropertyAge(),
                $this->config->maxMetricAge(),
                $this->config->maxRosterAge()
            );
            return new IntegrationVmwareFastOverlayReader(
                [$base, 'query'],
                $fast,
                [$this->context, 'current'],
                true,
                $this->clock
            );
        }, $enabled);
    }
}
