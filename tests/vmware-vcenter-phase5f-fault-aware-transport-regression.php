<?php

require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterFaultAwareTransport.php';

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
}

$captured = array();
$base = function ($method, $url, $headers, $body, $verifyTls, $connectTimeout, $requestTimeout) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'body', 'verifyTls', 'connectTimeout', 'requestTimeout');
    if (strpos($body, '<RetrievePropertiesEx') !== false) {
        return array(
            'status' => 500,
            'headers' => array('Content-Type: text/xml; charset=utf-8'),
            'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><soapenv:Fault><faultcode>ServerFaultCode</faultcode><faultstring>Permission to perform this operation was denied.</faultstring><detail><NoPermissionFault xmlns="urn:vim25"/></detail></soapenv:Fault></soapenv:Body></soapenv:Envelope>'
        );
    }
    return array('status' => 200, 'headers' => array(), 'body' => '<ok/>');
};

$transport = new IntegrationVmwareVcenterFaultAwareTransport($base);

$thrown = false;
try {
    $transport('POST', 'https://vc.example.test/sdk', array('Cookie: secret-session-cookie'), '<RetrievePropertiesEx xmlns="urn:vim25"><password>DO_NOT_LEAK</password></RetrievePropertiesEx>', false, 3, 7);
} catch (RuntimeException $e) {
    $thrown = true;
    $message = $e->getMessage();
    assert_true(strpos($message, 'RetrievePropertiesEx') !== false, 'known SOAP operation is named');
    assert_true(strpos($message, 'HTTP 500') !== false, 'HTTP status is retained');
    assert_true(strpos($message, 'Permission to perform this operation was denied.') !== false, 'faultstring is exposed');
    assert_true(strpos($message, 'DO_NOT_LEAK') === false, 'request body is never exposed');
    assert_true(strpos($message, 'secret-session-cookie') === false, 'headers/cookie are never exposed');
}
assert_true($thrown, 'SOAP 500 becomes a fault-aware exception');

$ok = $transport('POST', 'https://vc.example.test/sdk', array(), '<Login xmlns="urn:vim25"/>', true, 3, 7);
assert_true((int)$ok['status'] === 200, 'successful response passes through unchanged');

print "PHASE5F_FAULT_AWARE_TRANSPORT_REGRESSION_OK\n";
