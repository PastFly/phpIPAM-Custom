<?php
/**
 * Lazy local query-source selector. Disabled mode never constructs the optional overlay.
 * No provider, transport, credential, filesystem or write dependency belongs here.
 */
final class IntegrationVmwareInventoryQuerySelector
{
    private object $base;
    private $overlayFactory;
    private bool $enabled;
    private ?object $selected = null;

    public function __construct($base, callable $overlayFactory, bool $enabled = false)
    {
        self::queryObject($base);
        $this->base = $base;
        $this->overlayFactory = $overlayFactory;
        $this->enabled = $enabled;
    }

    /** Same call shape as IntegrationVmwareInventoryReader::query(). */
    public function query(string $view = 'status', string $id = '', string $search = '', int $page = 1, int $pageSize = 50): array
    {
        return $this->reader()->query($view, $id, $search, $page, $pageSize);
    }

    private function reader(): object
    {
        // Disabled is the exact construction-level rollback: do not even invoke the fast/overlay factory.
        if (!$this->enabled) return $this->base;
        if ($this->selected !== null) return $this->selected;

        $candidate = ($this->overlayFactory)($this->base);
        self::queryObject($candidate);
        // Explicit enablement must not silently resolve to the same Phase5G-only object.
        if ($candidate === $this->base || $candidate === $this) {
            throw new RuntimeException('inventory_overlay_unavailable');
        }
        return $this->selected = $candidate;
    }

    private static function queryObject($reader): void
    {
        if (!is_object($reader) || !is_callable([$reader, 'query'])) {
            throw new InvalidArgumentException('invalid_inventory_query_reader');
        }
    }
}
