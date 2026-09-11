<?php

/**
 * phpIPAM Custom — Phase 5F.3.3 bounded transient VM search facts.
 * Bulk legacy-compatible RetrieveProperties over an explicit VM-id set.
 */
class IntegrationVmwareVcenterVmSearchFacts
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxVms;
    private $batchSize;
    private $requestCount = 0;

    public function __construct($transport, $connectTimeout = 8, $requestTimeout = 30, $maxResponseBytes = 8388608, $maxVms = 1000, $batchSize = 20)
    {
        if (!is_callable($transport)) throw new InvalidArgumentException('VM search facts transport must be callable');
        $this->transport = $transport;
        $this->connectTimeout = max(1, min(30, (int)$connectTimeout));
        $this->requestTimeout = max($this->connectTimeout, min(60, (int)$requestTimeout));
        $this->maxResponseBytes = max(65536, min(8388608, (int)$maxResponseBytes));
        $this->maxVms = max(1, min(2000, (int)$maxVms));
        $this->batchSize = max(1, min(50, (int)$batchSize));
    }

    public function read_facts($endpoint, $username, $password, array $vmIds, $verifyTls = true)
    {
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP DOM extension is required for VM search facts');
        if (!class_exists('IntegrationVmwareVcenter')) throw new RuntimeException('Base VMware connector class is required');

        $EndpointPolicy = new IntegrationVmwareVcenter();
        $endpoint = $EndpointPolicy->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $verifyTls = (bool)$verifyTls;
        if ($username === '' || $password === '') throw new InvalidArgumentException('vCenter username and password are required');

        $requested = array();
        foreach ($vmIds as $raw) {
            $id = trim((string)$raw);
            if (!preg_match('/^vm-[0-9]+$/', $id)) throw new InvalidArgumentException('Invalid VirtualMachine managed object id');
            $requested[$id] = true;
        }
        $ids = array_keys($requested);
        sort($ids, SORT_STRING);
        if (count($ids) === 0) throw new InvalidArgumentException('At least one VM id is required');
        if (count($ids) > $this->maxVms) throw new RuntimeException('VM search fact set exceeded the configured safety limit');

        $this->requestCount = 0;
        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;
        $started = microtime(true);
        $rows = array();

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
                'name', 'config.annotation', 'config.guestId', 'config.guestFullName',
                'guest.guestId', 'guest.guestFullName', 'guest.guestFamily', 'guest.hostName',
                'guest.ipAddress', 'guest.net', 'config.hardware.memoryMB', 'config.hardware.numCPU',
                'config.version', 'config.uuid', 'config.instanceUuid', 'config.template',
                'runtime.host', 'config.hardware.device'
            );
            $propXml = '';
            foreach ($paths as $path) $propXml .= '<pathSet>' . $this->xml($path) . '</pathSet>';

            foreach (array_chunk($ids, $this->batchSize) as $batch) {
                $objects = '';
                foreach ($batch as $id) $objects .= '<objectSet><obj type="VirtualMachine">' . $this->xml($id) . '</obj><skip>false</skip></objectSet>';
                $retrieve = '<RetrieveProperties xmlns="urn:vim25"><_this type="PropertyCollector">' . $this->xml($service['property_collector']) . '</_this>'
                    . '<specSet><propSet><type>VirtualMachine</type>' . $propXml . '</propSet>' . $objects . '</specSet></RetrieveProperties>';
                $response = $this->soap_request($sdk, $this->envelope($retrieve), $cookie, $verifyTls);
                $doc = $this->parse_soap($response['body'], 'RetrieveProperties(VirtualMachine search facts)');
                foreach ($this->parse_batch($doc) as $id => $row) {
                    if (!isset($requested[$id])) throw new RuntimeException('VM search facts returned an unexpected VM id');
                    if (isset($rows[$id])) throw new RuntimeException('VM search facts returned duplicate VM id');
                    $rows[$id] = $row;
                }
            }
            foreach ($ids as $id) if (!isset($rows[$id])) throw new RuntimeException('VM search facts response omitted an explicit VM id');

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'vm_count' => count($rows),
                'rows' => $rows,
                'property_method' => 'RetrieveProperties',
                'batch_size' => $this->batchSize,
                'soap_request_count' => $this->requestCount,
                'verify_tls' => $verifyTls,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'persistent_inventory_written' => false,
                'phpipam_objects_modified' => false,
                'vcenter_objects_modified' => false
            );
        } finally {
            if ($cookie !== null && is_array($service) && !empty($service['session_manager'])) {
                $logout = '<Logout xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this></Logout>';
                try { $this->soap_request($sdk, $this->envelope($logout), $cookie, $verifyTls, true); } catch (Exception $ignored) {}
            }
        }
    }

    private function parse_batch(DOMDocument $doc)
    {
        $xpath = new DOMXPath($doc);
        $rows = array();
        foreach ($xpath->query('//*[local-name()="returnval"]') as $returnval) {
            $obj = $this->direct_child($returnval, 'obj');
            if ($obj === null) continue;
            $id = trim((string)$obj->textContent);
            if (!preg_match('/^vm-[0-9]+$/', $id)) throw new RuntimeException('VM search facts returned an invalid VM id');

            $props = array();
            $deviceVal = null;
            $guestNetVal = null;
            foreach ($returnval->childNodes as $child) {
                if (!($child instanceof DOMElement) || $child->localName !== 'propSet') continue;
                $nameNode = $this->direct_child($child, 'name');
                $valNode = $this->direct_child($child, 'val');
                if ($nameNode === null) continue;
                $name = trim((string)$nameNode->textContent);
                if ($name === 'config.hardware.device') $deviceVal = $valNode;
                elseif ($name === 'guest.net') $guestNetVal = $valNode;
                else $props[$name] = $valNode === null ? null : $this->clean_optional_text($valNode->textContent, 131072);
            }

            $devices = $this->parse_devices($deviceVal);
            $guestNet = $this->parse_guest_net($guestNetVal);
            $macs = array_values(array_unique(array_merge($devices['mac_addresses'], $guestNet['mac_addresses'])));
            sort($macs, SORT_STRING);
            $networks = array_values(array_unique(array_merge($devices['network_names'], $guestNet['network_names'])));
            natcasesort($networks);
            $networks = array_values($networks);
            $ips = array_values(array_unique($guestNet['ip_addresses']));
            sort($ips, SORT_STRING);

            $guestFull = isset($props['guest.guestFullName']) ? $props['guest.guestFullName'] : null;
            $configFull = isset($props['config.guestFullName']) ? $props['config.guestFullName'] : null;
            $guestId = isset($props['guest.guestId']) ? $props['guest.guestId'] : null;
            $configId = isset($props['config.guestId']) ? $props['config.guestId'] : null;
            $friendlyGuest = $guestFull !== null ? $guestFull : ($configFull !== null ? $configFull : ($guestId !== null ? $guestId : $configId));

            $rows[$id] = array(
                'vm_external_id' => $id,
                'name' => isset($props['name']) ? $props['name'] : null,
                'annotation' => isset($props['config.annotation']) ? $props['config.annotation'] : null,
                'config_guest_id' => $configId,
                'config_guest_full_name' => $configFull,
                'guest_guest_id' => $guestId,
                'guest_guest_full_name' => $guestFull,
                'guest_guest_family' => isset($props['guest.guestFamily']) ? $props['guest.guestFamily'] : null,
                'friendly_guest_os' => $friendlyGuest,
                'guest_hostname' => isset($props['guest.hostName']) ? $props['guest.hostName'] : null,
                'primary_ip' => isset($props['guest.ipAddress']) ? $props['guest.ipAddress'] : null,
                'memory_mb' => $this->int_or_null(isset($props['config.hardware.memoryMB']) ? $props['config.hardware.memoryMB'] : null),
                'num_cpu' => $this->int_or_null(isset($props['config.hardware.numCPU']) ? $props['config.hardware.numCPU'] : null),
                'hardware_version' => isset($props['config.version']) ? $props['config.version'] : null,
                'bios_uuid' => isset($props['config.uuid']) ? $props['config.uuid'] : null,
                'instance_uuid' => isset($props['config.instanceUuid']) ? $props['config.instanceUuid'] : null,
                'template' => $this->bool_or_null(isset($props['config.template']) ? $props['config.template'] : null),
                'host_external_id' => isset($props['runtime.host']) ? $props['runtime.host'] : null,
                'mac_addresses' => $macs,
                'network_names' => $networks,
                'ip_addresses' => $ips,
                'virtual_nic_count' => count($devices['mac_addresses'])
            );
        }
        return $rows;
    }

    private function parse_devices($val)
    {
        $out = array('mac_addresses' => array(), 'network_names' => array());
        if (!($val instanceof DOMElement)) return $out;
        $xpath = new DOMXPath($val->ownerDocument);
        foreach ($xpath->query('.//*[local-name()="macAddress"]', $val) as $macNode) {
            $mac = $this->normalize_mac($macNode->textContent);
            if ($mac !== null) $out['mac_addresses'][] = $mac;
            $device = $macNode->parentNode;
            if (!($device instanceof DOMElement)) continue;
            $networkNode = $xpath->query('.//*[local-name()="backing"]/*[local-name()="deviceName"]', $device)->item(0);
            if ($networkNode !== null) {
                $network = $this->clean_optional_text($networkNode->textContent, 2048);
                if ($network !== null) $out['network_names'][] = $network;
            }
            $pgNode = $xpath->query('.//*[local-name()="backing"]//*[local-name()="portgroupKey"]', $device)->item(0);
            if ($pgNode !== null) {
                $pg = $this->clean_optional_text($pgNode->textContent, 2048);
                if ($pg !== null) $out['network_names'][] = $pg;
            }
        }
        return $out;
    }

    private function parse_guest_net($val)
    {
        $out = array('mac_addresses' => array(), 'network_names' => array(), 'ip_addresses' => array());
        if (!($val instanceof DOMElement)) return $out;
        $xpath = new DOMXPath($val->ownerDocument);
        foreach ($xpath->query('.//*[local-name()="macAddress"]', $val) as $node) {
            $mac = $this->normalize_mac($node->textContent);
            if ($mac !== null) $out['mac_addresses'][] = $mac;
        }
        foreach ($xpath->query('.//*[local-name()="network"]', $val) as $node) {
            $network = $this->clean_optional_text($node->textContent, 2048);
            if ($network !== null) $out['network_names'][] = $network;
        }
        foreach ($xpath->query('.//*[local-name()="ipAddress"]', $val) as $node) {
            $ip = trim((string)$node->textContent);
            if ($ip !== '' && @inet_pton($ip) !== false) $out['ip_addresses'][] = $ip;
        }
        return $out;
    }

    private function direct_child($node, $localName)
    {
        if (!($node instanceof DOMNode)) return null;
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement && $child->localName === $localName) return $child;
        return null;
    }

    private function soap_request($url, $body, $cookie, $verifyTls, $cleanup = false)
    {
        if (!$cleanup) {
            $this->requestCount++;
            if ($this->requestCount > 128) throw new RuntimeException('VM search facts SOAP request safety limit exceeded');
        }
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $response = call_user_func($this->transport, 'POST', $url, $headers, $body, (bool)$verifyTls, $this->connectTimeout, $this->requestTimeout);
        if (!is_array($response)) throw new RuntimeException('VM search facts transport returned an invalid response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $responseBody = isset($response['body']) ? (string)$response['body'] : '';
        $responseHeaders = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array();
        if (strlen($responseBody) > $this->maxResponseBytes) throw new RuntimeException('VM search facts response exceeded the safety limit');
        if ($status < 200 || $status >= 300) throw new RuntimeException('VM search facts SOAP request failed (HTTP ' . $status . ')');
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

    private function clean_optional_text($value, $max)
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (strlen($value) > (int)$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new RuntimeException('VM search fact text exceeded validation bounds');
        return $value;
    }

    private function normalize_mac($value)
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string)$value));
        if (strlen($hex) !== 12 || !ctype_xdigit($hex)) return null;
        return implode(':', str_split($hex, 2));
    }

    private function int_or_null($value)
    {
        if ($value === null || !preg_match('/^[0-9]+$/', (string)$value)) return null;
        return (int)$value;
    }

    private function bool_or_null($value)
    {
        if ($value === null) return null;
        $value = strtolower(trim((string)$value));
        if ($value === 'true' || $value === '1') return true;
        if ($value === 'false' || $value === '0') return false;
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
