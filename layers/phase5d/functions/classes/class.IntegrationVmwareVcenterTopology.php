<?php

/**
 * phpIPAM Custom — Phase 5D authoritative VMware topology collector.
 *
 * Uses modern vCenter list filters to establish Datacenter -> Cluster -> Host
 * -> VM relationships by stable external IDs. It performs read-only inventory
 * GETs plus session create/delete and never persists inventory or mutates
 * vCenter/phpIPAM objects.
 */
class IntegrationVmwareVcenterTopology
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxCollectionItems;
    private $maxRelationRequests;
    private $relationRequests = 0;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 2097152, $maxCollectionItems = 10000, $maxRelationRequests = 500)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('VMware topology transport must be callable');
        }

        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(4096, (int)$maxResponseBytes);
        $this->maxCollectionItems = max(1, min(20000, (int)$maxCollectionItems));
        $this->maxRelationRequests = max(8, min(5000, (int)$maxRelationRequests));
    }

    public function read_topology($endpoint, $username, $password, $verifyTls = true)
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

        $started = microtime(true);
        $session = null;
        $this->relationRequests = 0;

        try {
            $login = $this->request(
                'POST',
                $endpoint . '/api/session',
                array('Accept: application/json'),
                $username,
                $password,
                $verifyTls
            );

            $authStatus = (int)$login['status'];
            if ($authStatus === 404 || $authStatus === 405) {
                throw new RuntimeException('Phase 5D topology filters require the modern vCenter API');
            }
            if ($authStatus !== 200 && $authStatus !== 201) {
                throw new RuntimeException($this->authentication_failure_message($authStatus));
            }

            $session = $this->extract_session_token($login['body']);
            $headers = $this->session_headers($session);

            $datacenters = $this->normalize_datacenters($this->get_collection($endpoint, 'datacenter', null, null, $headers, $verifyTls));
            $clusters = $this->normalize_clusters($this->get_collection($endpoint, 'cluster', null, null, $headers, $verifyTls));
            $hosts = $this->normalize_hosts($this->get_collection($endpoint, 'host', null, null, $headers, $verifyTls));
            $vms = $this->normalize_vms($this->get_collection($endpoint, 'vm', null, null, $headers, $verifyTls));

            $estimated = 4 + (count($datacenters) * 3) + (count($clusters) * 2) + count($hosts);
            if ($estimated > $this->maxRelationRequests) {
                throw new RuntimeException('vCenter topology relation crawl would exceed the connector request safety limit');
            }

            $datacenterMap = $this->index_by_id($datacenters);
            $clusterMap = $this->index_by_id($clusters);
            $hostMap = $this->index_by_id($hosts);
            $vmMap = $this->index_by_id($vms);

            foreach ($datacenterMap as $datacenterId => $datacenter) {
                $filteredClusters = $this->normalize_clusters($this->get_collection($endpoint, 'cluster', 'datacenters', $datacenterId, $headers, $verifyTls));
                foreach ($filteredClusters as $row) {
                    $id = $row['external_id'];
                    $this->require_known_id($clusterMap, $id, 'Cluster');
                    $this->assign_parent($clusterMap[$id], 'datacenter_external_id', $datacenterId, 'Cluster', $id);
                }

                $filteredHosts = $this->normalize_hosts($this->get_collection($endpoint, 'host', 'datacenters', $datacenterId, $headers, $verifyTls));
                foreach ($filteredHosts as $row) {
                    $id = $row['external_id'];
                    $this->require_known_id($hostMap, $id, 'Host');
                    $this->assign_parent($hostMap[$id], 'datacenter_external_id', $datacenterId, 'Host', $id);
                }

                $filteredVms = $this->normalize_vms($this->get_collection($endpoint, 'vm', 'datacenters', $datacenterId, $headers, $verifyTls));
                foreach ($filteredVms as $row) {
                    $id = $row['external_id'];
                    $this->require_known_id($vmMap, $id, 'VM');
                    $this->assign_parent($vmMap[$id], 'datacenter_external_id', $datacenterId, 'VM', $id);
                }
            }

            foreach ($clusterMap as $clusterId => $cluster) {
                $filteredHosts = $this->normalize_hosts($this->get_collection($endpoint, 'host', 'clusters', $clusterId, $headers, $verifyTls));
                foreach ($filteredHosts as $row) {
                    $id = $row['external_id'];
                    $this->require_known_id($hostMap, $id, 'Host');
                    $this->assign_parent($hostMap[$id], 'cluster_external_id', $clusterId, 'Host', $id);
                    if ($clusterMap[$clusterId]['datacenter_external_id'] !== null) {
                        $this->assign_parent($hostMap[$id], 'datacenter_external_id', $clusterMap[$clusterId]['datacenter_external_id'], 'Host', $id);
                    }
                }

                $filteredVms = $this->normalize_vms($this->get_collection($endpoint, 'vm', 'clusters', $clusterId, $headers, $verifyTls));
                foreach ($filteredVms as $row) {
                    $id = $row['external_id'];
                    $this->require_known_id($vmMap, $id, 'VM');
                    $this->assign_parent($vmMap[$id], 'cluster_external_id', $clusterId, 'VM', $id);
                    if ($clusterMap[$clusterId]['datacenter_external_id'] !== null) {
                        $this->assign_parent($vmMap[$id], 'datacenter_external_id', $clusterMap[$clusterId]['datacenter_external_id'], 'VM', $id);
                    }
                }
            }

            foreach ($hostMap as $hostId => $host) {
                $filteredVms = $this->normalize_vms($this->get_collection($endpoint, 'vm', 'hosts', $hostId, $headers, $verifyTls));
                foreach ($filteredVms as $row) {
                    $id = $row['external_id'];
                    $this->require_known_id($vmMap, $id, 'VM');
                    $this->assign_parent($vmMap[$id], 'host_external_id', $hostId, 'VM', $id);
                    if ($hostMap[$hostId]['cluster_external_id'] !== null) {
                        $this->assign_parent($vmMap[$id], 'cluster_external_id', $hostMap[$hostId]['cluster_external_id'], 'VM', $id);
                    }
                    if ($hostMap[$hostId]['datacenter_external_id'] !== null) {
                        $this->assign_parent($vmMap[$id], 'datacenter_external_id', $hostMap[$hostId]['datacenter_external_id'], 'VM', $id);
                    }
                }
            }

            $clusters = array_values($clusterMap);
            $hosts = array_values($hostMap);
            $vms = array_values($vmMap);

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'endpoint' => $endpoint,
                'api_family' => 'modern',
                'auth_http_status' => $authStatus,
                'verify_tls' => $verifyTls,
                'datacenter_count' => count($datacenters),
                'cluster_count' => count($clusters),
                'host_count' => count($hosts),
                'vm_count' => count($vms),
                'relation_request_count' => $this->relationRequests,
                'unresolved_cluster_parent_count' => $this->count_null_parent($clusters, 'datacenter_external_id'),
                'unresolved_host_cluster_count' => $this->count_null_parent($hosts, 'cluster_external_id'),
                'unresolved_host_datacenter_count' => $this->count_null_parent($hosts, 'datacenter_external_id'),
                'unresolved_vm_host_count' => $this->count_null_parent($vms, 'host_external_id'),
                'unresolved_vm_cluster_count' => $this->count_null_parent($vms, 'cluster_external_id'),
                'unresolved_vm_datacenter_count' => $this->count_null_parent($vms, 'datacenter_external_id'),
                'datacenters' => $datacenters,
                'clusters' => $clusters,
                'hosts' => $hosts,
                'vms' => $vms,
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

    private function get_collection($endpoint, $resource, $filterName, $filterValue, array $headers, $verifyTls)
    {
        $allowedResources = array('datacenter', 'cluster', 'host', 'vm');
        if (!in_array($resource, $allowedResources, true)) {
            throw new RuntimeException('Unsupported VMware topology resource');
        }

        $url = $endpoint . '/api/vcenter/' . $resource;
        if ($filterName !== null) {
            $allowedFilters = array('datacenters', 'clusters', 'hosts');
            if (!in_array($filterName, $allowedFilters, true)) {
                throw new RuntimeException('Unsupported VMware topology filter');
            }
            $filterValue = $this->validate_identifier_value($filterValue, 'topology filter identifier');
            $url .= '?' . $filterName . '=' . rawurlencode($filterValue);
        }

        $this->relationRequests++;
        if ($this->relationRequests > $this->maxRelationRequests) {
            throw new RuntimeException('vCenter topology relation crawl exceeded the connector request safety limit');
        }

        $response = $this->request('GET', $url, $headers, null, null, $verifyTls);
        $status = (int)$response['status'];
        if ($status !== 200) {
            if ($status === 401) {
                throw new RuntimeException('vCenter session was not accepted for topology inventory access (HTTP 401)');
            }
            if ($status === 403) {
                throw new RuntimeException('vCenter account lacks permission to read topology inventory (HTTP 403)');
            }
            throw new RuntimeException('vCenter topology inventory request failed (HTTP ' . $status . ')');
        }

        $decoded = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('vCenter topology response is not valid JSON collection');
        }
        if (count($decoded) > $this->maxCollectionItems) {
            throw new RuntimeException('vCenter topology collection exceeded the connector safety limit');
        }

        return array_values($decoded);
    }

    private function normalize_datacenters(array $rows)
    {
        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('vCenter Datacenter topology row has an invalid shape');
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
                throw new RuntimeException('vCenter Cluster topology row has an invalid shape');
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
                throw new RuntimeException('vCenter Host topology row has an invalid shape');
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
                throw new RuntimeException('vCenter VM topology row has an invalid shape');
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

    private function index_by_id(array $rows)
    {
        $map = array();
        foreach ($rows as $row) {
            $id = $row['external_id'];
            if (isset($map[$id])) {
                throw new RuntimeException('vCenter topology collection contains a duplicate external id');
            }
            $map[$id] = $row;
        }
        return $map;
    }

    private function require_known_id(array $map, $id, $label)
    {
        if (!array_key_exists($id, $map)) {
            throw new RuntimeException('vCenter filtered topology returned an unknown ' . $label . ' external id');
        }
    }

    private function assign_parent(array &$row, $field, $parentId, $label, $objectId)
    {
        $parentId = $this->validate_identifier_value($parentId, $label . ' parent identifier');
        if ($row[$field] === null) {
            $row[$field] = $parentId;
            return;
        }
        if ($row[$field] !== $parentId) {
            throw new RuntimeException('vCenter topology returned conflicting parent relationships for ' . $label . ' ' . $objectId);
        }
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

    private function require_identifier(array $row, $field, $label)
    {
        if (!array_key_exists($field, $row)) {
            throw new RuntimeException('vCenter ' . $label . ' topology row is missing its external id');
        }
        return $this->validate_identifier_value($row[$field], $label . ' external id');
    }

    private function validate_identifier_value($value, $label)
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('vCenter ' . $label . ' is invalid');
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' is invalid');
        }
        return $value;
    }

    private function require_name(array $row, $field, $label)
    {
        $value = isset($row[$field]) ? trim((string)$row[$field]) : '';
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' topology row has an invalid name');
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
            throw new RuntimeException('VMware topology transport returned an invalid response');
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
            throw new RuntimeException('PHP cURL extension is required for the VMware vCenter topology connector');
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
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-Topology/1.0',
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
            // Best-effort cleanup must not hide the primary topology result/error.
        }
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
}
