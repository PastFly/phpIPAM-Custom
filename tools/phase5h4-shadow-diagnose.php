#!/usr/bin/env php
<?php
/** Bounded read-only diagnostic for the one-shot shadow cycle. No fast-domain publication. */
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 33 || $argc !== 1) {
    fwrite(STDERR, "PHASE5H_DIAG_ERROR=identity\nPHASE5H_DIAG_EXIT=1\n"); exit(1);
}
const APP = '/var/www/html';
const ACTIVE = '/opt/phpipam-custom/inventory-recovery5g5';
$stage = 'bootstrap'; $runtime = null;
$clean = static function (Throwable $e): string {
    $m = $e->getMessage();
    return preg_match('/^(?:batch|shadow|mirror|fast)_[a-z0-9_:-]{1,90}$/D', $m) ? $m : 'unexpected';
};
try {
    require_once APP . '/functions/functions.php';
    foreach (['IntegrationVmwareSnapshotService','IntegrationVmwareVcenter','IntegrationSecretCipher','IntegrationVmwareVcenterDirectTransport'] as $name)
        require_once APP . '/functions/classes/class.' . $name . '.php';
    foreach (['Store','Context'] as $name) require_once ACTIVE . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
    require_once ACTIVE . '/layers/phase5g3/functions/classes/class.IntegrationVmwareInventoryTelemetryStore.php';
    $root = dirname(__DIR__);
    foreach (['Session','Inventory','Performance'] as $name) require_once $root . '/layers/phase5h/functions/classes/class.IntegrationVmwareBatch' . $name . '.php';
    require_once $root . '/layers/phase5h3/functions/classes/class.IntegrationVmwareCapabilityCache.php';
    require_once $root . '/layers/phase5h4/functions/classes/class.IntegrationVmwareShadowPlanner.php';

    $stage = 'context';
    $db = new Database_PDO(); $ctx = new IntegrationVmwareInventoryContext($db, APP, 1);
    if (!$ctx->current()) throw new RuntimeException('mirror_access_denied');
    $store = $ctx->store(); $current = $store->read('current');
    if (!is_array($current) || !is_array($current['inventory'] ?? null) || !empty($current['blocked'])) throw new RuntimeException('shadow_inventory_unavailable');
    $telemetry = $ctx->telemetry(); if ($telemetry === null) throw new RuntimeException('active_telemetry_unavailable');
    $epoch = $current['access_epoch'] ?? 'initial'; if (!is_string($epoch) || $epoch === '') throw new RuntimeException('shadow_epoch_invalid');
    $runtime = $ctx->workerRuntime(IntegrationSecretCipher::from_keyring_file());

    $stage = 'roster';
    $roster = IntegrationVmwareShadowPlanner::roster($current);
    $entities = array_values(array_filter($roster['entities'], static fn(array $e): bool => in_array($e['kind'], ['vm','host'], true)));
    $stage = 'planning';
    $plan = IntegrationVmwareShadowPlanner::metricPlans($entities, static fn(string $id): array => $telemetry->read($id, $epoch), null, time());
    printf("PHASE5H_DIAG_COUNTS=%s\n", json_encode(['vm'=>count($roster['membership']['vm']),'host'=>count($roster['membership']['host']),
        'datastore'=>count($roster['membership']['datastore']),'plans'=>count($plan['plans']),'due'=>count($plan['capability_due']),'unsupported'=>count($plan['unsupported'])], JSON_THROW_ON_ERROR));

    $session = new IntegrationVmwareBatchSession($runtime, [$ctx,'current'], null, 128, 120);
    $stage = 'session';
    $result = $session->run(function (IntegrationVmwareBatchSession $s) use (&$stage, $roster, $plan): array {
        $stage = 'properties';
        $inventory = (new IntegrationVmwareBatchInventory($s, 50))->read($roster['entities']);
        $perf = new IntegrationVmwareBatchPerformance($s, 25);
        $stage = 'counter_registry'; $perf->definitions();
        $stage = 'capabilities'; $checked = 0;
        foreach ($plan['capability_due'] as $entity) {
            if ($checked >= 3) break; ++$checked;
            $provider = $perf->provider($entity['kind'], $entity['id']);
            if (($provider['current_supported'] ?? false) === true && is_int($provider['interval_id'] ?? null))
                $perf->availability($entity['kind'], $entity['id'], $provider['interval_id']);
        }
        $stage = 'metrics';
        if ($plan['plans'] !== []) $perf->read(array_slice($plan['plans'], 0, 50), 2);
        $stage = 'callback_done';
        return ['property_objects'=>count($inventory['objects']),'capability_checked'=>$checked,'metric_probe'=>min(50,count($plan['plans']))];
    });
    $stage = 'post_logout';
    $stats = $session->statistics();
    print 'PHASE5H_DIAG_RESULT=' . json_encode($result + ['requests'=>$stats['requests'],'response_bytes'=>$stats['response_bytes']], JSON_THROW_ON_ERROR) . "\n";
    print "PHASE5H_DIAG_EXIT=0\n"; $rc=0;
} catch (Throwable $e) {
    fwrite(STDERR, 'PHASE5H_DIAG_STAGE=' . $stage . "\nPHASE5H_DIAG_ERROR=" . $clean($e) . "\nPHASE5H_DIAG_EXIT=1\n"); $rc=1;
} finally {
    if (is_array($runtime) && isset($runtime['password']) && is_string($runtime['password'])) sodium_memzero($runtime['password']);
}
exit($rc);
