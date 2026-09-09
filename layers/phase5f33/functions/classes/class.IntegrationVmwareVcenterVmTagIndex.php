<?php

/**
 * phpIPAM Custom — Phase 5F.3.3 bounded transient VM tag/category index.
 *
 * Uses the modern tagging API with one session and the bulk
 * list-attached-objects-on-tags action. Session lifecycle is the only state
 * involved; no tag/object mutation and no phpIPAM persistence is performed.
 */
class IntegrationVmwareVcenterVmTagIndex
{
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxTags;
    private $maxRequests;
    private $associationBatchSize;
    private $requestCount = 0;

    public function __construct($connectTimeout = 8, $requestTimeout = 25, $maxResponseBytes = 4194304, $maxTags = 500, $maxRequests = 800, $associationBatchSize = 50)
    {
        $this->connectTimeout = max(1, min(30, (int)$connectTimeout));
        $this->requestTimeout = max($this->connectTimeout, min(60, (int)$requestTimeout));
        $this->maxResponseBytes = max(65536, min(8388608, (int)$maxResponseBytes));
        $this->maxTags = max(1, min(1000, (int)$maxTags));
        $this->maxRequests = max(16, min(2000, (int)$maxRequests));
        $this->associationBatchSize = max(1, min(100, (int)$associationBatchSize));
    }

    public function read_index($endpoint, $username, $password, array $allowedVmIds, $verifyTls = true)
    {
        if (!class_exists('IntegrationVmwareVcenter')) throw new RuntimeException('Base VMware connector class is required');
        $EndpointPolicy = new IntegrationVmwareVcenter();
        $endpoint = $EndpointPolicy->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $verifyTls = (bool)$verifyTls;
        if ($username === '' || $password === '') throw new InvalidArgumentException('vCenter username and password are required');

        $allowed = array();
        foreach ($allowedVmIds as $raw) {
            $id = trim((string)$raw);
            if (!preg_match('/^vm-[0-9]+$/', $id)) throw new InvalidArgumentException('Invalid allowed VirtualMachine id');
            $allowed[$id] = true;
        }
        if (count($allowed) === 0) throw new InvalidArgumentException('At least one allowed VM id is required');

        $this->requestCount = 0;
        $session = null;
        $started = microtime(true);
        try {
            $login = $this->request('POST', $endpoint . '/api/session', array('Accept: application/json'), $username, $password, $verifyTls);
            if ((int)$login['status'] !== 200 && (int)$login['status'] !== 201) {
                if ((int)$login['status'] === 401) throw new RuntimeException('vCenter authentication failed (HTTP 401)');
                if ((int)$login['status'] === 403) throw new RuntimeException('vCenter authentication was forbidden (HTTP 403)');
                throw new RuntimeException('vCenter authentication failed (HTTP ' . (int)$login['status'] . ')');
            }
            $session = $this->extract_session($login['body']);
            $headers = array('Accept: application/json', 'vmware-api-session-id: ' . $session);

            $tagIds = $this->list_tag_ids($endpoint, $headers, $verifyTls);
            if (count($tagIds) > $this->maxTags) throw new RuntimeException('VMware tag collection exceeded the safety limit');

            $tags = array();
            $categories = array();
            foreach ($tagIds as $tagId) {
                $tag = $this->get_object($endpoint . '/api/cis/tagging/tag/' . rawurlencode($tagId), $headers, $verifyTls, 'tag');
                $returnedId = $this->clean_id(isset($tag['id']) ? $tag['id'] : $tagId, 'Tag id');
                if ($returnedId !== $tagId) throw new RuntimeException('vCenter tag detail returned a conflicting id');
                $categoryId = $this->clean_id(isset($tag['category_id']) ? $tag['category_id'] : null, 'Category id');
                if (!isset($categories[$categoryId])) {
                    $category = $this->get_object($endpoint . '/api/cis/tagging/category/' . rawurlencode($categoryId), $headers, $verifyTls, 'category');
                    $returnedCategoryId = $this->clean_id(isset($category['id']) ? $category['id'] : $categoryId, 'Category id');
                    if ($returnedCategoryId !== $categoryId) throw new RuntimeException('vCenter category detail returned a conflicting id');
                    $categories[$categoryId] = array(
                        'external_id' => $categoryId,
                        'name' => $this->clean_text(isset($category['name']) ? $category['name'] : null, 'Category name', 2048),
                        'description' => $this->optional_text(isset($category['description']) ? $category['description'] : null, 8192)
                    );
                }
                $tags[$tagId] = array(
                    'external_id' => $tagId,
                    'name' => $this->clean_text(isset($tag['name']) ? $tag['name'] : null, 'Tag name', 2048),
                    'description' => $this->optional_text(isset($tag['description']) ? $tag['description'] : null, 8192),
                    'category_external_id' => $categoryId,
                    'category_name' => $categories[$categoryId]['name']
                );
            }

            $vmTags = array();
            foreach (array_chunk($tagIds, $this->associationBatchSize) as $batch) {
                $rows = $this->bulk_associations($endpoint, $headers, $batch, $verifyTls);
                foreach ($rows as $tagId => $objectIds) {
                    if (!isset($tags[$tagId])) continue;
                    foreach ($objectIds as $object) {
                        if (!is_array($object)) continue;
                        $type = isset($object['type']) ? trim((string)$object['type']) : '';
                        $id = isset($object['id']) ? trim((string)$object['id']) : '';
                        if ($type !== 'VirtualMachine' || !isset($allowed[$id])) continue;
                        if (!isset($vmTags[$id])) $vmTags[$id] = array();
                        $vmTags[$id][$tagId] = $tags[$tagId];
                    }
                }
            }

            $associationCount = 0;
            foreach ($vmTags as $vmId => $tagMap) {
                $associationCount += count($tagMap);
                $vmTags[$vmId] = array_values($tagMap);
            }

            return array(
                'ok' => true,
                'provider' => 'vmware_vcenter',
                'api_family' => 'modern',
                'transport' => 'direct_no_proxy',
                'tag_count' => count($tags),
                'category_count' => count($categories),
                'vm_with_tags_count' => count($vmTags),
                'vm_tag_association_count' => $associationCount,
                'tags' => array_values($tags),
                'categories' => array_values($categories),
                'vm_tags' => $vmTags,
                'request_count' => $this->requestCount,
                'verify_tls' => $verifyTls,
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'persistent_inventory_written' => false,
                'phpipam_objects_modified' => false,
                'vcenter_objects_modified' => false
            );
        } finally {
            if (is_string($session) && $session !== '') {
                try { $this->request('DELETE', $endpoint . '/api/session', array('Accept: application/json', 'vmware-api-session-id: ' . $session), null, null, $verifyTls, null, true); } catch (Exception $ignored) {}
            }
        }
    }

