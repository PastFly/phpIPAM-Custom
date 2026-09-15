<?php
/**
 * Encrypted independent fast-domain stores over IntegrationVmwareInventoryStore.
 * Directories must be provisioned by deployment code; readers never create them.
 */
final class IntegrationVmwareFastStore
{
    private string $root;
    private string $key;
    private string $binding;
    /** @var array<string,IntegrationVmwareInventoryStore> */
    private array $stores = [];
    private const DOMAINS = ['properties', 'metrics', 'capabilities', 'roster'];

    public function __construct(string $root, string $masterKey, string $binding)
    {
        if (strlen($masterKey) !== 32 || !preg_match('/^[a-f0-9]{64}$/D', $binding)) {
            throw new InvalidArgumentException('fast_store_identity_invalid');
        }
        IntegrationVmwareInventoryStore::directory($root);
        $this->root = rtrim($root, '/');
        $this->key = $masterKey;
        $this->binding = $binding;
        foreach (self::DOMAINS as $domain) {
            $path = $this->root . '/' . $domain;
            IntegrationVmwareInventoryStore::directory($path);
            $domainKey = sodium_crypto_generichash('phpipam.fast.' . $domain . '.v1', $masterKey, 32);
            try {
                $domainBinding = hash('sha256', 'phpipam.fast.binding.v1:' . $domain . ':' . $binding);
                $this->stores[$domain] = new IntegrationVmwareInventoryStore($path, $domainKey, $domainBinding, 16777216);
            } finally {
                sodium_memzero($domainKey);
            }
        }
    }

    public function read(string $domain): ?array
    {
        return $this->store($domain)->read('current');
    }

    public function publish(string $domain, array $payload): array
    {
        self::validatePayload($domain, $payload);
        $store = $this->store($domain);
        return $store->exclusive(function () use ($store, $domain, $payload): array {
            self::validatePayload($domain, $payload);
            $store->write('current', $payload);
            return ['state' => 'published', 'domain' => $domain, 'published_at' => $payload['published_at'] ?? null];
        });
    }

    /** Atomically transform one domain under that domain's writer lock. */
    public function update(string $domain, callable $callback): array
    {
        $store = $this->store($domain);
        return $store->exclusive(function () use ($store, $domain, $callback): array {
            $before = $store->read('current');
            $after = $callback($before);
            if (!is_array($after)) throw new RuntimeException('fast_store_update_invalid');
            self::validatePayload($domain, $after);
            $store->write('current', $after);
            return ['state' => 'published', 'domain' => $domain, 'published_at' => $after['published_at'] ?? null];
        });
    }

    private function store(string $domain): IntegrationVmwareInventoryStore
    {
        if (!in_array($domain, self::DOMAINS, true)) throw new InvalidArgumentException('fast_store_domain_invalid');
        return $this->stores[$domain];
    }

    private static function validatePayload(string $domain, array $payload): void
    {
        if (($payload['schema'] ?? null) !== 1) throw new RuntimeException('fast_store_payload_invalid');
        if ($domain === 'properties' || $domain === 'metrics') {
            if (($payload['domain'] ?? null) !== $domain || !is_array($payload['objects'] ?? null) ||
                ($payload['authoritative_membership'] ?? null) !== false) {
                throw new RuntimeException('fast_store_payload_invalid');
            }
        } elseif ($domain === 'capabilities') {
            if (($payload['domain'] ?? null) !== 'capabilities' || !is_array($payload['entries'] ?? null)) {
                throw new RuntimeException('fast_store_payload_invalid');
            }
        } elseif ($domain === 'roster') {
            if (($payload['domain'] ?? null) !== 'roster' || !array_key_exists('authoritative', $payload) ||
                !array_key_exists('pending', $payload)) throw new RuntimeException('fast_store_payload_invalid');
        }
    }

    public function __destruct()
    {
        if (isset($this->key)) sodium_memzero($this->key);
    }
}
