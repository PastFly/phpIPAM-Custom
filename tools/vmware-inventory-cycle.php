#!/usr/bin/env php
<?php
/** Combined bounded inventory + telemetry tick. Dormant until the reviewed runtime rollout. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$help = "Usage: php tools/vmware-inventory-cycle.php --status|--tick --connector=ID\n";
$args = array_slice($argv, 1);
if ($args === ['--help']) { print $help; exit(0); }
if (count($args) !== 2 || !in_array($args[0], ['--status', '--tick'], true) ||
    !preg_match('/^--connector=([1-9][0-9]{0,8})$/D', $args[1], $match)) {
    fwrite(STDERR, $help); exit(64);
}
$mode = $args[0]; $id = (int)$match[1]; $application = '/var/www/html'; $runtime = null;
try {
    foreach (['sodium_crypto_secretbox', 'fsync', 'posix_geteuid'] as $fn) {
        if (!function_exists($fn)) throw new RuntimeException('capability_missing');
    }
    if (posix_geteuid() === 0) throw new RuntimeException('run_as_configured_unprivileged_uid');
    require_once($application . '/functions/functions.php');
    require_once($application . '/functions/classes/class.IntegrationVmwareSnapshotService.php');
    $new = dirname(__DIR__) . '/layers/phase5g/functions/classes/';
    foreach (['Store', 'Context', 'Reader', 'Worker'] as $name) require_once($new . 'class.IntegrationVmwareInventory' . $name . '.php');
    $context = new IntegrationVmwareInventoryContext(new Database_PDO(), $application, $id);
    $store = $context->store();
    $metricsDirectory = dirname(__DIR__) . '/layers/phase5g3/functions/classes/';
    foreach (['TelemetryStore', 'Sampler'] as $name) require_once($metricsDirectory . 'class.IntegrationVmwareInventory' . $name . '.php');
    $telemetry = $context->telemetry();
    if ($telemetry === null) throw new RuntimeException('telemetry_not_provisioned');
    if ($mode === '--status') {
        $result = (new IntegrationVmwareInventoryReader($store, [$context, 'current'], null, $telemetry))->query('status');
    } else {
        // A hard process deadline is distinct from the state machine's between-unit soft budget.
        if (!function_exists('pcntl_async_signals')) throw new RuntimeException('pcntl_required');
        pcntl_async_signals(true);
        $interrupted = false;
        $stop = static function () use (&$interrupted): void { $interrupted = true; throw new RuntimeException('mirror_process_deadline'); };
        pcntl_signal(SIGALRM, $stop); pcntl_signal(SIGTERM, $stop); pcntl_signal(SIGINT, $stop); pcntl_alarm(120);
        foreach (['IntegrationVmwareVcenter', 'IntegrationSecretCipher', 'IntegrationVmwareSnapshotCache',
            'IntegrationVmwareVcenterDirectRestTransport', 'IntegrationVmwareVcenterDirectTransport',
            'IntegrationVmwareVcenterFaultAwareTransport', 'IntegrationVmwareVcenterTopology',
            'IntegrationVmwareVcenterDatastore', 'IntegrationVmwareVcenterDatastoreResolver',
            'IntegrationVmwareVcenterExplorer', 'IntegrationVmwareVcenterVmDetail', 'IntegrationVmwareVcenterGuestNetwork',
            'IntegrationVmwareVcenterFolderInventory', 'IntegrationVmwareVcenterObjectFolders',
            'IntegrationVmwareVcenterVmSearchFacts', 'IntegrationVmwareVcenterVmTagIndex',
            'IntegrationVmwareVcenterSoapReadOnly', 'IntegrationVmwareVcenterHostNetworkHints',
            'IntegrationVmwareVcenterVmGuestFacts', 'IntegrationVmwareVcenterVmAnnotation',
            'IntegrationVmwareVcenterVmNetworkPath', 'IntegrationVmwareVcenterPerformance', 'IntegrationVmwareVcenterStoragePerformance'] as $class) {
            require_once($application . '/functions/classes/class.' . $class . '.php');
        }
        define('PHPIPAM_VMWARE_CACHE_GUARDED', true);
        require_once($application . '/app/integrations/vmware/cache/search-provider.php');
        require_once($new . 'class.IntegrationVmwareInventorySource.php');
        $runtime = $context->workerRuntime(IntegrationSecretCipher::from_keyring_file());
        $source = new IntegrationVmwareInventorySource($runtime);
        $inventory = (new IntegrationVmwareInventoryWorker($store, $source, [$context, 'current']))->tick(30, 30);
        if ($interrupted) throw new RuntimeException('mirror_process_deadline');
        require_once($metricsDirectory . 'class.IntegrationVmwareInventoryMetricSource.php');
        $metricSource = new IntegrationVmwareInventoryMetricSource($runtime);
        $metrics = (new IntegrationVmwareInventorySampler($store, $telemetry, $metricSource, [$context, 'current']))->tick(20, 25);
        $result = ['state' => ($inventory['state'] ?? '') === 'error' || ($metrics['state'] ?? '') === 'error' ? 'error' : 'tick_complete',
            'inventory' => $inventory, 'telemetry' => $metrics,
            'php_peak_bytes' => memory_get_peak_usage(true), 'vcenter_requests_from_http' => 0];
        pcntl_alarm(0);
    }
    print json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    $exit = ($result['state'] ?? '') === 'error' ? 2 : 0;
} catch (Throwable $error) {
    // Do not forward arbitrary exception text from a dependency, DB driver or provider.
    fwrite(STDERR, "MIRROR_OPERATION_FAILED=1\n"); $exit = 1;
} finally {
    if (is_array($runtime) && isset($runtime['password'])) sodium_memzero($runtime['password']);
}

exit($exit);
