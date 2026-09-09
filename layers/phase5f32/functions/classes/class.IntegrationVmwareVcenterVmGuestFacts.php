<?php

/**
 * phpIPAM Custom — Phase 5F.3.2 bounded read-only VM guest/config facts.
 * Reads one explicit VirtualMachine through legacy-compatible RetrieveProperties.
 */
class IntegrationVmwareVcenterVmGuestFacts
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;

    public function __construct($transport, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 4194304)
    {
        if (!is_callable($transport)) throw new InvalidArgumentException('VM guest facts transport must be callable');
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(65536, min(8388608, (int)$maxResponseBytes));
    }

    public function read_facts($endpoint, $username, $password, $vmId, $verifyTls = true)
    {
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP DOM extension is required for VM guest facts');
        if (!class_exists('IntegrationVmwareVcenter')) throw new RuntimeException('Base VMware connector class is required');

        $EndpointPolicy = new IntegrationVmwareVcenter();
        $endpoint = $EndpointPolicy->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $vmId = trim((string)$vmId);
        $verifyTls = (bool)$verifyTls;
        if ($username === '' || $password === '') throw new InvalidArgumentException('vCenter username and password are required');
        if (!preg_match('/^vm-[0-9]+$/', $vmId)) throw new InvalidArgumentException('Invalid VirtualMachine managed object id');

        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;
        $started = microtime(true);
        try {
            $serviceResponse = $this->soap_request($sdk, $this->envelope('<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'), null, $verifyTls);
            $serviceDoc = $this->parse_soap($serviceResponse['body'], 'RetrieveServiceContent');
            $service = array(
                'session_manager' => $this->required_text($serviceDoc, 'sessionManager'),
                'property_collector' => $this->required_text($serviceDoc, 'propertyCollector')
            );

            $login = '<Login xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this>'
                . '<userName>' . $this->xml($username) . '</userName><password>' . $this->xml($password) . '</password></Login>';
            $loginResponse = $this->soap_request($sdk, $this->envelope($login), null, $verifyTls);
            $this->parse_soap($loginResponse['body'], 'Login');
            $cookie = $this->extract_cookie($loginResponse['headers']);
            if ($cookie === null) throw new RuntimeException('vCenter SOAP login did not return a session cookie');

            $paths = array(
                'name',
                'config.guestId',
                'config.guestFullName',
                'guest.guestId',
                'guest.guestFullName',
                'guest.guestFamily',
                'config.hardware.memoryMB',
                'config.hardware.numCPU',
                'config.version',
                'config.annotation'
            );
            $propXml = '';
            foreach ($paths as $path) $propXml .= '<pathSet>' . $this->xml($path) . '</pathSet>';
            $retrieve = '<RetrieveProperties xmlns="urn:vim25"><_this type="PropertyCollector">' . $this->xml($service['property_collector']) . '</_this>'
                . '<specSet><propSet><type>VirtualMachine</type>' . $propXml . '</propSet>'
                . '<objectSet><obj type="VirtualMachine">' . $this->xml($vmId) . '</obj><skip>false</skip></objectSet></specSet></RetrieveProperties>';
            $response = $this->soap_request($sdk, $this->envelope($retrieve), $cookie, $verifyTls);
            $doc = $this->parse_soap($response['body'], 'RetrieveProperties(VirtualMachine guest facts)');

            $facts = array();
            foreach ($paths as $path) $facts[$path] = $this->find_property_text($doc, $path);
            if ($facts['name'] === null) throw new RuntimeException('VirtualMachine name was not returned');

            return array(
                'ok' => true,
                'vm_external_id' => $vmId,
                'name' => $facts['name'],
                'config_guest_id' => $facts['config.guestId'],
                'config_guest_full_name' => $facts['config.guestFullName'],
                'guest_guest_id' => $facts['guest.guestId'],
                'guest_guest_full_name' => $facts['guest.guestFullName'],
                'guest_guest_family' => $facts['guest.guestFamily'],
                'memory_mb' => $this->int_or_null($facts['config.hardware.memoryMB']),
                'num_cpu' => $this->int_or_null($facts['config.hardware.numCPU']),
                'hardware_version' => $facts['config.version'],
                'annotation_present' => $facts['config.annotation'] !== null && trim($facts['config.annotation']) !== '',
                'annotation_length' => $facts['config.annotation'] === null ? 0 : strlen($facts['config.annotation']),
                'property_method' => 'RetrieveProperties',
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

    private function soap_request($url, $body, $cookie, $verifyTls)
    {
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $response = call_user_func($this->transport, 'POST', $url, $headers, $body, (bool)$verifyTls, $this->connectTimeout, $this->requestTimeout);
        if (!is_array($response)) throw new RuntimeException('VM guest facts transport returned an invalid response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $responseBody = isset($response['body']) ? (string)$response['body'] : '';
        $responseHeaders = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array();
        if (strlen($responseBody) > $this->maxResponseBytes) throw new RuntimeException('VM guest facts response exceeded the safety limit');
        if ($status < 200 || $status >= 300) throw new RuntimeException('VM guest facts SOAP request failed (HTTP ' . $status . ')');
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

    private function extract_cookie(array $headers)
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

    private function int_or_null($value)
    {
        if ($value === null || !preg_match('/^[0-9]+$/', (string)$value)) return null;
        return (int)$value;
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
