<?php
/** Real encrypted Store/Reader plus native rendering. No provider, curl or SOAP instance exists. */
$root = dirname(__DIR__);
foreach (['Store', 'Reader'] as $name) require $root . '/layers/phase5g/functions/classes/class.IntegrationVmwareInventory' . $name . '.php';
require $root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterExplorer.php';
require $root . '/layers/phase5g2/functions/classes/class.IntegrationVmwareInventoryNative.php';
define('PHPIPAM_VMWARE_MIRROR_GUARDED', true);
require $argv[1] . '/app/integrations/vmware/mirror/native-views.php';
set_error_handler(static function ($code, $message): bool { if (!(error_reporting() & $code)) return false; throw new RuntimeException($message); });
$n = 0;
function test(bool $ok, string $why): void { global $n; if (!$ok) throw new RuntimeException($why); $n++; }
$directory = sys_get_temp_dir() . '/mirror-native-' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
$key = random_bytes(32); $binding = str_repeat('b', 64); $allowed = true; $now = 1800000000;
$store = new IntegrationVmwareInventoryStore($directory, $key, $binding);
$guard = static function () use (&$allowed): bool { return $allowed; };
$clock = static function () use (&$now): int { return $now; };
$reader = new IntegrationVmwareInventoryReader($store, $guard, $clock);
$links = new class { public function resolve(string $ip): ?array { return $ip === '192.0.2.1' ? ['url' => '/native/address/17/'] : null; } };
$native = new IntegrationVmwareInventoryNative($reader, $links);
$inputs = ['vm_id' => 'vm-1', 'host_id' => 'host-1', 'entity_type' => 'vm', 'entity_id' => 'vm-1', 'include_tags' => '1'];
try {
    foreach (IntegrationVmwareInventoryNative::OPERATIONS as $op) {
        $out = $native->response($op, $inputs);
        test($out['status'] === 200, 'empty ' . $op);
        if ($out['format'] === 'json') test($out['body']['vcenter_requests'] === 0, 'zero cold ' . $op);
        else test(str_contains($out['body'], 'Первичная синхронизация'), 'explicit initial ' . $op);
    }
    test(scandir($directory) === ['.', '..'], 'cold reads wrote files');
    $dc = ['external_id' => 'datacenter-1', 'name' => '<DC>'];
    $host = ['external_id' => 'host-1', 'name' => 'esxi-one', 'datacenter_external_id' => 'datacenter-1', 'cluster_external_id' => null];
    $vm = ['external_id' => 'vm-1', 'name' => '<script>alert(1)</script>', 'datacenter_external_id' => 'datacenter-1',
        'cluster_external_id' => null, 'host_external_id' => 'host-1', 'power_state' => 'powered_on', 'cpu_count' => 2,
        'memory_size_mib' => 4096, 'identity' => ['bios_uuid' => 'uuid-1'], 'disks' => []];
    $ds = ['external_id' => 'datastore-1', 'name' => 'ds-one', 'datacenter_external_id' => 'datacenter-1',
        'type' => 'vmfs', 'capacity_bytes' => 1073741824, 'free_space_bytes' => 536870912];
    $topology = ['datacenters' => [$dc], 'clusters' => [], 'hosts' => [$host], 'vms' => [$vm],
        'unresolved_host_cluster_count' => 1, 'unresolved_vm_cluster_count' => 1];
    $core = ['ok' => true, 'topology' => $topology, 'datastores' => ['datastores' => [$ds]]];
    $projection = (new IntegrationVmwareVcenterExplorer())->build($topology, $core['datastores']);
    $part = static fn(array $data): array => ['state' => 'ok', 'observed_at' => $now, 'data' => $data];
    $details = ['vm' => $vm, 'guest_identity' => ['guest_os' => 'raw-id', 'primary_ip' => '192.0.2.1'], 'tools' => []];
    $record = ['ok' => true, 'name' => $vm['name'], 'detail' => $details, 'context' => (new IntegrationVmwareVcenterExplorer())->vm_context($projection, 'vm-1'),
        'sections' => ['guest_facts' => $part(['guest_guest_full_name' => 'Debian Friendly', 'memory_mb' => 8192, 'num_cpu' => 4, 'hardware_version' => 'vmx-13']),
            'guest_network' => $part(['vms' => [['status' => 'ok', 'interfaces' => [], 'ip_count' => 0]]]),
            'annotation' => $part(['annotation' => '<script>Notes</script>']), 'network_path' => $part(['nics' => [], 'host_name' => 'esxi-one']),
            'performance' => $part(['samples' => [['cpu_percent' => 10, 'memory_percent' => 20], ['cpu_percent' => 30, 'memory_percent' => 40]], 'provider' => ['refresh_rate' => 20]]),
            'storage_performance' => $part(['supported' => false, 'reason' => 'not_sampled'])]];
    $rec = static fn(array $data): array => ['collected_at' => $now, 'data' => $data];
    $inventory = ['core' => $core, 'host_folders' => $rec(['ok' => true, 'folders' => []]),
        'object_folders' => $rec(['ok' => true, 'vm' => ['folders' => []], 'datastore' => ['folders' => []]]),
        'search' => $rec(['ok' => true, 'rows' => [['vm_external_id' => 'vm-1', 'name' => $vm['name'], 'tags' => [['name' => '<TAG>', 'category' => ['name' => 'Category']]]]]]),
        'objects' => ['vm' => ['vm-1' => $rec($record)], 'host' => ['host-1' => $rec(['summary' => ['product' => ['name' => 'esxi-one']], 'sections' => ['network_hints' => $part(['hints' => []])]])],
            'datastore' => ['datastore-1' => $rec(['name' => 'ds-one', 'detail' => ['datastore' => $ds]])]]];
    $current = ['state' => 'ready', 'blocked' => false, 'generation' => str_repeat('c', 32), 'started_at' => $now, 'published_at' => $now, 'inventory' => $inventory];
    $store->exclusive(fn() => $store->write('current', $current));
    $digest = hash_file('sha256', $directory . '/current.enc');
    foreach (IntegrationVmwareInventoryNative::OPERATIONS as $op) {
        $out = $native->response($op, $inputs);
        test($out['status'] === 200, 'published ' . $op);
        if ($out['format'] === 'json') test($out['body']['vcenter_requests'] === 0, 'zero published ' . $op);
    }
    $body = $native->response('vm', $inputs)['body'];
    test(!str_contains($body, '<script>') && str_contains($body, '&lt;script&gt;'), 'escape VM');
    test(str_contains($body, 'Debian Friendly') && str_contains($body, '8.00 GiB'), 'friendly OS and RAM in primary response');
    test(str_contains($body, '/native/address/17/') && str_contains($body, '&lt;TAG&gt;'), 'current IP link and escaped tags');
    test(!str_contains($body, 'phase5g2-freshness') && !str_contains($body, 'Локальный инвентарь'), 'no duplicate freshness banner in VM card');
    $notes = $native->response('annotation', $inputs)['body'];
    test(str_contains($notes, '&lt;script&gt;Notes') && !str_contains($notes, 'не сохраняется'), 'escaped dated saved annotation');
    $performanceBody = $native->response('performance', $inputs)['body'];
    test(str_contains($performanceBody, '<svg'), 'retained chart renderer accepts saved samples');
    test(!str_contains($performanceBody, 'Real-time'), 'saved charts not called live');
    test(!str_contains($performanceBody, 'Локальный инвентарь'), 'no duplicate freshness banner in performance panel');
    foreach (['overview', 'vms', 'datastores'] as $view) {
        test($native->response('workspace', ['view' => $view])['body']['ok'], 'native workspace ' . $view);
    }
    test($native->response('object_folders', ['mode' => 'datastores'])['body']['datastores'][0]['external_id'] === 'datastore-1', 'storage tree metadata');
    if (isset($argv[2])) {
        $fixtures = [];
        foreach (IntegrationVmwareInventoryNative::OPERATIONS as $op) $fixtures[$op] = $native->response($op, $inputs);
        file_put_contents($argv[2], json_encode($fixtures, JSON_THROW_ON_ERROR));
    }
    $now += 86400;
    for ($i = 0; $i < 100; $i++) $body = $native->response('vm', $inputs + ['force_refresh' => '1'])['body'];
    $status = $native->response('status', $inputs)['body'];
    test(!empty($status['stale']) && !str_contains($body, 'Локальный инвентарь') &&
        hash_file('sha256', $directory . '/current.enc') === $digest, '100 overnight forced reads keep stale state in status and still zero writes');
    $allowed = false;
    foreach (IntegrationVmwareInventoryNative::OPERATIONS as $op) {
        try { $native->response($op, $inputs); throw new LogicException('authorization bypass ' . $op); }
        catch (RuntimeException $e) { test($e->getMessage() === 'mirror_access_denied', 'denial ' . $op); }
    }
    print 'PHASE5G2_NATIVE_ASSERTIONS=' . $n . "\nPHASE5G2_NATIVE_LOCAL_RENDERING_OK\n";
} finally {
    restore_error_handler();
    foreach (glob($directory . '/*') as $path) unlink($path);
    rmdir($directory);
}
