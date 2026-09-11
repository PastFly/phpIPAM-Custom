<?php
/** Current connector authorization/binding. Reader does not decrypt vCenter credentials. */
final class IntegrationVmwareInventoryContext
{
    private $database;
    private string $application;
    private int $id;
    private string $key;
    private string $binding;
    private array $row;
    private string $directory;
    public function __construct($database, string $application, int $id)
    {
        $this->database = $database; $this->application = $application; $this->id = $id;
        if ($application !== '/var/www/html' || realpath($application) !== $application || $id < 1 || $id > 2147483647) {
            throw new RuntimeException('mirror_configuration_invalid');
        }
        $this->row = $this->loadRow();
        // The runtime directory must have been provisioned explicitly by the reviewed deployment.
        $config = json_decode(IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-inventory.json',
            4096, 0, 0640, posix_getegid()), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($config) || ($config['schema'] ?? null) !== 1 || ($config['enabled'] ?? null) !== true ||
            ($config['application'] ?? '') !== $application || ($config['root'] ?? '') !== '/var/lib/phpipam-custom/vmware-inventory' ||
            ($config['uid'] ?? null) !== posix_geteuid() || ($config['gid'] ?? null) !== posix_getegid()) {
            throw new RuntimeException('mirror_configuration_invalid');
        }
        $this->key = IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-cache.key', 32, 0, 0640, posix_getegid());
        $this->binding = IntegrationVmwareInventoryStore::binding($this->key, $application, $this->row);
        $this->directory = $config['root'] . '/connector-' . $id;
    }
    private function loadRow(): array
    {
        $rows = $this->database->getObjectsQuery('no_html_escape',
            'SELECT `id`,`provider`,`enabled`,`endpoint`,`username`,`verify_tls`,`secret_envelope`,`secret_key_id` FROM `custom_integrations` WHERE `id` = ? AND `provider` = ?;',
            [$this->id, 'vmware_vcenter']);
        if (!is_array($rows) || count($rows) !== 1) throw new RuntimeException('mirror_connector_unavailable');
        $row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
        if (!is_array($row) || !in_array($row['enabled'] ?? null, [1, '1', true], true) ||
            !in_array($row['verify_tls'] ?? null, [1, '1', true], true) || empty($row['secret_envelope'])) {
            throw new RuntimeException('mirror_connector_unavailable');
        }
        return $row;
    }
    public function current(): bool
    {
        try { return hash_equals($this->binding, IntegrationVmwareInventoryStore::binding($this->key, $this->application, $this->loadRow())); }
        catch (Throwable $e) { return false; }
    }
    public function store(): IntegrationVmwareInventoryStore
    {
        return new IntegrationVmwareInventoryStore($this->directory, $this->key, $this->binding);
    }
    public function telemetry(): ?IntegrationVmwareInventoryTelemetryStore
    {
        $path = $this->directory . '/telemetry';
        IntegrationVmwareInventoryStore::noLinks($path);
        if (@lstat($path) === false) return null; // Provisioning is never a web-read side effect.
        return new IntegrationVmwareInventoryTelemetryStore($path, $this->key, $this->binding);
    }
    public function workerRuntime(IntegrationSecretCipher $cipher): array
    {
        if (PHP_SAPI !== 'cli' || !$this->current()) throw new RuntimeException('mirror_access_denied');
        return ['id' => $this->id, 'endpoint' => $this->row['endpoint'], 'username' => $this->row['username'],
            'password' => $cipher->decrypt($this->row['secret_envelope'], 'vmware_vcenter'), 'verify_tls' => true];
    }
    public function __destruct() { if (isset($this->key)) sodium_memzero($this->key); }
}
