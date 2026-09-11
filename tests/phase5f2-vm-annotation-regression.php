<?php

require_once(__DIR__ . '/../layers/phase5f2/functions/classes/class.IntegrationVmwareVcenterVmAnnotation.php');

class IntegrationVmwareVcenter
{
    public function normalize_endpoint($endpoint)
    {
        $endpoint = rtrim((string)$endpoint, '/');
        if ($endpoint !== 'https://vc.example.test') throw new RuntimeException('unexpected endpoint');
        return $endpoint;
    }
}

$calls = array();
$transport = function ($method, $url, $headers, $body, $verifyTls, $connectTimeout, $requestTimeout) use (&$calls) {
    $calls[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'verify_tls' => $verifyTls);
    if ($method !== 'POST' || $url !== 'https://vc.example.test/sdk') throw new RuntimeException('unexpected transport target');

    if (strpos($body, '<RetrieveServiceContent ') !== false) {
        return array('status' => 200, 'headers' => array(), 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><RetrieveServiceContentResponse xmlns="urn:vim25"><returnval><sessionManager>SessionManager</sessionManager><propertyCollector>propertyCollector</propertyCollector></returnval></RetrieveServiceContentResponse></soapenv:Body></soapenv:Envelope>');
    }
    if (strpos($body, '<Login ') !== false) {
        if (strpos($body, '<userName>svc-vcenter</userName>') === false || strpos($body, '<password>secret</password>') === false) throw new RuntimeException('login fields missing');
        return array('status' => 200, 'headers' => array('Set-Cookie: vmware_soap_session="abc"; Path=/sdk; HttpOnly'), 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><LoginResponse xmlns="urn:vim25"><returnval/></LoginResponse></soapenv:Body></soapenv:Envelope>');
    }
    if (strpos($body, '<RetrieveProperties ') !== false) {
        if (strpos($body, '<type>VirtualMachine</type>') === false) throw new RuntimeException('VirtualMachine type missing');
        if (strpos($body, '<pathSet>config.annotation</pathSet>') === false) throw new RuntimeException('annotation path missing');
        if (strpos($body, '<obj type="VirtualMachine">vm-123</obj>') === false) throw new RuntimeException('VM MOR missing');
        return array('status' => 200, 'headers' => array(), 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><RetrievePropertiesResponse xmlns="urn:vim25"><returnval><obj type="VirtualMachine">vm-123</obj><propSet><name>config.annotation</name><val>Owner: DB Team&#10;Backup: daily</val></propSet></returnval></RetrievePropertiesResponse></soapenv:Body></soapenv:Envelope>');
    }
    if (strpos($body, '<Logout ') !== false) {
        return array('status' => 200, 'headers' => array(), 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><LogoutResponse xmlns="urn:vim25"/></soapenv:Body></soapenv:Envelope>');
    }
    throw new RuntimeException('unexpected SOAP operation');
};

$reader = new IntegrationVmwareVcenterVmAnnotation($transport, 2, 5, 1048576, 65535);
$result = $reader->read_annotation('https://vc.example.test', 'svc-vcenter', 'secret', 'vm-123', false);

if (!is_array($result) || $result['vm_external_id'] !== 'vm-123') throw new RuntimeException('VM id mismatch');
if ($result['annotation'] !== "Owner: DB Team\nBackup: daily") throw new RuntimeException('annotation mismatch');
if ($result['property_method'] !== 'RetrieveProperties') throw new RuntimeException('property method mismatch');
if ($result['verify_tls'] !== false) throw new RuntimeException('TLS mode mismatch');
if ($result['persistent_inventory_written'] !== false || $result['phpipam_objects_modified'] !== false || $result['vcenter_objects_modified'] !== false) throw new RuntimeException('read-only flags mismatch');
if (count($calls) !== 4) throw new RuntimeException('unexpected SOAP call count');
foreach ($calls as $call) {
    if ($call['method'] !== 'POST') throw new RuntimeException('non-POST SOAP call');
}

print "PHASE5F2_VM_ANNOTATION_REGRESSION_OK\n";
