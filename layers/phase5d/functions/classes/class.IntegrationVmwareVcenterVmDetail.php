<?php

/**
 * phpIPAM Custom — Phase 5D on-demand VMware VM detail reader.
 *
 * One VM per call. Uses the modern VM detail, guest identity and Tools GET
 * endpoints. Hardware disk/NIC maps returned by VM detail are normalized in
 * memory. No inventory persistence or vCenter/phpIPAM mutation is performed.
 */
class IntegrationVmwareVcenterVmDetail
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxDisks;
    private $maxNics;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 4194304, $maxDisks = 128, $maxNics = 64)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('VMware VM detail transport must be callable');
        }
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(4096, (int)$maxResponseBytes);
        $this->maxDisks = max(1, min(512, (int)$maxDisks));
        $this->maxNics = max(1, min(256, (int)$maxNics));
    }

    public function read_detail($endpoint, $username, $password, $vmId, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $vmId = $this->validate_identifier($vmId, 'VM');
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

            $core = $this->get_required_object(
                $endpoint . '/api/vcenter/vm/' . rawurlencode($vmId),
                $headers,
                $verifyTls,
                'VM detail'
            );

            $vm = $this->normalize_vm($vmId, $core);

            $guestResponse = $this->request(
                'GET',
                $endpoint . '/api/vcenter/vm/' . rawurlencode($vmId) . '/guest/identity',
                $headers,
                null,
                null,
                $verifyTls
            );
            $guest = $this->normalize_optional_guest_identity($guestResponse);

            $toolsResponse = $this->request(
                'GET',
                $endpoint . '/api/vcenter/vm/' . rawurlencode($vmId) . '/tools',
                $headers,
                null,
                null,
                $verifyTls
            );
            $tools = $this->normalize_optional_tools($toolsResponse);

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'endpoint' => $endpoint,
                'api_family' => 'modern',
                'verify_tls' => $verifyTls,
                'vm' => $vm,
                'guest_identity' => $guest,
                'tools' => $tools,
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
            throw new RuntimeException('Phase 5D VM detail requires the modern vCenter API');
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

    private function get_required_object($url, array $headers, $verifyTls, $label)
    {
        $response = $this->request('GET', $url, $headers, null, null, $verifyTls);
        $status = (int)$response['status'];
        if ($status !== 200) {
            if ($status === 404) {
                throw new RuntimeException('vCenter ' . $label . ' object was not found (HTTP 404)');
            }
            if ($status === 403) {
                throw new RuntimeException('vCenter account lacks permission to read ' . $label . ' (HTTP 403)');
            }
            throw new RuntimeException('vCenter ' . $label . ' request failed (HTTP ' . $status . ')');
        }
        $decoded = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('vCenter ' . $label . ' response is not valid JSON object');
        }
        return $decoded;
    }

    private function normalize_vm($vmId, array $row)
    {
        $identity = null;
        if (array_key_exists('identity', $row) && $row['identity'] !== null) {
            if (!is_array($row['identity'])) {
                throw new RuntimeException('vCenter VM identity has an invalid shape');
            }
            $identity = array(
                'name' => $this->optional_text($row['identity'], 'name', 'VM identity name', 2048),
                'bios_uuid' => $this->optional_uuid_like($row['identity'], 'bios_uuid', 'VM BIOS UUID'),
                'instance_uuid' => $this->optional_uuid_like($row['identity'], 'instance_uuid', 'VM instance UUID')
            );
        }

        $hardware = array_key_exists('hardware', $row) && is_array($row['hardware']) ? $row['hardware'] : array();
        $cpu = array_key_exists('cpu', $row) && is_array($row['cpu']) ? $row['cpu'] : array();
        $memory = array_key_exists('memory', $row) && is_array($row['memory']) ? $row['memory'] : array();
        $disks = $this->normalize_disks(isset($row['disks']) ? $row['disks'] : array());
        $nics = $this->normalize_nics(isset($row['nics']) ? $row['nics'] : array());

        return array(
            'object_type' => 'vm',
            'external_id' => $vmId,
            'name' => $this->require_text($row, 'name', 'VM name', 2048),
            'guest_os' => $this->optional_token($row, 'guest_os', 'VM guest OS'),
            'power_state' => $this->optional_token($row, 'power_state', 'VM power state'),
            'identity' => $identity,
            'hardware_version' => $this->optional_token($hardware, 'version', 'VM hardware version'),
            'cpu_count' => array_key_exists('count', $cpu) ? $this->optional_nonnegative_int($cpu['count'], 'VM CPU count') : null,
            'cores_per_socket' => array_key_exists('cores_per_socket', $cpu) ? $this->optional_nonnegative_int($cpu['cores_per_socket'], 'VM cores per socket') : null,
            'memory_size_mib' => array_key_exists('size_mib', $memory) ? $this->optional_nonnegative_int($memory['size_mib'], 'VM memory size') : null,
            'disks' => $disks,
            'nics' => $nics,
            'disk_count' => count($disks),
            'nic_count' => count($nics)
        );
    }

    private function normalize_disks($container)
    {
        if ($container === null || $container === array()) {
            return array();
        }
        if (!is_array($container)) {
            throw new RuntimeException('vCenter VM disks have an invalid shape');
        }
        if (count($container) > $this->maxDisks) {
            throw new RuntimeException('vCenter VM disk collection exceeded the detail safety limit');
        }

        $result = array();
        foreach ($container as $diskId => $disk) {
            if (!is_array($disk)) {
                throw new RuntimeException('vCenter VM disk entry has an invalid shape');
            }
            $diskId = $this->validate_identifier($diskId, 'virtual disk');
            $backing = isset($disk['backing']) && is_array($disk['backing']) ? $disk['backing'] : array();
            $vmdkFile = $this->optional_text($backing, 'vmdk_file', 'VMDK backing path', 8192);
            $result[] = array(
                'external_id' => $diskId,
                'label' => $this->optional_text($disk, 'label', 'Virtual disk label', 2048),
                'adapter_type' => $this->optional_token($disk, 'type', 'Virtual disk adapter type'),
                'capacity_bytes' => array_key_exists('capacity', $disk) ? $this->optional_nonnegative_int($disk['capacity'], 'Virtual disk capacity') : null,
                'backing_type' => $this->optional_token($backing, 'type', 'Virtual disk backing type'),
                'vmdk_file' => $vmdkFile,
                'observed_datastore_name' => $this->extract_vmdk_datastore_name($vmdkFile),
                'datastore_external_id' => null,
                'datastore_relation_evidence' => $vmdkFile !== null ? 'vmdk_backing_path_observation_only' : null
            );
        }
        return $result;
    }

    private function normalize_nics($container)
    {
        if ($container === null || $container === array()) {
            return array();
        }
        if (!is_array($container)) {
            throw new RuntimeException('vCenter VM NICs have an invalid shape');
        }
        if (count($container) > $this->maxNics) {
            throw new RuntimeException('vCenter VM NIC collection exceeded the detail safety limit');
        }

        $result = array();
        foreach ($container as $nicId => $nic) {
            if (!is_array($nic)) {
                throw new RuntimeException('vCenter VM NIC entry has an invalid shape');
            }
            $nicId = $this->validate_identifier($nicId, 'virtual NIC');
            $backing = isset($nic['backing']) && is_array($nic['backing']) ? $nic['backing'] : array();
            $result[] = array(
                'external_id' => $nicId,
                'label' => $this->optional_text($nic, 'label', 'Virtual NIC label', 2048),
                'adapter_type' => $this->optional_token($nic, 'type', 'Virtual NIC adapter type'),
                'mac_address' => $this->optional_mac($nic, 'mac_address'),
                'state' => $this->optional_token($nic, 'state', 'Virtual NIC state'),
                'backing_type' => $this->optional_token($backing, 'type', 'Virtual NIC backing type'),
                'network_external_id' => $this->optional_identifier($backing, 'network', 'Network'),
                'network_name' => $this->optional_text($backing, 'network_name', 'Network name', 2048),
                'distributed_switch_uuid' => $this->optional_text($backing, 'distributed_switch_uuid', 'Distributed switch UUID', 1024),
                'distributed_port' => $this->optional_text($backing, 'distributed_port', 'Distributed port', 1024),
                'opaque_network_type' => $this->optional_text($backing, 'opaque_network_type', 'Opaque network type', 1024),
                'opaque_network_id' => $this->optional_text($backing, 'opaque_network_id', 'Opaque network ID', 1024)
            );
        }
        return $result;
    }

    private function normalize_optional_guest_identity(array $response)
    {
        $status = (int)$response['status'];
        if ($status === 503) {
            return array('status' => 'tools_unavailable');
        }
        if ($status === 404 || $status === 405) {
            return array('status' => 'unsupported');
        }
        if ($status === 403) {
            return array('status' => 'forbidden');
        }
        if ($status !== 200) {
            throw new RuntimeException('vCenter guest identity request failed (HTTP ' . $status . ')');
        }

        $row = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($row)) {
            throw new RuntimeException('vCenter guest identity response is not valid JSON object');
        }

        return array(
            'status' => 'ok',
            'guest_os' => $this->optional_token($row, 'name', 'Guest OS identifier'),
            'guest_os_family' => $this->optional_token($row, 'family', 'Guest OS family'),
            'host_name' => $this->optional_text($row, 'host_name', 'Guest hostname', 2048),
            'primary_ip' => $this->optional_ip($row, 'ip_address')
        );
    }

    private function normalize_optional_tools(array $response)
    {
        $status = (int)$response['status'];
        if ($status === 503) {
            return array('status' => 'unavailable');
        }
        if ($status === 404 || $status === 405) {
            return array('status' => 'unsupported');
        }
        if ($status === 403) {
            return array('status' => 'forbidden');
        }
        if ($status !== 200) {
            throw new RuntimeException('vCenter VMware Tools request failed (HTTP ' . $status . ')');
        }

        $row = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($row)) {
            throw new RuntimeException('vCenter VMware Tools response is not valid JSON object');
        }

        return array(
            'status' => 'ok',
            'version' => $this->optional_text($row, 'version', 'VMware Tools version', 1024),
            'version_number' => array_key_exists('version_number', $row) ? $this->optional_nonnegative_int($row['version_number'], 'VMware Tools version number') : null,
            'version_status' => $this->optional_token($row, 'version_status', 'VMware Tools version status'),
            'install_type' => $this->optional_token($row, 'install_type', 'VMware Tools install type'),
            'run_state' => $this->optional_token($row, 'run_state', 'VMware Tools run state')
        );
    }

    private function extract_vmdk_datastore_name($path)
    {
        if ($path === null) {
            return null;
        }
        if (preg_match('/^\[([^\]\r\n]{1,2048})\](?:\s|\/)/', $path, $matches) !== 1) {
            return null;
        }
        $name = trim($matches[1]);
        return $name === '' ? null : $name;
    }

    private function optional_identifier(array $row, $field, $label)
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        return $this->validate_identifier($row[$field], $label);
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

    private function require_text(array $row, $field, $label, $maxLength)
    {
        $value = $this->optional_text($row, $field, $label, $maxLength);
        if ($value === null) {
            throw new RuntimeException('vCenter ' . $label . ' is required');
        }
        return $value;
    }

    private function optional_text(array $row, $field, $label, $maxLength)
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        if (!is_scalar($row[$field])) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        $value = trim((string)$row[$field]);
        if ($value === '' || strlen($value) > (int)$maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
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
        if ($value === '' || strlen($value) > 256 || !preg_match('/^[A-Z0-9_.:-]+$/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' has an invalid value');
        }
        return strtolower($value);
    }

    private function optional_uuid_like(array $row, $field, $label)
    {
        $value = $this->optional_text($row, $field, $label, 128);
        if ($value === null) {
            return null;
        }
        if (!preg_match('/^[A-Fa-f0-9-]{16,128}$/', $value)) {
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

    private function optional_mac(array $row, $field)
    {
        $value = $this->optional_text($row, $field, 'Virtual NIC MAC address', 64);
        if ($value === null) {
            return null;
        }
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $value));
        if (strlen($hex) !== 12 || !ctype_xdigit($hex)) {
            throw new RuntimeException('vCenter Virtual NIC MAC address has an invalid value');
        }
        return implode(':', str_split($hex, 2));
    }

    private function optional_ip(array $row, $field)
    {
        $value = $this->optional_text($row, $field, 'Guest primary IP', 128);
        if ($value === null) {
            return null;
        }
        $packed = @inet_pton($value);
        if ($packed === false) {
            throw new RuntimeException('vCenter Guest primary IP has an invalid value');
        }
        return inet_ntop($packed);
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
            throw new RuntimeException('VMware VM detail transport returned an invalid response');
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
            throw new RuntimeException('PHP cURL extension is required for the VMware vCenter VM detail connector');
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
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-VM-Detail/1.0',
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
            // Best-effort cleanup must not hide the primary VM detail result/error.
        }
    }
}
