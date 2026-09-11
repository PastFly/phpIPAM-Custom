<?php
/** Server-local snapshots. The HTTP controller must authorize before construction. */
final class IntegrationVmwareSnapshotService
{
    private ?IntegrationVmwareSnapshotCache $cache;
    private array $runtime;
    private int $connector;
    private string $disabled;
    private $loader;

    public function __construct(?IntegrationVmwareSnapshotCache $cache, array $runtime, int $connector,
        string $disabled = 'not_configured', ?callable $loader = null)
    {
        $this->cache = $cache; $this->runtime = $runtime; $this->connector = $connector;
        $this->disabled = $disabled; $this->loader = $loader;
    }
    public static function capabilities(): array
    {
        $out = ['sapi' => PHP_SAPI, 'php_version' => PHP_VERSION];
        foreach (['sodium_crypto_secretbox', 'sodium_crypto_secretbox_open', 'posix_geteuid', 'posix_getegid', 'fsync', 'flock', 'curl_init'] as $fn) $out[$fn] = function_exists($fn);
        $out['dom'] = class_exists('DOMDocument');
        return $out;
    }
    public static function fromRuntime(string $application, int $connector, array $runtime): self
    {
        $key = null;
        try {
            foreach (self::capabilities() as $capability) if ($capability === false) throw new RuntimeException('web_runtime_unsupported');
            $config = json_decode(self::readPrivate('/etc/phpipam-custom/vmware-cache.json', 8192, 0, 0640, posix_getegid()), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($config) || ($config['schema'] ?? null) !== 1 || ($config['enabled'] ?? null) !== true ||
                ($config['application'] ?? null) !== realpath($application) ||
                ($config['uid'] ?? null) !== posix_geteuid() || ($config['gid'] ?? null) !== posix_getegid() ||
                ($config['root'] ?? null) !== '/var/cache/phpipam-custom/vmware' ||
                ($config['key'] ?? null) !== '/etc/phpipam-custom/vmware-cache.key') throw new RuntimeException('cache_configuration_unavailable');
            $key = self::readPrivate($config['key'], 32, 0, 0640, posix_getegid());
            if (strlen($key) !== 32 || $connector < 1) throw new RuntimeException('cache_configuration_unavailable');
            $root = $config['root']; self::directory($root, posix_geteuid());
            $dir = $root . '/connector-' . $connector;
            clearstatcache(true, $dir);
            if (@lstat($dir) === false) {
                $space = disk_free_space($root);
                if ($space === false || $space < 268435456) throw new RuntimeException('cache_space_low');
                $items = scandir($root);
                if ($items === false || count($items) > 66) throw new RuntimeException('cache_connector_limit');
                $mask = umask(0077);
                try { if (!@mkdir($dir, 0700) && !is_dir($dir)) throw new RuntimeException('cache_directory_unavailable'); }
                finally { umask($mask); }
            }
            self::directory($dir, posix_geteuid());
            $binding = IntegrationVmwareSnapshotCache::binding($key, (string)realpath($application), $connector,
                $runtime['endpoint'], $runtime['username'], $runtime['password'], (bool)$runtime['verify_tls'], 'phase5f34.adapters.v2');
            return new self(new IntegrationVmwareSnapshotCache($dir, $key, $binding), $runtime, $connector);
        } catch (Throwable $error) {
            // Never expose an old snapshot when local configuration is invalid. Authorized live reads remain available.
            $reason = in_array($error->getMessage(), ['web_runtime_unsupported', 'cache_connector_limit', 'cache_space_low'], true)
                ? $error->getMessage() : 'cache_configuration_unavailable';
            return new self(null, $runtime, $connector, $reason);
        } finally { if (is_string($key) && function_exists('sodium_memzero')) sodium_memzero($key); }
    }
    private static function noLinks(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || in_array('..', explode('/', $path), true)) throw new RuntimeException('cache_path_invalid');
        for ($p = $path; $p !== '/'; $p = dirname($p)) if (is_link($p)) throw new RuntimeException('cache_path_invalid');
    }
    public static function directory(string $path, int $uid): void
    {
        self::noLinks($path); clearstatcache(true, $path); $s = @lstat($path);
        if (!$s || ($s['mode'] & 0170000) !== 0040000 || $s['uid'] !== $uid || ($s['mode'] & 0777) !== 0700) throw new RuntimeException('cache_directory_unavailable');
    }
    public static function readPrivate(string $path, int $maxBytes, int $owner, int $mode, ?int $group = null): string
    {
        self::noLinks($path); clearstatcache(true, $path); $before = @lstat($path);
        if (!$before || ($before['mode'] & 0170000) !== 0100000 || $before['uid'] !== $owner ||
            ($group !== null && $before['gid'] !== $group) || ($before['mode'] & 0777) !== $mode ||
            $before['nlink'] !== 1 || $before['size'] > $maxBytes) throw new RuntimeException('cache_private_file_unavailable');
        $f = @fopen($path, 'rb');
        if ($f === false) throw new RuntimeException('cache_private_file_unavailable');
        try {
            $stat = fstat($f);
            if ($stat['ino'] !== $before['ino'] || $stat['dev'] !== $before['dev'] || $stat['uid'] !== $owner ||
                ($stat['mode'] & 0777) !== $mode || $stat['nlink'] !== 1 ||
                ($group !== null && $stat['gid'] !== $group)) throw new RuntimeException('cache_private_file_changed');
            $value = stream_get_contents($f, $maxBytes + 1);
            if (!is_string($value) || strlen($value) > $maxBytes) throw new RuntimeException('cache_private_file_unavailable');
            return $value;
        } finally { fclose($f); }
    }
    public function enabled(): bool { return $this->cache !== null; }
    public function status(): array
    {
        $out = ['ok' => true, 'cache_enabled' => $this->enabled(), 'capabilities' => self::capabilities(),
            'connector_id' => $this->connector, 'inventory_db_written' => false, 'domains' => []];
        if ($this->cache === null) $out['disabled_reason'] = $this->disabled;
        else foreach (array_keys(IntegrationVmwareSnapshotCache::POLICY) as $domain) $out['domains'][$domain] = $this->cache->inspect($domain);
        return $out;
    }
    public function read(string $domain, bool $refresh = false, bool $force = false): array
    {
        if (!isset(IntegrationVmwareSnapshotCache::POLICY[$domain])) throw new InvalidArgumentException('snapshot_domain_invalid');
        $candidate = null;
        $load = function () use ($domain, &$candidate): array {
            try { $data = $this->loader !== null ? ($this->loader)($domain) : $this->load($domain); }
            catch (Throwable $error) {
                if ($error instanceof IntegrationVmwareSnapshotAccessDenied || self::denied($error)) throw new IntegrationVmwareSnapshotAccessDenied('provider_access_denied');
                throw $error;
            }
            $candidate = self::publicPayload($data);
            return $candidate;
        };
        if ($this->cache === null) {
            if ($refresh) throw new RuntimeException('snapshot_disabled');
            return ['payload' => $load(), 'cache' => ['domain' => $domain, 'state' => 'disabled',
                'disabled_reason' => $this->disabled, 'cache_snapshot_written' => false, 'inventory_db_written' => false]];
        }
        try { return $refresh || $force ? $this->cache->refresh($domain, $load, $force) : $this->cache->get($domain, $load); }
        catch (RuntimeException $error) {
            // Only a current, bounded PARTIAL graph may fall back. Never return a malformed or oversized response.
            if ($candidate !== null && ($candidate['ok'] ?? false) === true && ($candidate['partial'] ?? false) === true &&
                $error->getMessage() === 'snapshot_refresh_failed' && strlen(json_encode($candidate, JSON_THROW_ON_ERROR)) < 8384512) {
                return ['payload' => $candidate, 'cache' => ['domain' => $domain, 'state' => 'incomplete_uncached',
                    'retry_after_seconds' => 30, 'cache_snapshot_written' => false, 'inventory_db_written' => false]];
            }
            throw $error;
        }
    }
    public static function denied(Throwable $error): bool
    {
        return (bool)preg_match('/(?:http[ _]*(?:401|403)|authentication|authorization|notauthenticated|nopermission|forbidden)/i', $error->getMessage());
    }
    public static function publicPayload(array $data): array
    {
        $walk = function ($value, int $depth = 0) use (&$walk) {
            if ($depth > 48 || is_object($value) || is_resource($value)) throw new RuntimeException('snapshot_payload_shape');
            if (!is_array($value)) return $value;
            $out = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/^(endpoint|username|password|passwd|credentials?|session(_id|_token)?|headers?|cookies?|set_cookie|authorization|proxy_authorization|access_token|refresh_token|api_key|secret|private_key|auth_http_status)$/iD', $key)) continue;
                $out[$key] = $walk($child, $depth + 1);
            }
            return $out;
        };
        return $walk($data);
    }
    private function load(string $domain): array
    {
        $r = $this->runtime;
        if ($domain === 'core') {
            $transport = new IntegrationVmwareVcenterDirectRestTransport(8388608);
            $topology = (new IntegrationVmwareVcenterTopology($transport, 8, 30, 8388608, 10000, 800))
                ->read_topology($r['endpoint'], $r['username'], $r['password'], (bool)$r['verify_tls']);
            $datastores = (new IntegrationVmwareVcenterDatastore($transport))->read_inventory($r['endpoint'], $r['username'],
                $r['password'], array_column($topology['datacenters'], 'external_id'), (bool)$r['verify_tls']);
            (new IntegrationVmwareVcenterExplorer(100))->build($topology, $datastores);
            return ['ok' => true, 'topology' => $topology, 'datastores' => $datastores];
        }
        if ($domain === 'host_folders') {
            $data = (new IntegrationVmwareVcenterFolderInventory())->read_inventory($r['endpoint'], $r['username'], $r['password'], (bool)$r['verify_tls']);
            $data['partial'] = !empty($data['host_multi_folder_matches']) || !empty($data['cluster_multi_folder_matches']);
            return $data;
        }
        if ($domain === 'object_folders') {
            $direct = new IntegrationVmwareVcenterDirectTransport(4194304);
            $checked = static function (...$args) use ($direct) {
                $response = $direct(...$args); $body = (string)($response['body'] ?? '');
                if (in_array((int)($response['status'] ?? 0), [401, 403], true) ||
                    preg_match('/<(?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?\b/i', $body) ||
                    preg_match('/xsi:type=["\'](?:[A-Za-z0-9_]+:)?(?:NotAuthenticated|NoPermission)(?:Fault)?["\']/i', $body)) throw new IntegrationVmwareSnapshotAccessDenied('folder_access_denied');
                return $response;
            };
            return (new IntegrationVmwareVcenterObjectFolders($checked))->read_inventory($r['endpoint'], $r['username'], $r['password'], (bool)$r['verify_tls']);
        }
        return phase5f34_build_search($r, $this->connector);
    }
    public function __destruct()
    {
        if (isset($this->runtime['password']) && is_string($this->runtime['password']) && function_exists('sodium_memzero')) sodium_memzero($this->runtime['password']);
    }
}
