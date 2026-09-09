<?php

require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterPerformance.php';

function p5f_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
}

function p5f_env($body)
{
    return '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
}

$calls = array();
$transport = function ($method, $url, $headers, $body, $verifyTls, $connectTimeout, $requestTimeout) use (&$calls) {
    $calls[] = array('method'=>$method,'url'=>$url,'headers'=>$headers,'body'=>$body,'verify_tls'=>$verifyTls);

    if (strpos($body, '<RetrieveServiceContent') !== false) {
        return array('status'=>200,'headers'=>array(),'body'=>p5f_env('<RetrieveServiceContentResponse xmlns="urn:vim25"><returnval><sessionManager type="SessionManager">SessionManager</sessionManager><propertyCollector type="PropertyCollector">propertyCollector</propertyCollector><perfManager type="PerformanceManager">PerfMgr</perfManager></returnval></RetrieveServiceContentResponse>'));
    }
    if (strpos($body, '<Login') !== false) {
        return array('status'=>200,'headers'=>array('Set-Cookie: vmware_soap_session="abc123"; Path=/; HttpOnly; Secure'),'body'=>p5f_env('<LoginResponse xmlns="urn:vim25"><returnval><key>abc123</key></returnval></LoginResponse>'));
    }
    if (strpos($body, '<QueryPerfProviderSummary') !== false) {
        return array('status'=>200,'headers'=>array(),'body'=>p5f_env('<QueryPerfProviderSummaryResponse xmlns="urn:vim25"><returnval><currentSupported>true</currentSupported><summarySupported>true</summarySupported><refreshRate>20</refreshRate></returnval></QueryPerfProviderSummaryResponse>'));
    }
    if (strpos($body, '<RetrieveProperties xmlns="urn:vim25">') !== false && strpos($body, '<pathSet>perfCounter</pathSet>') !== false) {
        $cpu = '<PerfCounterInfo><key>6</key><nameInfo><key>usage</key></nameInfo><groupInfo><key>cpu</key></groupInfo><unitInfo><key>percent</key></unitInfo><rollupType>average</rollupType><statsType>rate</statsType></PerfCounterInfo>';
        $mem = '<PerfCounterInfo><key>24</key><nameInfo><key>usage</key></nameInfo><groupInfo><key>mem</key></groupInfo><unitInfo><key>percent</key></unitInfo><rollupType>average</rollupType><statsType>absolute</statsType></PerfCounterInfo>';
        return array('status'=>200,'headers'=>array(),'body'=>p5f_env('<RetrievePropertiesResponse xmlns="urn:vim25" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><returnval><obj type="PerformanceManager">PerfMgr</obj><propSet><name>perfCounter</name><val xsi:type="ArrayOfPerfCounterInfo">'.$cpu.$mem.'</val></propSet></returnval></RetrievePropertiesResponse>'));
    }
    if (strpos($body, '<QueryAvailablePerfMetric') !== false) {
        return array('status'=>200,'headers'=>array(),'body'=>p5f_env('<QueryAvailablePerfMetricResponse xmlns="urn:vim25"><returnval><counterId>6</counterId><instance></instance></returnval><returnval><counterId>24</counterId><instance></instance></returnval></QueryAvailablePerfMetricResponse>'));
    }
    if (strpos($body, '<QueryPerf') !== false) {
        $sample1 = '<sampleInfo><timestamp>2026-09-09T00:00:00Z</timestamp><interval>20</interval></sampleInfo>';
        $sample2 = '<sampleInfo><timestamp>2026-09-09T00:00:20Z</timestamp><interval>20</interval></sampleInfo>';
        $cpu = '<value><id><counterId>6</counterId><instance></instance></id><value>2067</value><value>2500</value></value>';
        $mem = '<value><id><counterId>24</counterId><instance></instance></id><value>3750</value><value>4000</value></value>';
        return array('status'=>200,'headers'=>array(),'body'=>p5f_env('<QueryPerfResponse xmlns="urn:vim25"><returnval>'.$sample1.$sample2.$cpu.$mem.'</returnval></QueryPerfResponse>'));
    }
    if (strpos($body, '<Logout') !== false) {
        return array('status'=>200,'headers'=>array(),'body'=>p5f_env('<LogoutResponse xmlns="urn:vim25"/>'));
    }
    return array('status'=>500,'headers'=>array(),'body'=>'unexpected');
};

