<?php
/** Durable encrypted inventory. This class has no transport or collection fallback. */
final class IntegrationVmwareInventoryStore
{
    private const MAGIC = "IPAM-MIRROR-1\n";
    private string $key;
    private string $binding;
    private string $root;
    private int $limit;
    private bool $writer = false;

    public function __construct(string $root, string $key, string $binding, int $limit = 33554432)
    {
        if (strlen($key) !== 32 || !preg_match('/^[a-f0-9]{64}$/D', $binding)) {
            throw new InvalidArgumentException('mirror_identity_invalid');
        }
        $this->root = rtrim($root, '/');
        self::directory($this->root);
        if ($limit < 65536 || $limit > 67108864) throw new InvalidArgumentException('mirror_limit_invalid');
        // A separate cryptographic domain; never reuse the old cache's ciphertext format.
        $this->key = sodium_crypto_generichash('phpipam.inventory.v1.encryption', $key, 32);
        $this->binding = $binding;
        $this->limit = $limit;
    }

    public static function binding(string $key, string $application, array $row): string
    {
        if (strlen($key) !== 32 || ($row['provider'] ?? '') !== 'vmware_vcenter' ||
            !in_array($row['enabled'] ?? null, [1, '1', true], true) ||
            !in_array($row['verify_tls'] ?? null, [1, '1', true], true) ||
            !is_string($row['secret_envelope'] ?? null) || $row['secret_envelope'] === '') {
            throw new RuntimeException('mirror_connector_unavailable');
        }
        foreach (['id', 'endpoint', 'username'] as $name) {
            if (!isset($row[$name]) || !is_scalar($row[$name]) || (string)$row[$name] === '') {
                throw new RuntimeException('mirror_connector_unavailable');
            }
        }
        // Bind to encrypted credential identity, not updated_at (Test changes that field).
        return hash_hmac('sha256', json_encode(['inventory.v1', $application, (string)$row['id'],
            $row['provider'], $row['endpoint'], $row['username'], true, $row['secret_envelope'],
            $row['secret_key_id'] ?? ''], JSON_THROW_ON_ERROR), $key);
    }

