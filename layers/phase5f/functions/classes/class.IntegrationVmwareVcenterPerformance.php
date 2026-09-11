<?php

/**
 * phpIPAM Custom — bounded read-only vSphere PerformanceManager collector.
 *
 * Counter IDs are discovered from PerformanceManager.perfCounter at runtime;
 * no version-specific numeric counter IDs are hardcoded. The first slice reads
 * only aggregate cpu.usage.average and mem.usage.average for one selected
 * HostSystem or VirtualMachine using the provider's real-time refresh rate.
 */
class IntegrationVmwareVcenterPerformance
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 25, $maxResponseBytes = 6291456)
    {
        if ($transport !== null && !is_callable($transport)) throw new InvalidArgumentException('VMware performance transport must be callable');
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(65536, min(8388608, (int)$maxResponseBytes));
    }

    public function read_realtime_percent_series($endpoint, $username, $password, $entityType, $entityId, $maxSamples = 180, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $verifyTls = (bool)$verifyTls;
        $entityType = strtolower(trim((string)$entityType));
        $entityId = trim((string)$entityId);
        $maxSamples = (int)$maxSamples;

        if ($username === '' || $password === '') throw new InvalidArgumentException('vCenter username and password are required');
        if ($maxSamples < 2 || $maxSamples > 360) throw new InvalidArgumentException('Performance maxSamples must be between 2 and 360');
        if ($entityType === 'host') {
            $morType = 'HostSystem';
            if (!preg_match('/^host-[0-9]+$/', $entityId)) throw new InvalidArgumentException('Invalid HostSystem managed object id');
        } elseif ($entityType === 'vm') {
            $morType = 'VirtualMachine';
            if (!preg_match('/^vm-[0-9]+$/', $entityId)) throw new InvalidArgumentException('Invalid VirtualMachine managed object id');
        } else {
            throw new InvalidArgumentException('Performance entity type must be host or vm');
        }
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP DOM extension is required for vSphere PerformanceManager');

        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;
        $started = microtime(true);

        try {
            $serviceResponse = $this->soap_request($sdk, $this->envelope('<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'), null, $verifyTls);
            $serviceDoc = $this->parse_soap($serviceResponse['body'], 'RetrieveServiceContent');
            $service = array(
                'session_manager' => $this->required_text($serviceDoc, 'sessionManager'),
                'property_collector' => $this->required_text($serviceDoc, 'propertyCollector'),
                'performance_manager' => $this->required_text($serviceDoc, 'perfManager')
            );

            $login = '<Login xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this>'
                . '<userName>' . $this->xml($username) . '</userName><password>' . $this->xml($password) . '</password></Login>';
            $loginResponse = $this->soap_request($sdk, $this->envelope($login), null, $verifyTls);
            $this->parse_soap($loginResponse['body'], 'Login');
            $cookie = $this->extract_session_cookie($loginResponse['headers']);
            if ($cookie === null) throw new RuntimeException('vCenter SOAP login did not return a session cookie');

            $providerXml = '<QueryPerfProviderSummary xmlns="urn:vim25"><_this type="PerformanceManager">'
                . $this->xml($service['performance_manager']) . '</_this><entity type="' . $morType . '">'
                . $this->xml($entityId) . '</entity></QueryPerfProviderSummary>';
            $providerResponse = $this->soap_request($sdk, $this->envelope($providerXml), $cookie, $verifyTls);
            $providerDoc = $this->parse_soap($providerResponse['body'], 'QueryPerfProviderSummary');
            $provider = $this->parse_provider_summary($providerDoc);
            if (!$provider['current_supported'] || $provider['refresh_rate'] === null || $provider['refresh_rate'] < 1 || $provider['refresh_rate'] > 300) {
                throw new RuntimeException('Selected vCenter entity does not expose bounded real-time performance statistics');
            }

            // Use legacy RetrieveProperties deliberately for the single bounded
            // perfCounter property read. It remains supported and avoids requiring
            // explicit SOAP API version negotiation before the counter discovery.
            $counterXml = '<RetrieveProperties xmlns="urn:vim25"><_this type="PropertyCollector">'
                . $this->xml($service['property_collector']) . '</_this><specSet><propSet><type>PerformanceManager</type>'
                . '<pathSet>perfCounter</pathSet></propSet><objectSet><obj type="PerformanceManager">'
                . $this->xml($service['performance_manager']) . '</obj><skip>false</skip></objectSet></specSet>'
                . '</RetrieveProperties>';
            $counterResponse = $this->soap_request($sdk, $this->envelope($counterXml), $cookie, $verifyTls);
            $counterDoc = $this->parse_soap($counterResponse['body'], 'RetrieveProperties(perfCounter)');
            $counterMap = $this->parse_counter_map($counterDoc);

            foreach (array('cpu.usage.average', 'mem.usage.average') as $required) {
                if (!isset($counterMap[$required])) throw new RuntimeException('Required vCenter performance counter not available: ' . $required);
                if ($counterMap[$required]['unit'] !== 'percent') throw new RuntimeException('Unexpected unit for performance counter: ' . $required);
            }

            $availableXml = '<QueryAvailablePerfMetric xmlns="urn:vim25"><_this type="PerformanceManager">'
                . $this->xml($service['performance_manager']) . '</_this><entity type="' . $morType . '">'
                . $this->xml($entityId) . '</entity><intervalId>' . (int)$provider['refresh_rate'] . '</intervalId></QueryAvailablePerfMetric>';
            $availableResponse = $this->soap_request($sdk, $this->envelope($availableXml), $cookie, $verifyTls);
            $availableDoc = $this->parse_soap($availableResponse['body'], 'QueryAvailablePerfMetric');
            $available = $this->parse_available_metrics($availableDoc);

            $selected = array();
            foreach (array('cpu.usage.average', 'mem.usage.average') as $name) {
                $id = (int)$counterMap[$name]['id'];
                if (!isset($available[$id]) || !$available[$id]) throw new RuntimeException('Aggregate performance metric is unavailable for selected entity: ' . $name);
                $selected[$name] = $counterMap[$name];
            }

            $metricXml = '';
            foreach ($selected as $counter) {
                $metricXml .= '<metricId><counterId>' . (int)$counter['id'] . '</counterId><instance></instance></metricId>';
            }
            $queryXml = '<QueryPerf xmlns="urn:vim25"><_this type="PerformanceManager">'
                . $this->xml($service['performance_manager']) . '</_this><querySpec><entity type="' . $morType . '">'
                . $this->xml($entityId) . '</entity><maxSample>' . $maxSamples . '</maxSample>' . $metricXml
                . '<intervalId>' . (int)$provider['refresh_rate'] . '</intervalId><format>normal</format></querySpec></QueryPerf>';
            $queryResponse = $this->soap_request($sdk, $this->envelope($queryXml), $cookie, $verifyTls);
            $queryDoc = $this->parse_soap($queryResponse['body'], 'QueryPerf');
            $series = $this->parse_query_perf($queryDoc, $selected, $maxSamples);

            return array(
                'entity_type' => $entityType,
                'entity_external_id' => $entityId,
                'provider' => $provider,
                'counters' => $selected,
                'samples' => $series,
                'sample_count' => count($series),
                'property_method' => 'RetrieveProperties',
                'verify_tls' => $verifyTls,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000)
            );
        } finally {
            if ($cookie !== null && is_array($service) && !empty($service['session_manager'])) {
                $logout = '<Logout xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this></Logout>';
                try { $this->soap_request($sdk, $this->envelope($logout), $cookie, $verifyTls); } catch (Exception $ignored) {}
            }
        }
    }

    private function parse_provider_summary(DOMDocument $doc)
    {
        $xpath = new DOMXPath($doc);
        $returnval = $xpath->query('//*[local-name()="QueryPerfProviderSummaryResponse"]/*[local-name()="returnval"]')->item(0);
        if ($returnval === null) throw new RuntimeException('vCenter performance provider summary missing');
        return array(
            'current_supported' => $this->bool_value($this->child_text($returnval, 'currentSupported')) === true,
            'summary_supported' => $this->bool_value($this->child_text($returnval, 'summarySupported')) === true,
            'refresh_rate' => $this->int_value($this->child_text($returnval, 'refreshRate'))
        );
    }

    private function parse_counter_map(DOMDocument $doc)
    {
        $xpath = new DOMXPath($doc);
        $map = array();
        foreach ($xpath->query('//*[local-name()="PerfCounterInfo"]') as $counter) {
            $id = $this->int_value($this->child_text($counter, 'key'));
            $group = $this->nested_text($counter, 'groupInfo', 'key');
            $name = $this->nested_text($counter, 'nameInfo', 'key');
            $unit = $this->nested_text($counter, 'unitInfo', 'key');
            $rollup = $this->child_text($counter, 'rollupType');
            if ($id === null || $group === null || $name === null || $rollup === null) continue;
            $canonical = $group . '.' . $name . '.' . $rollup;
            $map[$canonical] = array('id' => $id, 'group' => $group, 'name' => $name, 'rollup' => $rollup, 'unit' => $unit);
        }
        return $map;
    }

    private function parse_available_metrics(DOMDocument $doc)
    {
        $xpath = new DOMXPath($doc);
        $available = array();
        foreach ($xpath->query('//*[local-name()="QueryAvailablePerfMetricResponse"]/*[local-name()="returnval"]') as $metric) {
            $id = $this->int_value($this->child_text($metric, 'counterId'));
            if ($id === null) continue;
            $instance = $this->child_text($metric, 'instance');
            if (!isset($available[$id])) $available[$id] = false;
            if ($instance === null || $instance === '') $available[$id] = true;
        }
        return $available;
    }

    private function parse_query_perf(DOMDocument $doc, $selected, $maxSamples)
    {
        $xpath = new DOMXPath($doc);
        $returnval = $xpath->query('//*[local-name()="QueryPerfResponse"]/*[local-name()="returnval"]')->item(0);
        if ($returnval === null) return array();

        $timestamps = array();
        foreach ($xpath->query('./*[local-name()="sampleInfo"]', $returnval) as $sampleInfo) {
            $timestamp = $this->child_text($sampleInfo, 'timestamp');
            $interval = $this->int_value($this->child_text($sampleInfo, 'interval'));
            if ($timestamp !== null) $timestamps[] = array('timestamp' => $timestamp, 'interval_seconds' => $interval);
            if (count($timestamps) >= $maxSamples) break;
        }

        $idToName = array();
        foreach ($selected as $name => $counter) $idToName[(int)$counter['id']] = $name;
        $valuesByName = array();
        foreach ($xpath->query('./*[local-name()="value"]', $returnval) as $metricSeries) {
            $idNode = null;
            foreach ($metricSeries->childNodes as $child) if ($child instanceof DOMElement && $child->localName === 'id') { $idNode = $child; break; }
            if ($idNode === null) continue;
            $counterId = $this->int_value($this->child_text($idNode, 'counterId'));
            $instance = $this->child_text($idNode, 'instance');
            if ($counterId === null || !isset($idToName[$counterId]) || ($instance !== null && $instance !== '')) continue;
            $name = $idToName[$counterId];
            $values = array();
            foreach ($metricSeries->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'value') {
                    $raw = trim($child->textContent);
                    $values[] = preg_match('/^-?[0-9]+$/', $raw) ? ((int)$raw / 100.0) : null;
                    if (count($values) >= $maxSamples) break;
                }
            }
            $valuesByName[$name] = $values;
        }

        $count = count($timestamps);
        foreach ($valuesByName as $values) $count = min($count, count($values));
        if (!isset($valuesByName['cpu.usage.average']) || !isset($valuesByName['mem.usage.average'])) return array();

        $result = array();
        for ($i = 0; $i < $count; $i++) {
            $result[] = array(
                'timestamp' => $timestamps[$i]['timestamp'],
                'interval_seconds' => $timestamps[$i]['interval_seconds'],
                'cpu_percent' => $valuesByName['cpu.usage.average'][$i],
                'memory_percent' => $valuesByName['mem.usage.average'][$i]
            );
        }
        return $result;
    }

    private function soap_request($url, $body, $cookie, $verifyTls)
    {
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        if ($this->transport !== null) {
            return $this->normalize_response(call_user_func($this->transport, 'POST', $url, $headers, $body, (bool)$verifyTls, $this->connectTimeout, $this->requestTimeout));
        }
        return $this->curl_post($url, $headers, $body, $verifyTls);
    }

    private function curl_post($url, $headers, $body, $verifyTls)
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for vSphere PerformanceManager');
        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Unable to initialize cURL');
        $responseBody = '';
        $responseHeaders = array();
        $tooLarge = false;
        $maxBytes = $this->maxResponseBytes;
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout, CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls, CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'phpIPAM-Custom-vSpherePerf/1.0',
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) { $trim = trim($line); if ($trim !== '') $responseHeaders[] = $trim; return strlen($line); },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$responseBody, &$tooLarge, $maxBytes) {
                if (strlen($responseBody) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
                $responseBody .= $chunk; return strlen($chunk);
            }
        ));
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        curl_close($ch);
        if ($tooLarge) throw new RuntimeException('vCenter performance response exceeded the safety limit');
        if ($ok === false) throw new RuntimeException('vCenter performance network error: ' . ($error !== '' ? $error : 'request failed'));
        return $this->normalize_response(array('status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders));
    }

    private function normalize_response($response)
    {
        if (!is_array($response)) throw new RuntimeException('VMware performance transport returned an invalid response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $body = isset($response['body']) ? (string)$response['body'] : '';
        $headers = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array();
        if (strlen($body) > $this->maxResponseBytes) throw new RuntimeException('vCenter performance response exceeded the safety limit');
        if ($status < 200 || $status >= 300) throw new RuntimeException('vCenter PerformanceManager request failed (HTTP ' . $status . ')');
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

    private function child_text($node, $localName)
    {
        if (!($node instanceof DOMNode)) return null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $value = trim($child->textContent);
                return $value === '' ? null : $value;
            }
        }
        return null;
    }

    private function nested_text($node, $parentName, $childName)
    {
        if (!($node instanceof DOMNode)) return null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $parentName) return $this->child_text($child, $childName);
        }
        return null;
    }

    private function int_value($value)
    {
        if ($value === null || !preg_match('/^-?[0-9]+$/', (string)$value)) return null;
        return (int)$value;
    }

    private function bool_value($value)
    {
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
        return '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
    }

    private function xml($value) { return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

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
