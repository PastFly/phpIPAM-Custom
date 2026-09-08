<?php

/**
 * phpIPAM Custom — VMware vCenter read-only inventory client.
 *
 * Phase 5C collects bounded inventory summaries using authenticated GET-only
 * vCenter API calls and best-effort session cleanup. It never persists
 * credentials, tokens or raw response bodies and never performs vCenter or
 * phpIPAM inventory writes.
 */
class IntegrationVmwareVcenterInventory
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxCollectionItems;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 2097152, $maxCollectionItems = 10000)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException("VMware inventory transport must be callable");
        }

        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(4096, (int)$maxResponseBytes);
        $this->maxCollectionItems = max(1, min(20000, (int)$maxCollectionItems));
    }

    public function read_datacenters_and_clusters($endpoint, $username, $password, $verifyTls = true)
    {
        return $this->read_inventory_resources(
            $endpoint,
            $username,
            $password,
            $verifyTls,
            array('datacenter', 'cluster')
        );
    }

    public function read_core_inventory($endpoint, $username, $password, $verifyTls = true)
    {
        return $this->read_inventory_resources(
            $endpoint,
            $username,
            $password,
            $verifyTls,
            array('datacenter', 'cluster', 'host', 'vm')
        );
    }

    private function read_inventory_resources($endpoint, $username, $password, $verifyTls, array $resources)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $verifyTls = (bool)$verifyTls;

        if ($username === "") {
            throw new InvalidArgumentException("vCenter username is required");
        }
        if ($password === "") {
            throw new InvalidArgumentException("vCenter password is required");
        }

        $allowed = array('datacenter', 'cluster', 'host', 'vm');
        foreach ($resources as $resource) {
            if (!in_array($resource, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported VMware inventory resource');
            }
        }

        $started = microtime(true);
        $session = null;
        $apiFamily = null;
        $authStatus = 0;
        $collections = array(
            'datacenter' => array(),
            'cluster' => array(),
            'host' => array(),
            'vm' => array()
        );

        try {
            $auth = $this->authenticate($endpoint, $username, $password, $verifyTls);
            $session = $auth['session'];
            $apiFamily = $auth['api_family'];
            $authStatus = $auth['auth_http_status'];
            $headers = $this->session_headers($session);

            foreach ($resources as $resource) {
                $response = $this->inventory_request(
                    $endpoint,
                    $resource,
                    $apiFamily,
                    $headers,
                    $verifyTls
                );
                $label = $this->resource_label($resource);
                $rows = $this->decode_collection($response['body'], $apiFamily, $label);
                $collections[$resource] = $this->normalize_resource_collection($resource, $rows);
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
            'datacenter_count' => count($collections['datacenter']),
            'cluster_count' => count($collections['cluster']),
            'host_count' => count($collections['host']),
            'vm_count' => count($collections['vm']),
            'datacenters' => $collections['datacenter'],
            'clusters' => $collections['cluster'],
            'hosts' => $collections['host'],
            'vms' => $collections['vm'],
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

    private function inventory_request($endpoint, $resource, $apiFamily, array $headers, $verifyTls)
    {
        if ($apiFamily === 'modern') {
            $url = $endpoint . '/api/vcenter/' . $resource;
        } elseif ($apiFamily === 'legacy') {
            $url = $endpoint . '/rest/vcenter/' . $resource;
        } else {
            throw new RuntimeException('Unsupported vCenter API family for inventory request');
        }

        $response = $this->request('GET', $url, $headers, null, null, $verifyTls);
        if ((int)$response['status'] !== 200) {
            throw new RuntimeException($this->inventory_failure_message($resource, (int)$response['status']));
        }
        return $response;
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
            // Cleanup is best-effort and must not hide the primary inventory result.
        }
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

    private function decode_collection($body, $apiFamily, $label)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('vCenter ' . $label . ' response is not valid JSON');
        }

        if ($apiFamily === 'legacy') {
            if (!is_array($decoded) || !array_key_exists('value', $decoded) || !is_array($decoded['value'])) {
                throw new RuntimeException('Unexpected legacy vCenter ' . $label . ' response');
            }
            $decoded = $decoded['value'];
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected vCenter ' . $label . ' response');
        }
        if (count($decoded) > $this->maxCollectionItems) {
            throw new RuntimeException('vCenter ' . $label . ' collection exceeded the connector safety limit');
        }

        return array_values($decoded);
    }

    private function normalize_resource_collection($resource, array $rows)
    {
        if ($resource === 'datacenter') {
            return $this->normalize_datacenters($rows);
        }
        if ($resource === 'cluster') {
            return $this->normalize_clusters($rows);
        }
        if ($resource === 'host') {
            return $this->normalize_hosts($rows);
        }
        if ($resource === 'vm') {
            return $this->normalize_vms($rows);
        }
        throw new RuntimeException('Unsupported VMware inventory normalization resource');
    }

    private function normalize_datacenters(array $rows)
    {
        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('vCenter Datacenter inventory row has an invalid shape');
            }
            $result[] = array(
                'object_type' => 'datacenter',
                'external_id' => $this->require_identifier($row, 'datacenter', 'Datacenter'),
                'name' => $this->require_name($row, 'name', 'Datacenter')
            );
        }
        return $result;
    }

    private function normalize_clusters(array $rows)
    {
        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('vCenter Cluster inventory row has an invalid shape');
            }
            $result[] = array(
                'object_type' => 'cluster',
                'external_id' => $this->require_identifier($row, 'cluster', 'Cluster'),
                'name' => $this->require_name($row, 'name', 'Cluster'),
                'ha_enabled' => array_key_exists('ha_enabled', $row) ? (bool)$row['ha_enabled'] : null,
                'drs_enabled' => array_key_exists('drs_enabled', $row) ? (bool)$row['drs_enabled'] : null,
                'datacenter_external_id' => null
            );
        }
        return $result;
    }

    private function normalize_hosts(array $rows)
    {
        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('vCenter Host inventory row has an invalid shape');
            }
            $result[] = array(
                'object_type' => 'host',
                'external_id' => $this->require_identifier($row, 'host', 'Host'),
                'name' => $this->require_name($row, 'name', 'Host'),
                'connection_state' => $this->optional_token($row, 'connection_state', 'Host connection state'),
                'power_state' => $this->optional_token($row, 'power_state', 'Host power state'),
                'cluster_external_id' => null,
                'datacenter_external_id' => null
            );
        }
        return $result;
    }

    private function normalize_vms(array $rows)
    {
        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('vCenter VM inventory row has an invalid shape');
            }

            $memory = null;
            if (array_key_exists('memory_size_MiB', $row)) {
                $memory = $this->optional_nonnegative_int_value($row['memory_size_MiB'], 'VM memory size');
            } elseif (array_key_exists('memory_size_mib', $row)) {
                $memory = $this->optional_nonnegative_int_value($row['memory_size_mib'], 'VM memory size');
            }

            $result[] = array(
                'object_type' => 'vm',
                'external_id' => $this->require_identifier($row, 'vm', 'VM'),
                'name' => $this->require_name($row, 'name', 'VM'),
                'power_state' => $this->optional_token($row, 'power_state', 'VM power state'),
                'cpu_count' => array_key_exists('cpu_count', $row) ? $this->optional_nonnegative_int_value($row['cpu_count'], 'VM CPU count') : null,
                'memory_size_mib' => $memory,
                'host_external_id' => null,
                'cluster_external_id' => null,
                'datacenter_external_id' => null
            );
        }
        return $result;
    }

    private function require_identifier(array $row, $field, $label)
    {
        $value = isset($row[$field]) ? trim((string)$row[$field]) : '';
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' inventory row has an invalid external id');
        }
        return $value;
    }

    private function require_name(array $row, $field, $label)
    {
        $value = isset($row[$field]) ? trim((string)$row[$field]) : '';
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' inventory row has an invalid name');
        }
        return $value;
    }

    private function optional_token(array $row, $field, $label)
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        if (!is_scalar($row[$field])) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        $value = strtoupper(trim((string)$row[$field]));
        if ($value === '' || strlen($value) > 128 || !preg_match('/^[A-Z0-9_.:-]+$/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        return strtolower($value);
    }

    private function optional_nonnegative_int_value($value, $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
            $integer = (int)$value;
        } else {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        if ($integer < 0 || $integer > 2147483647) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        return $integer;
    }

    private function resource_label($resource)
    {
        if ($resource === 'datacenter') {
            return 'Datacenter';
        }
        if ($resource === 'cluster') {
            return 'Cluster';
        }
        if ($resource === 'host') {
            return 'Host';
        }
        if ($resource === 'vm') {
            return 'VM';
        }
        return 'Inventory';
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
            throw new RuntimeException('VMware inventory transport returned an invalid response');
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
            throw new RuntimeException('PHP cURL extension is required for the VMware vCenter inventory connector');
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
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-Inventory/1.0',
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

    private function inventory_failure_message($resource, $status)
    {
        $status = (int)$status;
        $label = $this->resource_label($resource);
        if ($status === 401) {
            return 'vCenter session was not accepted for ' . $label . ' inventory access (HTTP 401)';
        }
        if ($status === 403) {
            return 'vCenter account lacks permission to read ' . $label . ' inventory (HTTP 403)';
        }
        return 'vCenter ' . $label . ' inventory request failed (HTTP ' . $status . ')';
    }
}
