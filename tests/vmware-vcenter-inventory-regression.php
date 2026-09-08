<?php

/**
 * Phase 5C VMware vCenter read-only inventory regression suite.
 *
 * Uses injected fake transports only. No real vCenter, stored credential or
 * production database is required.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php');
require_once($root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterInventory.php');

$passed = 0;
$failed = 0;

function inv_check_true($name, $condition)
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

function inv_check_same($name, $expected, $actual)
{
    inv_check_true($name, $expected === $actual);
}

function inv_expect_exception_contains($name, $needle, $callback)
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

$classSource = file_get_contents($root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterInventory.php');
inv_check_true('source-readonly-no-put-patch', strpos($classSource, "'PUT'") === false && strpos($classSource, "'PATCH'") === false);
inv_check_true('source-no-persistence-sql', strpos($classSource, 'INSERT INTO') === false && strpos($classSource, 'UPDATE ') === false && strpos($classSource, 'DELETE FROM') === false);
inv_check_true('source-no-follow-redirects', strpos($classSource, 'CURLOPT_FOLLOWLOCATION => false') !== false);
inv_check_true('source-https-protocol-guard', strpos($classSource, 'CURLPROTO_HTTPS') !== false);
inv_check_true('source-bounded-collection', strpos($classSource, 'maxCollectionItems') !== false);
inv_check_true('source-reuses-base-endpoint-validator', strpos($classSource, 'normalize_endpoint($endpoint)') !== false);

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
        return array('status' => 201, 'body' => json_encode('inventory-modern-token'));
    }
    if ($method === 'GET' && substr($url, -23) === '/api/vcenter/datacenter') {
        return array('status' => 200, 'body' => json_encode(array(
            array('datacenter' => 'datacenter-11', 'name' => 'DC-A'),
            array('datacenter' => 'datacenter-22', 'name' => 'DC-B')
        )));
    }
    if ($method === 'GET' && substr($url, -20) === '/api/vcenter/cluster') {
        return array('status' => 200, 'body' => json_encode(array(
            array('cluster' => 'domain-c101', 'name' => 'Cluster-A', 'ha_enabled' => true, 'drs_enabled' => true),
            array('cluster' => 'domain-c202', 'name' => 'Cluster-B', 'ha_enabled' => false, 'drs_enabled' => true)
        )));
    }
    if ($method === 'DELETE' && substr($url, -12) === '/api/session') {
        return array('status' => 204, 'body' => '');
    }
    return array('status' => 500, 'body' => '{}');
};

$modernClient = new IntegrationVmwareVcenterInventory($modernTransport);
$modern = $modernClient->read_datacenters_and_clusters(
    'vcenter.example.local',
    'svc-inventory@example.local',
    'inventory-secret',
    true
);

inv_check_true('modern-success', $modern['ok'] === true);
inv_check_same('modern-api-family', 'modern', $modern['api_family']);
inv_check_same('modern-datacenter-count', 2, $modern['datacenter_count']);
inv_check_same('modern-cluster-count', 2, $modern['cluster_count']);
inv_check_same('modern-datacenter-id-normalized', 'datacenter-11', $modern['datacenters'][0]['external_id']);
inv_check_same('modern-cluster-id-normalized', 'domain-c101', $modern['clusters'][0]['external_id']);
inv_check_same('modern-cluster-name-normalized', 'Cluster-A', $modern['clusters'][0]['name']);
inv_check_true('modern-cluster-flags-normalized', $modern['clusters'][0]['ha_enabled'] === true && $modern['clusters'][0]['drs_enabled'] === true);
inv_check_same('modern-cluster-parent-not-inferred', null, $modern['clusters'][0]['datacenter_external_id']);
inv_check_same('modern-call-count', 4, count($modernCalls));
inv_check_true('modern-call-order', $modernCalls[0]['method'] === 'POST' && strpos($modernCalls[1]['url'], '/api/vcenter/datacenter') !== false && strpos($modernCalls[2]['url'], '/api/vcenter/cluster') !== false && $modernCalls[3]['method'] === 'DELETE');
inv_check_true('modern-basic-creds-login-only', $modernCalls[0]['username'] === 'svc-inventory@example.local' && $modernCalls[0]['password'] === 'inventory-secret' && $modernCalls[1]['username'] === null && $modernCalls[2]['username'] === null && $modernCalls[3]['username'] === null);
inv_check_true('modern-session-header-on-both-gets', in_array('vmware-api-session-id: inventory-modern-token', $modernCalls[1]['headers'], true) && in_array('vmware-api-session-id: inventory-modern-token', $modernCalls[2]['headers'], true));
inv_check_true('modern-tls-propagated', $modernCalls[0]['verify_tls'] === true && $modernCalls[1]['verify_tls'] === true && $modernCalls[2]['verify_tls'] === true && $modernCalls[3]['verify_tls'] === true);
$modernSerialized = json_encode($modern);
inv_check_true('modern-result-no-password', strpos($modernSerialized, 'inventory-secret') === false);
inv_check_true('modern-result-no-username', strpos($modernSerialized, 'svc-inventory@example.local') === false);
inv_check_true('modern-result-no-session-token', strpos($modernSerialized, 'inventory-modern-token') === false);

$legacyCalls = array();
$legacyTransport = function ($method, $url, $headers, $username, $password, $verifyTls) use (&$legacyCalls) {
    $legacyCalls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'verify_tls' => $verifyTls);

    if ($method === 'POST' && substr($url, -12) === '/api/session') {
        return array('status' => 404, 'body' => '{}');
    }
    if ($method === 'POST' && substr($url, -28) === '/rest/com/vmware/cis/session') {
        return array('status' => 200, 'body' => json_encode(array('value' => 'inventory-legacy-token')));
    }
    if ($method === 'GET' && substr($url, -24) === '/rest/vcenter/datacenter') {
        return array('status' => 200, 'body' => json_encode(array('value' => array(
            array('datacenter' => 'datacenter-old', 'name' => 'Legacy-DC')
        ))));
    }
    if ($method === 'GET' && substr($url, -21) === '/rest/vcenter/cluster') {
        return array('status' => 200, 'body' => json_encode(array('value' => array(
            array('cluster' => 'domain-c-old', 'name' => 'Legacy-Cluster', 'ha_enabled' => true, 'drs_enabled' => false)
        ))));
    }
    if ($method === 'DELETE' && substr($url, -28) === '/rest/com/vmware/cis/session') {
        return array('status' => 200, 'body' => '{}');
    }
    return array('status' => 500, 'body' => '{}');
};

$legacyClient = new IntegrationVmwareVcenterInventory($legacyTransport);
$legacy = $legacyClient->read_datacenters_and_clusters('legacy-vcenter.example.local', 'legacy-user', 'legacy-pass', true);
inv_check_same('legacy-api-family', 'legacy', $legacy['api_family']);
inv_check_same('legacy-datacenter-count', 1, $legacy['datacenter_count']);
inv_check_same('legacy-cluster-count', 1, $legacy['cluster_count']);
inv_check_same('legacy-datacenter-id', 'datacenter-old', $legacy['datacenters'][0]['external_id']);
inv_check_same('legacy-cluster-id', 'domain-c-old', $legacy['clusters'][0]['external_id']);
inv_check_same('legacy-call-count', 5, count($legacyCalls));
inv_check_true('legacy-fallback-order', strpos($legacyCalls[0]['url'], '/api/session') !== false && strpos($legacyCalls[1]['url'], '/rest/com/vmware/cis/session') !== false && strpos($legacyCalls[2]['url'], '/rest/vcenter/datacenter') !== false && strpos($legacyCalls[3]['url'], '/rest/vcenter/cluster') !== false && $legacyCalls[4]['method'] === 'DELETE');

$malformedCalls = array();
$malformedClient = new IntegrationVmwareVcenterInventory(function ($method, $url) use (&$malformedCalls) {
    $malformedCalls[] = $method;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('malformed-token'));
    }
    if ($method === 'GET' && strpos($url, '/datacenter') !== false) {
        return array('status' => 200, 'body' => '{not-json');
    }
    return array('status' => 204, 'body' => '');
});
inv_expect_exception_contains('malformed-datacenter-json-rejected', 'not valid JSON', function () use ($malformedClient) {
    $malformedClient->read_datacenters_and_clusters('vcenter.example.local', 'user', 'pass', true);
});
inv_check_true('malformed-response-still-logs-out', $malformedCalls === array('POST', 'GET', 'DELETE'));

$permissionCalls = array();
$permissionClient = new IntegrationVmwareVcenterInventory(function ($method, $url) use (&$permissionCalls) {
    $permissionCalls[] = array($method, $url);
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('permission-token'));
    }
    if ($method === 'GET' && strpos($url, '/datacenter') !== false) {
        return array('status' => 200, 'body' => json_encode(array(array('datacenter' => 'dc-1', 'name' => 'DC1'))));
    }
    if ($method === 'GET' && strpos($url, '/cluster') !== false) {
        return array('status' => 403, 'body' => '{}');
    }
    return array('status' => 204, 'body' => '');
});
inv_expect_exception_contains('cluster-403-distinguished', 'Cluster inventory', function () use ($permissionClient) {
    $permissionClient->read_datacenters_and_clusters('vcenter.example.local', 'limited', 'pass', true);
});
inv_check_true('cluster-failure-still-logs-out', count($permissionCalls) === 4 && $permissionCalls[3][0] === 'DELETE');

$invalidRowClient = new IntegrationVmwareVcenterInventory(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('row-token'));
    }
    if ($method === 'GET' && strpos($url, '/datacenter') !== false) {
        return array('status' => 200, 'body' => json_encode(array(array('name' => 'Missing-ID'))));
    }
    return array('status' => 204, 'body' => '');
});
inv_expect_exception_contains('missing-external-id-rejected', 'invalid external id', function () use ($invalidRowClient) {
    $invalidRowClient->read_datacenters_and_clusters('vcenter.example.local', 'user', 'pass', true);
});

$boundedClient = new IntegrationVmwareVcenterInventory(function ($method, $url) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bounded-token'));
    }
    if ($method === 'GET' && strpos($url, '/datacenter') !== false) {
        return array('status' => 200, 'body' => json_encode(array(
            array('datacenter' => 'dc-1', 'name' => 'DC1'),
            array('datacenter' => 'dc-2', 'name' => 'DC2')
        )));
    }
    return array('status' => 204, 'body' => '');
}, 8, 20, 2097152, 1);
inv_expect_exception_contains('collection-limit-enforced', 'collection exceeded', function () use ($boundedClient) {
    $boundedClient->read_datacenters_and_clusters('vcenter.example.local', 'user', 'pass', true);
});

$tlsCalls = array();
$tlsOffClient = new IntegrationVmwareVcenterInventory(function ($method, $url, $headers, $username, $password, $verifyTls) use (&$tlsCalls) {
    $tlsCalls[] = $verifyTls;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('tls-off-inventory-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 204, 'body' => '');
});
$tlsOff = $tlsOffClient->read_datacenters_and_clusters('vcenter.example.local', 'user', 'pass', false);
inv_check_true('tls-off-explicit-result', $tlsOff['verify_tls'] === false);
inv_check_true('tls-off-propagated-all-requests', $tlsCalls === array(false, false, false, false));

print "\nPhase 5C VMware inventory regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
