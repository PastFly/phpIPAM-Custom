<?php

/**
 * phpIPAM Custom — narrow read-only vSphere SearchIndex Host resolver.
 *
 * Used only to resolve one explicitly supplied ESXi DNS name to one HostSystem
 * managed-object id for the Phase 5F compatibility preview. No inventory is
 * persisted and no VMware mutation method is exposed.
 */
class IntegrationVmwareVcenterSoapHostResolver
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 1048576)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('VMware SOAP host resolver transport must be callable');
        }
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(16384, min(2097152, (int)$maxResponseBytes));
    }

    public function resolve_host_id_by_dns_name($endpoint, $username, $password, $hostName, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $hostName = trim((string)$hostName);
        $verifyTls = (bool)$verifyTls;

        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('vCenter username and password are required');
        }
        if ($hostName === '' || strlen($hostName) > 255 || preg_match('/[\x00-\x20\x7F]/', $hostName)) {
            throw new InvalidArgumentException('Invalid ESXi DNS name');
        }
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('PHP DOM extension is required for vSphere Web Services');
        }

        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;

        try {
            $serviceResponse = $this->soap_request($sdk, $this->envelope(
                '<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'
            ), null, $verifyTls);
            $serviceDoc = $this->parse_soap($serviceResponse['body'], 'RetrieveServiceContent');
            $service = array(
                'session_manager' => $this->required_text($serviceDoc, 'sessionManager'),
                'search_index' => $this->required_text($serviceDoc, 'searchIndex')
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

            $findXml = '<FindByDnsName xmlns="urn:vim25">'
                . '<_this type="SearchIndex">' . $this->xml($service['search_index']) . '</_this>'
                . '<dnsName>' . $this->xml($hostName) . '</dnsName>'
                . '<vmSearch>false</vmSearch>'
                . '</FindByDnsName>';
            $findResponse = $this->soap_request($sdk, $this->envelope($findXml), $cookie, $verifyTls);
            $findDoc = $this->parse_soap($findResponse['body'], 'FindByDnsName');

            $xpath = new DOMXPath($findDoc);
            $returnNode = $xpath->query('//*[local-name()="FindByDnsNameResponse"]/*[local-name()="returnval"]')->item(0);
            if ($returnNode === null) {
                throw new RuntimeException('ESXi DNS name was not found by vCenter SearchIndex');
            }
            $type = $returnNode instanceof DOMElement ? trim((string)$returnNode->getAttribute('type')) : '';
            $hostId = trim((string)$returnNode->textContent);
            if ($type !== '' && $type !== 'HostSystem') {
                throw new RuntimeException('SearchIndex DNS result is not a HostSystem');
            }
            if (!preg_match('/^host-[0-9]+$/', $hostId)) {
                throw new RuntimeException('SearchIndex returned an unexpected HostSystem managed object id');
            }

            return $hostId;
        } finally {
            if ($cookie !== null && is_array($service) && !empty($service['session_manager'])) {
                $logout = '<Logout xmlns="urn:vim25"><_this type="SessionManager">'
                    . $this->xml($service['session_manager']) . '</_this></Logout>';
                try {
                    $this->soap_request($sdk, $this->envelope($logout), $cookie, $verifyTls);
                } catch (Exception $ignored) {
                    // Best-effort cleanup must not hide the primary read result.
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
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vSphereSOAP-HostResolver/1.0',
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

        if ($tooLarge) throw new RuntimeException('vCenter SOAP host resolver response exceeded the safety limit');
        if ($ok === false) throw new RuntimeException('vCenter SOAP host resolver network error: ' . ($error !== '' ? $error : 'request failed'));
        return $this->normalize_response(array('status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders));
    }

    private function normalize_response($response)
    {
        if (!is_array($response)) throw new RuntimeException('VMware SOAP host resolver transport returned an invalid response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $body = isset($response['body']) ? (string)$response['body'] : '';
        $headers = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array();
        if (strlen($body) > $this->maxResponseBytes) throw new RuntimeException('vCenter SOAP host resolver response exceeded the safety limit');
        if ($status < 200 || $status >= 300) throw new RuntimeException('vCenter SOAP host resolver request failed (HTTP ' . $status . ')');
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
        if ($node === null || trim($node->textContent) === '') {
            throw new RuntimeException('vCenter ServiceContent missing ' . $localName);
        }
        return trim($node->textContent);
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
        if (!class_exists('IntegrationVmwareVcenter')) {
            throw new RuntimeException('Base VMware vCenter connector class is required for endpoint validation');
        }
        $client = new IntegrationVmwareVcenter();
        return $client->normalize_endpoint($endpoint);
    }
}
