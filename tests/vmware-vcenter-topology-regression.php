<?php

/**
 * Phase 5D.1 VMware authoritative topology regression.
 * Injected transports only; no live vCenter or production mutation.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php');
require_once($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterTopology.php');

$passed = 0;
$failed = 0;

function topo_check($name, $condition)
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

function topo_same($name, $expected, $actual)
{
    topo_check($name, $expected === $actual);
}

function topo_expect_exception($name, $needle, $callback)
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

$source = file_get_contents($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterTopology.php');
topo_check('source-read-topology-method', strpos($source, 'read_topology') !== false);
topo_check('source-authoritative-datacenter-filter', strpos($source, "'datacenters'") !== false);
topo_check('source-authoritative-cluster-filter', strpos($source, "'clusters'") !== false);
topo_check('source-authoritative-host-filter', strpos($source, "'hosts'") !== false);
topo_check('source-no-put-patch', strpos($source, "'PUT'") === false && strpos($source, "'PATCH'") === false);
topo_check('source-no-db-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
topo_check('source-no-phpipam-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);
topo_check('source-no-follow-redirects', strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false);
topo_check('source-request-safety-limit', strpos($source, 'maxRelationRequests') !== false);

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
        return array('status' => 201, 'body' => json_encode('topology-token'));
    }
    if ($method === 'DELETE' && substr($url, -12) === '/api/session') {
        return array('status' => 204, 'body' => '');
    }

    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str((string)$query, $params);

    if ($method !== 'GET') {
        return array('status' => 500, 'body' => '{}');
    }

    if ($path === '/api/vcenter/datacenter' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array(
            array('datacenter' => 'datacenter-1', 'name' => 'DC1')
        )));
    }
    if ($path === '/api/vcenter/cluster' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array(
            array('cluster' => 'domain-c1', 'name' => 'Cluster1', 'ha_enabled' => true, 'drs_enabled' => true)
        )));
    }
    if ($path === '/api/vcenter/host' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array(
            array('host' => 'host-101', 'name' => 'esxi-01.example.local', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON'),
            array('host' => 'host-102', 'name' => 'esxi-standalone.example.local', 'connection_state' => 'CONNECTED', 'power_state' => 'POWERED_ON')
        )));
    }
    if ($path === '/api/vcenter/vm' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array(
            array('vm' => 'vm-501', 'name' => 'app-01', 'power_state' => 'POWERED_ON', 'cpu_count' => 4, 'memory_size_MiB' => 8192),
            array('vm' => 'vm-502', 'name' => 'standalone-01', 'power_state' => 'POWERED_ON', 'cpu_count' => 2, 'memory_size_MiB' => 4096),
            array('vm' => 'vm-503', 'name' => 'dc-only-01', 'power_state' => 'POWERED_OFF', 'cpu_count' => 1, 'memory_size_MiB' => 2048)
        )));
    }

    if ($path === '/api/vcenter/cluster' && isset($params['datacenters']) && $params['datacenters'] === 'datacenter-1') {
        return array('status' => 200, 'body' => json_encode(array(
            array('cluster' => 'domain-c1', 'name' => 'Cluster1', 'ha_enabled' => true, 'drs_enabled' => true)
        )));
    }
    if ($path === '/api/vcenter/host' && isset($params['datacenters']) && $params['datacenters'] === 'datacenter-1') {
        return array('status' => 200, 'body' => json_encode(array(
            array('host' => 'host-101', 'name' => 'esxi-01.example.local'),
            array('host' => 'host-102', 'name' => 'esxi-standalone.example.local')
        )));
    }
    if ($path === '/api/vcenter/vm' && isset($params['datacenters']) && $params['datacenters'] === 'datacenter-1') {
        return array('status' => 200, 'body' => json_encode(array(
            array('vm' => 'vm-501', 'name' => 'app-01'),
            array('vm' => 'vm-502', 'name' => 'standalone-01'),
            array('vm' => 'vm-503', 'name' => 'dc-only-01')
        )));
    }
    if ($path === '/api/vcenter/host' && isset($params['clusters']) && $params['clusters'] === 'domain-c1') {
        return array('status' => 200, 'body' => json_encode(array(
            array('host' => 'host-101', 'name' => 'esxi-01.example.local')
        )));
    }
    if ($path === '/api/vcenter/vm' && isset($params['clusters']) && $params['clusters'] === 'domain-c1') {
        return array('status' => 200, 'body' => json_encode(array(
            array('vm' => 'vm-501', 'name' => 'app-01')
        )));
    }
    if ($path === '/api/vcenter/vm' && isset($params['hosts']) && $params['hosts'] === 'host-101') {
        return array('status' => 200, 'body' => json_encode(array(
            array('vm' => 'vm-501', 'name' => 'app-01')
        )));
    }
    if ($path === '/api/vcenter/vm' && isset($params['hosts']) && $params['hosts'] === 'host-102') {
        return array('status' => 200, 'body' => json_encode(array(
            array('vm' => 'vm-502', 'name' => 'standalone-01')
        )));
    }

    return array('status' => 500, 'body' => '{}');
};

$client = new IntegrationVmwareVcenterTopology($transport);
$result = $client->read_topology('vcenter.example.local', 'svc-topology@example.local', 'topology-secret', true);

topo_check('modern-topology-success', $result['ok'] === true);
topo_same('modern-topology-api-family', 'modern', $result['api_family']);
topo_same('modern-topology-datacenter-count', 1, $result['datacenter_count']);
topo_same('modern-topology-cluster-count', 1, $result['cluster_count']);
topo_same('modern-topology-host-count', 2, $result['host_count']);
topo_same('modern-topology-vm-count', 3, $result['vm_count']);
topo_same('cluster-parent-datacenter', 'datacenter-1', $result['clusters'][0]['datacenter_external_id']);
topo_same('clustered-host-parent-cluster', 'domain-c1', $result['hosts'][0]['cluster_external_id']);
topo_same('clustered-host-parent-datacenter', 'datacenter-1', $result['hosts'][0]['datacenter_external_id']);
topo_same('standalone-host-no-cluster-guess', null, $result['hosts'][1]['cluster_external_id']);
topo_same('standalone-host-datacenter', 'datacenter-1', $result['hosts'][1]['datacenter_external_id']);
topo_same('clustered-vm-host', 'host-101', $result['vms'][0]['host_external_id']);
topo_same('clustered-vm-cluster', 'domain-c1', $result['vms'][0]['cluster_external_id']);
topo_same('clustered-vm-datacenter', 'datacenter-1', $result['vms'][0]['datacenter_external_id']);
topo_same('standalone-vm-host', 'host-102', $result['vms'][1]['host_external_id']);
topo_same('standalone-vm-no-cluster-guess', null, $result['vms'][1]['cluster_external_id']);
topo_same('standalone-vm-datacenter', 'datacenter-1', $result['vms'][1]['datacenter_external_id']);
topo_same('dc-only-vm-no-host-guess', null, $result['vms'][2]['host_external_id']);
topo_same('dc-only-vm-no-cluster-guess', null, $result['vms'][2]['cluster_external_id']);
topo_same('dc-only-vm-datacenter', 'datacenter-1', $result['vms'][2]['datacenter_external_id']);
topo_same('unresolved-cluster-parent-zero', 0, $result['unresolved_cluster_parent_count']);
topo_same('unresolved-host-cluster-one', 1, $result['unresolved_host_cluster_count']);
topo_same('unresolved-vm-host-one', 1, $result['unresolved_vm_host_count']);
topo_same('unresolved-vm-cluster-two', 2, $result['unresolved_vm_cluster_count']);
topo_check('result-declares-no-persistence', $result['persistent_inventory_written'] === false && $result['phpipam_objects_modified'] === false && $result['vcenter_objects_modified'] === false);

$serialized = json_encode($result);
topo_check('result-no-password', strpos($serialized, 'topology-secret') === false);
topo_check('result-no-username', strpos($serialized, 'svc-topology@example.local') === false);
topo_check('result-no-session-token', strpos($serialized, 'topology-token') === false);
topo_check('tls-propagated', array_reduce($calls, function ($carry, $call) { return $carry && $call['verify_tls'] === true; }, true));
topo_check('basic-auth-login-only', $calls[0]['username'] === 'svc-topology@example.local' && $calls[0]['password'] === 'topology-secret' && $calls[1]['username'] === null);
topo_check('session-cleanup-last', $calls[count($calls) - 1]['method'] === 'DELETE');
$sessionHeadersOk = true;
for ($i = 1; $i < count($calls); $i++) {
    if (!in_array('vmware-api-session-id: topology-token', $calls[$i]['headers'], true)) {
        $sessionHeadersOk = false;
    }
}
topo_check('session-header-all-authenticated-calls', $sessionHeadersOk);

$legacyCalls = array();
$legacyClient = new IntegrationVmwareVcenterTopology(function ($method, $url) use (&$legacyCalls) {
    $legacyCalls[] = array($method, $url);
    if ($method === 'POST') {
        return array('status' => 404, 'body' => '{}');
    }
    return array('status' => 500, 'body' => '{}');
});
topo_expect_exception('legacy-fails-closed', 'modern vCenter API', function () use ($legacyClient) {
    $legacyClient->read_topology('legacy-vcenter.example.local', 'user', 'pass', true);
});
topo_same('legacy-no-guessed-rest-crawl', 1, count($legacyCalls));

$permissionCalls = array();
$permissionClient = new IntegrationVmwareVcenterTopology(function ($method, $url) use (&$permissionCalls) {
    $permissionCalls[] = $method;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('permission-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    if (strpos($url, '/api/vcenter/datacenter') !== false) {
        return array('status' => 403, 'body' => '{}');
    }
    return array('status' => 200, 'body' => json_encode(array()));
});
topo_expect_exception('topology-403-distinguished', 'lacks permission', function () use ($permissionClient) {
    $permissionClient->read_topology('vcenter.example.local', 'limited', 'pass', true);
});
topo_check('topology-403-still-cleans-session', $permissionCalls === array('POST', 'GET', 'DELETE'));

$limitClient = new IntegrationVmwareVcenterTopology(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('limit-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    if (strpos($url, '/datacenter') !== false) {
        return array('status' => 200, 'body' => json_encode(array(array('datacenter' => 'dc-1', 'name' => 'DC'))));
    }
    if (strpos($url, '/cluster') !== false) {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    if (strpos($url, '/host') !== false) {
        $rows = array();
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = array('host' => 'host-' . $i, 'name' => 'host-' . $i);
        }
        return array('status' => 200, 'body' => json_encode($rows));
    }
    if (strpos($url, '/vm') !== false) {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 500, 'body' => '{}');
}, 8, 20, 2097152, 10000, 12);
topo_expect_exception('precomputed-request-limit-fails-closed', 'would exceed', function () use ($limitClient) {
    $limitClient->read_topology('vcenter.example.local', 'user', 'pass', true);
});

$conflictCalls = array();
$conflictClient = new IntegrationVmwareVcenterTopology(function ($method, $url) use (&$conflictCalls) {
    $conflictCalls[] = $method;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('conflict-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str((string)$query, $params);
    if ($path === '/api/vcenter/datacenter' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array(
            array('datacenter' => 'dc-a', 'name' => 'A'),
            array('datacenter' => 'dc-b', 'name' => 'B')
        )));
    }
    if ($path === '/api/vcenter/cluster' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array(array('cluster' => 'cluster-1', 'name' => 'C'))));
    }
    if ($path === '/api/vcenter/host' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    if ($path === '/api/vcenter/vm' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    if ($path === '/api/vcenter/cluster' && isset($params['datacenters'])) {
        return array('status' => 200, 'body' => json_encode(array(array('cluster' => 'cluster-1', 'name' => 'C'))));
    }
    return array('status' => 200, 'body' => json_encode(array()));
});
topo_expect_exception('conflicting-parent-fails-closed', 'conflicting parent relationships', function () use ($conflictClient) {
    $conflictClient->read_topology('vcenter.example.local', 'user', 'pass', true);
});
topo_check('conflict-still-cleans-session', $conflictCalls[count($conflictCalls) - 1] === 'DELETE');

print "\nPhase 5D.1 topology regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
