<?php

/**
 * phpIPAM Custom — safe fault-aware wrapper for the Phase 5F direct transport.
 *
 * vSphere commonly returns SOAP Fault documents with HTTP 500. The collectors
 * intentionally reject non-2xx responses before parsing them, which can hide
 * the useful faultstring during compatibility work. This wrapper keeps the
 * same bounded direct transport but, on a non-2xx SOAP response, extracts only
 * a short faultstring and the known operation name. Request/response bodies,
 * credentials, cookies and arbitrary XML are never included in the exception.
 */
class IntegrationVmwareVcenterFaultAwareTransport
{
    private $transport;

    public function __construct($transport)
    {
        if (!is_callable($transport)) {
            throw new InvalidArgumentException('Fault-aware VMware transport requires a callable transport');
        }
        $this->transport = $transport;
    }

    public function __invoke($method, $url, $headers, $body, $verifyTls, $connectTimeout, $requestTimeout)
    {
        $response = call_user_func(
            $this->transport,
            $method,
            $url,
            $headers,
            $body,
            $verifyTls,
            $connectTimeout,
            $requestTimeout
        );

        if (!is_array($response)) {
            throw new RuntimeException('Fault-aware VMware transport received an invalid response');
        }

        $status = isset($response['status']) ? (int)$response['status'] : 0;
        if ($status >= 200 && $status < 300) {
            return $response;
        }

        $operation = $this->operation_name((string)$body);
        $fault = $this->soap_fault_string(isset($response['body']) ? (string)$response['body'] : '');

        $message = 'vCenter SOAP ' . $operation . ' failed (HTTP ' . $status . ')';
        if ($fault !== null && $fault !== '') {
            $message .= ': ' . $fault;
        }
        throw new RuntimeException($message);
    }

    private function operation_name($body)
    {
        $allowed = array(
            'RetrieveServiceContent',
            'Login',
            'Logout',
            'FindByDnsName',
            'RetrievePropertiesEx',
            'QueryPerfProviderSummary',
            'QueryAvailablePerfMetric',
            'QueryPerf'
        );
        foreach ($allowed as $name) {
            if (strpos($body, '<' . $name) !== false) return $name;
        }
        return 'request';
    }

    private function soap_fault_string($xml)
    {
        if ($xml === '' || !class_exists('DOMDocument')) return null;

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) return null;

        $xpath = new DOMXPath($doc);
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);
        if ($fault === null) return null;

        $node = $xpath->query('.//*[local-name()="faultstring"]', $fault)->item(0);
        if ($node === null) return 'SOAP fault';

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string)$node->textContent));
        $value = preg_replace('/\s+/', ' ', $value);
        return substr((string)$value, 0, 300);
    }
}