    private function list_tag_ids($endpoint, array $headers, $verifyTls)
    {
        $response = $this->request('GET', $endpoint . '/api/cis/tagging/tag', $headers, null, null, $verifyTls);
        $status = (int)$response['status'];
        if ($status === 404 || $status === 405) throw new RuntimeException('bulk_tag_api_unsupported');
        if ($status === 403) throw new RuntimeException('vCenter account lacks permission to list tags (HTTP 403)');
        if ($status !== 200) throw new RuntimeException('vCenter tag list request failed (HTTP ' . $status . ')');
        $decoded = $this->decode_json($response['body'], 'tag list');
        $decoded = $this->unwrap_value($decoded);
        if (!is_array($decoded)) throw new RuntimeException('vCenter tag list was not a collection');
        $ids = array();
        foreach ($decoded as $raw) $ids[$this->clean_id($raw, 'Tag id')] = true;
        return array_keys($ids);
    }

    private function get_object($url, array $headers, $verifyTls, $label)
    {
        $response = $this->request('GET', $url, $headers, null, null, $verifyTls);
        if ((int)$response['status'] === 403) throw new RuntimeException('vCenter account lacks permission to read ' . $label . ' detail (HTTP 403)');
        if ((int)$response['status'] !== 200) throw new RuntimeException('vCenter ' . $label . ' detail request failed (HTTP ' . (int)$response['status'] . ')');
        $decoded = $this->unwrap_value($this->decode_json($response['body'], $label . ' detail'));
        if (!is_array($decoded)) throw new RuntimeException('vCenter ' . $label . ' detail was not an object');
        return $decoded;
    }

