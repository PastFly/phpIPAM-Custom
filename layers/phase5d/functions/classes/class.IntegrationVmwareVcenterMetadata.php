<?php

/**
 * phpIPAM Custom — Phase 5D read-only VMware VM metadata client.
 *
 * Current scope: Tags/Categories only.
 *
 * VMware exposes list-attached-tags as a POST action even though the operation
 * is semantically read-only. This client allowlists that one POST action and
 * uses GET for Tag/Category detail. It never calls attach/detach/create/update
 * operations and never persists metadata.
 */
class IntegrationVmwareVcenterMetadata
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxTags;
    private $maxRequests;
    private $requestCount = 0;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 2097152, $maxTags = 100, $maxRequests = 250)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException('VMware metadata transport must be callable');
        }
        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(4096, (int)$maxResponseBytes);
        $this->maxTags = max(1, min(500, (int)$maxTags));
        $this->maxRequests = max(4, min(1000, (int)$maxRequests));
    }

    public function read_vm_tags($endpoint, $username, $password, $vmExternalId, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $vmExternalId = $this->validate_identifier($vmExternalId, 'VM');
        $verifyTls = (bool)$verifyTls;
        $this->requestCount = 0;

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

            $tagIds = $this->list_attached_tag_ids($endpoint, $headers, $vmExternalId, $verifyTls);
            if (count($tagIds) > $this->maxTags) {
                throw new RuntimeException('VMware attached tag collection exceeded the metadata safety limit');
            }

            $tags = array();
            $categoryCache = array();
            foreach ($tagIds as $tagId) {
                $tag = $this->get_tag($endpoint, $headers, $tagId, $verifyTls);
                $categoryId = $tag['category_external_id'];
                if (!isset($categoryCache[$categoryId])) {
                    $categoryCache[$categoryId] = $this->get_category($endpoint, $headers, $categoryId, $verifyTls);
                }
                $tag['category'] = $categoryCache[$categoryId];
                $tags[] = $tag;
            }

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'api_family' => 'modern',
                'verify_tls' => $verifyTls,
                'vm_external_id' => $vmExternalId,
                'tag_count' => count($tags),
                'category_count' => count($categoryCache),
                'tags' => $tags,
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
        $response = $this->request('POST', $endpoint . '/api/session', array('Accept: application/json'), $username, $password, $verifyTls, null, true);
        $status = (int)$response['status'];
        if ($status === 404 || $status === 405) {
            throw new RuntimeException('Phase 5D VM metadata requires the modern vCenter API');
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
        return array('session' => $this->extract_session_token($response['body']), 'auth_http_status' => $status);
    }

    private function list_attached_tag_ids($endpoint, array $headers, $vmExternalId, $verifyTls)
    {
        $url = $endpoint . '/api/cis/tagging/tag-association?action=list-attached-tags';
        $payload = json_encode(array('object_id' => array('type' => 'VirtualMachine', 'id' => $vmExternalId)));
        if ($payload === false) {
            throw new RuntimeException('Unable to encode VMware tag association request');
        }
        $requestHeaders = $headers;
        $requestHeaders[] = 'Content-Type: application/json';
        $response = $this->request('POST', $url, $requestHeaders, null, null, $verifyTls, $payload, true);
        $status = (int)$response['status'];
        if ($status === 403) {
            throw new RuntimeException('vCenter account lacks permission to read VM tag associations (HTTP 403)');
        }
        if ($status !== 200) {
            throw new RuntimeException('vCenter VM tag association request failed (HTTP ' . $status . ')');
        }

        $decoded = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('vCenter VM tag association response is not a valid JSON collection');
        }

        $seen = array();
        $result = array();
        foreach ($decoded as $value) {
            $id = $this->validate_identifier($value, 'Tag');
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $id;
            }
        }
        return $result;
    }

    private function get_tag($endpoint, array $headers, $tagId, $verifyTls)
    {
        $response = $this->request('GET', $endpoint . '/api/cis/tagging/tag/' . rawurlencode($tagId), $headers, null, null, $verifyTls, null, true);
        $row = $this->decode_required_object($response, 'tag');
        $returnedId = $this->validate_identifier(isset($row['id']) ? $row['id'] : null, 'Tag');
        if ($returnedId !== $tagId) {
            throw new RuntimeException('vCenter tag detail returned a conflicting tag identifier');
        }
        return array(
            'external_id' => $returnedId,
            'name' => $this->required_text(isset($row['name']) ? $row['name'] : null, 'Tag name', 2048),
            'description' => $this->required_text(isset($row['description']) ? $row['description'] : null, 'Tag description', 8192, true),
            'category_external_id' => $this->validate_identifier(isset($row['category_id']) ? $row['category_id'] : null, 'Category')
        );
    }

    private function get_category($endpoint, array $headers, $categoryId, $verifyTls)
    {
        $response = $this->request('GET', $endpoint . '/api/cis/tagging/category/' . rawurlencode($categoryId), $headers, null, null, $verifyTls, null, true);
        $row = $this->decode_required_object($response, 'category');
        $returnedId = $this->validate_identifier(isset($row['id']) ? $row['id'] : null, 'Category');
        if ($returnedId !== $categoryId) {
            throw new RuntimeException('vCenter category detail returned a conflicting category identifier');
        }
        return array(
            'external_id' => $returnedId,
            'name' => $this->required_text(isset($row['name']) ? $row['name'] : null, 'Category name', 2048),
            'description' => $this->required_text(isset($row['description']) ? $row['description'] : null, 'Category description', 8192, true),
            'cardinality' => $this->required_token(isset($row['cardinality']) ? $row['cardinality'] : null, 'Category cardinality')
        );
    }

    private function decode_required_object(array $response, $label)
    {
        $status = (int)$response['status'];
        if ($status === 403) {
            throw new RuntimeException('vCenter account lacks permission to read ' . $label . ' detail (HTTP 403)');
        }
        if ($status === 404) {
            throw new RuntimeException('vCenter ' . $label . ' detail was not found (HTTP 404)');
        }
        if ($status !== 200) {
            throw new RuntimeException('vCenter ' . $label . ' detail request failed (HTTP ' . $status . ')');
        }
        $decoded = json_decode((string)$response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException('vCenter ' . $label . ' detail response is not a valid JSON object');
        }
        return $decoded;
    }

    private function cleanup_session($endpoint, $session, $verifyTls)
    {
        try {
            $this->request('DELETE', $endpoint . '/api/session', $this->session_headers($session), null, null, $verifyTls, null, true);
        } catch (Exception $ignored) {
        }
    }

    private function session_headers($session)
    {
        $session = trim((string)$session);
        if ($session === '') {
            throw new RuntimeException('vCenter session token is empty');
        }
        return array('Accept: application/json', 'vmware-api-session-id: ' . $session);
    }

    private function request($method, $url, $headers, $username, $password, $verifyTls, $body = null, $allowlisted = false)
    {
        $method = strtoupper((string)$method);
        if (!$allowlisted) {
            throw new RuntimeException('VMware metadata request was not allowlisted');
        }
        if (!in_array($method, array('GET', 'POST', 'DELETE'), true)) {
            throw new RuntimeException('VMware metadata request method is not allowed');
        }
        if ($method === 'POST' && substr($url, -12) !== '/api/session' && strpos($url, '/api/cis/tagging/tag-association?action=list-attached-tags') === false) {
            throw new RuntimeException('VMware metadata POST endpoint is not an approved read-only action');
        }
        if ($method === 'DELETE' && substr($url, -12) !== '/api/session') {
            throw new RuntimeException('VMware metadata DELETE endpoint is not session cleanup');
        }

        $this->requestCount++;
        if ($this->requestCount > $this->maxRequests) {
            throw new RuntimeException('VMware metadata request count exceeded the safety limit');
        }

        if ($this->transport !== null) {
            $response = call_user_func($this->transport, $method, (string)$url, (array)$headers, $username, $password, (bool)$verifyTls, $body, $this->connectTimeout, $this->requestTimeout);
            return $this->normalize_transport_response($response);
        }
        return $this->curl_request($method, $url, $headers, $username, $password, $verifyTls, $body);
    }

    private function normalize_transport_response($response)
    {
        if (!is_array($response)) {
            throw new RuntimeException('VMware metadata transport returned an invalid response');
        }
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $body = isset($response['body']) ? (string)$response['body'] : '';
        $error = isset($response['error']) ? trim((string)$response['error']) : '';
        if ($status === 0 && $error !== '') {
            throw new RuntimeException('vCenter network error: ' . $error);
        }
        return array('status' => $status, 'body' => $body, 'error' => $error);
    }

    private function curl_request($method, $url, $headers, $username, $password, $verifyTls, $body)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for VMware metadata');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        $responseBody = '';
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
            CURLOPT_HTTPHEADER => (array)$headers,
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-Metadata/1.0',
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$responseBody, &$tooLarge, $maxBytes) {
                if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
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
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = (string)$body;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        curl_close($ch);

        if ($tooLarge) {
            throw new RuntimeException('vCenter metadata response exceeded the safety limit');
        }
        if ($ok === false) {
            throw new RuntimeException('vCenter network error: ' . ($error !== '' ? $error : 'request failed'));
        }
        return array('status' => $status, 'body' => $responseBody, 'error' => $error);
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

    private function required_text($value, $label, $maxLength, $allowEmpty = false)
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('vCenter ' . $label . ' is invalid');
        }
        $value = trim((string)$value);
        if ((!$allowEmpty && $value === '') || strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' is invalid');
        }
        return $value;
    }

    private function required_token($value, $label)
    {
        $value = strtoupper($this->required_text($value, $label, 128));
        if (!preg_match('/^[A-Z0-9_.:-]+$/', $value)) {
            throw new RuntimeException('vCenter ' . $label . ' is invalid');
        }
        return strtolower($value);
    }

    private function extract_session_token($body)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_string($decoded)) {
            throw new RuntimeException('vCenter session response did not contain a valid modern session token');
        }
        $decoded = trim($decoded);
        if ($decoded === '' || strlen($decoded) > 4096) {
            throw new RuntimeException('vCenter authentication returned an invalid session token');
        }
        return $decoded;
    }
}
