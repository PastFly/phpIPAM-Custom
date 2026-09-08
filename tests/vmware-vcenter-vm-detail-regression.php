<?php

/**
 * Phase 5D.3 VMware on-demand VM detail regression.
 * Injected transports only; no live vCenter or production mutation.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php');
require_once($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterVmDetail.php');

$passed = 0;
$failed = 0;

function vm_detail_check($name, $condition)
{
    global $passed, $failed;
    if ($condition) {
        print "PASS $name\n";
        $passed++;
    } else {
        print "FAIL $name\n";
        $failed++;
    }
}

function vm_detail_same($name, $expected, $actual)
{
    vm_detail_check($name, $expected === $actual);
}

function vm_detail_expect_exception($name, $needle, $callback)
{
    global $passed, $failed;
    try {
        $callback();
        print "FAIL $name\n";
        $failed++;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), $needle) !== false) {
            print "PASS $name\n";
            $passed++;
        } else {
            print "FAIL $name\n";
            print "  expected: $needle\n";
            print "  actual: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

$sourcePath = $root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterVmDetail.php';
$source = file_get_contents($sourcePath);
vm_detail_check('source-detail-method', strpos($source, 'read_detail') !== false);
vm_detail_check('source-vm-detail-get', strpos($source, "'/api/vcenter/vm/'") !== false);
vm_detail_check('source-guest-identity-get', strpos($source, "'/guest/identity'") !== false);
vm_detail_check('source-tools-get', strpos($source, "'/tools'") !== false);
vm_detail_check('source-vmdk-provenance', strpos($source, 'vmdk_backing_path_observation_only') !== false);
vm_detail_check('source-datastore-id-left-null', strpos($source, "'datastore_external_id' => null") !== false);
vm_detail_check('source-no-put-patch', strpos($source, "'PUT'") === false && strpos($source, "'PATCH'") === false);
vm_detail_check('source-no-db-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
vm_detail_check('source-no-object-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);
vm_detail_check('source-no-redirects', strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false);

$calls = array();
$transport = function ($method, $url, $headers, $username, $password, $verifyTls) use (&$calls) {
    $calls[] = array(
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'username' => $username,
        'password' => $password,
        'verify_tls' => $verifyTls
    );

    if ($method === 'POST' && substr($url, -12) === '/api/session') {
        return array('status' => 201, 'body' => json_encode('vm-detail-token'));
    }
    if ($method === 'DELETE' && substr($url, -12) === '/api/session') {
        return array('status' => 204, 'body' => '');
    }
    if ($method === 'GET' && substr($url, -19) === '/api/vcenter/vm/vm-501') {
        return array('status' => 200, 'body' => json_encode(array(
            'guest_os' => 'RHEL_8_64',
            'name' => 'app-01',
            'identity' => array(
                'name' => 'app-01',
                'bios_uuid' => '420d1234-1234-5678-90ab-1234567890ab',
                'instance_uuid' => '500d1234-1234-5678-90ab-1234567890ab'
            ),
            'power_state' => 'POWERED_ON',
            'hardware' => array('version' => 'VMX_19'),
            'cpu' => array('count' => 4, 'cores_per_socket' => 2),
            'memory' => array('size_mib' => 8192),
            'disks' => array(
                '2000' => array(
                    'label' => 'Hard disk 1',
                    'type' => 'SCSI',
                    'capacity' => 107374182400,
                    'backing' => array(
                        'type' => 'VMDK_FILE',
                        'vmdk_file' => '[SAN-01] app-01/app-01.vmdk'
                    )
                ),
                '2001' => array(
                    'label' => 'Hard disk 2',
                    'type' => 'SCSI',
                    'capacity' => '214748364800',
                    'backing' => array(
                        'type' => 'VMDK_FILE',
                        'vmdk_file' => '/vmfs/volumes/uuid/app-01_1.vmdk'
                    )
                )
            ),
            'nics' => array(
                '4000' => array(
                    'label' => 'Network adapter 1',
                    'type' => 'VMXNET3',
                    'mac_address' => '00:50:56:aa:bb:cc',
                    'state' => 'CONNECTED',
                    'backing' => array(
                        'type' => 'STANDARD_PORTGROUP',
                        'network' => 'network-101',
                        'network_name' => 'Server VLAN'
                    )
                ),
                '4001' => array(
                    'label' => 'Network adapter 2',
                    'type' => 'VMXNET3',
                    'mac_address' => '005056AABBdd',
                    'state' => 'DISCONNECTED',
                    'backing' => array(
                        'type' => 'DISTRIBUTED_PORTGROUP',
                        'network' => 'dvportgroup-22',
                        'distributed_switch_uuid' => '50 0d aa bb cc dd',
                        'distributed_port' => '123'
                    )
                )
            )
        )));
    }
    if ($method === 'GET' && substr($url, -15) === '/guest/identity') {
        return array('status' => 200, 'body' => json_encode(array(
            'name' => 'RHEL_8_64',
            'family' => 'LINUX',
            'host_name' => 'app-01.example.local',
            'ip_address' => '10.20.30.40'
        )));
    }
    if ($method === 'GET' && substr($url, -6) === '/tools') {
        return array('status' => 200, 'body' => json_encode(array(
            'version_number' => 12365,
            'version' => '12.3.5',
            'version_status' => 'CURRENT',
            'install_type' => 'OPEN_VM_TOOLS',
            'run_state' => 'RUNNING'
        )));
    }
    return array('status' => 500, 'body' => '{}');
};

$client = new IntegrationVmwareVcenterVmDetail($transport);
$result = $client->read_detail('vcenter.example.local', 'svc-vm@example.local', 'vm-secret', 'vm-501', true);

vm_detail_check('detail-success', $result['ok'] === true);
vm_detail_same('detail-api-family', 'modern', $result['api_family']);
vm_detail_same('vm-id', 'vm-501', $result['vm']['external_id']);
vm_detail_same('vm-name', 'app-01', $result['vm']['name']);
vm_detail_same('vm-guest-os', 'rhel_8_64', $result['vm']['guest_os']);
vm_detail_same('vm-power', 'powered_on', $result['vm']['power_state']);
vm_detail_same('vm-hardware-version', 'vmx_19', $result['vm']['hardware_version']);
vm_detail_same('vm-cpu', 4, $result['vm']['cpu_count']);
vm_detail_same('vm-cores-per-socket', 2, $result['vm']['cores_per_socket']);
vm_detail_same('vm-memory', 8192, $result['vm']['memory_size_mib']);
vm_detail_same('vm-bios-uuid', '420d1234-1234-5678-90ab-1234567890ab', $result['vm']['identity']['bios_uuid']);
vm_detail_same('vm-instance-uuid', '500d1234-1234-5678-90ab-1234567890ab', $result['vm']['identity']['instance_uuid']);
vm_detail_same('vm-disk-count', 2, $result['vm']['disk_count']);
vm_detail_same('vm-disk-id', '2000', $result['vm']['disks'][0]['external_id']);
vm_detail_same('vm-disk-capacity', 107374182400, $result['vm']['disks'][0]['capacity_bytes']);
vm_detail_same('vm-disk-observed-datastore', 'SAN-01', $result['vm']['disks'][0]['observed_datastore_name']);
vm_detail_same('vm-disk-no-fake-datastore-id', null, $result['vm']['disks'][0]['datastore_external_id']);
vm_detail_same('vm-disk-provenance', 'vmdk_backing_path_observation_only', $result['vm']['disks'][0]['datastore_relation_evidence']);
vm_detail_same('vm-disk-unparseable-path-no-datastore-name', null, $result['vm']['disks'][1]['observed_datastore_name']);
vm_detail_same('vm-disk-string-capacity', 214748364800, $result['vm']['disks'][1]['capacity_bytes']);
vm_detail_same('vm-nic-count', 2, $result['vm']['nic_count']);
vm_detail_same('vm-nic-id', '4000', $result['vm']['nics'][0]['external_id']);
vm_detail_same('vm-nic-mac', '00:50:56:aa:bb:cc', $result['vm']['nics'][0]['mac_address']);
vm_detail_same('vm-nic-network-id', 'network-101', $result['vm']['nics'][0]['network_external_id']);
vm_detail_same('vm-nic-network-name', 'Server VLAN', $result['vm']['nics'][0]['network_name']);
vm_detail_same('vm-nic-mac-canonicalized', '00:50:56:aa:bb:dd', $result['vm']['nics'][1]['mac_address']);
vm_detail_same('guest-status-ok', 'ok', $result['guest_identity']['status']);
vm_detail_same('guest-hostname', 'app-01.example.local', $result['guest_identity']['host_name']);
vm_detail_same('guest-primary-ip', '10.20.30.40', $result['guest_identity']['primary_ip']);
vm_detail_same('tools-status-ok', 'ok', $result['tools']['status']);
vm_detail_same('tools-version', '12.3.5', $result['tools']['version']);
vm_detail_same('tools-run-state', 'running', $result['tools']['run_state']);
vm_detail_check('detail-no-persistence', $result['persistent_inventory_written'] === false && $result['phpipam_objects_modified'] === false && $result['vcenter_objects_modified'] === false);
vm_detail_same('detail-call-count', 5, count($calls));
vm_detail_check('detail-session-cleanup-last', $calls[4]['method'] === 'DELETE');
vm_detail_check('detail-tls-propagated', array_reduce($calls, function ($carry, $call) { return $carry && $call['verify_tls'] === true; }, true));
vm_detail_check('detail-basic-auth-login-only', $calls[0]['username'] === 'svc-vm@example.local' && $calls[0]['password'] === 'vm-secret' && $calls[1]['username'] === null);
$serialized = json_encode($result);
vm_detail_check('detail-result-no-password', strpos($serialized, 'vm-secret') === false);
vm_detail_check('detail-result-no-username', strpos($serialized, 'svc-vm@example.local') === false);
vm_detail_check('detail-result-no-session-token', strpos($serialized, 'vm-detail-token') === false);

$statusCalls = array();
$statusClient = new IntegrationVmwareVcenterVmDetail(function ($method, $url) use (&$statusCalls) {
    $statusCalls[] = array($method, $url);
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('status-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    if (substr($url, -15) === '/guest/identity') {
        return array('status' => 503, 'body' => '{}');
    }
    if (substr($url, -6) === '/tools') {
        return array('status' => 404, 'body' => '{}');
    }
    return array('status' => 200, 'body' => json_encode(array(
        'guest_os' => 'OTHER_64',
        'name' => 'powered-off-vm',
        'power_state' => 'POWERED_OFF',
        'cpu' => array('count' => 1, 'cores_per_socket' => 1),
        'memory' => array('size_mib' => 1024),
        'disks' => array(),
        'nics' => array()
    )));
});
$statusResult = $statusClient->read_detail('vcenter.example.local', 'u', 'p', 'vm-off', false);
vm_detail_same('guest-tools-unavailable-is-state', 'tools_unavailable', $statusResult['guest_identity']['status']);
vm_detail_same('tools-unsupported-is-state', 'unsupported', $statusResult['tools']['status']);
vm_detail_same('powered-off-still-detail', 'powered_off', $statusResult['vm']['power_state']);
vm_detail_same('empty-disks', 0, $statusResult['vm']['disk_count']);
vm_detail_same('empty-nics', 0, $statusResult['vm']['nic_count']);

$forbiddenClient = new IntegrationVmwareVcenterVmDetail(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('forbidden-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    if (substr($url, -15) === '/guest/identity' || substr($url, -6) === '/tools') {
        return array('status' => 403, 'body' => '{}');
    }
    return array('status' => 200, 'body' => json_encode(array(
        'guest_os' => 'WINDOWS_9_SERVER_64',
        'name' => 'vm',
        'power_state' => 'POWERED_ON',
        'disks' => array(),
        'nics' => array()
    )));
});
$forbidden = $forbiddenClient->read_detail('vcenter.example.local', 'u', 'p', 'vm-1', true);
vm_detail_same('guest-forbidden-is-state', 'forbidden', $forbidden['guest_identity']['status']);
vm_detail_same('tools-forbidden-is-state', 'forbidden', $forbidden['tools']['status']);

$legacyCalls = array();
$legacyClient = new IntegrationVmwareVcenterVmDetail(function ($method, $url) use (&$legacyCalls) {
    $legacyCalls[] = array($method, $url);
    if ($method === 'POST') {
        return array('status' => 404, 'body' => '{}');
    }
    return array('status' => 500, 'body' => '{}');
});
vm_detail_expect_exception('legacy-fails-closed', 'modern vCenter API', function () use ($legacyClient) {
    $legacyClient->read_detail('legacy.example.local', 'u', 'p', 'vm-1', true);
});
vm_detail_same('legacy-no-guessed-rest', 1, count($legacyCalls));

$corePermissionCalls = array();
$corePermissionClient = new IntegrationVmwareVcenterVmDetail(function ($method) use (&$corePermissionCalls) {
    $corePermissionCalls[] = $method;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('perm-token'));
    }
    if ($method === 'GET') {
        return array('status' => 403, 'body' => '{}');
    }
    return array('status' => 204, 'body' => '');
});
vm_detail_expect_exception('core-403-distinguished', 'lacks permission', function () use ($corePermissionClient) {
    $corePermissionClient->read_detail('vcenter.example.local', 'u', 'p', 'vm-1', true);
});
vm_detail_check('core-403-cleanup', $corePermissionCalls === array('POST', 'GET', 'DELETE'));

$badMacClient = new IntegrationVmwareVcenterVmDetail(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bad-mac-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    if (substr($url, -15) === '/guest/identity' || substr($url, -6) === '/tools') {
        return array('status' => 404, 'body' => '{}');
    }
    return array('status' => 200, 'body' => json_encode(array(
        'guest_os' => 'OTHER_64',
        'name' => 'vm',
        'power_state' => 'POWERED_ON',
        'disks' => array(),
        'nics' => array('1' => array('label' => 'nic', 'type' => 'VMXNET3', 'mac_address' => 'not-a-mac', 'state' => 'CONNECTED'))
    )));
});
vm_detail_expect_exception('invalid-mac-rejected', 'MAC address', function () use ($badMacClient) {
    $badMacClient->read_detail('vcenter.example.local', 'u', 'p', 'vm-1', true);
});

$diskLimitClient = new IntegrationVmwareVcenterVmDetail(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('disk-limit-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    $disks = array();
    for ($i = 0; $i < 3; $i++) {
        $disks[(string)$i] = array('label' => 'disk', 'type' => 'SCSI');
    }
    return array('status' => 200, 'body' => json_encode(array(
        'guest_os' => 'OTHER_64',
        'name' => 'vm',
        'power_state' => 'POWERED_ON',
        'disks' => $disks,
        'nics' => array()
    )));
}, 8, 20, 4194304, 2, 64);
vm_detail_expect_exception('disk-limit-rejected', 'disk collection exceeded', function () use ($diskLimitClient) {
    $diskLimitClient->read_detail('vcenter.example.local', 'u', 'p', 'vm-1', true);
});

print "\nPhase 5D.3 VM detail regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
