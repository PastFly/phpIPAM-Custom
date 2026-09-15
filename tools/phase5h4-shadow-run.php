#!/usr/bin/env php
<?php
/** One-shot Phase5H shadow benchmark. Never serves website data or changes active 5G.5 files/units. */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "SHADOW_IDENTITY_INVALID=1\n"); exit(1);
}
const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
const SHADOW_ROOT = '/var/lib/phpipam-custom/vmware-fast-shadow';
$runtime = null; $key = null;
try {
    foreach (['sodium_crypto_secretbox', 'fsync', 'posix_geteuid'] as $fn) if (!function_exists($fn)) throw new RuntimeException('capability_missing');
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService', 'IntegrationVmwareVcenter', 'IntegrationSecretCipher', 'IntegrationVmwareVcenterDirectTransport'] as $name) {
        require_once APP . '/functions/classes/class.' . $name . '.php';
    }
    foreach (['Store','Context'] as $name) require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    require_once ACTIVE . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php';
    foreach (['Session','Inventory','Performance'] as $name) require_once __DIR__ . '/../layers/phase5h/functions/classes/class.IntegrationVmwareBatch' . $name . '.php';
    require_once __DIR__ . '/../layers/phase5h2/functions/classes/class.IntegrationVmwareFastDomain.php';
    require_once __DIR__ . '/../layers/phase5h3/functions/classes/class.IntegrationVmwareFastStore.php';
    require_once __DIR__ . '/../layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
    require_once __DIR__ . '/../layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';
    require_once __DIR__ . '/../layers/phase5h4/functions/classes/class.IntegrationVmwareShadowCycle.php';

    $database = new Database_PDO();
    $context = new IntegrationVmwareInventoryContext($database, APP, 1);
    if (!$context->current()) throw new RuntimeException('binding_changed');
    $store = $context->store();
    $current = $store->read('current');
    if (!is_array($current) || !is_array($current['inventory'] ?? null) || !empty($current['blocked'])) throw new RuntimeException('active_inventory_unavailable');
    $telemetry = $context->telemetry();
    if ($telemetry === null) throw new RuntimeException('active_telemetry_unavailable');

    $rows = $database->getObjectsQuery('no_html_escape',
        'SELECT `id`,`provider`,`enabled`,`endpoint`,`username`,`verify_tls`,`secret_envelope`,`secret_key_id` FROM `custom_integrations` WHERE `id` = ? AND `provider` = ?;',
        [1, 'vmware_vcenter']);
    if (!is_array($rows) || count($rows) !== 1) throw new RuntimeException('connector_unavailable');
    $row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
    if (!is_array($row)) throw new RuntimeException('connector_unavailable');
    $key = IntegrationVmwareSnapshotService::readPrivate('/etc/phpipam-custom/vmware-cache.key', 32, 0, 0640, posix_getegid());
    $binding = IntegrationVmwareInventoryStore::binding($key, APP, $row);
    $fast = new IntegrationVmwareFastStore(SHADOW_ROOT, $key, $binding);
    $runtime = $context->workerRuntime(IntegrationSecretCipher::from_keyring_file());
    $epoch = $current['access_epoch'] ?? 'initial';
    if (!is_string($epoch) || $epoch === '') throw new RuntimeException('active_epoch_invalid');
    $telemetryRead = static fn(string $id): array => $telemetry->read($id, $epoch);
    $authorized = [$context, 'current'];

    $result = IntegrationVmwareShadowCycle::run($runtime, $current, $authorized, $telemetryRead, $fast, null, 12);
    $result['active_generation_unchanged'] = ($store->read('current')['generation'] ?? null) === ($current['generation'] ?? null);
    $result['shadow_root'] = 'isolated';
    $result['connector_id'] = 1;
    print 'PHASE5H_SHADOW_RESULT=' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    print "PHASE5H_SHADOW_EXIT=0\n";
    $exit = 0;
} catch (Throwable $error) {
    $category = match ($error->getMessage()) {
        'batch_access_denied', 'binding_changed', 'batch_binding_changed', 'shadow_binding_changed_before_publish', 'mirror_connector_unavailable' => 'access',
        'batch_budget_exhausted' => 'budget',
        'batch_transport_failed', 'batch_http_failed' => 'transport',
        default => 'operation',
    };
    fwrite(STDERR, 'PHASE5H_SHADOW_ERROR=' . $category . "\nPHASE5H_SHADOW_EXIT=1\n");
    $exit = 1;
} finally {
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
    if (is_string($key)) sodium_memzero($key);
}
exit($exit);
