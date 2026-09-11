#!/usr/bin/env php
<?php
/** Readiness for the staged rollout only. Prints counts/status, never inventory or secrets. */
if (PHP_SAPI !== 'cli' || array_slice($argv, 1) !== ['--connector=1']) { http_response_code(403); exit(64); }
try {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 33 || posix_getegid() !== 33) throw new RuntimeException('uid');
    $app = '/var/www/html'; $release = dirname(__DIR__);
    require_once($app . '/functions/functions.php');
    require_once($app . '/functions/classes/class.IntegrationVmwareSnapshotService.php');
    require_once($app . '/functions/classes/class.IntegrationVmwareVcenterExplorer.php');
    foreach (['5g' => ['Store', 'Context', 'Reader'], '5g2' => ['Native'], '5g3' => ['TelemetryStore'], '5g4' => ['Readiness']] as $layer => $names) {
        foreach ($names as $name) require_once($release . '/layers/phase' . $layer . '/functions/classes/class.IntegrationVmwareInventory' . $name . '.php');
    }
    $ctx = new IntegrationVmwareInventoryContext(new Database_PDO(), $app, 1);
    $store = $ctx->store(); $telemetry = $ctx->telemetry();
    if ($telemetry === null) throw new RuntimeException('telemetry');
    $result = $store->exclusive(static function () use ($store, $telemetry, $ctx, $release): array {
        if (!$ctx->current()) throw new RuntimeException('binding');
        $current = $store->read('current');
        $result = IntegrationVmwareInventoryReadiness::inspect($current, [$telemetry, 'read'], time());
        if ($result['ready']) {
            // Exercise actual retained renderers against saved production data without emitting their HTML.
            define('PHPIPAM_VMWARE_MIRROR_GUARDED', true);
            require_once($release . '/staged-app/app/integrations/vmware/mirror/native-views.php');
            $reader = new IntegrationVmwareInventoryReader($store, [$ctx, 'current'], null, $telemetry);
            $native = new IntegrationVmwareInventoryNative($reader);
            $vm = array_key_first($current['inventory']['objects']['vm']);
            $host = array_key_first($current['inventory']['objects']['host']);
            $cases = [['status', []], ['workspace', []], ['workspace', ['view' => 'vms']],
                ['workspace', ['view' => 'datastores']], ['host_folders', []], ['object_folders', []],
                ['object_folders', ['mode' => 'datastores']], ['search', []], ['vm', ['vm_id' => $vm, 'include_tags' => '1']],
                ['host', ['host_id' => $host]], ['annotation', ['vm_id' => $vm]], ['network_path', ['vm_id' => $vm]],
                ['guest_facts', ['vm_id' => $vm]], ['host_network', ['host_id' => $host]], ['performance', ['vm_id' => $vm]],
                ['storage_performance', ['entity_type' => 'vm', 'entity_id' => $vm]],
                ['storage_performance', ['entity_type' => 'host', 'entity_id' => $host]]];
            set_error_handler(static function (int $level, string $message): bool {
                if ($level & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) throw new RuntimeException('render_warning');
                return true;
            });
            try {
                foreach ($cases as [$op, $input]) {
                    $response = $native->response($op, $input);
                    if ($response['status'] !== 200) throw new RuntimeException('render_failed');
                    json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                }
            } finally { restore_error_handler(); }
            $result['native_contracts_rendered'] = count($cases);
        }
        if (!$ctx->current()) throw new RuntimeException('binding');
        return $result;
    });
    print json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "PHASE5G4_READINESS_FAILED=1\n"); exit(1);
}
