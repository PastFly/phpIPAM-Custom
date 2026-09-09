<?php

/**
 * phpIPAM Custom — Phase 5F.1 optional read-only storage performance collector.
 *
 * Reads only aggregate disk.usage.average for one explicitly selected HostSystem
 * or VirtualMachine. Counter IDs are discovered dynamically. If the counter or
 * aggregate instance is unavailable, the collector returns supported=false
 * instead of inventing data or failing the primary CPU/RAM observability path.
 */
class IntegrationVmwareVcenterStoragePerformance
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;

    public function __construct($transport, $connectTimeout = 8, $requestTimeout = 25)
    {
        if (!is_callable($transport)) throw new InvalidArgumentException('VMware storage performance transport must be callable');
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
    }

    public function read_realtime_series($endpoint, $username, $password, $entityType, $entityId, $maxSamples = 60, $verifyTls = true)
    {
        $endpoint = rtrim(trim((string)$endpoint), '/');
        if (!preg_match('#^https://[^/]+(?::[0-9]+)?$#i', $endpoint)) throw new InvalidArgumentException('Invalid vCenter endpoint');
        $username = trim((string)$username);
        $password = (string)$password;
        if ($username === '' || $password === '') throw new InvalidArgumentException('vCenter username and password are required');

        $entityType = strtolower(trim((string)$entityType));
        $entityId = trim((string)$entityId);
        if ($entityType === 'host') {
            $morType = 'HostSystem';
            if (!preg_match('/^host-[0-9]+$/', $entityId)) throw new InvalidArgumentException('Invalid HostSystem id');
        } elseif ($entityType === 'vm') {
            $morType = 'VirtualMachine';
            if (!preg_match('/^vm-[0-9]+$/', $entityId)) throw new InvalidArgumentException('Invalid VirtualMachine id');
        } else {
            throw new InvalidArgumentException('Storage performance entity type must be host or vm');
        }

        $maxSamples = (int)$maxSamples;
        if ($maxSamples < 12 || $maxSamples > 180) throw new InvalidArgumentException('Storage performance maxSamples must be between 12 and 180');
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP DOM extension is required');

        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;
        $started = microtime(true);
        try {
            $serviceResponse = $this->request($sdk, $this->envelope('<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'), null, $verifyTls);
            $serviceDoc = $this->parse($serviceResponse['body']);
            $service = array(
                'session_manager' => $this->required_text($serviceDoc, 'sessionManager'),
                'property_collector' => $this->required_text($serviceDoc, 'propertyCollector'),
                'performance_manager' => $this->required_text($serviceDoc, 'perfManager')
            );

            $login = '<Login xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this><userName>' . $this->xml($username) . '</userName><password>' . $this->xml($password) . '</password></Login>';
            $loginResponse = $this->request($sdk, $this->envelope($login), null, $verifyTls);
            $this->parse($loginResponse['body']);
            $cookie = $this->extract_cookie($loginResponse['headers']);
            if ($cookie === null) throw new RuntimeException('vCenter SOAP login did not return a session cookie');

            $providerXml = '<QueryPerfProviderSummary xmlns="urn:vim25"><_this type="PerformanceManager">' . $this->xml($service['performance_manager']) . '</_this><entity type="' . $morType . '">' . $this->xml($entityId) . '</entity></QueryPerfProviderSummary>';
            $providerDoc = $this->parse($this->request($sdk, $this->envelope($providerXml), $cookie, $verifyTls)['body']);
            $refreshRate = $this->provider_refresh_rate($providerDoc);
            if ($refreshRate === null) return $this->unsupported($entityType, $entityId, 'provider_not_realtime', $verifyTls, $started);

            $counterXml = '<RetrieveProperties xmlns="urn:vim25"><_this type="PropertyCollector">' . $this->xml($service['property_collector']) . '</_this><specSet><propSet><type>PerformanceManager</type><pathSet>perfCounter</pathSet></propSet><objectSet><obj type="PerformanceManager">' . $this->xml($service['performance_manager']) . '</obj><skip>false</skip></objectSet></specSet></RetrieveProperties>';
            $counterDoc = $this->parse($this->request($sdk, $this->envelope($counterXml), $cookie, $verifyTls)['body']);
            $counter = $this->find_disk_usage_counter($counterDoc);
            if ($counter === null) return $this->unsupported($entityType, $entityId, 'disk_usage_counter_unavailable', $verifyTls, $started);

            $availableXml = '<QueryAvailablePerfMetric xmlns="urn:vim25"><_this type="PerformanceManager">' . $this->xml($service['performance_manager']) . '</_this><entity type="' . $morType . '">' . $this->xml($entityId) . '</entity><intervalId>' . $refreshRate . '</intervalId></QueryAvailablePerfMetric>';
            $availableDoc = $this->parse($this->request($sdk, $this->envelope($availableXml), $cookie, $verifyTls)['body']);
            if (!$this->aggregate_metric_available($availableDoc, (int)$counter['id'])) return $this->unsupported($entityType, $entityId, 'aggregate_disk_usage_unavailable', $verifyTls, $started);

            $queryXml = '<QueryPerf xmlns="urn:vim25"><_this type="PerformanceManager">' . $this->xml($service['performance_manager']) . '</_this><querySpec><entity type="' . $morType . '">' . $this->xml($entityId) . '</entity><maxSample>' . $maxSamples . '</maxSample><metricId><counterId>' . (int)$counter['id'] . '</counterId><instance></instance></metricId><intervalId>' . $refreshRate . '</intervalId><format>normal</format></querySpec></QueryPerf>';
            $queryDoc = $this->parse($this->request($sdk, $this->envelope($queryXml), $cookie, $verifyTls)['body']);
            $samples = $this->parse_samples($queryDoc, (int)$counter['id'], $maxSamples);

            return array(
                'supported' => count($samples) >= 2,
                'reason' => count($samples) >= 2 ? null : 'insufficient_samples',
                'entity_type' => $entityType,
                'entity_external_id' => $entityId,
                'counter' => $counter,
                'refresh_rate' => $refreshRate,
                'samples' => $samples,
                'sample_count' => count($samples),
                'verify_tls' => (bool)$verifyTls,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000)
            );
        } finally {
            if ($cookie !== null && is_array($service) && !empty($service['session_manager'])) {
                $logout = '<Logout xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this></Logout>';
                try { $this->request($sdk, $this->envelope($logout), $cookie, $verifyTls); } catch (Exception $ignored) {}
            }
        }
    }

    private function unsupported($entityType, $entityId, $reason, $verifyTls, $started)
    {
        return array('supported' => false, 'reason' => $reason, 'entity_type' => $entityType, 'entity_external_id' => $entityId, 'samples' => array(), 'sample_count' => 0, 'verify_tls' => (bool)$verifyTls, 'duration_ms' => (int)round((microtime(true) - $started) * 1000));
    }

    private function provider_refresh_rate(DOMDocument $doc)
    {
        $xp = new DOMXPath($doc);
        $node = $xp->query('//*[local-name()="QueryPerfProviderSummaryResponse"]/*[local-name()="returnval"]')->item(0);
        if ($node === null) return null;
        $current = strtolower((string)$this->child_text($node, 'currentSupported'));
        $rate = $this->child_text($node, 'refreshRate');
        if (!in_array($current, array('true', '1'), true) || $rate === null || !preg_match('/^[0-9]+$/', $rate)) return null;
        $rate = (int)$rate;
        return $rate >= 1 && $rate <= 300 ? $rate : null;
    }

    private function find_disk_usage_counter(DOMDocument $doc)
    {
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*[local-name()="PerfCounterInfo"]') as $counter) {
            $id = $this->child_text($counter, 'key');
            $group = $this->nested_text($counter, 'groupInfo', 'key');
            $name = $this->nested_text($counter, 'nameInfo', 'key');
            $rollup = $this->child_text($counter, 'rollupType');
            $unit = $this->nested_text($counter, 'unitInfo', 'key');
            if ($group === 'disk' && $name === 'usage' && $rollup === 'average' && $id !== null && preg_match('/^[0-9]+$/', $id)) {
                return array('id' => (int)$id, 'canonical' => 'disk.usage.average', 'unit' => $unit);
            }
        }
        return null;
    }

    private function aggregate_metric_available(DOMDocument $doc, $counterId)
    {
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*[local-name()="QueryAvailablePerfMetricResponse"]/*[local-name()="returnval"]') as $metric) {
            $id = $this->child_text($metric, 'counterId');
            $instance = $this->child_text($metric, 'instance');
            if ((string)$id === (string)$counterId && ($instance === null || $instance === '')) return true;
        }
        return false;
    }

    private function parse_samples(DOMDocument $doc, $counterId, $maxSamples)
    {
        $xp = new DOMXPath($doc);
        $root = $xp->query('//*[local-name()="QueryPerfResponse"]/*[local-name()="returnval"]')->item(0);
        if ($root === null) return array();
        $meta = array();
        foreach ($xp->query('./*[local-name()="sampleInfo"]', $root) as $sample) {
            $ts = $this->child_text($sample, 'timestamp');
            $interval = $this->child_text($sample, 'interval');
            if ($ts !== null) $meta[] = array('timestamp' => $ts, 'interval_seconds' => $interval !== null ? (int)$interval : null);
            if (count($meta) >= $maxSamples) break;
        }
        $values = array();
        foreach ($xp->query('./*[local-name()="value"]', $root) as $series) {
            $idNode = null;
            foreach ($series->childNodes as $child) if ($child instanceof DOMElement && $child->localName === 'id') { $idNode = $child; break; }
            if ($idNode === null || (int)$this->child_text($idNode, 'counterId') !== $counterId) continue;
            $instance = $this->child_text($idNode, 'instance');
            if ($instance !== null && $instance !== '') continue;
            foreach ($series->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'value') {
                    $raw = trim($child->textContent);
                    $values[] = preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $raw) ? max(0.0, (float)$raw) : null;
                    if (count($values) >= $maxSamples) break;
                }
            }
            break;
        }
        $count = min(count($meta), count($values));
        $out = array();
        for ($i = 0; $i < $count; $i++) {
            if ($values[$i] === null) continue;
            $out[] = array('timestamp' => $meta[$i]['timestamp'], 'interval_seconds' => $meta[$i]['interval_seconds'], 'disk_kib_per_second' => $values[$i], 'disk_mib_per_second' => $values[$i] / 1024.0);
        }
        return $out;
    }

    private function request($url, $body, $cookie, $verifyTls)
    {
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $response = call_user_func($this->transport, 'POST', $url, $headers, $body, (bool)$verifyTls, $this->connectTimeout, $this->requestTimeout);
        if (!is_array($response) || !isset($response['status']) || !isset($response['body'])) throw new RuntimeException('Invalid vCenter transport response');
        if ((int)$response['status'] < 200 || (int)$response['status'] >= 300) throw new RuntimeException('vCenter SOAP request failed (HTTP ' . (int)$response['status'] . ')');
        return $response;
    }

    private function parse($xml)
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML((string)$xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) throw new RuntimeException('Invalid vCenter SOAP XML');
        $xp = new DOMXPath($doc);
        $fault = $xp->query('//*[local-name()="Fault"]')->item(0);
        if ($fault !== null) throw new RuntimeException('vCenter SOAP fault');
        return $doc;
    }

    private function required_text(DOMDocument $doc, $name)
    {
        $xp = new DOMXPath($doc);
        $node = $xp->query('//*[local-name()="RetrieveServiceContentResponse"]//*[local-name()="' . $name . '"]')->item(0);
        if ($node === null || trim($node->textContent) === '') throw new RuntimeException('vCenter service content missing ' . $name);
        return trim($node->textContent);
    }

    private function child_text(DOMNode $node, $name)
    {
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement && $child->localName === $name) return trim($child->textContent);
        return null;
    }

    private function nested_text(DOMNode $node, $parent, $childName)
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $parent) return $this->child_text($child, $childName);
        }
        return null;
    }

    private function extract_cookie($headers)
    {
        foreach ((array)$headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/i', (string)$header, $m)) return trim($m[1]);
        }
        return null;
    }

    private function envelope($body)
    {
        return '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
    }

    private function xml($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
