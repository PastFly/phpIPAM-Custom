<?php

/**
 * phpIPAM Custom — VMware vCenter guest network observation client.
 *
 * Phase 5C.3 is deliberately read-only and bounded. It authenticates once,
 * performs GET-only guest networking interface reads for an explicit set of VM
 * external IDs, and closes the session. It never writes to vCenter/phpIPAM and
 * never persists raw guest payloads, credentials or session tokens.
 */
class IntegrationVmwareVcenterGuestNetwork
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxVmRequests;
    private $maxInterfacesPerVm;
    private $maxIpAddressesPerInterface;

    public function __construct(
        $transport = null,
        $connectTimeout = 8,
        $requestTimeout = 20,
        $maxResponseBytes = 1048576,
        $maxVmRequests = 250,
        $maxInterfacesPerVm = 128,
        $maxIpAddressesPerInterface = 128
    ) {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('VMware guest-network transport must be callable');
        }

        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(4096, (int)$maxResponseBytes);
        $this->maxVmRequests = max(1, min(2000, (int)$maxVmRequests));
        $this->maxInterfacesPerVm = max(1, min(512, (int)$maxInterfacesPerVm));
        $this->maxIpAddressesPerInterface = max(1, min(512, (int)$maxIpAddressesPerInterface));
    }

    public function read_vm_interfaces($endpoint, $username, $password, array $vmExternalIds, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $verifyTls = (bool)$verifyTls;

        if ($username === '') {
            throw new InvalidArgumentException('vCenter username is required');
        }
        if ($password === '') {
            throw new InvalidArgumentException('vCenter password is required');
        }

        $vmIds = $this->normalize_vm_ids($vmExternalIds);
        if (count($vmIds) === 0) {
            throw new InvalidArgumentException('At least one VM external id is required');
        }
        if (count($vmIds) > $this->maxVmRequests) {
            throw new InvalidArgumentException('VM guest-network request set exceeded the connector safety limit');
        }

        $started = microtime(true);
        $session = null;
        $apiFamily = null;
        $authStatus = 0;
        $vms = array();
        $flatIpObservations = array();
        $interfaceCount = 0;
        $ipCount = 0;

        try {
            $auth = $this->authenticate($endpoint, $username, $password, $verifyTls);
            $session = $auth['session'];
            $apiFamily = $auth['api_family'];
            $authStatus = $auth['auth_http_status'];

            if ($apiFamily !== 'modern') {
                throw new RuntimeException('VM guest-network inventory requires modern vCenter API support');
            }

            $headers = $this->session_headers($session);
            foreach ($vmIds as $vmId) {
                $response = $this->request(
                    'GET',
                    $endpoint . '/api/vcenter/vm/' . rawurlencode($vmId) . '/guest/networking/interfaces',
                    $headers,
                    null,
                    null,
                    $verifyTls
                );

                $status = (int)$response['status'];
                if ($status === 503) {
                    $vms[] = array(
                        'vm_external_id' => $vmId,
                        'status' => 'tools_unavailable',
                        'interface_count' => 0,
                        'ip_count' => 0,
                        'interfaces' => array()
                    );
                    continue;
                }
                if ($status === 404) {
                    $vms[] = array(
                        'vm_external_id' => $vmId,
                        'status' => 'vm_not_found',
                        'interface_count' => 0,
                        'ip_count' => 0,
                        'interfaces' => array()
                    );
                    continue;
                }
                if ($status !== 200) {
                    throw new RuntimeException($this->guest_network_failure_message($status));
                }

                $rows = $this->decode_interfaces($response['body']);
                $interfaces = array();
                $vmIpCount = 0;
                foreach ($rows as $row) {
                    $interface = $this->normalize_interface($vmId, $row);
                    $interfaces[] = $interface;
                    foreach ($interface['ip_addresses'] as $ip) {
                        $flatIpObservations[] = array(
                            'vm_external_id' => $vmId,
                            'nic_external_id' => $interface['nic_external_id'],
                            'mac_address' => $interface['mac_address'],
                            'ip_address' => $ip['ip_address'],
                            'prefix_length' => $ip['prefix_length'],
                            'origin' => $ip['origin'],
                            'state' => $ip['state']
                        );
                        $vmIpCount++;
                        $ipCount++;
                    }
                }

                $interfaceCount += count($interfaces);
                $vms[] = array(
                    'vm_external_id' => $vmId,
                    'status' => 'ok',
                    'interface_count' => count($interfaces),
                    'ip_count' => $vmIpCount,
                    'interfaces' => $interfaces
                );
            }
        } finally {
            if (is_string($session) && $session !== '' && is_string($apiFamily)) {
                $this->cleanup_session($endpoint, $session, $apiFamily, $verifyTls);
            }
        }

        return array(
            'ok' => true,
            'provider' => 'vmware_vcenter',
            'endpoint' => $endpoint,
            'api_family' => $apiFamily,
            'auth_http_status' => $authStatus,
            'verify_tls' => $verifyTls,
            'vm_count' => count($vms),
            'interface_count' => $interfaceCount,
            'ip_count' => $ipCount,
            'vms' => $vms,
            'ip_observations' => $flatIpObservations,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000)
        );
    }

    private function normalize_endpoint($endpoint)
    {
        if (!class_exists('IntegrationVmwareVcenter')) {
            throw new RuntimeException('Base VMware vCenter connector class is required for endpoint validation');
        }
        $baseClient = new IntegrationVmwareVcenter();
        return $baseClient->normalize_endpoint($endpoint);
    }

    private function normalize_vm_ids(array $vmExternalIds)
    {
        $seen = array();
        $result = array();
        foreach ($vmExternalIds as $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('VM external id has an invalid value');
            }
            $vmId = trim((string)$value);
            if (
                $vmId === '' ||
                strlen($vmId) > 1024 ||
                preg_match('/[\x00-\x20\x7F]/', $vmId) ||
                preg_match('/[\/?#]/', $vmId)
            ) {
                throw new InvalidArgumentException('VM external id has an invalid value');
            }
            if (!isset($seen[$vmId])) {
                $seen[$vmId] = true;
                $result[] = $vmId;
            }
        }
        return $result;
    }

    private function authenticate($endpoint, $username, $password, $verifyTls)
    {
        $login = $this->request(
            'POST',
            $endpoint . '/api/session',
            array('Accept: application/json'),
            $username,
            $password,
            $verifyTls
        );
        $authStatus = (int)$login['status'];

        if ($authStatus === 200 || $authStatus === 201) {
            return array(
                'session' => $this->extract_session_token($login['body']),
                'api_family' => 'modern',
                'auth_http_status' => $authStatus
            );
        }

        if ($authStatus === 404 || $authStatus === 405) {
            $login = $this->request(
                'POST',
                $endpoint . '/rest/com/vmware/cis/session',
                array('Accept: application/json'),
                $username,
                $password,
                $verifyTls
            );
            $authStatus = (int)$login['status'];
            if ($authStatus !== 200 && $authStatus !== 201) {
                throw new RuntimeException($this->authentication_failure_message($authStatus));
            }
            return array(
                'session' => $this->extract_session_token($login['body']),
                'api_family' => 'legacy',
                'auth_http_status' => $authStatus
            );
        }

        throw new RuntimeException($this->authentication_failure_message($authStatus));
    }

    private function decode_interfaces($body)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('vCenter guest network response is not valid JSON');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected vCenter guest network response');
        }
        if (count($decoded) > $this->maxInterfacesPerVm) {
            throw new RuntimeException('vCenter guest interface collection exceeded the connector safety limit');
        }
        return array_values($decoded);
    }

    private function normalize_interface($vmId, $row)
    {
        if (!is_array($row)) {
            throw new RuntimeException('vCenter guest network interface row has an invalid shape');
        }

        $nic = isset($row['nic']) ? trim((string)$row['nic']) : '';
        if ($nic === '' || strlen($nic) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $nic)) {
            throw new RuntimeException('vCenter guest NIC has an invalid external id');
        }

        $mac = null;
        if (array_key_exists('mac_address', $row) && $row['mac_address'] !== null && $row['mac_address'] !== '') {
            $mac = $this->normalize_mac_address($row['mac_address']);
        }

        $ipRows = array();
        if (isset($row['ip']) && is_array($row['ip']) && array_key_exists('ip_addresses', $row['ip'])) {
            if (!is_array($row['ip']['ip_addresses'])) {
                throw new RuntimeException('vCenter guest IP address collection has an invalid shape');
            }
            if (count($row['ip']['ip_addresses']) > $this->maxIpAddressesPerInterface) {
                throw new RuntimeException('vCenter guest IP address collection exceeded the connector safety limit');
            }
            foreach ($row['ip']['ip_addresses'] as $ipRow) {
                $ipRows[] = $this->normalize_ip_observation($ipRow);
            }
        }

        return array(
            'vm_external_id' => $vmId,
            'nic_external_id' => $nic,
            'mac_address' => $mac,
            'ip_addresses' => $ipRows
        );
    }

    private function normalize_mac_address($value)
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('vCenter guest MAC address has an invalid value');
        }
        $mac = strtolower(trim((string)$value));
        $compact = str_replace(array(':', '-', '.'), '', $mac);
        if (!preg_match('/^[0-9a-f]{12}$/', $compact)) {
            throw new RuntimeException('vCenter guest MAC address has an invalid value');
        }
        return implode(':', str_split($compact, 2));
    }

    private function normalize_ip_observation($row)
    {
        if (!is_array($row)) {
            throw new RuntimeException('vCenter guest IP observation has an invalid shape');
        }
        $raw = isset($row['ip_address']) ? trim((string)$row['ip_address']) : '';
        if ($raw === '' || filter_var($raw, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('vCenter guest IP address has an invalid value');
        }

        $packed = @inet_pton($raw);
        if ($packed === false) {
            throw new RuntimeException('vCenter guest IP address has an invalid value');
        }
        $canonical = @inet_ntop($packed);
        if (!is_string($canonical) || $canonical === '') {
            throw new RuntimeException('vCenter guest IP address has an invalid value');
        }
        $canonical = strtolower($canonical);

        $prefix = null;
        if (array_key_exists('prefix_length', $row) && $row['prefix_length'] !== null && $row['prefix_length'] !== '') {
            if (is_int($row['prefix_length'])) {
                $prefix = $row['prefix_length'];
            } elseif (is_string($row['prefix_length']) && preg_match('/^[0-9]+$/', $row['prefix_length'])) {
                $prefix = (int)$row['prefix_length'];
            } else {
                throw new RuntimeException('vCenter guest IP prefix length has an invalid value');
            }
            $maxPrefix = strlen($packed) === 4 ? 32 : 128;
            if ($prefix < 0 || $prefix > $maxPrefix) {
                throw new RuntimeException('vCenter guest IP prefix length has an invalid value');
            }
        }

        return array(
            'ip_address' => $canonical,
            'prefix_length' => $prefix,
            'origin' => $this->optional_token_value(isset($row['origin']) ? $row['origin'] : null, 'guest IP origin'),
            'state' => $this->optional_token_value(isset($row['state']) ? $row['state'] : null, 'guest IP state')
        );
    }

    private function optional_token_value($value, $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        $token = strtoupper(trim((string)$value));
        if ($token === '' || strlen($token) > 128 || !preg_match('/^[A-Z0-9_.:-]+$/', $token)) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        return strtolower($token);
    }

    private function session_headers($session)
    {
        $session = trim((string)$session);
        if ($session === '') {
            throw new RuntimeException('vCenter session token is empty');
        }
        return array(
            'Accept: application/json',
            'vmware-api-session-id: ' . $session
        );
    }

    private function cleanup_session($endpoint, $session, $apiFamily, $verifyTls)
    {
        $headers = $this->session_headers($session);
        try {
            if ($apiFamily === 'modern') {
                $this->request('DELETE', $endpoint . '/api/session', $headers, null, null, $verifyTls);
            } else {
                $this->request('DELETE', $endpoint . '/rest/com/vmware/cis/session', $headers, null, null, $verifyTls);
            }
        } catch (Exception $ignored) {
            // Cleanup is best-effort and must not hide the primary result.
        }
    }

    private function request($method, $url, $headers, $username, $password, $verifyTls)
    {
        if ($this->transport !== null) {
            $response = call_user_func(
                $this->transport,
                strtoupper((string)$method),
                (string)$url,
                (array)$headers,
                $username,
                $password,
                (bool)$verifyTls,
                $this->connectTimeout,
                $this->requestTimeout
            );
            return $this->normalize_transport_response($response);
        }
        return $this->curl_request($method, $url, $headers, $username, $password, $verifyTls);
    }

    private function normalize_transport_response($response)
    {
        if (!is_array($response)) {
            throw new RuntimeException('VMware guest-network transport returned an invalid response');
        }
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $body = isset($response['body']) ? (string)$response['body'] : '';
        $error = isset($response['error']) ? trim((string)$response['error']) : '';
        $duration = isset($response['duration_ms']) ? max(0, (int)$response['duration_ms']) : 0;
        if ($status === 0 && $error !== '') {
            throw new RuntimeException('vCenter network error: ' . $error);
        }
        return array(
            'status' => $status,
            'body' => $body,
            'error' => $error,
            'duration_ms' => $duration
        );
    }

    private function curl_request($method, $url, $headers, $username, $password, $verifyTls)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for VMware guest network inventory');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        $body = '';
        $tooLarge = false;
        $maxBytes = $this->maxResponseBytes;
        $options = array(
            CURLOPT_URL => (string)$url,
            CURLOPT_CUSTOMREQUEST => strtoupper((string)$method),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => (array)$headers,
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-GuestNetwork/1.0',
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }
        );
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if ($username !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = (string)$username . ':' . (string)$password;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $duration = (int)round(((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        $error = trim((string)curl_error($ch));
        curl_close($ch);

        if ($tooLarge) {
            throw new RuntimeException('vCenter response exceeded the connector safety limit');
        }
        if ($ok === false) {
            throw new RuntimeException('vCenter network error: ' . ($error !== '' ? $error : 'request failed'));
        }
        return array(
            'status' => $status,
            'body' => $body,
            'error' => $error,
            'duration_ms' => $duration
        );
    }

    private function extract_session_token($body)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('vCenter session response is not valid JSON');
        }
        if (is_string($decoded)) {
            $token = $decoded;
        } elseif (is_array($decoded) && isset($decoded['value']) && is_string($decoded['value'])) {
            $token = $decoded['value'];
        } else {
            throw new RuntimeException('vCenter session response did not contain a session token');
        }
        $token = trim($token);
        if ($token === '' || strlen($token) > 4096) {
            throw new RuntimeException('vCenter authentication returned an invalid session token');
        }
        return $token;
    }

    private function authentication_failure_message($status)
    {
        $status = (int)$status;
        if ($status === 401) {
            return 'vCenter authentication failed (HTTP 401)';
        }
        if ($status === 403) {
            return 'vCenter authentication was forbidden (HTTP 403)';
        }
        return 'vCenter authentication failed (HTTP ' . $status . ')';
    }

    private function guest_network_failure_message($status)
    {
        $status = (int)$status;
        if ($status === 401) {
            return 'vCenter session was not accepted for guest network inventory (HTTP 401)';
        }
        if ($status === 403) {
            return 'vCenter account lacks permission to read guest network inventory (HTTP 403)';
        }
        return 'vCenter guest network inventory request failed (HTTP ' . $status . ')';
    }
}
