<?php
/**
 * Phase 5F.3.4: isolated server snapshot engine, NOT activated in any endpoint yet.
 * The caller must validate session, admin, CSRF and enabled connector BEFORE use.
 * Approved installer must provision a private local directory and a separate key.
 * No automatic scheduler, directory creation, vCenter calls, or credential storage.
 */
final class IntegrationVmwareSnapshotAccessDenied extends RuntimeException {}

final class IntegrationVmwareSnapshotCache
{
    public const POLICY = [
        'core' => [60, 600],
        'host_folders' => [300, 1800],
        'object_folders' => [300, 1800], // VM + datastore graph is one coherent read.
        'search' => [300, 1800],
    ];
    private const MAX_BYTES = 8388608;
    private const BACKOFF = 30;
    private const FORCE_FLOOR = 5;
    private string $root;
    private string $key;
    private string $binding;
    private int $uid;
    private $clock;

    public function __construct(string $root, string $masterKey, string $binding, ?callable $clock = null)
    {
        if (!function_exists('sodium_crypto_secretbox') || !function_exists('posix_geteuid')) {
            throw new RuntimeException('snapshot_dependencies_missing');
        }
        if (strlen($masterKey) !== 32 || !preg_match('/^[a-f0-9]{64}$/D', $binding)) {
            throw new InvalidArgumentException('snapshot_key_or_binding_invalid');
        }
        $this->root = rtrim($root, '/');
        $this->uid = posix_geteuid();
        $this->assertRoot();
        $this->key = hash_hmac('sha256', 'phpipam.snapshot.encryption.v1', $masterKey, true);
        $this->binding = $binding;
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** All configuration inputs stay in memory; fingerprint is keyed, not a password hash. */
    public static function binding(string $key, string $application, int $connector, string $endpoint,
        string $username, string $password, bool $verifyTls, string $revision = ''): string
    {
        if (strlen($key) !== 32 || $connector < 1 || $application === '' || $username === '' || $password === '') {
            throw new InvalidArgumentException('snapshot_identity_invalid');
        }
        $identity = json_encode(['v1', $application, $connector, $endpoint, $username, $password, $verifyTls, $revision], JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $identity, hash_hmac('sha256', 'phpipam.snapshot.identity.v1', $key, true));
    }

    /** Fast path: soft-stale data is returned without running the loader. */
    public function get(string $domain, callable $loader): array
    {
        $this->domain($domain);
        $blocked = $this->readEnvelope('blocked.bin', 'blocked');
        $entry = $blocked === null ? $this->snapshot($domain) : null;
        if ($entry !== null) return $this->result($entry, $domain, false);
        return $this->refresh($domain, $loader);
    }

    /** Explicit request-driven revalidation. Does not launch background processes. */
    public function refresh(string $domain, callable $loader, bool $force = false): array
    {
        $this->domain($domain);
        $blocked = $this->readEnvelope('blocked.bin', 'blocked');
        $entry = $blocked === null ? $this->snapshot($domain) : null;
        if ($entry !== null && ($entry['created'] + self::FORCE_FLOOR > $this->now()
            || (!$force && $entry['created'] + self::POLICY[$domain][0] > $this->now()))) {
            return $this->result($entry, $domain, false);
        }
        if ($this->retryAfter($domain, $blocked) > 0) {
            if ($entry !== null) return $this->result($entry, $domain, false, 'backoff');
            throw new RuntimeException($blocked !== null ? 'snapshot_access_denied' : 'snapshot_backoff');
        }
        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            // A concurrent authorization failure must not be hidden by an earlier read.
            if ($this->readEnvelope('blocked.bin', 'blocked') === null) $entry = $this->snapshot($domain);
            else $entry = null;
            if ($entry !== null) return $this->result($entry, $domain, false, 'refreshing');
            throw new RuntimeException('snapshot_refresh_busy');
        }
        try {
            // Double-check after obtaining the connector-wide lock.
            $blocked = $this->readEnvelope('blocked.bin', 'blocked');
            $entry = $blocked === null ? $this->snapshot($domain) : null;
            if ($entry !== null && ($entry['created'] + self::FORCE_FLOOR > $this->now()
                || (!$force && $entry['created'] + self::POLICY[$domain][0] > $this->now()))) {
                return $this->result($entry, $domain, false);
            }
            if ($this->retryAfter($domain, $blocked) > 0) {
                if ($entry !== null) return $this->result($entry, $domain, false, 'backoff');
                throw new RuntimeException($blocked !== null ? 'snapshot_access_denied' : 'snapshot_backoff');
            }
            $start = $this->now();
            try {
                $payload = $loader();
                $this->validatePayload($payload);
            } catch (IntegrationVmwareSnapshotAccessDenied $error) {
                // Deny all domains as soon as a provider observes an authentication/authorization failure.
                // Persist the denial marker before removing old snapshots. No raw exception is stored.
                try { $this->writeEnvelope('blocked.bin', 'blocked', ['retry_at' => $this->now() + self::BACKOFF]); }
                finally { foreach (array_keys(self::POLICY) as $name) $this->remove($name . '.bin'); }
                throw new RuntimeException('snapshot_access_denied');
            } catch (Throwable $error) {
                try { $this->writeEnvelope($domain . '.retry', 'retry:' . $domain, ['retry_at' => $this->now() + self::BACKOFF]); }
                catch (Throwable $ignored) {}
                // Old data survives, but never past the hard TTL, even after a slow failed request.
                $entry = $blocked === null ? $this->snapshot($domain) : null;
                if ($entry !== null) return $this->result($entry, $domain, false, 'refresh_failed');
                throw new RuntimeException('snapshot_refresh_failed');
            }
            if ($this->now() < $start || $this->now() - $start > 120) {
                throw new RuntimeException('snapshot_refresh_deadline_exceeded');
            }
            $new = ['created' => $this->now(), 'payload' => $payload];
            $written = false;
            try {
                // A successful reauthentication invalidates ALL old domains, not just this one.
                if ($blocked !== null) foreach (array_keys(self::POLICY) as $name) $this->remove($name . '.bin');
                $this->writeEnvelope($domain . '.bin', 'data:' . $domain, $new);
                $this->remove($domain . '.retry');
                if ($blocked !== null) $this->remove('blocked.bin');
                $written = true;
            } catch (Throwable $ignored) {
                // The fresh read can still be used; write failure is explicit, never reported as a cache hit.
            }
            return $this->result($new, $domain, $written, $written ? 'refreshed' : 'uncached');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function inspect(string $domain): array
    {
        $this->domain($domain);
        $blocked = $this->readEnvelope('blocked.bin', 'blocked');
        $entry = $blocked === null ? $this->snapshot($domain) : null;
        return ['state' => $blocked !== null ? 'denied' : ($entry === null ? 'miss' : ($this->now() < $entry['created'] + self::POLICY[$domain][0] ? 'fresh' : 'stale')),
            'age_seconds' => $entry === null ? null : max(0, $this->now() - $entry['created']),
            'retry_after_seconds' => $this->retryAfter($domain, $blocked)];
    }

    private function result(array $entry, string $domain, bool $written, ?string $state = null): array
    {
        $age = max(0, $this->now() - $entry['created']);
        $stale = $age >= self::POLICY[$domain][0];
        return ['payload' => $entry['payload'], 'cache' => [
            'domain' => $domain, 'state' => $state ?? ($stale ? 'stale' : 'fresh'),
            'created_at' => $entry['created'], 'age_seconds' => $age,
            'soft_ttl_seconds' => self::POLICY[$domain][0], 'hard_ttl_seconds' => self::POLICY[$domain][1],
            'refresh_required' => $stale && $this->retryAfter($domain, null) === 0,
            'retry_after_seconds' => $this->retryAfter($domain, null),
            'cache_snapshot_written' => $written, 'inventory_db_written' => false,
        ]];
    }

    private function snapshot(string $domain): ?array
    {
        $entry = $this->readEnvelope($domain . '.bin', 'data:' . $domain);
        if ($entry === null || !isset($entry['created']) || !is_int($entry['created']) || $entry['created'] > $this->now()
            || $entry['created'] + self::POLICY[$domain][1] <= $this->now()) return null;
        try { $this->validatePayload($entry['payload'] ?? null); } catch (Throwable $error) { return null; }
        return $entry;
    }

    private function retryAfter(string $domain, ?array $blocked): int
    {
        $entry = $blocked ?? $this->readEnvelope($domain . '.retry', 'retry:' . $domain);
        $retry = $entry['retry_at'] ?? null;
        // Bounds tolerate clock corrections without creating unbounded denial/backoff.
        return is_int($retry) && $retry <= $this->now() + self::BACKOFF ? max(0, $retry - $this->now()) : 0;
    }

    private function domain(string $domain): void
    {
        if (!array_key_exists($domain, self::POLICY)) throw new InvalidArgumentException('snapshot_domain_invalid');
    }
    private function now(): int { return (int)call_user_func($this->clock); }

    private function validatePayload($data): void
    {
        if (!is_array($data) || ($data['ok'] ?? null) !== true || !empty($data['partial']) || isset($data['error'])) {
            throw new RuntimeException('snapshot_payload_incomplete');
        }
        $walk = function ($value, int $depth = 0) use (&$walk): void {
            if ($depth > 48 || is_object($value) || is_resource($value)) throw new RuntimeException('snapshot_payload_shape');
            if (is_array($value)) foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/^(password|passwd|username|endpoint|credentials?|authorization|proxy_authorization|cookies?|set_cookie|session(_id|_token)?|headers?|access_token|refresh_token|api_key|secret|private_key)$/iD', $key)) {
                    throw new RuntimeException('snapshot_sensitive_field');
                }
                $walk($child, $depth + 1);
            }
        };
        $walk($data);
        // Free-text Notes can themselves be sensitive; encryption is mandatory, not a content classifier.
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > self::MAX_BYTES - 4096) throw new RuntimeException('snapshot_payload_too_large');
    }

    private function assertRoot(): void
    {
        if ($this->root === '' || $this->root === '/' || $this->root[0] !== '/' || realpath($this->root) !== $this->root) {
            throw new RuntimeException('snapshot_private_root_required');
        }
        for ($p = $this->root; $p !== '/'; $p = dirname($p)) if (is_link($p)) throw new RuntimeException('snapshot_symlink_rejected');
        clearstatcache(true, $this->root);
        $s = lstat($this->root);
        if ($s === false || ($s['mode'] & 0170000) !== 0040000 || $s['uid'] !== $this->uid || ($s['mode'] & 0077) !== 0) {
            throw new RuntimeException('snapshot_private_root_required');
        }
    }

    /** Existing files must be private, regular, single-link and owned by this runtime UID. */
    private function statFile(string $name): ?array
    {
        $this->assertRoot();
        $path = $this->root . '/' . $name;
        clearstatcache(true, $path);
        $s = @lstat($path);
        if ($s === false) return null;
        if (($s['mode'] & 0170000) !== 0100000 || $s['uid'] !== $this->uid || ($s['mode'] & 0077) !== 0 || $s['nlink'] !== 1) {
            throw new RuntimeException('snapshot_unsafe_file');
        }
        return $s;
    }

    private function openLock()
    {
        $name = 'refresh.lock'; $path = $this->root . '/' . $name;
        $before = $this->statFile($name);
        if ($before === null) {
            $oldMask = umask(0077);
            try { $f = @fopen($path, 'x+b'); } finally { umask($oldMask); }
            if ($f === false) {
                $before = $this->statFile($name);
                if ($before === null) throw new RuntimeException('snapshot_lock_unavailable');
                $f = @fopen($path, 'r+b');
            }
        } else $f = @fopen($path, 'r+b');
        if ($f === false) throw new RuntimeException('snapshot_lock_unavailable');
        $s = fstat($f); $after = $this->statFile($name);
        if ($after === null || $s['ino'] !== $after['ino'] || $s['dev'] !== $after['dev']) {
            fclose($f); throw new RuntimeException('snapshot_lock_changed');
        }
        return $f;
    }

    private function readEnvelope(string $name, string $purpose): ?array
    {
        $before = $this->statFile($name);
        if ($before === null) return null;
        if ($before['size'] < 41 || $before['size'] > self::MAX_BYTES + 4096) return $this->corrupt($purpose);
        $f = @fopen($this->root . '/' . $name, 'rb');
        if ($f === false) throw new RuntimeException('snapshot_read_failed');
        try {
            $stat = fstat($f);
            if ($stat['ino'] !== $before['ino'] || $stat['dev'] !== $before['dev']) return $this->corrupt($purpose);
            $raw = stream_get_contents($f, self::MAX_BYTES + 4097);
        } finally { fclose($f); }
        if (!is_string($raw) || strlen($raw) < 41 || strlen($raw) > self::MAX_BYTES + 4096) return $this->corrupt($purpose);
        $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $this->key);
        if ($plain === false) return $this->corrupt($purpose);
        try { $box = json_decode($plain, true, 64, JSON_THROW_ON_ERROR); } catch (Throwable $error) { return $this->corrupt($purpose); }
        if (!is_array($box) || ($box['version'] ?? null) !== 1 || ($box['binding'] ?? null) !== $this->binding
            || ($box['purpose'] ?? null) !== $purpose || !is_array($box['data'] ?? null)) return null;
        return $box['data'];
    }

    private function corrupt(string $purpose): ?array
    {
        if ($purpose === 'blocked' || strpos($purpose, 'retry:') === 0) throw new RuntimeException('snapshot_control_corrupt');
        return null;
    }

    private function writeEnvelope(string $name, string $purpose, array $data): void
    {
        $this->statFile($name);
        $plain = json_encode(['version' => 1, 'binding' => $this->binding, 'purpose' => $purpose, 'data' => $data], JSON_THROW_ON_ERROR);
        if (strlen($plain) > self::MAX_BYTES) throw new RuntimeException('snapshot_size_limit');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $raw = $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
        $temp = 'tmp-' . bin2hex(random_bytes(16));
        $oldMask = umask(0077);
        try { $f = @fopen($this->root . '/' . $temp, 'x+b'); } finally { umask($oldMask); }
        if ($f === false) throw new RuntimeException('snapshot_write_failed');
        try {
            $offset = 0;
            while ($offset < strlen($raw)) {
                $n = fwrite($f, substr($raw, $offset));
                if ($n === false || $n === 0) throw new RuntimeException('snapshot_write_failed');
                $offset += $n;
            }
            if (!fflush($f) || !fsync($f)) throw new RuntimeException('snapshot_write_failed');
            $this->statFile($name);
            if (!@rename($this->root . '/' . $temp, $this->root . '/' . $name)) throw new RuntimeException('snapshot_replace_failed');
        } finally {
            fclose($f);
            if (file_exists($this->root . '/' . $temp)) @unlink($this->root . '/' . $temp);
        }
    }

    private function remove(string $name): void
    {
        if ($this->statFile($name) !== null && !@unlink($this->root . '/' . $name)) throw new RuntimeException('snapshot_remove_failed');
    }

    public function __destruct() { if (isset($this->key)) sodium_memzero($this->key); }
}
