<?php
/** Fixed-path root-owned reader configuration. Missing file means exact disabled fallback. */
final class IntegrationVmwareFastReaderConfigLoader
{
    public const PATH = '/etc/phpipam-custom/vmware-fast-reader.json';
    private const MAX_BYTES = 4096;

    /**
     * Optional callables exist only for deterministic contract tests. Production callers pass none.
     * $probe(string $path): bool, $read(string $path): string.
     */
    public static function load(?callable $probe = null, ?callable $read = null): IntegrationVmwareFastReaderConfig
    {
        $probe ??= static function (string $path): bool {
            IntegrationVmwareInventoryStore::noLinks($path);
            clearstatcache(true, $path);
            return @lstat($path) !== false;
        };
        $read ??= static fn(string $path): string => IntegrationVmwareSnapshotService::readPrivate(
            $path,
            self::MAX_BYTES,
            0,
            0640,
            posix_getegid()
        );

        $present = $probe(self::PATH);
        if (!is_bool($present)) throw new RuntimeException('fast_reader_configuration_invalid');
        if (!$present) return IntegrationVmwareFastReaderConfig::disabled();

        try {
            $raw = $read(self::PATH);
            if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
                throw new RuntimeException('fast_reader_configuration_invalid');
            }
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('fast_reader_configuration_invalid');
            return IntegrationVmwareFastReaderConfig::fromArray($decoded);
        } catch (JsonException|InvalidArgumentException $error) {
            // Configuration faults are server state, never a client request error.
            throw new RuntimeException('fast_reader_configuration_invalid', 0, $error);
        }
    }
}
