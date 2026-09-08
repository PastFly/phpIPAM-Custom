<?php

/**
 * Phase 5D.2 VMware vCenter datastore read-only regression.
 * Injected transports only; no real vCenter or production mutation.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php');
require_once($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastore.php');

$passed = 0;
$failed = 0;

function datastore_check($name, $condition)
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

function datastore_same($name, $expected, $actual)
{
    datastore_check($name, $expected === $actual);
}

function datastore_expect_exception($name, $needle, $callback)
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

$sourcePath = $root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastore.php';
$source = file_get_contents($sourcePath);
datastore_check('source-read-inventory', strpos($source, 'read_inventory') !== false);
datastore_check('source-read-detail', strpos($source, 'read_detail') !== false);
datastore_check('source-datacenter-filter', strpos($source, 'datastores?datacenters=') !== false);
datastore_check('source-stable-datastore-id', strpos($source, "'external_id'") !== false);
datastore_check('source-capacity-free-space', strpos($source, 'capacity_bytes') !== false && strpos($source, 'free_space_bytes') !== false);
datastore_check('source-no-put-patch', strpos($source, "'PUT'") === false && strpos($source, "'PATCH'") === false);
datastore_check('source-no-db-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
datastore_check('source-no-phpipam-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);
datastore_check('source-no-redirects', strpos($source, 'CURLOPT_FOLLOWLOCATION => false') !== false);

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
        return array('status' => 201, 'body' => json_encode('datastore-token'));
    }
    if ($method === 'DELETE' && substr($url, -12) === '/api/session') {
        return array('status' => 204, 'body' => '');
    }

    $path = parse_url($url, PHP_URL_PATH);
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str((string)$query, $params);

    if ($method === 'GET' && $path === '/api/vcenter/datastore' && $query === null) {
        return array('status' => 200, 'body' => json_encode(array(
            array('datastore' => 'datastore-11', 'name' => 'SAN-01', 'type' => 'VMFS', 'free_space' => 1000000000, 'capacity' => 2000000000),
            array('datastore' => 'datastore-12', 'name' => 'NFS-01', 'type' => 'NFS', 'free_space' => '3000000000', 'capacity' => '4000000000'),
            array('datastore' => 'datastore-13', 'name' => 'Shared-Unknown', 'type' => 'NFS41', 'free_space' => null, 'capacity' => null)
        )));
    }
    if ($method === 'GET' && $path === '/api/vcenter/datastore' && isset($params['datacenters']) && $params['datacenters'] === 'datacenter-1') {
        return array('status' => 200, 'body' => json_encode(array(
            array('datastore' => 'datastore-11', 'name' => 'SAN-01', 'type' => 'VMFS', 'free_space' => 1000000000, 'capacity' => 2000000000),
            array('datastore' => 'datastore-12', 'name' => 'NFS-01', 'type' => 'NFS', 'free_space' => '3000000000', 'capacity' => '4000000000')
        )));
    }
    if ($method === 'GET' && $path === '/api/vcenter/datastore' && isset($params['datacenters']) && $params['datacenters'] === 'datacenter-2') {
        return array('status' => 200, 'body' => json_encode(array()));
    }

    return array('status' => 500, 'body' => '{}');
};

$client = new IntegrationVmwareVcenterDatastore($transport);
$result = $client->read_inventory(
    'vcenter.example.local',
    'svc-datastore@example.local',
    'datastore-secret',
    array('datacenter-1', 'datacenter-2', 'datacenter-1'),
    true
);

datastore_check('inventory-success', $result['ok'] === true);
datastore_same('inventory-api-family', 'modern', $result['api_family']);
datastore_same('inventory-count', 3, $result['datastore_count']);
datastore_same('inventory-dedup-dc-filter-call-count', 5, count($calls));
datastore_same('datastore-id', 'datastore-11', $result['datastores'][0]['external_id']);
datastore_same('datastore-name', 'SAN-01', $result['datastores'][0]['name']);
datastore_same('datastore-type-normalized', 'vmfs', $result['datastores'][0]['type']);
datastore_same('datastore-capacity', 2000000000, $result['datastores'][0]['capacity_bytes']);
datastore_same('datastore-free-space', 1000000000, $result['datastores'][0]['free_space_bytes']);
datastore_same('datastore-string-int-normalized', 4000000000, $result['datastores'][1]['capacity_bytes']);
datastore_same('datastore-parent-dc', 'datacenter-1', $result['datastores'][0]['datacenter_external_id']);
datastore_same('datastore-parent-dc-second', 'datacenter-1', $result['datastores'][1]['datacenter_external_id']);
datastore_same('datastore-unresolved-parent', null, $result['datastores'][2]['datacenter_external_id']);
datastore_same('datastore-unresolved-count', 1, $result['unresolved_datacenter_count']);
datastore_check('inventory-no-persistence', $result['persistent_inventory_written'] === false && $result['phpipam_objects_modified'] === false && $result['vcenter_objects_modified'] === false);
$serialized = json_encode($result);
datastore_check('inventory-result-no-password', strpos($serialized, 'datastore-secret') === false);
datastore_check('inventory-result-no-username', strpos($serialized, 'svc-datastore@example.local') === false);
datastore_check('inventory-result-no-token', strpos($serialized, 'datastore-token') === false);
datastore_check('inventory-session-cleanup-last', $calls[count($calls) - 1]['method'] === 'DELETE');
datastore_check('inventory-tls-propagated', array_reduce($calls, function ($carry, $call) { return $carry && $call['verify_tls'] === true; }, true));
datastore_check('inventory-basic-auth-login-only', $calls[0]['username'] === 'svc-datastore@example.local' && $calls[0]['password'] === 'datastore-secret' && $calls[1]['username'] === null);

$detailCalls = array();
$detailClient = new IntegrationVmwareVcenterDatastore(function ($method, $url, $headers, $username, $password, $verifyTls) use (&$detailCalls) {
    $detailCalls[] = array($method, $url, $headers, $username, $password, $verifyTls);
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('detail-token'));
    }
    if ($method === 'GET' && substr($url, -35) === '/api/vcenter/datastore/datastore-11') {
        return array('status' => 200, 'body' => json_encode(array(
            'name' => 'SAN-01',
            'type' => 'VMFS',
            'accessible' => true,
            'free_space' => 900000000,
            'multiple_host_access' => true,
            'thin_provisioning_supported' => true
        )));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    return array('status' => 500, 'body' => '{}');
});
$detail = $detailClient->read_detail('vcenter.example.local', 'svc', 'pass', 'datastore-11', false);
datastore_check('detail-success', $detail['ok'] === true);
datastore_same('detail-id', 'datastore-11', $detail['datastore']['external_id']);
datastore_same('detail-name', 'SAN-01', $detail['datastore']['name']);
datastore_same('detail-type', 'vmfs', $detail['datastore']['type']);
datastore_same('detail-accessible', true, $detail['datastore']['accessible']);
datastore_same('detail-multiple-host', true, $detail['datastore']['multiple_host_access']);
datastore_same('detail-thin-supported', true, $detail['datastore']['thin_provisioning_supported']);
datastore_same('detail-free-space', 900000000, $detail['datastore']['free_space_bytes']);
datastore_same('detail-call-count', 3, count($detailCalls));
datastore_check('detail-tls-off-explicit-propagation', array_reduce($detailCalls, function ($carry, $call) { return $carry && $call[5] === false; }, true));
datastore_check('detail-session-cleanup', $detailCalls[2][0] === 'DELETE');

$legacyCalls = array();
$legacyClient = new IntegrationVmwareVcenterDatastore(function ($method, $url) use (&$legacyCalls) {
    $legacyCalls[] = array($method, $url);
    if ($method === 'POST') {
        return array('status' => 404, 'body' => '{}');
    }
    return array('status' => 500, 'body' => '{}');
});
datastore_expect_exception('legacy-fails-closed', 'modern vCenter API', function () use ($legacyClient) {
    $legacyClient->read_inventory('legacy.example.local', 'u', 'p', array(), true);
});
datastore_same('legacy-no-guessed-rest', 1, count($legacyCalls));

$unknownClient = new IntegrationVmwareVcenterDatastore(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('unknown-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    $query = parse_url($url, PHP_URL_QUERY);
    if ($query === null) {
        return array('status' => 200, 'body' => json_encode(array(array('datastore' => 'ds-1', 'name' => 'DS1', 'type' => 'VMFS', 'free_space' => 1, 'capacity' => 2))));
    }
    return array('status' => 200, 'body' => json_encode(array(array('datastore' => 'ds-unknown', 'name' => 'Other', 'type' => 'VMFS', 'free_space' => 1, 'capacity' => 2))));
});
datastore_expect_exception('filtered-unknown-id-fails-closed', 'unknown datastore external id', function () use ($unknownClient) {
    $unknownClient->read_inventory('vcenter.example.local', 'u', 'p', array('dc-1'), true);
});

$conflictClient = new IntegrationVmwareVcenterDatastore(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('conflict-token'));
    }
    if ($method === 'DELETE') {
        return array('status' => 204, 'body' => '');
    }
    $query = parse_url($url, PHP_URL_QUERY);
    if ($query === null) {
        return array('status' => 200, 'body' => json_encode(array(array('datastore' => 'ds-shared', 'name' => 'Shared', 'type' => 'VMFS', 'free_space' => 1, 'capacity' => 2))));
    }
    return array('status' => 200, 'body' => json_encode(array(array('datastore' => 'ds-shared', 'name' => 'Shared', 'type' => 'VMFS', 'free_space' => 1, 'capacity' => 2))));
});
datastore_expect_exception('conflicting-datacenter-parent-fails-closed', 'conflicting Datacenter relationships', function () use ($conflictClient) {
    $conflictClient->read_inventory('vcenter.example.local', 'u', 'p', array('dc-1', 'dc-2'), true);
});

$permissionClient = new IntegrationVmwareVcenterDatastore(function ($method) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('permission-token'));
    }
    if ($method === 'GET') {
        return array('status' => 403, 'body' => '{}');
    }
    return array('status' => 204, 'body' => '');
});
datastore_expect_exception('inventory-403-distinguished', 'lacks permission', function () use ($permissionClient) {
    $permissionClient->read_inventory('vcenter.example.local', 'u', 'p', array(), true);
});

$badDetailClient = new IntegrationVmwareVcenterDatastore(function ($method) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bad-detail-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array(
            'name' => 'DS',
            'type' => 'VMFS',
            'accessible' => 'yes',
            'multiple_host_access' => true,
            'thin_provisioning_supported' => true
        )));
    }
    return array('status' => 204, 'body' => '');
});
datastore_expect_exception('detail-invalid-boolean-rejected', 'accessible', function () use ($badDetailClient) {
    $badDetailClient->read_detail('vcenter.example.local', 'u', 'p', 'ds-1', true);
});

$limitClient = new IntegrationVmwareVcenterDatastore(function () {
    return array('status' => 500, 'body' => '{}');
}, 8, 20, 2097152, 2500, 2);
datastore_expect_exception('datacenter-filter-limit-before-network', 'filter set exceeded', function () use ($limitClient) {
    $limitClient->read_inventory('vcenter.example.local', 'u', 'p', array('dc-1', 'dc-2', 'dc-3'), true);
});

print "\nPhase 5D.2 datastore regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
