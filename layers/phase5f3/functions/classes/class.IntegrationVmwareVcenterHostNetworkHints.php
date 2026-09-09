<?php

/**
 * phpIPAM Custom — Phase 5F.3 bounded read-only ESXi physical NIC discovery reader.
 *
 * Reads exactly one HostSystem.configManager.networkSystem MOR and invokes the
 * semantically read-only HostNetworkSystem.QueryNetworkHint method for that host.
 * The result is normalized to physical adapter -> CDP/LLDP peer identifiers.
 * No generic SOAP method is exposed and no persistence or mutation is performed.
 */
class IntegrationVmwareVcenterHostNetworkHints
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxHints;

    public function __construct($transport, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 2097152, $maxHints = 64)
    {
        if (!is_callable($transport)) throw new InvalidArgumentException('Host network hint transport must be callable');
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(16384, min(4194304, (int)$maxResponseBytes));
        $this->maxHints = max(1, min(128, (int)$maxHints));
    }

    public function read_hints($endpoint, $username, $password, $hostId, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $hostId = trim((string)$hostId);
        $verifyTls = (bool)$verifyTls;

        if ($username === '' || $password === '') throw new InvalidArgumentException('vCenter username and password are required');
        if (!preg_match('/^host-[0-9]+$/', $hostId)) throw new InvalidArgumentException('Invalid HostSystem managed object id');
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP DOM extension is required for host network hints');

        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;
        $started = microtime(true);

        try {
            $serviceResponse = $this->soap_request(
                $sdk,
                $this->envelope('<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'),
                null,
                $verifyTls
            );
            $serviceDoc = $this->parse_soap($serviceResponse['body'], 'RetrieveServiceContent');
            $service = array(
                'session_manager' => $this->required_text($serviceDoc, 'sessionManager'),
                'property_collector' => $this->required_text($serviceDoc, 'propertyCollector')
            );

            $login = '<Login xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this>'
                . '<userName>' . $this->xml($username) . '</userName><password>' . $this->xml($password) . '</password></Login>';
            $loginResponse = $this->soap_request($sdk, $this->envelope($login), null, $verifyTls);
            $this->parse_soap($loginResponse['body'], 'Login');
            $cookie = $this->extract_session_cookie($loginResponse['headers']);
            if ($cookie === null) throw new RuntimeException('vCenter SOAP login did not return a session cookie');

            $retrieve = '<RetrieveProperties xmlns="urn:vim25"><_this type="PropertyCollector">'
                . $this->xml($service['property_collector']) . '</_this><specSet><propSet><type>HostSystem</type>'
                . '<pathSet>configManager.networkSystem</pathSet></propSet><objectSet><obj type="HostSystem">'
                . $this->xml($hostId) . '</obj><skip>false</skip></objectSet></specSet></RetrieveProperties>';
            $propertyResponse = $this->soap_request($sdk, $this->envelope($retrieve), $cookie, $verifyTls);
            $propertyDoc = $this->parse_soap($propertyResponse['body'], 'RetrieveProperties(configManager.networkSystem)');
            $networkSystemId = $this->find_property_text($propertyDoc, 'configManager.networkSystem');
            if ($networkSystemId === null || !preg_match('/^[A-Za-z0-9_.:-]+$/', $networkSystemId)) {
                throw new RuntimeException('HostNetworkSystem managed object reference was not returned');
            }

            $query = '<QueryNetworkHint xmlns="urn:vim25"><_this type="HostNetworkSystem">'
                . $this->xml($networkSystemId) . '</_this></QueryNetworkHint>';
            $queryResponse = $this->soap_request($sdk, $this->envelope($query), $cookie, $verifyTls);
            $queryDoc = $this->parse_soap($queryResponse['body'], 'QueryNetworkHint');
            $hints = $this->parse_hints($queryDoc);

            return array(
                'host_external_id' => $hostId,
                'network_system_external_id' => $networkSystemId,
                'hints' => $hints,
                'hint_count' => count($hints),
                'property_method' => 'RetrieveProperties',
                'hint_method' => 'QueryNetworkHint',
                'verify_tls' => $verifyTls,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'persistent_inventory_written' => false,
                'phpipam_objects_modified' => false,
                'vcenter_objects_modified' => false
            );
        } finally {
            if ($cookie !== null && is_array($service) && !empty($service['session_manager'])) {
                $logout = '<Logout xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this></Logout>';
                try { $this->soap_request($sdk, $this->envelope($logout), $cookie, $verifyTls); } catch (Exception $ignored) {}
            }
        }
    }

    private function parse_hints(DOMDocument $doc)
    {
        $xpath = new DOMXPath($doc);
        $result = array();
        $nodes = $xpath->query('//*[local-name()="QueryNetworkHintResponse"]/*[local-name()="returnval"]');
        foreach ($nodes as $node) {
            if (count($result) >= $this->maxHints) throw new RuntimeException('vCenter host network hint count exceeded the safety limit');

            $device = $this->child_text($node, 'device');
            if ($device === null || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $device)) continue;

            $protocol = null;
            $remoteDeviceId = null;
            $portId = null;
            $systemName = null;
            $managementAddress = null;

            $cdp = $this->child($node, 'connectedSwitchPort');
            if ($cdp !== null) {
                $protocol = 'CDP';
                $remoteDeviceId = $this->bounded_text($this->child_text($cdp, 'devId'));
                $portId = $this->bounded_text($this->child_text($cdp, 'portId'));
                $systemName = $this->bounded_text($this->child_text($cdp, 'systemName'));
                $managementAddress = $this->bounded_text($this->child_text($cdp, 'mgmtAddr'));
                if ($managementAddress === null) $managementAddress = $this->bounded_text($this->child_text($cdp, 'address'));
            } else {
                $lldp = $this->child($node, 'lldpInfo');
                if ($lldp !== null) {
                    $protocol = 'LLDP';
                    $remoteDeviceId = $this->bounded_text($this->child_text($lldp, 'chassisId'));
                    $portId = $this->bounded_text($this->child_text($lldp, 'portId'));
                }
            }

            $result[] = array(
                'adapter' => $device,
                'protocol' => $protocol,
                'device_id' => $remoteDeviceId,
                'port_id' => $portId,
                'system_name' => $systemName,
                'management_address' => $managementAddress
            );
        }
        return $result;
    }

    private function bounded_text($value)
    {
        if ($value === null) return null;
        $value = trim((string)$value);
        if ($value === '') return null;
        if (strlen($value) > 1024) throw new RuntimeException('vCenter network hint text exceeded the safety limit');
        if (preg_match('/[\x00\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new RuntimeException('vCenter network hint contains unsupported control characters');
        return $value;
    }

    private function normalize_endpoint($endpoint)
    {
        if (!class_exists('IntegrationVmwareVcenter')) throw new RuntimeException('Base VMware connector class is required');
        $client = new IntegrationVmwareVcenter();
        return $client->normalize_endpoint($endpoint);
    }

    private function soap_request($url, $body, $cookie, $verifyTls)
    {
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $response = call_user_func($this->transport, 'POST', $url, $headers, $body, (bool)$verifyTls, $this->connectTimeout, $this->requestTimeout);
        if (!is_array($response)) throw new RuntimeException('Host network hint transport returned an invalid response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $responseBody = isset($response['body']) ? (string)$response['body'] : '';
        $responseHeaders = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array();
        if (strlen($responseBody) > $this->maxResponseBytes) throw new RuntimeException('vCenter host network hint response exceeded the safety limit');
        if ($status < 200 || $status >= 300) throw new RuntimeException('vCenter host network hint SOAP request failed (HTTP ' . $status . ')');
        return array('status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders);
    }

    private function parse_soap($xml, $operation)
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML((string)$xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) throw new RuntimeException('vCenter SOAP ' . $operation . ' response is not valid XML');
        $xpath = new DOMXPath($doc);
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);
        if ($fault !== null) {
            $faultString = $xpath->query('.//*[local-name()="faultstring"]', $fault)->item(0);
            $message = $faultString !== null ? trim($faultString->textContent) : 'SOAP fault';
            throw new RuntimeException('vCenter SOAP ' . $operation . ' failed: ' . substr($message, 0, 300));
        }
        return $doc;
    }

    private function required_text(DOMDocument $doc, $localName)
    {
        $xpath = new DOMXPath($doc);
        $node = $xpath->query('//*[local-name()="' . $localName . '"]')->item(0);
        if ($node === null || trim($node->textContent) === '') throw new RuntimeException('vCenter ServiceContent missing ' . $localName);
        return trim($node->textContent);
    }

    private function find_property_text(DOMDocument $doc, $propertyName)
    {
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//*[local-name()="propSet"]') as $propSet) {
            $name = null;
            $value = null;
            foreach ($propSet->childNodes as $child) {
                if (!($child instanceof DOMElement)) continue;
                if ($child->localName === 'name') $name = trim($child->textContent);
                elseif ($child->localName === 'val') $value = trim((string)$child->textContent);
            }
            if ($name === $propertyName) return ($value === '' ? null : $value);
        }
        return null;
    }

    private function child($node, $localName)
    {
        if (!($node instanceof DOMNode)) return null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) return $child;
        }
        return null;
    }

    private function child_text($node, $localName)
    {
        $child = $this->child($node, $localName);
        if ($child === null) return null;
        $value = trim((string)$child->textContent);
        return $value === '' ? null : $value;
    }

    private function extract_session_cookie(array $headers)
    {
        foreach ($headers as $header) {
            if (stripos((string)$header, 'Set-Cookie:') !== 0) continue;
            $value = trim(substr((string)$header, strlen('Set-Cookie:')));
            $pair = explode(';', $value, 2);
            $cookie = trim($pair[0]);
            if ($cookie !== '') return $cookie;
        }
        return null;
    }

    private function envelope($body)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
    }

    private function xml($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