    private function bulk_associations($endpoint, array $headers, array $tagIds, $verifyTls)
    {
        $payload = json_encode(array('tag_ids' => array_values($tagIds)));
        if ($payload === false) throw new RuntimeException('Unable to encode bulk tag association request');
        $requestHeaders = $headers;
        $requestHeaders[] = 'Content-Type: application/json';
        $response = $this->request('POST', $endpoint . '/api/cis/tagging/tag-association?action=list-attached-objects-on-tags', $requestHeaders, null, null, $verifyTls, $payload);
        $status = (int)$response['status'];
        if ($status === 404 || $status === 405) throw new RuntimeException('bulk_tag_association_unsupported');
        if ($status === 403) throw new RuntimeException('vCenter account lacks permission to read bulk tag associations (HTTP 403)');
        if ($status !== 200) throw new RuntimeException('vCenter bulk tag association request failed (HTTP ' . $status . ')');
        $decoded = $this->unwrap_value($this->decode_json($response['body'], 'bulk tag association'));
        if (!is_array($decoded)) throw new RuntimeException('vCenter bulk tag association response was not a collection');

        $out = array();
        foreach ($decoded as $key => $row) {
            if (is_array($row) && isset($row['tag_id']) && isset($row['object_ids']) && is_array($row['object_ids'])) {
                $tagId = $this->clean_id($row['tag_id'], 'Tag id');
                $out[$tagId] = array_values($row['object_ids']);
                continue;
            }
            if (is_string($key) && is_array($row)) {
                $tagId = $this->clean_id($key, 'Tag id');
                $out[$tagId] = array_values($row);
                continue;
            }
            throw new RuntimeException('vCenter bulk tag association row has an unsupported shape');
        }
        return $out;
    }

    private function decode_json($body, $label)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('vCenter ' . $label . ' response was not valid JSON');
        return $decoded;
    }

    private function unwrap_value($decoded)
    {
        if (is_array($decoded) && count($decoded) === 1 && array_key_exists('value', $decoded)) return $decoded['value'];
        return $decoded;
    }

    private function extract_session($body)
    {
        $decoded = $this->decode_json($body, 'session');
        if (is_string($decoded)) $session = $decoded;
        elseif (is_array($decoded) && isset($decoded['value']) && is_string($decoded['value'])) $session = $decoded['value'];
        else throw new RuntimeException('vCenter session response did not contain a token');
        $session = trim($session);
        if ($session === '' || strlen($session) > 4096) throw new RuntimeException('vCenter session token is invalid');
        return $session;
    }

    private function request($method, $url, array $headers, $username, $password, $verifyTls, $payload = null, $cleanup = false)
    {
        if (!$cleanup) {
            $this->requestCount++;
            if ($this->requestCount > $this->maxRequests) throw new RuntimeException('VM tag index request safety limit exceeded');
        }
        $method = strtoupper(trim((string)$method));
        if (!in_array($method, array('GET', 'POST', 'DELETE'), true)) throw new InvalidArgumentException('Unsupported VM tag index HTTP method');
        if (!preg_match('#^https://#i', (string)$url)) throw new InvalidArgumentException('VM tag index requires HTTPS');
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for VM tag index');

        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Unable to initialize VM tag index cURL transport');
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
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-VM-Search-Tags/1.0',
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
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
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = (string)$payload;

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        curl_close($ch);
        if ($tooLarge) throw new RuntimeException('VM tag index response exceeded the safety limit');
        if ($ok === false) throw new RuntimeException('VM tag index network error: ' . ($error !== '' ? $error : 'request failed'));
        return array('status' => $status, 'body' => $body);
    }

    private function clean_id($value, $label)
    {
        if (!is_scalar($value)) throw new RuntimeException($label . ' is invalid');
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new RuntimeException($label . ' is invalid');
        return $value;
    }

    private function clean_text($value, $label, $max)
    {
        if (!is_scalar($value)) throw new RuntimeException($label . ' is invalid');
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > (int)$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new RuntimeException($label . ' is invalid');
        return $value;
    }

    private function optional_text($value, $max)
    {
        if ($value === null || $value === '') return null;
        if (!is_scalar($value)) throw new RuntimeException('Optional VM tag text is invalid');
        $value = trim((string)$value);
        if ($value === '') return null;
        if (strlen($value) > (int)$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new RuntimeException('Optional VM tag text is invalid');
        return $value;
    }
}