$client = new IntegrationVmwareVcenterPerformance($transport, 3, 8, 1048576);
$result = $client->read_realtime_percent_series('vc.example.test', 'svc', 'secret<&', 'host', 'host-42', 12, false);

p5f_assert(count($calls) === 7, 'service/login/provider/counters/available/query/logout call count');
foreach ($calls as $call) {
    p5f_assert($call['method'] === 'POST', 'SOAP transport uses POST only');
    p5f_assert($call['url'] === 'https://vc.example.test/sdk', 'normalized /sdk URL');
    p5f_assert($call['verify_tls'] === false, 'saved TLS mode passed through');
}
p5f_assert(strpos($calls[1]['body'], 'secret&lt;&amp;') !== false, 'password is XML escaped');
p5f_assert(strpos($calls[2]['body'], '<entity type="HostSystem">host-42</entity>') !== false, 'HostSystem stable MOR used');
p5f_assert(strpos($calls[3]['body'], '<RetrieveProperties xmlns="urn:vim25">') !== false, 'compatible RetrieveProperties used for perfCounter metadata');
p5f_assert(strpos($calls[3]['body'], '<RetrievePropertiesEx') === false, '4.1-only RetrievePropertiesEx not used');
p5f_assert(strpos($calls[5]['body'], '<counterId>6</counterId>') !== false, 'dynamically discovered CPU counter selected');
p5f_assert(strpos($calls[5]['body'], '<counterId>24</counterId>') !== false, 'dynamically discovered memory counter selected');
p5f_assert(strpos($calls[5]['body'], '<intervalId>20</intervalId>') !== false, 'provider refresh rate used as real-time interval');
p5f_assert(strpos($calls[5]['body'], '<maxSample>12</maxSample>') !== false, 'sample bound passed to QueryPerf');

p5f_assert($result['provider']['refresh_rate'] === 20, 'refresh rate parsed');
p5f_assert($result['counters']['cpu.usage.average']['id'] === 6, 'CPU counter id dynamically parsed');
p5f_assert($result['counters']['mem.usage.average']['id'] === 24, 'memory counter id dynamically parsed');
p5f_assert($result['property_method'] === 'RetrieveProperties', 'compatible property method reported');
p5f_assert($result['sample_count'] === 2, 'two samples parsed');
p5f_assert(abs($result['samples'][0]['cpu_percent'] - 20.67) < 0.001, 'fixed-point CPU percent normalized');
p5f_assert(abs($result['samples'][1]['cpu_percent'] - 25.00) < 0.001, 'second CPU sample normalized');
p5f_assert(abs($result['samples'][0]['memory_percent'] - 37.50) < 0.001, 'fixed-point memory percent normalized');
p5f_assert(abs($result['samples'][1]['memory_percent'] - 40.00) < 0.001, 'second memory sample normalized');

$before = count($calls);
$thrown = false;
try { $client->read_realtime_percent_series('vc.example.test', 'u', 'p', 'host', 'vm-1', 10, true); }
catch (InvalidArgumentException $e) { $thrown = true; }
p5f_assert($thrown, 'host request rejects VM MOR');
p5f_assert(count($calls) === $before, 'invalid MOR fails before network');

$thrown = false;
try { $client->read_realtime_percent_series('vc.example.test', 'u', 'p', 'vm', 'vm-1', 1, true); }
catch (InvalidArgumentException $e) { $thrown = true; }
p5f_assert($thrown, 'too-small sample count rejected');

$thrown = false;
try { $client->read_realtime_percent_series('vc.example.test', 'u', 'p', 'vm', 'vm-1', 361, true); }
catch (InvalidArgumentException $e) { $thrown = true; }
p5f_assert($thrown, 'too-large sample count rejected');

print "PHASE5F_PERFORMANCE_REGRESSION_OK\n";
