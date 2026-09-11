<?php

require_once(__DIR__ . '/../layers/phase5f3/functions/classes/class.IntegrationVmwareVcenterHostNetworkHints.php');

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
        if (strpos($body, '<type>HostSystem</type>') === false) throw new RuntimeException('HostSystem type missing');
        if (strpos($body, '<pathSet>configManager.networkSystem</pathSet>') === false) throw new RuntimeException('networkSystem path missing');
        if (strpos($body, '<obj type="HostSystem">host-44</obj>') === false) throw new RuntimeException('Host MOR missing');
        return array('status' => 200, 'headers' => array(), 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><RetrievePropertiesResponse xmlns="urn:vim25"><returnval><obj type="HostSystem">host-44</obj><propSet><name>configManager.networkSystem</name><val type="HostNetworkSystem">networkSystem-44</val></propSet></returnval></RetrievePropertiesResponse></soapenv:Body></soapenv:Envelope>');
    }
    if (strpos($body, '<QueryNetworkHint ') !== false) {
        if (strpos($body, '<_this type="HostNetworkSystem">networkSystem-44</_this>') === false) throw new RuntimeException('HostNetworkSystem MOR missing');
        if (strpos($body, '<device>') !== false) throw new RuntimeException('device filter must be omitted to read bounded all-pNIC hints');
        return array('status' => 200, 'headers' => array(), 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><QueryNetworkHintResponse xmlns="urn:vim25"><returnval><device>vmnic0</device><connectedSwitchPort><devId>mska-nord-01-n9k-1</devId><portId>Ethernet103/1/13</portId><systemName>mska-nord-01-n9k-1</systemName><mgmtAddr>10.176.2.3</mgmtAddr></connectedSwitchPort></returnval><returnval><device>vmnic1</device><lldpInfo><chassisId>00:11:22:33:44:55</chassisId><portId>Ethernet1/48</portId><timeToLive>120</timeToLive></lldpInfo></returnval></QueryNetworkHintResponse></soapenv:Body></soapenv:Envelope>');
    }
    if (strpos($body, '<Logout ') !== false) {
        return array('status' => 200, 'headers' => array(), 'body' => '<?xml version="1.0"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body><LogoutResponse xmlns="urn:vim25"/></soapenv:Body></soapenv:Envelope>');
    }
    throw new RuntimeException('unexpected SOAP operation');
};

$reader = new IntegrationVmwareVcenterHostNetworkHints($transport, 2, 5, 1048576, 8);
$result = $reader->read_hints('https://vc.example.test', 'svc-vcenter', 'secret', 'host-44', false);

if (!is_array($result) || $result['host_external_id'] !== 'host-44') throw new RuntimeException('host id mismatch');
if ($result['network_system_external_id'] !== 'networkSystem-44') throw new RuntimeException('network system id mismatch');
if ($result['hint_method'] !== 'QueryNetworkHint' || $result['property_method'] !== 'RetrieveProperties') throw new RuntimeException('SOAP method metadata mismatch');
if ($result['hint_count'] !== 2 || count($result['hints']) !== 2) throw new RuntimeException('hint count mismatch');

$cdp = $result['hints'][0];
if ($cdp['adapter'] !== 'vmnic0' || $cdp['protocol'] !== 'CDP') throw new RuntimeException('CDP adapter mismatch');
if ($cdp['device_id'] !== 'mska-nord-01-n9k-1' || $cdp['port_id'] !== 'Ethernet103/1/13') throw new RuntimeException('CDP peer mismatch');
if ($cdp['management_address'] !== '10.176.2.3') throw new RuntimeException('CDP management address mismatch');

$lldp = $result['hints'][1];
if ($lldp['adapter'] !== 'vmnic1' || $lldp['protocol'] !== 'LLDP') throw new RuntimeException('LLDP adapter mismatch');
if ($lldp['device_id'] !== '00:11:22:33:44:55' || $lldp['port_id'] !== 'Ethernet1/48') throw new RuntimeException('LLDP peer mismatch');

if ($result['verify_tls'] !== false) throw new RuntimeException('TLS mode mismatch');
if ($result['persistent_inventory_written'] !== false || $result['phpipam_objects_modified'] !== false || $result['vcenter_objects_modified'] !== false) throw new RuntimeException('read-only flags mismatch');
if (count($calls) !== 5) throw new RuntimeException('unexpected SOAP call count');
foreach ($calls as $call) if ($call['method'] !== 'POST') throw new RuntimeException('non-POST SOAP call');

print "PHASE5F3_HOST_NETWORK_HINTS_REGRESSION_OK\n";
