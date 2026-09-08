<?php

/**
 * Phase 5C.3 VMware vCenter guest NIC/MAC/IP observation regression.
 *
 * Injected transports only. No real vCenter, credentials, live database or
 * phpIPAM object mutation is required.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php');
require_once($root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php');

$passed = 0;
$failed = 0;

function guest_inv_check_true($name, $condition)
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

function guest_inv_check_same($name, $expected, $actual)
{
    guest_inv_check_true($name, $expected === $actual);
}

function guest_inv_expect_exception_contains($name, $needle, $callback)
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

$classPath = $root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php';
$classSource = file_get_contents($classPath);
guest_inv_check_true('source-read-vm-interfaces-method', strpos($classSource, 'read_vm_interfaces') !== false);
guest_inv_check_true('source-guest-interface-get-endpoint', strpos($classSource, '/guest/networking/interfaces') !== false);
guest_inv_check_true('source-no-put-patch', strpos($classSource, "'PUT'") === false && strpos($classSource, "'PATCH'") === false);
guest_inv_check_true('source-no-persistence-sql', strpos($classSource, 'INSERT INTO') === false && strpos($classSource, 'UPDATE ') === false && strpos($classSource, 'DELETE FROM') === false);
guest_inv_check_true('source-no-phpipam-object-write', strpos($classSource, 'object_modify') === false && strpos($classSource, 'modify_object') === false);
guest_inv_check_true('source-no-follow-redirects', strpos($classSource, 'CURLOPT_FOLLOWLOCATION => false') !== false);
guest_inv_check_true('source-https-protocol-guard', strpos($classSource, 'CURLPROTO_HTTPS') !== false);
guest_inv_check_true('source-bounded-vm-requests', strpos($classSource, 'maxVmRequests') !== false);

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
        return array('status' => 201, 'body' => json_encode('guest-network-token'));
    }
    if ($method === 'GET' && strpos($url, '/vm/vm-101/guest/networking/interfaces') !== false) {
        return array('status' => 200, 'body' => json_encode(array(
            array(
                'nic' => '4000',
                'mac_address' => '00-50-56-AA-BB-CC',
                'ip' => array(
                    'ip_addresses' => array(
                        array('ip_address' => '10.20.30.40', 'prefix_length' => 24, 'origin' => 'DHCP', 'state' => 'PREFERRED'),
                        array('ip_address' => '2001:0db8:0000:0000:0000:0000:0000:0010', 'prefix_length' => '64', 'origin' => 'MANUAL', 'state' => 'PREFERRED')
                    )
                )
            ),
            array(
                'nic' => '4001',
                'mac_address' => '005056112233',
                'ip' => array('ip_addresses' => array())
            )
        )));
    }
    if ($method === 'GET' && strpos($url, '/vm/vm-102/guest/networking/interfaces') !== false) {
        return array('status' => 503, 'body' => '{"error_type":"SERVICE_UNAVAILABLE"}');
    }
    if ($method === 'GET' && strpos($url, '/vm/vm-103/guest/networking/interfaces') !== false) {
        return array('status' => 404, 'body' => '{"error_type":"NOT_FOUND"}');
    }
    if ($method === 'DELETE' && substr($url, -12) === '/api/session') {
        return array('status' => 204, 'body' => '');
    }
    return array('status' => 500, 'body' => '{}');
};

$client = new IntegrationVmwareVcenterGuestNetwork($transport);
$result = $client->read_vm_interfaces(
    'vcenter.example.local',
    'svc-network@example.local',
    'guest-secret',
    array('vm-101', 'vm-102', 'vm-103', 'vm-101'),
    true
);

guest_inv_check_true('modern-guest-success', $result['ok'] === true);
guest_inv_check_same('modern-guest-api-family', 'modern', $result['api_family']);
guest_inv_check_same('duplicate-vm-id-deduplicated', 3, $result['vm_count']);
guest_inv_check_same('interface-count', 2, $result['interface_count']);
guest_inv_check_same('ip-count', 2, $result['ip_count']);
guest_inv_check_same('first-vm-status-ok', 'ok', $result['vms'][0]['status']);
guest_inv_check_same('tools-unavailable-soft-state', 'tools_unavailable', $result['vms'][1]['status']);
guest_inv_check_same('vm-not-found-soft-state', 'vm_not_found', $result['vms'][2]['status']);
guest_inv_check_same('nic-id-normalized', '4000', $result['vms'][0]['interfaces'][0]['nic_external_id']);
guest_inv_check_same('mac-hyphen-canonicalized', '00:50:56:aa:bb:cc', $result['vms'][0]['interfaces'][0]['mac_address']);
guest_inv_check_same('mac-compact-canonicalized', '00:50:56:11:22:33', $result['vms'][0]['interfaces'][1]['mac_address']);
guest_inv_check_same('ipv4-canonical', '10.20.30.40', $result['vms'][0]['interfaces'][0]['ip_addresses'][0]['ip_address']);
guest_inv_check_same('ipv4-prefix', 24, $result['vms'][0]['interfaces'][0]['ip_addresses'][0]['prefix_length']);
guest_inv_check_same('ip-origin-normalized', 'dhcp', $result['vms'][0]['interfaces'][0]['ip_addresses'][0]['origin']);
guest_inv_check_same('ip-state-normalized', 'preferred', $result['vms'][0]['interfaces'][0]['ip_addresses'][0]['state']);
guest_inv_check_same('ipv6-canonicalized', '2001:db8::10', $result['vms'][0]['interfaces'][0]['ip_addresses'][1]['ip_address']);
guest_inv_check_same('ipv6-prefix-string-normalized', 64, $result['vms'][0]['interfaces'][0]['ip_addresses'][1]['prefix_length']);
guest_inv_check_same('flat-ip-vm-link', 'vm-101', $result['ip_observations'][0]['vm_external_id']);
guest_inv_check_same('flat-ip-nic-link', '4000', $result['ip_observations'][0]['nic_external_id']);
guest_inv_check_same('flat-ip-mac-link', '00:50:56:aa:bb:cc', $result['ip_observations'][0]['mac_address']);
guest_inv_check_same('modern-call-count', 5, count($calls));
guest_inv_check_true(
    'modern-call-order',
    $calls[0]['method'] === 'POST' &&
    strpos($calls[1]['url'], '/vm/vm-101/guest/networking/interfaces') !== false &&
    strpos($calls[2]['url'], '/vm/vm-102/guest/networking/interfaces') !== false &&
    strpos($calls[3]['url'], '/vm/vm-103/guest/networking/interfaces') !== false &&
    $calls[4]['method'] === 'DELETE'
);
guest_inv_check_true(
    'basic-creds-login-only',
    $calls[0]['username'] === 'svc-network@example.local' &&
    $calls[0]['password'] === 'guest-secret' &&
    $calls[1]['username'] === null &&
    $calls[2]['username'] === null &&
    $calls[3]['username'] === null &&
    $calls[4]['username'] === null
);
$headerOk = true;
for ($i = 1; $i <= 4; $i++) {
    if (!in_array('vmware-api-session-id: guest-network-token', $calls[$i]['headers'], true)) {
        $headerOk = false;
    }
}
guest_inv_check_true('session-header-on-guest-and-logout-calls', $headerOk);
guest_inv_check_true('tls-on-propagated-all-calls', array_reduce($calls, function ($carry, $call) { return $carry && $call['verify_tls'] === true; }, true));
$serialized = json_encode($result);
guest_inv_check_true('result-no-password', strpos($serialized, 'guest-secret') === false);
guest_inv_check_true('result-no-username', strpos($serialized, 'svc-network@example.local') === false);
guest_inv_check_true('result-no-session-token', strpos($serialized, 'guest-network-token') === false);
guest_inv_check_true('result-no-raw-service-body', strpos($serialized, 'SERVICE_UNAVAILABLE') === false);

$invalidVmClient = new IntegrationVmwareVcenterGuestNetwork(function () {
    throw new RuntimeException('transport must not run for invalid VM id');
});
guest_inv_expect_exception_contains('vm-id-path-injection-rejected', 'VM external id', function () use ($invalidVmClient) {
    $invalidVmClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array('vm-1/../../api/session'), true);
});
guest_inv_expect_exception_contains('empty-vm-set-rejected', 'At least one VM', function () use ($invalidVmClient) {
    $invalidVmClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array(), true);
});

$boundedClient = new IntegrationVmwareVcenterGuestNetwork(function () {
    throw new RuntimeException('transport must not run for oversized VM set');
}, 8, 20, 1048576, 1);
guest_inv_expect_exception_contains('vm-request-limit-enforced', 'request set exceeded', function () use ($boundedClient) {
    $boundedClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array('vm-1', 'vm-2'), true);
});

$permissionCalls = array();
$permissionClient = new IntegrationVmwareVcenterGuestNetwork(function ($method, $url) use (&$permissionCalls) {
    $permissionCalls[] = $method;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('permission-token'));
    }
    if ($method === 'GET') {
        return array('status' => 403, 'body' => '{}');
    }
    return array('status' => 204, 'body' => '');
});
guest_inv_expect_exception_contains('guest-network-403-distinguished', 'lacks permission', function () use ($permissionClient) {
    $permissionClient->read_vm_interfaces('vcenter.example.local', 'limited', 'pass', array('vm-1'), true);
});
guest_inv_check_true('guest-network-403-still-logs-out', $permissionCalls === array('POST', 'GET', 'DELETE'));

$malformedCalls = array();
$malformedClient = new IntegrationVmwareVcenterGuestNetwork(function ($method) use (&$malformedCalls) {
    $malformedCalls[] = $method;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('malformed-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => '{bad-json');
    }
    return array('status' => 204, 'body' => '');
});
guest_inv_expect_exception_contains('malformed-interface-json-rejected', 'not valid JSON', function () use ($malformedClient) {
    $malformedClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array('vm-1'), true);
});
guest_inv_check_true('malformed-interface-still-logs-out', $malformedCalls === array('POST', 'GET', 'DELETE'));

$badMacClient = new IntegrationVmwareVcenterGuestNetwork(function ($method) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bad-mac-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array(array('nic' => '4000', 'mac_address' => 'not-a-mac'))));
    }
    return array('status' => 204, 'body' => '');
});
guest_inv_expect_exception_contains('invalid-mac-rejected', 'MAC address', function () use ($badMacClient) {
    $badMacClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array('vm-1'), true);
});

$badIpClient = new IntegrationVmwareVcenterGuestNetwork(function ($method) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bad-ip-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array(array(
            'nic' => '4000',
            'ip' => array('ip_addresses' => array(array('ip_address' => '999.1.1.1', 'prefix_length' => 24)))
        ))));
    }
    return array('status' => 204, 'body' => '');
});
guest_inv_expect_exception_contains('invalid-ip-rejected', 'IP address', function () use ($badIpClient) {
    $badIpClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array('vm-1'), true);
});

$badPrefixClient = new IntegrationVmwareVcenterGuestNetwork(function ($method) {
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('bad-prefix-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array(array(
            'nic' => '4000',
            'ip' => array('ip_addresses' => array(array('ip_address' => '10.0.0.1', 'prefix_length' => 129)))
        ))));
    }
    return array('status' => 204, 'body' => '');
});
guest_inv_expect_exception_contains('invalid-ip-prefix-rejected', 'prefix length', function () use ($badPrefixClient) {
    $badPrefixClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array('vm-1'), true);
});

$legacyCalls = array();
$legacyClient = new IntegrationVmwareVcenterGuestNetwork(function ($method, $url) use (&$legacyCalls) {
    $legacyCalls[] = array($method, $url);
    if ($method === 'POST' && substr($url, -12) === '/api/session') {
        return array('status' => 404, 'body' => '{}');
    }
    if ($method === 'POST' && substr($url, -28) === '/rest/com/vmware/cis/session') {
        return array('status' => 200, 'body' => json_encode(array('value' => 'legacy-guest-token')));
    }
    if ($method === 'DELETE') {
        return array('status' => 200, 'body' => '{}');
    }
    return array('status' => 500, 'body' => '{}');
});
guest_inv_expect_exception_contains('legacy-guest-network-not-guessed', 'requires modern', function () use ($legacyClient) {
    $legacyClient->read_vm_interfaces('legacy-vcenter.example.local', 'user', 'pass', array('vm-1'), true);
});
guest_inv_check_true(
    'legacy-unsupported-still-logs-out',
    count($legacyCalls) === 3 &&
    $legacyCalls[0][0] === 'POST' &&
    $legacyCalls[1][0] === 'POST' &&
    $legacyCalls[2][0] === 'DELETE'
);

$tlsCalls = array();
$tlsOffClient = new IntegrationVmwareVcenterGuestNetwork(function ($method, $url, $headers, $username, $password, $verifyTls) use (&$tlsCalls) {
    $tlsCalls[] = $verifyTls;
    if ($method === 'POST') {
        return array('status' => 201, 'body' => json_encode('guest-tls-off-token'));
    }
    if ($method === 'GET') {
        return array('status' => 200, 'body' => json_encode(array()));
    }
    return array('status' => 204, 'body' => '');
});
$tlsOff = $tlsOffClient->read_vm_interfaces('vcenter.example.local', 'user', 'pass', array('vm-1'), false);
guest_inv_check_true('tls-off-explicit-result', $tlsOff['verify_tls'] === false);
guest_inv_check_true('tls-off-propagated-all-calls', $tlsCalls === array(false, false, false));

print "\nPhase 5C.3 VMware guest network regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
