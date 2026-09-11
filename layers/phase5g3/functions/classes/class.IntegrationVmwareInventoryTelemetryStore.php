<?php
/** Encrypted, bounded metric shards. No transport; reads never create paths or locks. */
final class IntegrationVmwareInventoryTelemetryStore
{
    private string $root;
    private string $key;
    private string $binding;
    private IntegrationVmwareInventoryStore $state;
    private bool $writing = false;
    public function __construct(string $root, string $key, string $binding)
    {
        IntegrationVmwareInventoryStore::directory($root);
        $this->root = $root;
        $this->key = sodium_crypto_generichash('phpipam.inventory.telemetry.v1', $key, 32);
        $this->binding = $binding;
        $this->state = new IntegrationVmwareInventoryStore($root, $this->key, $binding, 1048576);
    }
    private static function id(string $id): void
    {
        if (!preg_match('/^(vm|host)-[0-9]{1,20}$/D', $id)) throw new InvalidArgumentException('mirror_metric_id_invalid');
    }
    public function exclusive(callable $callback): array
    {
        return $this->state->exclusive(function () use ($callback): array {
            $this->writing = true;
            try { return $callback(); } finally { $this->writing = false; }
        });
    }
    public function state(): array { return $this->state->read('current') ?? []; }
    public function saveState(array $state): void { $this->state->write('current', $state); }
    private function shard(string $id, bool $create): ?IntegrationVmwareInventoryStore
    {
        self::id($id); $path = $this->root . '/' . $id;
        IntegrationVmwareInventoryStore::noLinks($path);
        if (@lstat($path) === false) {
            if (!$create) return null;
            if (!$this->writing || !mkdir($path, 0700)) throw new RuntimeException('mirror_metric_directory_failed');
        }
        return new IntegrationVmwareInventoryStore($path, $this->key,
            hash_hmac('sha256', 'metric:' . $id . ':' . $this->binding, $this->key), 262144);
    }
    public function read(string $id, string $epoch): array
    {
        $value = $this->shard($id, false)?->read('current');
        return ($value['access_epoch'] ?? '') === $epoch ? ($value['sections'] ?? []) : [];
    }
    public function write(string $id, string $epoch, array $sections): void
    {
        if (!$this->writing) throw new LogicException('mirror_worker_lock_required');
        $store = $this->shard($id, true);
        $result = $store->exclusive(function () use ($store, $epoch, $sections): array {
            $store->write('current', ['access_epoch' => $epoch, 'sections' => $sections]); return ['state' => 'written'];
        });
        if ($result['state'] !== 'written') throw new RuntimeException('mirror_metric_busy');
    }
    /** Only a completed inventory's membership may retire metric shards. Never remove foreign paths. */
    public function prune(array $ids): void
    {
        if (!$this->writing) throw new LogicException('mirror_worker_lock_required');
        if (count($ids) > 2000) throw new RuntimeException('mirror_metric_size_limit');
        foreach ($ids as $id) self::id($id);
        $keep = array_fill_keys($ids, true); $entries = scandir($this->root);
        if ($entries === false || count($entries) > 4010) throw new RuntimeException('mirror_metric_size_limit');
        foreach ($entries as $id) {
            if (!preg_match('/^(vm|host)-[0-9]{1,20}$/D', $id) || isset($keep[$id])) continue;
            $path = $this->root . '/' . $id; IntegrationVmwareInventoryStore::directory($path);
            $cleanup = $this->shard($id, false)->exclusive(static fn(): array => ['state' => 'cleaned']);
            if (($cleanup['state'] ?? '') !== 'cleaned') throw new RuntimeException('mirror_metric_busy');
            $files = array_values(array_diff(scandir($path), ['.', '..']));
            foreach ($files as $file) {
                if (!in_array($file, ['current.enc', 'worker.lock'], true)) throw new RuntimeException('mirror_metric_foreign_file');
                $p = $path . '/' . $file; IntegrationVmwareInventoryStore::noLinks($p); $s = lstat($p);
                if (!$s || ($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== posix_geteuid() ||
                    ($s['mode'] & 0777) !== 0600 || $s['nlink'] !== 1) throw new RuntimeException('mirror_metric_foreign_file');
            }
            foreach ($files as $file) if (!unlink($path . '/' . $file)) throw new RuntimeException('mirror_metric_cleanup_failed');
            if (!rmdir($path)) throw new RuntimeException('mirror_metric_cleanup_failed');
        }
    }
    public static function summary(array $state): array
    {
        return array_intersect_key($state, array_flip(['state', 'completed', 'total', 'heartbeat_at',
            'cycle_started_at', 'cycle_completed_at', 'last_error', 'last_success_at']));
    }
    public function __destruct() { if (isset($this->key)) sodium_memzero($this->key); }
}
