<?php

require_once __DIR__ . '/../layers/phase5/functions/classes/class.IntegrationVmwareVcenter.php';
require_once __DIR__ . '/../layers/phase5f/functions/classes/class.IntegrationVmwareVcenterSoapHostResolver.php';

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
        'verify_tls' => $verifyTls
    );

    if (strpos($body, '<RetrieveServiceContent') !== false) {
        return array(
            'status' => 200,
            'headers' => array('Content-Type: text/xml'),
            'body' => soap_envelope('<RetrieveServiceContentResponse xmlns="urn:vim25"><returnval><sessionManager type="SessionManager">SessionManager</sessionManager><searchIndex type="SearchIndex">SearchIndex</searchIndex></returnval></RetrieveServiceContentResponse>')
        );
    }
    if (strpos($body, '<Login') !== false) {
        return array(
            'status' => 200,
            'headers' => array('Set-Cookie: vmware_soap_session="resolver-session"; Path=/; HttpOnly; Secure'),
            'body' => soap_envelope('<LoginResponse xmlns="urn:vim25"><returnval><key>resolver-session</key></returnval></LoginResponse>')
        );
    }
    if (strpos($body, '<FindByDnsName') !== false) {
        return array(
            'status' => 200,
            'headers' => array('Content-Type: text/xml'),
            'body' => soap_envelope('<FindByDnsNameResponse xmlns="urn:vim25"><returnval type="HostSystem">host-404</returnval></FindByDnsNameResponse>')
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

$resolver = new IntegrationVmwareVcenterSoapHostResolver($transport, 3, 7, 1048576);
$hostId = $resolver->resolve_host_id_by_dns_name('vc.example.test', 'svc_phpipam', 'secret<&', 'esxi-1-04.rbank.local', false);

assert_true($hostId === 'host-404', 'HostSystem MOR resolved from DNS name');
assert_true(count($calls) === 4, 'service/login/find/logout call count');
assert_true($calls[0]['url'] === 'https://vc.example.test/sdk', 'mature endpoint normalization reused');
foreach ($calls as $call) assert_true($call['verify_tls'] === false, 'saved TLS mode passed through unchanged');
assert_true(strpos($calls[1]['body'], 'secret&lt;&amp;') !== false, 'credential is XML escaped');
assert_true(strpos($calls[2]['body'], '<_this type="SearchIndex">SearchIndex</_this>') !== false, 'SearchIndex MOR used');
assert_true(strpos($calls[2]['body'], '<dnsName>esxi-1-04.rbank.local</dnsName>') !== false, 'explicit ESXi DNS name used');
assert_true(strpos($calls[2]['body'], '<vmSearch>false</vmSearch>') !== false, 'host-only DNS search enforced');
assert_true(in_array('Cookie: vmware_soap_session="resolver-session"', $calls[2]['headers'], true), 'FindByDnsName uses authenticated SOAP session');

$beforeInvalid = count($calls);
$thrown = false;
try {
    $resolver->resolve_host_id_by_dns_name('vc.example.test', 'u', 'p', "bad host", true);
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
assert_true($thrown, 'whitespace/control characters in host name rejected');
assert_true(count($calls) === $beforeInvalid, 'invalid host name fails before network');

print "PHASE5F_SOAP_HOST_RESOLVER_REGRESSION_OK\n";
