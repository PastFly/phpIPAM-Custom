<?php

/**
 * phpIPAM Custom — Phase 5F.2.5 VMware Folder compatibility preview.
 *
 * READ ONLY. Uses the saved VMware connector and modern vCenter REST inventory
 * filters to inspect HOST-type Folder hierarchy and Folder -> Host/Cluster
 * membership. It does not persist inventory or mutate phpIPAM/vCenter objects.
 *
 * Usage:
 *   php tools/live-vmware-phase5f25-folder-preview.php /var/www/html [connector-id]
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5F.2.5 folder preview is CLI-only.\n");
    exit(2);
}

$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
if (isset($argv[2])) {
    if (!preg_match('/^[1-9][0-9]*$/', (string)$argv[2])) {
        fwrite(STDERR, "ERROR: connector-id must be a positive integer.\n");
        exit(2);
    }
    $connectorId = (int)$argv[2];
}
if (isset($argv[3])) {
    fwrite(STDERR, "ERROR: too many arguments.\n");
    exit(2);
}
if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) {
    fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n");
    exit(2);
}

function phase5f25_zero_secret(&$value)
{
    if (!is_string($value) || $value === '') return;
    if (function_exists('sodium_memzero')) @sodium_memzero($value);
    else $value = str_repeat("\0", strlen($value));
}

function phase5f25_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) {
        if (!empty($config['enabled'])) $enabled[] = $config;
    }
    if (count($enabled) === 0) throw new RuntimeException('No enabled VMware connector');
    if (count($enabled) > 1) throw new RuntimeException('More than one enabled VMware connector; pass connector-id explicitly');
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
        throw new RuntimeException('Enabled VMware connector has invalid id');
    }
    return (int)$id;
}

function phase5f25_category($e)
{
    $m = strtolower((string)$e->getMessage());
    if (strpos($m, 'certificate') !== false || strpos($m, 'ssl') !== false || strpos($m, 'tls') !== false) return 'tls';
    if (strpos($m, 'timeout') !== false || strpos($m, 'timed out') !== false) return 'timeout';
    if (strpos($m, 'authentication') !== false || strpos($m, 'http 401') !== false) return 'authentication';
    if (strpos($m, 'forbidden') !== false || strpos($m, 'permission') !== false || strpos($m, 'http 403') !== false) return 'authorization';
    if (strpos($m, 'network') !== false || strpos($m, 'curl') !== false || strpos($m, 'resolve') !== false || strpos($m, 'connect') !== false) return 'network';
    if (strpos($m, 'json') !== false || strpos($m, 'response') !== false || strpos($m, 'safety limit') !== false || strpos($m, 'cycle') !== false) return 'invalid_response';
    if ($e instanceof InvalidArgumentException || strpos($m, 'connector') !== false || strpos($m, 'credential') !== false) return 'configuration';
    return 'unknown';
}

function phase5f25_clean_text($value, $label, $max = 2048)
{
    if (!is_scalar($value)) throw new RuntimeException($label . ' is not scalar');
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new RuntimeException($label . ' is invalid');
    }
    return $value;
}

function phase5f25_extract_token($body)
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

function phase5f25_decode_collection($body, $label, $maxItems)
{
    $decoded = json_decode((string)$body, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new RuntimeException($label . ' response is not a JSON collection');
    }
    if (count($decoded) > $maxItems) throw new RuntimeException($label . ' collection exceeded the safety limit');
    return array_values($decoded);
}

function phase5f25_query_url($base, $path, array $params = array())
{
    $url = rtrim($base, '/') . $path;
    if (count($params) > 0) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return $url;
}

function phase5f25_request($method, $url, array $headers, $username, $password, $verifyTls, &$requestCount, $maxRequests = 640, $maxBytes = 2097152)
{
    $requestCount++;
    if ($requestCount > $maxRequests) throw new RuntimeException('Phase 5F.2.5 request safety limit exceeded');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');

    $method = strtoupper(trim((string)$method));
    if (!in_array($method, array('GET', 'POST', 'DELETE'), true)) throw new InvalidArgumentException('Unsupported preview HTTP method');
    if (!preg_match('#^https://#i', (string)$url)) throw new InvalidArgumentException('Folder preview requires HTTPS');

    $ch = curl_init();
    if ($ch === false) throw new RuntimeException('Unable to initialize cURL');
    $body = '';
    $tooLarge = false;
    $options = array(
        CURLOPT_URL => (string)$url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_HTTPHEADER => array_values($headers),
        CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-FolderPreview/1.0',
        // Production diagnostics proved inherited HTTP(S)_PROXY can alter the
        // internal vCenter path. This preview is deliberately direct-only.
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

    if ($tooLarge) throw new RuntimeException('vCenter response exceeded the preview safety limit');
    if ($ok === false) throw new RuntimeException('vCenter network error: ' . ($error !== '' ? $error : 'request failed'));
    return array('status' => $status, 'body' => $body);
}

function phase5f25_get_collection($endpoint, $path, array $params, array $headers, $verifyTls, &$requestCount, $label, $maxItems)
{
    $response = phase5f25_request('GET', phase5f25_query_url($endpoint, $path, $params), $headers, null, null, $verifyTls, $requestCount);
    $status = (int)$response['status'];
    if ($status === 401) throw new RuntimeException('vCenter session was not accepted (HTTP 401)');
    if ($status === 403) throw new RuntimeException('vCenter account lacks permission for folder preview (HTTP 403)');
    if ($status !== 200) throw new RuntimeException($label . ' request failed (HTTP ' . $status . ')');
    return phase5f25_decode_collection($response['body'], $label, $maxItems);
}

function phase5f25_folder_rows(array $rows)
{
    $out = array();
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Folder row has invalid shape');
        $id = phase5f25_clean_text(isset($row['folder']) ? $row['folder'] : null, 'Folder id', 1024);
        $name = phase5f25_clean_text(isset($row['name']) ? $row['name'] : null, 'Folder name');
        $type = strtoupper(phase5f25_clean_text(isset($row['type']) ? $row['type'] : null, 'Folder type', 64));
        if ($type !== 'HOST') continue;
        if (isset($out[$id])) throw new RuntimeException('Duplicate Folder id returned by vCenter');
        $out[$id] = array('external_id' => $id, 'name' => $name, 'type' => $type, 'datacenter_external_id' => null, 'parent_folder_external_id' => null);
    }
    return $out;
}

function phase5f25_object_rows(array $rows, $idField, $label)
{
    $out = array();
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException($label . ' row has invalid shape');
        $id = phase5f25_clean_text(isset($row[$idField]) ? $row[$idField] : null, $label . ' id', 1024);
        $name = phase5f25_clean_text(isset($row['name']) ? $row['name'] : null, $label . ' name');
        $out[$id] = $name;
    }
    return $out;
}

function phase5f25_depth($folderId, array $folders, &$memo, &$stack)
{
    if (isset($memo[$folderId])) return $memo[$folderId];
    if (isset($stack[$folderId])) throw new RuntimeException('Folder parent cycle detected');
    $stack[$folderId] = true;
    $parent = isset($folders[$folderId]['parent_folder_external_id']) ? $folders[$folderId]['parent_folder_external_id'] : null;
    $depth = ($parent !== null && isset($folders[$parent])) ? phase5f25_depth($parent, $folders, $memo, $stack) + 1 : 0;
    unset($stack[$folderId]);
    $memo[$folderId] = $depth;
    return $depth;
}

$runtime = null;
$session = null;
$requestCount = 0;
$originalCwd = getcwd();
$stage = 'BOOTSTRAP';
try {
    $_SERVER['DOCUMENT_ROOT'] = $target;
    chdir($target . '/functions');
    require_once($target . '/functions/functions.php');
    if ($originalCwd !== false) chdir($originalCwd);

    require_once($target . '/functions/classes/class.IntegrationVmwareVcenter.php');
    require_once($target . '/functions/classes/class.IntegrationSecretCipher.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterConfigStore.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f25_autoselect_connector($Store);
    $runtime = $Store->load_runtime_config($connectorId, true);

    $Base = new IntegrationVmwareVcenter();
    $endpoint = $Base->normalize_endpoint($runtime['endpoint']);
    $verifyTls = !empty($runtime['verify_tls']);

    fwrite(STDERR, "PHASE5F25_FOLDER_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_TLS=" . ($verifyTls ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_DIRECT_TRANSPORT=1\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_PHPIPAM_MUTATIONS=0\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_VCENTER_MUTATIONS=0\n");

    $stage = 'AUTH';
    $login = phase5f25_request('POST', $endpoint . '/api/session', array('Accept: application/json'), $runtime['username'], $runtime['password'], $verifyTls, $requestCount);
    if ((int)$login['status'] !== 200 && (int)$login['status'] !== 201) {
        throw new RuntimeException('vCenter authentication failed (HTTP ' . (int)$login['status'] . ')');
    }
    $session = phase5f25_extract_token($login['body']);
    $headers = array('Accept: application/json', 'vmware-api-session-id: ' . $session);

    $stage = 'DATACENTERS';
    $dcRows = phase5f25_get_collection($endpoint, '/api/vcenter/datacenter', array(), $headers, $verifyTls, $requestCount, 'Datacenter', 1000);
    $datacenters = array();
    foreach ($dcRows as $row) {
        if (!is_array($row)) throw new RuntimeException('Datacenter row has invalid shape');
        $id = phase5f25_clean_text(isset($row['datacenter']) ? $row['datacenter'] : null, 'Datacenter id', 1024);
        $name = phase5f25_clean_text(isset($row['name']) ? $row['name'] : null, 'Datacenter name');
        $datacenters[$id] = $name;
    }

    $stage = 'FOLDERS';
    $folders = phase5f25_folder_rows(phase5f25_get_collection($endpoint, '/api/vcenter/folder', array('type' => 'HOST'), $headers, $verifyTls, $requestCount, 'HOST Folder', 1000));
    if (count($folders) > 256) throw new RuntimeException('HOST Folder count exceeded Phase 5F.2.5 preview safety limit');

    foreach ($datacenters as $dcId => $dcName) {
        $rows = phase5f25_folder_rows(phase5f25_get_collection($endpoint, '/api/vcenter/folder', array('type' => 'HOST', 'datacenters' => $dcId), $headers, $verifyTls, $requestCount, 'Datacenter HOST Folder', 1000));
        foreach ($rows as $folderId => $row) {
            if (!isset($folders[$folderId])) continue;
            $existing = $folders[$folderId]['datacenter_external_id'];
            if ($existing !== null && $existing !== $dcId) throw new RuntimeException('Folder matched multiple Datacenters');
            $folders[$folderId]['datacenter_external_id'] = $dcId;
        }
    }

    $children = array();
    $folderHosts = array();
    $folderClusters = array();
    $hostMatchCount = array();
    $clusterMatchCount = array();

    foreach (array_keys($folders) as $folderId) {
        $stage = 'FOLDER_RELATIONS';
        $childRows = phase5f25_folder_rows(phase5f25_get_collection($endpoint, '/api/vcenter/folder', array('type' => 'HOST', 'parent_folders' => $folderId), $headers, $verifyTls, $requestCount, 'Child HOST Folder', 1000));
        foreach ($childRows as $childId => $child) {
            if (!isset($folders[$childId])) continue;
            $existing = $folders[$childId]['parent_folder_external_id'];
            if ($existing !== null && $existing !== $folderId) throw new RuntimeException('Folder matched multiple parent folders');
            $folders[$childId]['parent_folder_external_id'] = $folderId;
            if (!isset($children[$folderId])) $children[$folderId] = array();
            $children[$folderId][$childId] = true;
        }

        $stage = 'FOLDER_HOSTS';
        $hostRows = phase5f25_object_rows(phase5f25_get_collection($endpoint, '/api/vcenter/host', array('folders' => $folderId), $headers, $verifyTls, $requestCount, 'Folder Host', 5000), 'host', 'Host');
        $folderHosts[$folderId] = $hostRows;
        foreach ($hostRows as $hostId => $hostName) {
            if (!isset($hostMatchCount[$hostId])) $hostMatchCount[$hostId] = 0;
            $hostMatchCount[$hostId]++;
        }

        $stage = 'FOLDER_CLUSTERS';
        $clusterRows = phase5f25_object_rows(phase5f25_get_collection($endpoint, '/api/vcenter/cluster', array('folders' => $folderId), $headers, $verifyTls, $requestCount, 'Folder Cluster', 1000), 'cluster', 'Cluster');
        $folderClusters[$folderId] = $clusterRows;
        foreach ($clusterRows as $clusterId => $clusterName) {
            if (!isset($clusterMatchCount[$clusterId])) $clusterMatchCount[$clusterId] = 0;
            $clusterMatchCount[$clusterId]++;
        }
    }

    $memo = array();
    foreach (array_keys($folders) as $folderId) {
        $stack = array();
        $folders[$folderId]['depth'] = phase5f25_depth($folderId, $folders, $memo, $stack);
    }

    uasort($folders, function ($a, $b) use ($datacenters) {
        $adc = isset($a['datacenter_external_id']) && isset($datacenters[$a['datacenter_external_id']]) ? $datacenters[$a['datacenter_external_id']] : '';
        $bdc = isset($b['datacenter_external_id']) && isset($datacenters[$b['datacenter_external_id']]) ? $datacenters[$b['datacenter_external_id']] : '';
        $cmp = strcasecmp($adc, $bdc);
        if ($cmp !== 0) return $cmp;
        if ((int)$a['depth'] !== (int)$b['depth']) return (int)$a['depth'] - (int)$b['depth'];
        $cmp = strcasecmp($a['name'], $b['name']);
        return $cmp !== 0 ? $cmp : strcmp($a['external_id'], $b['external_id']);
    });

    fwrite(STDERR, "PHASE5F25_FOLDER_DATACENTERS=" . count($datacenters) . "\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_HOST_FOLDERS=" . count($folders) . "\n");

    foreach ($folders as $folderId => $folder) {
        $dcId = $folder['datacenter_external_id'];
        $dcName = ($dcId !== null && isset($datacenters[$dcId])) ? $datacenters[$dcId] : 'UNRESOLVED';
        $parent = $folder['parent_folder_external_id'] !== null ? $folder['parent_folder_external_id'] : 'ROOT';
        $hostCount = isset($folderHosts[$folderId]) ? count($folderHosts[$folderId]) : 0;
        $clusterCount = isset($folderClusters[$folderId]) ? count($folderClusters[$folderId]) : 0;
        fwrite(STDERR, 'PHASE5F25_FOLDER_ROW depth=' . (int)$folder['depth']
            . ' dc=' . json_encode($dcName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' id=' . json_encode($folderId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' parent=' . json_encode($parent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' name=' . json_encode($folder['name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ' hosts=' . $hostCount . ' clusters=' . $clusterCount . "\n");

        $shown = 0;
        if (isset($folderClusters[$folderId])) {
            foreach ($folderClusters[$folderId] as $objectId => $objectName) {
                if ($shown >= 20) break;
                fwrite(STDERR, 'PHASE5F25_FOLDER_MEMBER type=cluster folder=' . json_encode($folderId) . ' id=' . json_encode($objectId) . ' name=' . json_encode($objectName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $shown++;
            }
        }
        if (isset($folderHosts[$folderId])) {
            foreach ($folderHosts[$folderId] as $objectId => $objectName) {
                if ($shown >= 20) break;
                fwrite(STDERR, 'PHASE5F25_FOLDER_MEMBER type=host folder=' . json_encode($folderId) . ' id=' . json_encode($objectId) . ' name=' . json_encode($objectName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $shown++;
            }
        }
    }

    $hostOverlaps = 0;
    foreach ($hostMatchCount as $count) if ($count > 1) $hostOverlaps++;
    $clusterOverlaps = 0;
    foreach ($clusterMatchCount as $count) if ($count > 1) $clusterOverlaps++;
    fwrite(STDERR, "PHASE5F25_FOLDER_HOST_MULTI_FOLDER_MATCHES={$hostOverlaps}\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_CLUSTER_MULTI_FOLDER_MATCHES={$clusterOverlaps}\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_REQUESTS={$requestCount}\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_RESULT=READ_ONLY_COMPATIBILITY_OK\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F25_FOLDER_RESULT=FAILED\n");
    fwrite(STDERR, "PHASE5F25_FOLDER_FAILED_STAGE={$stage}\n");
    fwrite(STDERR, "ERROR_CATEGORY=" . phase5f25_category($e) . "\n");
    fwrite(STDERR, "ERROR_CLASS=" . get_class($e) . "\n");
    fwrite(STDERR, "ERROR_MESSAGE_SHA256=" . hash('sha256', (string)$e->getMessage()) . "\n");
    exit(1);
} finally {
    if (is_string($session) && $session !== '' && is_array($runtime)) {
        try {
            phase5f25_request('DELETE', rtrim($runtime['endpoint'], '/') . '/api/session', array('Accept: application/json', 'vmware-api-session-id: ' . $session), null, null, !empty($runtime['verify_tls']), $requestCount);
        } catch (Exception $ignored) {
            // Session cleanup is best-effort and must not hide the primary result.
        }
    }
    if (is_array($runtime) && isset($runtime['password'])) phase5f25_zero_secret($runtime['password']);
    if ($originalCwd !== false) @chdir($originalCwd);
}
