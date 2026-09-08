<?php

/**
 * Phase 5C.2 VMware vCenter ESXi Host + VM read-only inventory regression.
 *
 * Injected transports only: no real vCenter, credentials, live schema or
 * phpIPAM object mutation is required.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php');
require_once($root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterInventory.php');

$passed = 0;
$failed = 0;

function core_inv_check_true($name, $condition)
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

function core_inv_check_same($name, $expected, $actual)
{
    core_inv_check_true($name, $expected === $actual);
}

function core_inv_expect_exception_contains($name, $needle, $callback)
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
            print "  expected exception containing: $needle\n";
            print "  actual: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

$classPath = $root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterInventory.php';
$classSource = file_get_contents($classPath);
core_inv_check_true('source-core-inventory-method', strpos($classSource, 'read_core_inventory') !== false);
core_inv_check_true('source-host-normalizer', strpos($classSource, 'normalize_hosts') !== false);
core_inv_check_true('source-vm-normalizer', strpos($classSource, 'normalize_vms') !== false);
core_inv_check_true('source-no-put-patch', strpos($classSource, "'PUT'") === false && strpos($classSource, "'PATCH'") === false);
core_inv_check_true('source-no-persistence-sql', strpos($classSource, 'INSERT INTO') === false && strpos($classSource, 'UPDATE ') === false && strpos($classSource, 'DELETE FROM') === false);
core_inv_check_true('source-no-phpipam-object-write', strpos($classSource, 'object_modify') === false && strpos($classSource, 'modify_object') === false);

$modernCalls = array();
$modernTransport = function ($method, $url, $headers, $username, $password, $verifyTls) use (&$modernCalls) {
    $modernCalls[] = array(
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'username' => $username,
        'password' => $password,
        'verify_tls' => $verifyTls
    );

    if ($method === 'POST' && substr($url, -12) === '/api/session') {
        return array('status' => 201, 'body' => json_encode('core-modern-token'));
    }
    if ($method === 'GET' && substr($url, -23) === '/api/vcenter/datacenter') {
        return array('status' => 200, 'body' => json_encode(array(
            array('datacenter' => 'datacenter-1', 'name' => 'DC1')
        )));
    }
    if ($method === 'GET' && substr($url, -20) === '/api/vcenter/cluster') {
        return array('status' => 200, 'body' => json_encode(array(
            array('cluster' => 'domain-c1', 'name' => 'Cluster1', 'ha_enabled' => true, 'drs_enabled' => true)
        )));
    }
    if ($method === 'GET' && substr($url, -17) === '/api/vcenter/host') {
        return array('status' => 200, 'body' => json_encode(array(
            array('host' => 'host-101', 'name' => 'esxi-01.example.local', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON'),
            array('host' => 'host-102', 'name' => 'esxi-02.example.local', 'connection_state' => 'NOT_RESPONDING', 'power_state' => 'POWERED_ON')
        )));
    }
    if ($method === 'GET' && substr($url, -15) === '/api/vcenter/vm') {
        return array('status' => 200, 'body' => json_encode(array(
            array('vm' => 'vm-501', 'name' => 'app-01', 'power_state' => 'POWERED_ON', 'cpu_count' => 4, 'memory_size_MiB' => 8192),
            array('vm' => 'vm-502', 'name' => 'db-01', 'power_state' => 'POWERED_OFF', 'cpu_count' => '8', 'memory_size_mib' => '16384')
        )));
    }
    if ($method === 'DELETE' && substr($url, -12) === '/api/session') {
        return array('status' => 204, 'body' => '');
    }
    return array('status' => 500, 'body' => '{}');
};

$modernClient = new IntegrationVmwareVcenterInventory($modernTransport);
$modern = $modernClient->read_core_inventory(
    'vcenter.example.local',
    'svc-core@example.local',
    'core-secret',
    true
);

core_inv_check_true('modern-core-success', $modern['ok'] === true);
core_inv_check_same('modern-core-api-family', 'modern', $modern['api_family']);
core_inv_check_same('modern-core-datacenter-count', 1, $modern['datacenter_count']);
core_inv_check_same('modern-core-cluster-count', 1, $modern['cluster_count']);
core_inv_check_same('modern-core-host-count', 2, $modern['host_count']);
core_inv_check_same('modern-core-vm-count', 2, $modern['vm_count']);
core_inv_check_same('modern-host-id', 'host-101', $modern['hosts'][0]['external_id']);
core_inv_check_same('modern-host-name', 'esxi-01.example.local', $modern['hosts'][0]['name']);
core_inv_check_same('modern-host-connection-normalized', 'connected', $modern['hosts'][0]['connection_state']);
core_inv_check_same('modern-host-power-normalized', 'powered_on', $modern['hosts'][0]['power_state']);
core_inv_check_same('modern-host-cluster-not-inferred', null, $modern['hosts'][0]['cluster_external_id']);
core_inv_check_same('modern-vm-id', 'vm-501', $modern['vms'][0]['external_id']);
core_inv_check_same('modern-vm-power-normalized', 'powered_on', $modern['vms'][0]['power_state']);
core_inv_check_same('modern-vm-cpu-int', 4, $modern['vms'][0]['cpu_count']);
core_inv_check_same('modern-vm-memory-mib', 8192, $modern['vms'][0]['memory_size_mib']);
core_inv_check_same('modern-vm-string-cpu-normalized', 8, $modern['vms'][1]['cpu_count']);
core_inv_check_same('modern-vm-memory-alias-normalized', 16384, $modern['vms'][1]['memory_size_mib']);
core_inv_check_same('modern-vm-host-not-inferred', null, $modern['vms'][0]['host_external_id']);
core_inv_check_same('modern-core-call-count', 6, count($modernCalls));
core_inv_check_true(
    'modern-core-call-order',
    $modernCalls[0]['method'] === 'POST' &&
    strpos($modernCalls[1]['url'], '/api/vcenter/datacenter') !== false &&
    strpos($modernCalls[2]['url'], '/api/vcenter/cluster') !== false &&
    strpos($modernCalls[3]['url'], '/api/vcenter/host') !== false &&
    strpos($modernCalls[4]['url'], '/api/vcenter/vm') !== false &&
    $modernCalls[5]['method'] === 'DELETE'
);
core_inv_check_true(
    'modern-core-basic-creds-login-only',
    $modernCalls[0]['username'] === 'svc-core@example.local' &&
    $modernCalls[0]['password'] === 'core-secret' &&
    $modernCalls[1]['username'] === null &&
    $modernCalls[2]['username'] === null &&
    $modernCalls[3]['username'] === null &&
    $modernCalls[4]['username'] === null &&
    $modernCalls[5]['username'] === null
);
$coreHeaderOk = true;
for ($i = 1; $i <= 5; $i++) {
    if (!in_array('vmware-api-session-id: core-modern-token', $modernCalls[$i]['headers'], true)) {
        $coreHeaderOk = false;
    }
}
core_inv_check_true('modern-core-session-header-all-authenticated-calls', $coreHeaderOk);
core_inv_check_true('modern-core-tls-propagated', array_reduce($modernCalls, function ($carry, $call) { return $carry && $call['verify_tls'] === true; }, true));
$modernSerialized = json_encode($modern);
core_inv_check_true('modern-core-result-no-password', strpos($modernSerialized, 'core-secret') === false);
core_inv_check_true('modern-core-result-no-username', strpos($modernSerialized, 'svc-core@example.local') === false);
core_inv_check_true('modern-core-result-no-session-token', strpos($modernSerialized, 'core-modern-token') === false);

$legacyCalls = array();
$legacyTransport = function ($method, $url, $headers, $username, $password, $verifyTls) use (&$legacyCalls) {
    $legacyCalls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'verify_tls' => $verifyTls);
    if ($method === 'POST' && substr($url, -12) === '/api/session') {
        return array('status' => 404, 'body' => '{}');
    }
    if ($method === 'POST' && substr($url, -28) === '/rest/com/vmware/cis/session') {
        return array('status' => 200, 'body' => json_encode(array('value' => 'core-legacy-token')));
    }
    if ($method === 'GET' && substr($url, -24) === '/rest/vcenter/datacenter') {
        return array('status' => 200, 'body' => json_encode(array('value' => array(array('datacenter' => 'dc-old', 'name' => 'LegacyDC')))));
    }
    if ($method === 'GET' && substr($url, -21) === '/rest/vcenter/cluster') {
        return array('status' => 200, 'body' => json_encode(array('value' => array(array('cluster' => 'cluster-old', 'name' => 'LegacyCluster')))));
    }
    if ($method === 'GET' && substr($url, -18) === '/rest/vcenter/host') {
        return array('status' => 200, 'body' => json_encode(array('value' => array(array('host' => 'host-old', 'name' => 'esxi-old', 'connection_state' => 'DISCONNECTED')))));
    }
    if ($method === 'GET' && substr($url, -16) === '/rest/vcenter/vm') {
        return array('status' => 200, 'body' => json_encode(array('value' => array(array('vm' => 'vm-old', 'name' => 'legacy-vm', 'power_state' => 'SUSPENDED')))));
    }
    if ($method === 'DELETE' && substr($url, -28) === '/rest/com/vmware/cis/session') {
        return array('status' => 200, 'body' => '{}');
    }
    return array('status' => 500, 'body' => '{}');
};

$legacyClient = new IntegrationVmwareVcenterInventory($legacyTransport);
$legacy = $legacyClient->read_core_inventory('legacy-vcenter.example.local', 'legacy-user', 'legacy-pass', true);
core_inv_check_same('legacy-core-api-family', 'legacy', $legacy['api_family']);
core_inv_check_same('legacy-core-host-count', 1, $legacy['host_count']);
core_inv_check_same('legacy-core-vm-count', 1, $legacy['vm_count']);
core_inv_check_same('legacy-core-host-id', 'host-old', $legacy['hosts'][0]['external_id']);
core_inv_check_same('legacy-core-host-state', 'disconnected', $legacy['hosts'][0]['connection_state']);
core_inv_check_same('legacy-core-vm-id', 'vm-old', $legacy['vms'][0]['external_id']);
core_inv_check_same('legacy-core-vm-state', 'suspended', $legacy['vms'][0]['power_state']);
core_inv_check_same('legacy-core-call-count', 7, count($legacyCalls));
core_inv_check_true(
    'legacy-core-call-order',
    strpos($legacyCalls[0]['url'], '/api/session') !== false &&
    strpos($legacyCalls[1]['url'], '/rest/com/vmware/cis/session') !== false &&
    strpos($legacyCalls[2]['url'], '/rest/vcenter/datacenter') !== false &&
    strpos($legacyCalls[3]['url'], '/rest/vcenter/cluster') !== false &&
    strpos($legacyCalls[4]['url'], '/rest/vcenter/host') !== false &&
    strpos($legacyCalls[5]['url'], '/rest/vcenter/vm') !== false &&
    $legacyCalls[6]['method'] === 'DELETE'
);

$hostPermissionCalls = array();
$hostPermissionClient = new IntegrationVmwareVcenterInventory(function ($method, $url) use (&$hostPermissionCalls) {
    $hostPermissionCalls[] = array($method, $url);
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('host-permission-token'));
    }
    if ($method === 'GET' && strpos($url, '/datacenter') !== false) {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    if ($method === 'GET' && strpos($url, '/cluster') !== false) {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    if ($method === 'GET' && strpos($url, '/host') !== false) {
        return array('status' => 403, 'body' => '{}');
    }
    return array('status' => 204, 'body' => '');
});
core_inv_expect_exception_contains('host-403-distinguished', 'Host inventory', function () use ($hostPermissionClient) {
    $hostPermissionClient->read_core_inventory('vcenter.example.local', 'limited', 'pass', true);
});
core_inv_check_true('host-403-still-logs-out', count($hostPermissionCalls) === 5 && $hostPermissionCalls[4][0] === 'DELETE');

$vmMalformedCalls = array();
$vmMalformedClient = new IntegrationVmwareVcenterInventory(function ($method, $url) use (&$vmMalformedCalls) {
    $vmMalformedCalls[] = $method;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('vm-malformed-token'));
    }
    if ($method === 'GET' && strpos($url, '/vm') !== false) {
        return array('status' => 200, 'body' => '{bad-json');
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 204, 'body' => '');
});
core_inv_expect_exception_contains('vm-malformed-json-rejected', 'VM response is not valid JSON', function () use ($vmMalformedClient) {
    $vmMalformedClient->read_core_inventory('vcenter.example.local', 'user', 'pass', true);
});
core_inv_check_true('vm-malformed-still-logs-out', $vmMalformedCalls === array('POST', 'GET', 'GET', 'GET', 'GET', 'DELETE'));

$badVmCpuClient = new IntegrationVmwareVcenterInventory(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bad-vm-token'));
    }
    if ($method === 'GET' && strpos($url, '/vm') !== false) {
        return array('status' => 200, 'body' => json_encode(array(array('vm' => 'vm-bad', 'name' => 'bad-vm', 'cpu_count' => -1))));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 204, 'body' => '');
});
core_inv_expect_exception_contains('negative-vm-cpu-rejected', 'VM CPU count', function () use ($badVmCpuClient) {
    $badVmCpuClient->read_core_inventory('vcenter.example.local', 'user', 'pass', true);
});

$badHostStateClient = new IntegrationVmwareVcenterInventory(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bad-host-token'));
    }
    if ($method === 'GET' && strpos($url, '/host') !== false) {
        return array('status' => 200, 'body' => json_encode(array(array('host' => 'host-bad', 'name' => 'bad-host', 'connection_state' => "CONNECTED\nBAD"))));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 204, 'body' => '');
});
core_inv_expect_exception_contains('invalid-host-state-rejected', 'Host connection state', function () use ($badHostStateClient) {
    $badHostStateClient->read_core_inventory('vcenter.example.local', 'user', 'pass', true);
});

$boundedVmClient = new IntegrationVmwareVcenterInventory(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bounded-vm-token'));
    }
    if ($method === 'GET' && strpos($url, '/vm') !== false) {
        return array('status' => 200, 'body' => json_encode(array(
            array('vm' => 'vm-1', 'name' => 'vm1'),
            array('vm' => 'vm-2', 'name' => 'vm2')
        )));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 204, 'body' => '');
}, 8, 20, 2097152, 1);
core_inv_expect_exception_contains('vm-collection-limit-enforced', 'VM collection exceeded', function () use ($boundedVmClient) {
    $boundedVmClient->read_core_inventory('vcenter.example.local', 'user', 'pass', true);
});

$tlsCalls = array();
$tlsOffClient = new IntegrationVmwareVcenterInventory(function ($method, $url, $headers, $username, $password, $verifyTls) use (&$tlsCalls) {
    $tlsCalls[] = $verifyTls;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('core-tls-off-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 204, 'body' => '');
});
$tlsOff = $tlsOffClient->read_core_inventory('vcenter.example.local', 'user', 'pass', false);
core_inv_check_true('core-tls-off-explicit-result', $tlsOff['verify_tls'] === false);
core_inv_check_true('core-tls-off-propagated-six-requests', $tlsCalls === array(false, false, false, false, false, false));

print "\nPhase 5C.2 VMware core inventory regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
