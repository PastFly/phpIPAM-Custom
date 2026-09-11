<?php

/**
 * phpIPAM Custom — Phase 5F.2.5 read-only VMware HOST-folder inventory.
 *
 * Reads the vCenter HOST Folder hierarchy and direct Folder -> Host/Cluster
 * membership through the modern vCenter REST inventory API. The collector is
 * intentionally transient: it does not persist inventory and never mutates
 * phpIPAM or vCenter inventory objects. Session create/delete is the only
 * vCenter state lifecycle involved.
 */
class IntegrationVmwareVcenterFolderInventory
{
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxFolders;
    private $maxRequests;
    private $requestCount = 0;

    public function __construct($connectTimeout = 8, $requestTimeout = 30, $maxResponseBytes = 2097152, $maxFolders = 256, $maxRequests = 640)
    {
        $this->connectTimeout = max(1, min(30, (int)$connectTimeout));
        $this->requestTimeout = max($this->connectTimeout, min(60, (int)$requestTimeout));
        $this->maxResponseBytes = max(16384, min(8388608, (int)$maxResponseBytes));
        $this->maxFolders = max(1, min(1024, (int)$maxFolders));
        $this->maxRequests = max(16, min(4096, (int)$maxRequests));
    }

    public function read_inventory($endpoint, $username, $password, $verifyTls = true)
    {
        if (!class_exists('IntegrationVmwareVcenter')) {
            throw new RuntimeException('Base VMware vCenter connector class is required');
        }

        $client = new IntegrationVmwareVcenter();
        $endpoint = $client->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $verifyTls = (bool)$verifyTls;
        if ($username === '') throw new InvalidArgumentException('vCenter username is required');
        if ($password === '') throw new InvalidArgumentException('vCenter password is required');

        $started = microtime(true);
        $session = null;
        $this->requestCount = 0;

        try {
            $login = $this->request(
                'POST',
                $endpoint . '/api/session',
                array('Accept: application/json'),
                $username,
                $password,
                $verifyTls
            );
            $status = (int)$login['status'];
            if ($status !== 200 && $status !== 201) {
                if ($status === 401) throw new RuntimeException('vCenter authentication failed (HTTP 401)');
                if ($status === 403) throw new RuntimeException('vCenter authentication was forbidden (HTTP 403)');
                throw new RuntimeException('vCenter authentication failed (HTTP ' . $status . ')');
            }
            $session = $this->extract_session_token($login['body']);
            $headers = array('Accept: application/json', 'vmware-api-session-id: ' . $session);

            $datacenters = $this->normalize_datacenters(
                $this->get_collection($endpoint, '/api/vcenter/datacenter', array(), $headers, $verifyTls, 'Datacenter', 1000)
            );
            $folders = $this->normalize_folders(
                $this->get_collection($endpoint, '/api/vcenter/folder', array('type' => 'HOST'), $headers, $verifyTls, 'HOST Folder', 1000)
            );
            if (count($folders) > $this->maxFolders) {
                throw new RuntimeException('HOST Folder count exceeded the configured safety limit');
            }

            foreach ($datacenters as $dcId => $dc) {
                $rows = $this->normalize_folders(
                    $this->get_collection(
                        $endpoint,
                        '/api/vcenter/folder',
                        array('type' => 'HOST', 'datacenters' => $dcId),
                        $headers,
                        $verifyTls,
                        'Datacenter HOST Folder',
                        1000
                    )
                );
                foreach ($rows as $folderId => $row) {
                    if (!isset($folders[$folderId])) continue;
                    $existing = $folders[$folderId]['datacenter_external_id'];
                    if ($existing !== null && $existing !== $dcId) {
                        throw new RuntimeException('HOST Folder matched multiple Datacenters');
                    }
                    $folders[$folderId]['datacenter_external_id'] = $dcId;
                }
            }

            $hostMatchCount = array();
            $clusterMatchCount = array();
            foreach (array_keys($folders) as $folderId) {
                $children = $this->normalize_folders(
                    $this->get_collection(
                        $endpoint,
                        '/api/vcenter/folder',
                        array('type' => 'HOST', 'parent_folders' => $folderId),
                        $headers,
                        $verifyTls,
                        'Child HOST Folder',
                        1000
                    )
                );
                foreach ($children as $childId => $child) {
                    if (!isset($folders[$childId])) continue;
                    $existing = $folders[$childId]['parent_folder_external_id'];
                    if ($existing !== null && $existing !== $folderId) {
                        throw new RuntimeException('HOST Folder matched multiple parent folders');
                    }
                    $folders[$childId]['parent_folder_external_id'] = $folderId;
                }

                $hosts = $this->normalize_object_ids(
                    $this->get_collection(
                        $endpoint,
                        '/api/vcenter/host',
                        array('folders' => $folderId),
                        $headers,
                        $verifyTls,
                        'Folder Host',
                        5000
                    ),
                    'host',
                    'Host'
                );
                $folders[$folderId]['host_external_ids'] = array_keys($hosts);
                foreach ($hosts as $hostId => $hostName) {
                    if (!isset($hostMatchCount[$hostId])) $hostMatchCount[$hostId] = 0;
                    $hostMatchCount[$hostId]++;
                }

                $clusters = $this->normalize_object_ids(
                    $this->get_collection(
                        $endpoint,
                        '/api/vcenter/cluster',
                        array('folders' => $folderId),
                        $headers,
                        $verifyTls,
                        'Folder Cluster',
                        1000
                    ),
                    'cluster',
                    'Cluster'
                );
                $folders[$folderId]['cluster_external_ids'] = array_keys($clusters);
                foreach ($clusters as $clusterId => $clusterName) {
                    if (!isset($clusterMatchCount[$clusterId])) $clusterMatchCount[$clusterId] = 0;
                    $clusterMatchCount[$clusterId]++;
                }
            }

            $memo = array();
            foreach (array_keys($folders) as $folderId) {
                $stack = array();
                $folders[$folderId]['depth'] = $this->folder_depth($folderId, $folders, $memo, $stack);
            }

            $hostFolderMap = array();
            $clusterFolderMap = array();
            foreach ($folders as $folderId => $folder) {
                foreach ($folder['host_external_ids'] as $hostId) {
                    if (isset($hostMatchCount[$hostId]) && $hostMatchCount[$hostId] === 1) {
                        $hostFolderMap[$hostId] = $folderId;
                    }
                }
                foreach ($folder['cluster_external_ids'] as $clusterId) {
                    if (isset($clusterMatchCount[$clusterId]) && $clusterMatchCount[$clusterId] === 1) {
                        $clusterFolderMap[$clusterId] = $folderId;
                    }
                }
            }

            uasort($folders, function ($left, $right) use ($datacenters) {
                $leftDc = isset($left['datacenter_external_id'], $datacenters[$left['datacenter_external_id']]) ? $datacenters[$left['datacenter_external_id']]['name'] : '';
                $rightDc = isset($right['datacenter_external_id'], $datacenters[$right['datacenter_external_id']]) ? $datacenters[$right['datacenter_external_id']]['name'] : '';
                $cmp = strcasecmp($leftDc, $rightDc);
                if ($cmp !== 0) return $cmp;
                if ((int)$left['depth'] !== (int)$right['depth']) return (int)$left['depth'] - (int)$right['depth'];
                $cmp = strcasecmp($left['name'], $right['name']);
                return $cmp !== 0 ? $cmp : strcmp($left['external_id'], $right['external_id']);
            });

            $rootFolders = array();
            foreach ($folders as $folderId => $folder) {
                if ($folder['datacenter_external_id'] !== null && $folder['parent_folder_external_id'] === null) {
                    $rootFolders[$folder['datacenter_external_id']] = $folderId;
                }
            }

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'verify_tls' => $verifyTls,
                'transport' => 'direct_no_proxy',
                'datacenters' => array_values($datacenters),
                'folders' => array_values($folders),
                'host_folder_map' => $hostFolderMap,
                'cluster_folder_map' => $clusterFolderMap,
                'root_folder_by_datacenter' => $rootFolders,
                'folder_count' => count($folders),
                'host_multi_folder_matches' => $this->count_multi_matches($hostMatchCount),
                'cluster_multi_folder_matches' => $this->count_multi_matches($clusterMatchCount),
                'request_count' => $this->requestCount,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'persistent_inventory_written' => false,
                'phpipam_objects_modified' => false,
                'vcenter_objects_modified' => false
            );
        } finally {
            if (is_string($session) && $session !== '') {
                try {
                    $this->request(
                        'DELETE',
                        $endpoint . '/api/session',
                        array('Accept: application/json', 'vmware-api-session-id: ' . $session),
                        null,
                        null,
                        $verifyTls,
                        true
                    );
                } catch (Exception $ignored) {
                    // Session cleanup is best-effort and must not hide the primary result.
                }
            }
        }
    }

    private function get_collection($endpoint, $path, array $params, array $headers, $verifyTls, $label, $maxItems)
    {
        $url = rtrim($endpoint, '/') . $path;
        if (count($params) > 0) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $response = $this->request('GET', $url, $headers, null, null, $verifyTls);
        $status = (int)$response['status'];
        if ($status === 401) throw new RuntimeException('vCenter session was not accepted (HTTP 401)');
        if ($status === 403) throw new RuntimeException('vCenter account lacks permission to read Folder inventory (HTTP 403)');
        if ($status !== 200) throw new RuntimeException($label . ' request failed (HTTP ' . $status . ')');

        $decoded = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException($label . ' response is not a JSON collection');
        }
        if (count($decoded) > (int)$maxItems) throw new RuntimeException($label . ' collection exceeded the safety limit');
        return array_values($decoded);
    }

    private function normalize_datacenters(array $rows)
    {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('Datacenter row has invalid shape');
            $id = $this->clean_text(isset($row['datacenter']) ? $row['datacenter'] : null, 'Datacenter id', 1024);
            $name = $this->clean_text(isset($row['name']) ? $row['name'] : null, 'Datacenter name');
            if (isset($out[$id])) throw new RuntimeException('Duplicate Datacenter id returned by vCenter');
            $out[$id] = array('external_id' => $id, 'name' => $name);
        }
        return $out;
    }

    private function normalize_folders(array $rows)
    {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('Folder row has invalid shape');
            $id = $this->clean_text(isset($row['folder']) ? $row['folder'] : null, 'Folder id', 1024);
            $name = $this->clean_text(isset($row['name']) ? $row['name'] : null, 'Folder name');
            $type = strtoupper($this->clean_text(isset($row['type']) ? $row['type'] : null, 'Folder type', 64));
            if ($type !== 'HOST') continue;
            if (isset($out[$id])) throw new RuntimeException('Duplicate HOST Folder id returned by vCenter');
            $out[$id] = array(
                'external_id' => $id,
                'name' => $name,
                'type' => $type,
                'datacenter_external_id' => null,
                'parent_folder_external_id' => null,
                'depth' => 0,
                'host_external_ids' => array(),
                'cluster_external_ids' => array()
            );
        }
        return $out;
    }

    private function normalize_object_ids(array $rows, $idField, $label)
    {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException($label . ' row has invalid shape');
            $id = $this->clean_text(isset($row[$idField]) ? $row[$idField] : null, $label . ' id', 1024);
            $name = $this->clean_text(isset($row['name']) ? $row['name'] : null, $label . ' name');
            $out[$id] = $name;
        }
        return $out;
    }

    private function folder_depth($folderId, array $folders, array &$memo, array &$stack)
    {
        if (isset($memo[$folderId])) return $memo[$folderId];
        if (isset($stack[$folderId])) throw new RuntimeException('HOST Folder parent cycle detected');
        $stack[$folderId] = true;
        $parent = isset($folders[$folderId]['parent_folder_external_id']) ? $folders[$folderId]['parent_folder_external_id'] : null;
        $depth = ($parent !== null && isset($folders[$parent])) ? $this->folder_depth($parent, $folders, $memo, $stack) + 1 : 0;
        unset($stack[$folderId]);
        $memo[$folderId] = $depth;
        return $depth;
    }

    private function count_multi_matches(array $counts)
    {
        $count = 0;
        foreach ($counts as $matches) if ((int)$matches > 1) $count++;
        return $count;
    }

    private function clean_text($value, $label, $max = 2048)
    {
        if (!is_scalar($value)) throw new RuntimeException($label . ' is not scalar');
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > (int)$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException($label . ' is invalid');
        }
        return $value;
    }

    private function extract_session_token($body)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('vCenter session response is not valid JSON');
        if (is_string($decoded)) $token = $decoded;
        elseif (is_array($decoded) && isset($decoded['value']) && is_string($decoded['value'])) $token = $decoded['value'];
        else throw new RuntimeException('vCenter session response did not contain a token');
        $token = trim($token);
        if ($token === '' || strlen($token) > 4096) throw new RuntimeException('vCenter session token is invalid');
        return $token;
    }

    private function request($method, $url, array $headers, $username, $password, $verifyTls, $cleanup = false)
    {
        if (!$cleanup) {
            $this->requestCount++;
            if ($this->requestCount > $this->maxRequests) throw new RuntimeException('HOST Folder request safety limit exceeded');
        }

        $method = strtoupper(trim((string)$method));
        if (!in_array($method, array('GET', 'POST', 'DELETE'), true)) throw new InvalidArgumentException('Unsupported HOST Folder HTTP method');
        if (!preg_match('#^https://#i', (string)$url)) throw new InvalidArgumentException('HOST Folder transport requires HTTPS');
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for HOST Folder inventory');

        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Unable to initialize HOST Folder cURL transport');
        $body = '';
        $tooLarge = false;
        $maxBytes = $this->maxResponseBytes;
        $options = array(
            CURLOPT_URL => (string)$url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-FolderInventory/1.0',
            // Production diagnostics proved inherited proxy variables alter the
            // internal vCenter path. Folder inventory is deliberately direct.
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }
        );
        if (defined('CURLOPT_NOPROXY')) $options[CURLOPT_NOPROXY] = '*';
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        if ($username !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = (string)$username . ':' . (string)$password;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        curl_close($ch);

        if ($tooLarge) throw new RuntimeException('HOST Folder response exceeded the safety limit');
        if ($ok === false) throw new RuntimeException('HOST Folder network error: ' . ($error !== '' ? $error : 'request failed'));
        return array('status' => $status, 'body' => $body);
    }
}
