<?php

require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterSoapReadOnly.php';

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
}

function soap_envelope($body)
{
    return '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
}

$calls = array();
$transport = function ($method, $url, $headers, $body, $verifyTls, $connectTimeout, $requestTimeout) use (&$calls) {
    $calls[] = array(
        'method' => $method,
        'url' => $url,
        'headers' => $headers,
        'body' => $body,
        'verify_tls' => $verifyTls,
        'connect_timeout' => $connectTimeout,
        'request_timeout' => $requestTimeout
    );

    if (strpos($body, '<RetrieveServiceContent') !== false) {
        return array(
            'status' => 200,
            'headers' => array('Content-Type: text/xml'),
            'body' => soap_envelope('<RetrieveServiceContentResponse xmlns="urn:vim25"><returnval><sessionManager type="SessionManager">SessionManager</sessionManager><propertyCollector type="PropertyCollector">propertyCollector</propertyCollector><perfManager type="PerformanceManager">PerfMgr</perfManager></returnval></RetrieveServiceContentResponse>')
        );
    }

    if (strpos($body, '<Login') !== false) {
        return array(
            'status' => 200,
            'headers' => array('Set-Cookie: vmware_soap_session="52abc"; Path=/; HttpOnly; Secure'),
            'body' => soap_envelope('<LoginResponse xmlns="urn:vim25"><returnval><key>52abc</key><userName>svc_phpipam</userName></returnval></LoginResponse>')
        );
    }

    if (strpos($body, '<RetrieveProperties xmlns="urn:vim25">') !== false) {
        return array(
            'status' => 200,
            'headers' => array('Content-Type: text/xml'),
            'body' => soap_envelope('<RetrievePropertiesResponse xmlns="urn:vim25" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><returnval><obj type="HostSystem">host-42</obj><propSet><name>summary</name><val xsi:type="HostListSummary"><hardware><vendor>Dell Inc.</vendor><model>PowerEdge R740</model><cpuModel>Intel(R) Xeon(R) Gold</cpuModel><cpuMhz>2400</cpuMhz><numCpuPkgs>2</numCpuPkgs><numCpuCores>32</numCpuCores><numCpuThreads>64</numCpuThreads><memorySize>274877906944</memorySize></hardware><quickStats><overallCpuUsage>12000</overallCpuUsage><overallMemoryUsage>98304</overallMemoryUsage><uptime>864123</uptime></quickStats><runtime><connectionState>connected</connectionState><powerState>poweredOn</powerState><inMaintenanceMode>false</inMaintenanceMode><bootTime>2026-08-30T10:00:00Z</bootTime></runtime><config><name>esxi-1-04.rbank.local</name><product><fullName>VMware ESXi 7.0.3 build-12345678</fullName><version>7.0.3</version><build>12345678</build></product></config></val></propSet></returnval></RetrievePropertiesResponse>')
        );
    }

    if (strpos($body, '<Logout') !== false) {
        return array(
            'status' => 200,
            'headers' => array(),
            'body' => soap_envelope('<LogoutResponse xmlns="urn:vim25"/>')
        );
    }

    return array('status' => 500, 'headers' => array(), 'body' => 'unexpected request');
};

$client = new IntegrationVmwareVcenterSoapReadOnly($transport, 3, 7, 1048576);
$result = $client->read_host_summary('vc.example.test', 'svc_phpipam', 'secret<&', 'host-42', false);

assert_true(count($calls) === 4, 'service/login/read/logout call count');
assert_true($calls[0]['method'] === 'POST', 'SOAP uses POST only');
assert_true($calls[0]['url'] === 'https://vc.example.test/sdk', 'normalized /sdk endpoint');
foreach ($calls as $call) assert_true($call['verify_tls'] === false, 'saved TLS mode is passed through without hidden downgrade logic');
assert_true(strpos($calls[1]['body'], 'secret&lt;&amp;') !== false, 'credential is XML escaped');
assert_true(strpos($calls[2]['body'], '<RetrieveProperties xmlns="urn:vim25">') !== false, 'compatible RetrieveProperties method is used');
assert_true(strpos($calls[2]['body'], '<RetrievePropertiesEx') === false, '4.1-only RetrievePropertiesEx is not used');
assert_true(strpos($calls[2]['body'], '<obj type="HostSystem">host-42</obj>') !== false, 'stable host id reused as HostSystem MOR');
assert_true(in_array('Cookie: vmware_soap_session="52abc"', $calls[2]['headers'], true), 'authenticated property read uses SOAP session cookie');
assert_true(in_array('Cookie: vmware_soap_session="52abc"', $calls[3]['headers'], true), 'logout uses SOAP session cookie');

assert_true($result['host_external_id'] === 'host-42', 'host id normalized');
assert_true($result['hardware']['vendor'] === 'Dell Inc.', 'vendor parsed');
assert_true($result['hardware']['model'] === 'PowerEdge R740', 'model parsed');
assert_true($result['hardware']['cpu_mhz_per_core'] === 2400, 'CPU MHz parsed');
assert_true($result['hardware']['cpu_packages'] === 2, 'CPU packages parsed');
assert_true($result['hardware']['cpu_cores'] === 32, 'CPU cores parsed');
assert_true($result['hardware']['cpu_threads'] === 64, 'CPU threads parsed');
assert_true($result['hardware']['memory_total_bytes'] === 274877906944, 'physical memory parsed');
assert_true($result['usage']['cpu_used_mhz'] === 12000, 'current CPU usage parsed');
assert_true($result['usage']['cpu_capacity_mhz'] === 76800, 'CPU capacity derived');
assert_true(abs($result['usage']['cpu_used_percent'] - 15.63) < 0.01, 'CPU percent derived');
assert_true($result['usage']['memory_used_bytes'] === 103079215104, 'memory usage MB converted to bytes');
assert_true($result['usage']['memory_free_bytes'] === 171798691840, 'free memory derived');
assert_true(abs($result['usage']['memory_used_percent'] - 37.5) < 0.01, 'memory percent derived');
assert_true($result['usage']['uptime_seconds'] === 864123, 'uptime parsed');
assert_true($result['runtime']['connection_state'] === 'connected', 'connection state parsed');
assert_true($result['runtime']['maintenance_mode'] === false, 'maintenance state parsed');
assert_true($result['product']['name'] === 'esxi-1-04.rbank.local', 'host name parsed');
assert_true($result['product']['version'] === '7.0.3', 'ESXi version parsed');
assert_true($result['soap']['performance_manager'] === 'PerfMgr', 'PerformanceManager MOR discovered for next slice');
assert_true($result['soap']['property_method'] === 'RetrieveProperties', 'compatible property method reported');

$beforeInvalid = count($calls);
$thrown = false;
try {
    $client->read_host_summary('vc.example.test', 'u', 'p', 'vm-42', true);
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
assert_true($thrown, 'non-HostSystem MOR is rejected');
assert_true(count($calls) === $beforeInvalid, 'invalid MOR fails before network');

$thrown = false;
try {
    $client->read_host_summary('https://vc.example.test/ui', 'u', 'p', 'host-42', true);
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
assert_true($thrown, 'endpoint path is rejected');

print "PHASE5F_SOAP_HOST_SUMMARY_REGRESSION_OK\n";
