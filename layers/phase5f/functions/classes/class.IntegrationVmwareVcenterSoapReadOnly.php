<?php

/**
 * phpIPAM Custom — bounded read-only vSphere Web Services client.
 *
 * Phase 5F uses the existing REST/Automation stable IDs for navigation and
 * talks to /sdk only for read-only host/performance data that the current
 * Automation Host summary does not expose. No generic SOAP method is public.
 */
class IntegrationVmwareVcenterSoapReadOnly
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 4194304)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('VMware SOAP transport must be callable');
        }
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(16384, min(8388608, (int)$maxResponseBytes));
    }

    public function read_host_summary($endpoint, $username, $password, $hostId, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $hostId = trim((string)$hostId);
        $verifyTls = (bool)$verifyTls;

        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('vCenter username and password are required');
        }
        if (!preg_match('/^host-[0-9]+$/', $hostId)) {
            throw new InvalidArgumentException('Invalid vCenter HostSystem managed object id');
        }
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('PHP DOM extension is required for vSphere Web Services');
        }

        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;
        $started = microtime(true);

        try {
            $serviceResponse = $this->soap_request($sdk, $this->envelope(
                '<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'
            ), null, $verifyTls);
            $serviceDoc = $this->parse_soap($serviceResponse['body'], 'RetrieveServiceContent');
            $service = array(
                'session_manager' => $this->required_text($serviceDoc, 'sessionManager'),
                'property_collector' => $this->required_text($serviceDoc, 'propertyCollector'),
                'performance_manager' => $this->required_text($serviceDoc, 'perfManager')
            );

            $loginXml = '<Login xmlns="urn:vim25">'
                . '<_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this>'
                . '<userName>' . $this->xml($username) . '</userName>'
                . '<password>' . $this->xml($password) . '</password>'
                . '</Login>';
            $loginResponse = $this->soap_request($sdk, $this->envelope($loginXml), null, $verifyTls);
            $this->parse_soap($loginResponse['body'], 'Login');
            $cookie = $this->extract_session_cookie($loginResponse['headers']);
            if ($cookie === null) {
                throw new RuntimeException('vCenter SOAP login did not return a session cookie');
            }

            $retrieveXml = '<RetrievePropertiesEx xmlns="urn:vim25">'
                . '<_this type="PropertyCollector">' . $this->xml($service['property_collector']) . '</_this>'
                . '<specSet><propSet><type>HostSystem</type><pathSet>summary</pathSet></propSet>'
                . '<objectSet><obj type="HostSystem">' . $this->xml($hostId) . '</obj><skip>false</skip></objectSet></specSet>'
                . '<options><maxObjects>1</maxObjects></options>'
                . '</RetrievePropertiesEx>';
            $propertyResponse = $this->soap_request($sdk, $this->envelope($retrieveXml), $cookie, $verifyTls);
            $propertyDoc = $this->parse_soap($propertyResponse['body'], 'RetrievePropertiesEx');
            $summaryNode = $this->find_property_value($propertyDoc, 'summary');
            if ($summaryNode === null) {
                throw new RuntimeException('HostSystem summary was not returned by vCenter');
            }

            $hardware = $this->child($summaryNode, 'hardware');
            $quick = $this->child($summaryNode, 'quickStats');
            $runtime = $this->child($summaryNode, 'runtime');
            $config = $this->child($summaryNode, 'config');

            $memoryTotal = $this->int_or_null($this->text($hardware, 'memorySize'));
            $memoryUsedMb = $this->int_or_null($this->text($quick, 'overallMemoryUsage'));
            $memoryUsed = $memoryUsedMb === null ? null : $memoryUsedMb * 1048576;
            $memoryFree = ($memoryTotal !== null && $memoryUsed !== null) ? max(0, $memoryTotal - $memoryUsed) : null;
            $memoryPercent = ($memoryTotal !== null && $memoryTotal > 0 && $memoryUsed !== null)
                ? round(($memoryUsed / $memoryTotal) * 100, 2) : null;

            $cpuMhz = $this->int_or_null($this->text($hardware, 'cpuMhz'));
            $cpuCores = $this->int_or_null($this->text($hardware, 'numCpuCores'));
            $cpuUsed = $this->int_or_null($this->text($quick, 'overallCpuUsage'));
            $cpuCapacity = ($cpuMhz !== null && $cpuCores !== null) ? $cpuMhz * $cpuCores : null;
            $cpuPercent = ($cpuCapacity !== null && $cpuCapacity > 0 && $cpuUsed !== null)
                ? round(($cpuUsed / $cpuCapacity) * 100, 2) : null;

            $product = $this->child($config, 'product');

            return array(
                'host_external_id' => $hostId,
                'hardware' => array(
                    'vendor' => $this->text($hardware, 'vendor'),
                    'model' => $this->text($hardware, 'model'),
                    'cpu_model' => $this->text($hardware, 'cpuModel'),
                    'cpu_mhz_per_core' => $cpuMhz,
                    'cpu_packages' => $this->int_or_null($this->text($hardware, 'numCpuPkgs')),
                    'cpu_cores' => $cpuCores,
                    'cpu_threads' => $this->int_or_null($this->text($hardware, 'numCpuThreads')),
                    'memory_total_bytes' => $memoryTotal
                ),
                'usage' => array(
                    'cpu_used_mhz' => $cpuUsed,
                    'cpu_capacity_mhz' => $cpuCapacity,
                    'cpu_used_percent' => $cpuPercent,
                    'memory_used_bytes' => $memoryUsed,
                    'memory_free_bytes' => $memoryFree,
                    'memory_used_percent' => $memoryPercent,
                    'uptime_seconds' => $this->int_or_null($this->text($quick, 'uptime'))
                ),
                'runtime' => array(
                    'connection_state' => $this->text($runtime, 'connectionState'),
                    'power_state' => $this->text($runtime, 'powerState'),
                    'maintenance_mode' => $this->bool_or_null($this->text($runtime, 'inMaintenanceMode')),
                    'boot_time' => $this->text($runtime, 'bootTime')
                ),
                'product' => array(
                    'name' => $this->text($config, 'name'),
                    'full_name' => $this->text($product, 'fullName'),
                    'version' => $this->text($product, 'version'),
                    'build' => $this->text($product, 'build')
                ),
                'soap' => array(
                    'property_collector' => $service['property_collector'],
                    'performance_manager' => $service['performance_manager'],
                    'verify_tls' => $verifyTls,
                    'duration_ms' => (int)round((microtime(true) - $started) * 1000)
                )
            );
        } finally {
            if ($cookie !== null && is_array($service) && !empty($service['session_manager'])) {
                $logout = '<Logout xmlns="urn:vim25"><_this type="SessionManager">'
                    . $this->xml($service['session_manager']) . '</_this></Logout>';
                try {
                    $this->soap_request($sdk, $this->envelope($logout), $cookie, $verifyTls);
                } catch (Exception $ignored) {
                    // Best-effort session cleanup must not hide the primary read result.
                }
            }
        }
    }

    private function soap_request($url, $body, $cookie, $verifyTls)
    {
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;

        if ($this->transport !== null) {
            $response = call_user_func($this->transport, 'POST', $url, $headers, $body, (bool)$verifyTls, $this->connectTimeout, $this->requestTimeout);
            return $this->normalize_response($response);
        }
        return $this->curl_post($url, $headers, $body, $verifyTls);
    }

    private function curl_post($url, $headers, $body, $verifyTls)
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for vSphere Web Services');
        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Unable to initialize cURL');
        $responseBody = '';
        $responseHeaders = array();
        $tooLarge = false;
        $maxBytes = $this->maxResponseBytes;
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vSphereSOAP/1.0',
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $trim = trim($line);
                if ($trim !== '') $responseHeaders[] = $trim;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$responseBody, &$tooLarge, $maxBytes) {
                if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            }
        ));
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        curl_close($ch);
        if ($tooLarge) throw new RuntimeException('vCenter SOAP response exceeded the safety limit');
        if ($ok === false) throw new RuntimeException('vCenter SOAP network error: ' . ($error !== '' ? $error : 'request failed'));
        return $this->normalize_response(array('status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders));
    }

    private function normalize_response($response)
    {
        if (!is_array($response)) throw new RuntimeException('VMware SOAP transport returned an invalid response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $body = isset($response['body']) ? (string)$response['body'] : '';
        $headers = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array();
        if (strlen($body) > $this->maxResponseBytes) throw new RuntimeException('vCenter SOAP response exceeded the safety limit');
        if ($status < 200 || $status >= 300) throw new RuntimeException('vCenter SOAP request failed (HTTP ' . $status . ')');
        return array('status' => $status, 'body' => $body, 'headers' => $headers);
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

    private function find_property_value(DOMDocument $doc, $name)
    {
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//*[local-name()="propSet"]') as $prop) {
            $nameNode = $xpath->query('./*[local-name()="name"]', $prop)->item(0);
            if ($nameNode !== null && trim($nameNode->textContent) === $name) {
                return $xpath->query('./*[local-name()="val"]', $prop)->item(0);
            }
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

    private function text($node, $localName)
    {
        $child = $this->child($node, $localName);
        if ($child === null) return null;
        $value = trim($child->textContent);
        return $value === '' ? null : $value;
    }

    private function int_or_null($value)
    {
        if ($value === null || !preg_match('/^-?[0-9]+$/', (string)$value)) return null;
        return (int)$value;
    }

    private function bool_or_null($value)
    {
        if ($value === null) return null;
        if ($value === 'true' || $value === '1') return true;
        if ($value === 'false' || $value === '0') return false;
        return null;
    }

    private function extract_session_cookie($headers)
    {
        foreach ((array)$headers as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) continue;
            $value = trim(substr($line, strlen('Set-Cookie:')));
            $pair = trim(strtok($value, ';'));
            if (stripos($pair, 'vmware_soap_session=') === 0 && strlen($pair) <= 8192) return $pair;
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
        return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function normalize_endpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') throw new InvalidArgumentException('vCenter endpoint is required');
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint)) $endpoint = 'https://' . $endpoint;
        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) throw new InvalidArgumentException('Invalid vCenter endpoint');
        if (strtolower($parts['scheme']) !== 'https') throw new InvalidArgumentException('vCenter endpoint must use HTTPS');
        if (isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('Credentials must not be embedded in the vCenter URL');
        if (!empty($parts['query']) || !empty($parts['fragment'])) throw new InvalidArgumentException('vCenter endpoint must not contain query or fragment data');
        $path = isset($parts['path']) ? trim((string)$parts['path']) : '';
        if ($path !== '' && $path !== '/') throw new InvalidArgumentException('Use the vCenter base URL without a path');
        $host = strtolower(trim((string)$parts['host'], '[]'));
        if ($host === 'localhost' || $host === 'localhost.localdomain') throw new InvalidArgumentException('localhost is not an allowed vCenter target');
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) throw new InvalidArgumentException('Invalid vCenter TCP port');
        $hostForUrl = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        return 'https://' . $hostForUrl . ($port !== null ? ':' . $port : '');
    }
}