    public static function noLinks(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") ||
            in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            throw new RuntimeException('mirror_path_invalid');
        }
        for ($p = $path; $p !== '/'; $p = dirname($p)) {
            clearstatcache(true, $p);
            if (is_link($p)) throw new RuntimeException('mirror_path_invalid');
        }
    }

    public static function directory(string $path): void
    {
        self::noLinks($path);
        $s = @lstat($path);
        if (!$s || ($s['mode'] & 0170000) !== 0040000 || $s['uid'] !== posix_geteuid() ||
            ($s['mode'] & 0777) !== 0700) throw new RuntimeException('mirror_directory_unavailable');
    }

    /** Only workers create a lock. Normal readers never create even an empty file. */
    public function exclusive(callable $callback)
    {
        if ($this->writer) throw new LogicException('mirror_recursive_writer');
        self::directory($this->root);
        $path = $this->root . '/worker.lock';
        self::noLinks($path);
        $mask = umask(0077);
        try {
            $f = @fopen($path, 'x+b');
            if ($f === false) {
                $this->fileStat($path, 0);
                $f = @fopen($path, 'r+b');
            }
        } finally { umask($mask); }
        if ($f === false) throw new RuntimeException('mirror_lock_unavailable');
        try {
            $before = $this->fileStat($path, 0);
            $after = fstat($f);
            if (!$after || $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev']) {
                throw new RuntimeException('mirror_lock_changed');
            }
            if (!flock($f, LOCK_EX | LOCK_NB)) return ['state' => 'busy', 'work_done' => 0];
            $this->writer = true;
            try {
                // The writer lock proves that these interrupted atomic writes are not active.
                foreach (glob($this->root . '/.write-*') ?: [] as $orphan) {
                    if (!preg_match('/^\.write-[a-f0-9]{24}$/D', basename($orphan))) continue;
                    $this->fileStat($orphan, $this->limit + 128);
                    if (!unlink($orphan)) throw new RuntimeException('mirror_cleanup_failed');
                }
                return $callback();
            }
            finally { $this->writer = false; flock($f, LOCK_UN); }
        } finally { fclose($f); }
    }

    private function fileStat(string $path, int $max): array
    {
        self::noLinks($path);
        $s = @lstat($path);
        if (!$s || ($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== posix_geteuid() ||
            ($s['mode'] & 0777) !== 0600 || $s['nlink'] !== 1 || $s['size'] > $max) {
            throw new RuntimeException('mirror_file_invalid');
        }
        return $s;
    }

    public function read(string $slot): ?array
    {
        $path = $this->path($slot);
        self::directory($this->root);
        self::noLinks($path);
        if (@lstat($path) === false) return null;
        $before = $this->fileStat($path, $this->limit + 128);
        $f = @fopen($path, 'rb');
        if ($f === false) throw new RuntimeException('mirror_file_unavailable');
        try {
            $after = fstat($f);
            if (!$after || $before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev']) {
                // An atomic publication raced with open; fail closed, never read a partial file.
                throw new RuntimeException('mirror_read_raced');
            }
            $wire = stream_get_contents($f, $this->limit + 129);
        } finally { fclose($f); }
        if (!is_string($wire) || strlen($wire) > $this->limit + 128 ||
            !str_starts_with($wire, self::MAGIC) || strlen($wire) < strlen(self::MAGIC) + 40) {
            throw new RuntimeException('mirror_ciphertext_invalid');
        }
        $wire = substr($wire, strlen(self::MAGIC));
        $plain = sodium_crypto_secretbox_open(substr($wire, 24), substr($wire, 0, 24), $this->key);
        if ($plain === false) throw new RuntimeException('mirror_ciphertext_invalid');
        try { $value = json_decode($plain, true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new RuntimeException('mirror_ciphertext_invalid'); }
        finally { sodium_memzero($plain); }
        if (!is_array($value) || ($value['schema'] ?? null) !== 1 ||
            ($value['slot'] ?? null) !== $slot || !is_array($value['payload'] ?? null) ||
            !is_string($value['binding'] ?? null)) throw new RuntimeException('mirror_envelope_invalid');
        if (!hash_equals($this->binding, $value['binding'])) return null;
        return $value['payload'];
    }

    public function write(string $slot, array $payload): void
    {
        if (!$this->writer) throw new LogicException('mirror_worker_lock_required');
        self::directory($this->root);
        $target = $this->path($slot);
        self::noLinks($target);
        if (@lstat($target) !== false) $this->fileStat($target, $this->limit + 128);
        $plain = json_encode(['schema' => 1, 'slot' => $slot, 'binding' => $this->binding,
            'payload' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            if (strlen($plain) > $this->limit) throw new RuntimeException('mirror_size_limit');
            $free = @disk_free_space($this->root);
            if ($free === false || $free < 268435456 + strlen($plain) * 2) throw new RuntimeException('mirror_space_low');
            $nonce = random_bytes(24);
            $wire = self::MAGIC . $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
        } finally { sodium_memzero($plain); }
        $tmp = $this->root . '/.write-' . bin2hex(random_bytes(12));
        $mask = umask(0077);
        try { $f = @fopen($tmp, 'x+b'); }
        finally { umask($mask); }
        if ($f === false) throw new RuntimeException('mirror_write_failed');
        try {
            $offset = 0;
            while ($offset < strlen($wire)) {
                $n = fwrite($f, substr($wire, $offset));
                if ($n === false || $n === 0) throw new RuntimeException('mirror_write_failed');
                $offset += $n;
            }
            if (!fflush($f) || !fsync($f)) throw new RuntimeException('mirror_sync_failed');
            fclose($f); $f = null;
            self::noLinks($target);
            if (!rename($tmp, $target)) throw new RuntimeException('mirror_publish_failed');
            // Persist the rename on supported Unix filesystems, including the target ext4 host.
            $directory = @fopen($this->root, 'r');
            if ($directory === false) throw new RuntimeException('mirror_directory_sync_failed');
            try { if (!@fsync($directory)) throw new RuntimeException('mirror_directory_sync_failed'); }
            finally { fclose($directory); }
        } finally {
            if (is_resource($f)) fclose($f);
            if (file_exists($tmp)) @unlink($tmp);
        }
    }

    public function discardPending(): void
    {
        if (!$this->writer) throw new LogicException('mirror_worker_lock_required');
        $path = $this->path('pending');
        if (@lstat($path) !== false) { $this->fileStat($path, $this->limit + 128); if (!unlink($path)) throw new RuntimeException('mirror_remove_failed'); }
    }

    private function path(string $slot): string
    {
        if (!in_array($slot, ['current', 'pending'], true)) throw new InvalidArgumentException('mirror_slot_invalid');
        return $this->root . '/' . $slot . '.enc';
    }

    public function __destruct() { sodium_memzero($this->key); }
}
