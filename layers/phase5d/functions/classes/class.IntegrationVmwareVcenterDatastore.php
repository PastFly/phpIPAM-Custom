<?php

/**
 * phpIPAM Custom — Phase 5D read-only VMware datastore inventory/detail client.
 *
 * Datastore IDs are authoritative identities. Datacenter membership is learned
 * only through the supported modern vCenter datastores?datacenters=<id> filter.
 * The client never mutates vCenter/phpIPAM and never persists inventory.
 */
class IntegrationVmwareVcenterDatastore
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxCollectionItems;
    private $maxDatacenterFilters;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 2097152, $maxCollectionItems = 2500, $maxDatacenterFilters = 250)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('VMware datastore transport must be callable');
        }

        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(4096, (int)$maxResponseBytes);
        $this->maxCollectionItems = max(1, min(2500, (int)$maxCollectionItems));
        $this->maxDatacenterFilters = max(1, min(1000, (int)$maxDatacenterFilters));
    }

    public function read_inventory($endpoint, $username, $password, array $datacenterIds, $verifyTls = true)
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

        $datacenterIds = $this->normalize_identifier_list($datacenterIds, 'Datacenter');
        if (count($datacenterIds) > $this->maxDatacenterFilters) {
            throw new RuntimeException('Datacenter filter set exceeded the datastore connector safety limit');
        }

        $started = microtime(true);
        $session = null;

        try {
            $auth = $this->authenticate_modern($endpoint, $username, $password, $verifyTls);
            $session = $auth['session'];
            $headers = $this->session_headers($session);

            $rows = $this->get_collection($endpoint . '/api/vcenter/datastore', $headers, $verifyTls);
            $datastores = $this->normalize_datastores($rows);
            $map = $this->index_by_id($datastores);

            foreach ($datacenterIds as $datacenterId) {
                $url = $endpoint . '/api/vcenter/datastore?datacenters=' . rawurlencode($datacenterId);
                $filtered = $this->normalize_datastores($this->get_collection($url, $headers, $verifyTls));
                foreach ($filtered as $row) {
                    $id = $row['external_id'];
                    if (!array_key_exists($id, $map)) {
                        throw new RuntimeException('vCenter filtered datastore inventory returned an unknown datastore external id');
                    }
                    if ($map[$id]['datacenter_external_id'] === null) {
                        $map[$id]['datacenter_external_id'] = $datacenterId;
                    } elseif ($map[$id]['datacenter_external_id'] !== $datacenterId) {
                        throw new RuntimeException('vCenter datastore inventory returned conflicting Datacenter relationships for datastore ' . $id);
                    }
                }
            }

            $datastores = array_values($map);

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'endpoint' => $endpoint,
                'api_family' => 'modern',
                'auth_http_status' => $auth['auth_http_status'],
                'verify_tls' => $verifyTls,
                'datastore_count' => count($datastores),
                'unresolved_datacenter_count' => $this->count_null_parent($datastores, 'datacenter_external_id'),
                'datastores' => $datastores,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'persistent_inventory_written' => false,
                'phpipam_objects_modified' => false,
                'vcenter_objects_modified' => false
            );
        } finally {
            if (is_string($session) && $session !== '') {
                $this->cleanup_session($endpoint, $session, $verifyTls);
            }
        }
    }

    public function read_detail($endpoint, $username, $password, $datastoreId, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $datastoreId = $this->validate_identifier($datastoreId, 'Datastore');
        $verifyTls = (bool)$verifyTls;

        if ($username === '') {
            throw new InvalidArgumentException('vCenter username is required');
        }
        if ($password === '') {
            throw new InvalidArgumentException('vCenter password is required');
        }

        $started = microtime(true);
        $session = null;

        try {
            $auth = $this->authenticate_modern($endpoint, $username, $password, $verifyTls);
            $session = $auth['session'];
            $headers = $this->session_headers($session);
            $url = $endpoint . '/api/vcenter/datastore/' . rawurlencode($datastoreId);
            $response = $this->request('GET', $url, $headers, null, null, $verifyTls);
            $status = (int)$response['status'];
            if ($status !== 200) {
                if ($status === 404) {
                    throw new RuntimeException('vCenter datastore was not found (HTTP 404)');
                }
                if ($status === 403) {
                    throw new RuntimeException('vCenter account lacks permission to read datastore detail (HTTP 403)');
                }
                throw new RuntimeException('vCenter datastore detail request failed (HTTP ' . $status . ')');
            }

            $decoded = json_decode((string)$response['body'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new RuntimeException('vCenter datastore detail response is not valid JSON object');
            }

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'endpoint' => $endpoint,
                'api_family' => 'modern',
                'verify_tls' => $verifyTls,
                'datastore' => $this->normalize_detail($datastoreId, $decoded),
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'persistent_inventory_written' => false,
                'phpipam_objects_modified' => false,
                'vcenter_objects_modified' => false
            );
        } finally {
            if (is_string($session) && $session !== '') {
                $this->cleanup_session($endpoint, $session, $verifyTls);
            }
        }
    }

    private function normalize_endpoint($endpoint)
    {
        if (!class_exists('IntegrationVmwareVcenter')) {
            throw new RuntimeException('Base VMware vCenter connector class is required for endpoint validation');
        }
        $client = new IntegrationVmwareVcenter();
        return $client->normalize_endpoint($endpoint);
    }

    private function authenticate_modern($endpoint, $username, $password, $verifyTls)
    {
        $response = $this->request(
            'POST',
            $endpoint . '/api/session',
            array('Accept: application/json'),
            $username,
            $password,
            $verifyTls
        );
        $status = (int)$response['status'];

        if ($status === 404 || $status === 405) {
            throw new RuntimeException('Phase 5D datastore inventory requires the modern vCenter API');
        }
        if ($status !== 200 && $status !== 201) {
            if ($status === 401) {
                throw new RuntimeException('vCenter authentication failed (HTTP 401)');
            }
            if ($status === 403) {
                throw new RuntimeException('vCenter authentication was forbidden (HTTP 403)');
            }
            throw new RuntimeException('vCenter authentication failed (HTTP ' . $status . ')');
        }

        return array(
            'session' => $this->extract_session_token($response['body']),
            'auth_http_status' => $status
        );
    }

    private function get_collection($url, array $headers, $verifyTls)
    {
        $response = $this->request('GET', $url, $headers, null, null, $verifyTls);
        $status = (int)$response['status'];
        if ($status !== 200) {
            if ($status === 403) {
                throw new RuntimeException('vCenter account lacks permission to read datastore inventory (HTTP 403)');
            }
            throw new RuntimeException('vCenter datastore inventory request failed (HTTP ' . $status . ')');
        }

        $decoded = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('vCenter datastore inventory response is not valid JSON collection');
        }
        if (count($decoded) > $this->maxCollectionItems) {
            throw new RuntimeException('vCenter datastore collection exceeded the connector safety limit');
        }
        return array_values($decoded);
    }

    private function normalize_datastores(array $rows)
    {
        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('vCenter datastore inventory row has an invalid shape');
            }

            $result[] = array(
                'object_type' => 'datastore',
                'external_id' => $this->require_field_identifier($row, 'datastore', 'Datastore'),
                'name' => $this->require_name($row, 'name', 'Datastore'),
                'type' => $this->optional_token($row, 'type', 'Datastore type'),
                'free_space_bytes' => array_key_exists('free_space', $row) ? $this->optional_nonnegative_int($row['free_space'], 'Datastore free space') : null,
                'capacity_bytes' => array_key_exists('capacity', $row) ? $this->optional_nonnegative_int($row['capacity'], 'Datastore capacity') : null,
                'datacenter_external_id' => null
            );
        }
        return $result;
    }

    private function normalize_detail($datastoreId, array $row)
    {
        return array(
            'object_type' => 'datastore',
            'external_id' => $datastoreId,
            'name' => $this->require_name($row, 'name', 'Datastore'),
            'type' => $this->optional_token($row, 'type', 'Datastore type'),
            'accessible' => $this->require_bool($row, 'accessible', 'Datastore accessible'),
            'free_space_bytes' => array_key_exists('free_space', $row) ? $this->optional_nonnegative_int($row['free_space'], 'Datastore free space') : null,
            'multiple_host_access' => $this->require_bool($row, 'multiple_host_access', 'Datastore multiple host access'),
            'thin_provisioning_supported' => $this->require_bool($row, 'thin_provisioning_supported', 'Datastore thin provisioning support')
        );
    }

    private function normalize_identifier_list(array $values, $label)
    {
        $seen = array();
        $result = array();
        foreach ($values as $value) {
            $id = $this->validate_identifier($value, $label);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $id;
            }
        }
        return $result;
    }

    private function validate_identifier($value, $label)
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('vCenter ' . $label . ' identifier is invalid');
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' identifier is invalid');
        }
        return $value;
    }

    private function require_field_identifier(array $row, $field, $label)
    {
        if (!array_key_exists($field, $row)) {
            throw new RuntimeException('vCenter ' . $label . ' inventory row is missing its external id');
        }
        return $this->validate_identifier($row[$field], $label);
    }

    private function require_name(array $row, $field, $label)
    {
        $value = isset($row[$field]) ? trim((string)$row[$field]) : '';
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid name');
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

    private function optional_nonnegative_int($value, $label)
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
        if ($integer < 0) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        return $integer;
    }

    private function require_bool(array $row, $field, $label)
    {
        if (!array_key_exists($field, $row) || !is_bool($row[$field])) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        return $row[$field];
    }

    private function index_by_id(array $rows)
    {
        $map = array();
        foreach ($rows as $row) {
            $id = $row['external_id'];
            if (isset($map[$id])) {
                throw new RuntimeException('vCenter datastore collection contains a duplicate external id');
            }
            $map[$id] = $row;
        }
        return $map;
    }

    private function count_null_parent(array $rows, $field)
    {
        $count = 0;
        foreach ($rows as $row) {
            if (!array_key_exists($field, $row) || $row[$field] === null) {
                $count++;
            }
        }
        return $count;
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
            throw new RuntimeException('VMware datastore transport returned an invalid response');
        }
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $body = isset($response['body']) ? (string)$response['body'] : '';
        $error = isset($response['error']) ? trim((string)$response['error']) : '';
        $duration = isset($response['duration_ms']) ? max(0, (int)$response['duration_ms']) : 0;
        if ($status === 0 && $error !== '') {
            throw new RuntimeException('vCenter network error: ' . $error);
        }
        return array('status' => $status, 'body' => $body, 'error' => $error, 'duration_ms' => $duration);
    }

    private function curl_request($method, $url, $headers, $username, $password, $verifyTls)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for the VMware vCenter datastore connector');
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
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-Datastore/1.0',
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
        return array('status' => $status, 'body' => $body, 'error' => $error, 'duration_ms' => $duration);
    }

    private function extract_session_token($body)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_string($decoded)) {
            throw new RuntimeException('vCenter modern session response did not contain a session token');
        }
        $token = trim($decoded);
        if ($token === '' || strlen($token) > 4096) {
            throw new RuntimeException('vCenter authentication returned an invalid session token');
        }
        return $token;
    }

    private function session_headers($session)
    {
        return array('Accept: application/json', 'vmware-api-session-id: ' . $session);
    }

    private function cleanup_session($endpoint, $session, $verifyTls)
    {
        try {
            $this->request('DELETE', $endpoint . '/api/session', $this->session_headers($session), null, null, $verifyTls);
        } catch (Exception $ignored) {
            // Best-effort cleanup must not hide the primary datastore result/error.
        }
    }
}
