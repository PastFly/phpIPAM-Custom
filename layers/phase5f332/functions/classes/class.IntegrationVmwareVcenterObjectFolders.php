<?php
/**
 * Phase 5F.3.3.2: read-only VM and datastore inventory-folder discovery.
 * No ContainerView/CreateFilter or changes to existing HOST-folder collectors.
 * Discovery follows typed MoRefs; names are display-only, never identity.
 */
class IntegrationVmwareVcenterObjectFolders
{
    private $transport;
    private $calls = 0;
    private $started;
    private $maxObjects;
    private $maxCalls;
    private $seen = array();
    private $records = array();
    private $missing = array();
    private $unsupported = 0;
    private $sdk;
    private $cookie;
    private $verifyTls;
    private $collector;
    private const BATCH = 20;
    private const MAX_BYTES = 4194304;
    private const PATHS = array(
        'Folder' => array('name', 'parent', 'childEntity'),
        'Datacenter' => array('name', 'vmFolder', 'datastoreFolder'),
        'VirtualMachine' => array('name', 'parent', 'parentVApp', 'config.template'),
        'Datastore' => array('name', 'parent'),
        'StoragePod' => array('name', 'parent', 'childEntity'),
        'VirtualApp' => array('name', 'parentFolder', 'parentVApp', 'vm', 'resourcePool')
    );

    public function __construct($transport, $maxObjects = 4096, $maxCalls = 256)
    {
        if (!is_callable($transport)) throw new InvalidArgumentException('folder_transport_required');
        $this->transport = $transport;
        $this->maxObjects = max(1, min(4096, (int)$maxObjects));
        $this->maxCalls = max(4, min(256, (int)$maxCalls));
    }

    public function read_inventory($endpoint, $username, $password, $verifyTls = true)
    {
        if (!class_exists('DOMDocument') || !class_exists('IntegrationVmwareVcenter')) {
            throw new RuntimeException('folder_dependencies_missing');
        }
        $this->sdk = (new IntegrationVmwareVcenter())->normalize_endpoint($endpoint) . '/sdk';
        if (!is_string($username) || trim($username) === '' || !is_string($password) || $password === '') {
            throw new InvalidArgumentException('folder_credentials_required');
        }
        $this->calls = 0; $this->started = microtime(true); $this->cookie = null;
        $this->records = $this->seen = $this->missing = array(); $this->unsupported = 0;
        $this->verifyTls = (bool)$verifyTls; $manager = null;
        try {
            list($service) = $this->request('RetrieveServiceContent', '<_this type="ServiceInstance">ServiceInstance</_this>');
            $this->collector = $this->service_ref($service, 'propertyCollector', 'PropertyCollector');
            $manager = $this->service_ref($service, 'sessionManager', 'SessionManager');
            $root = $this->service_ref($service, 'rootFolder', 'Folder');
            list(, $headers) = $this->request('Login', '<_this type="SessionManager">' . self::xml($manager['id']) . '</_this><userName>' . self::xml($username) . '</userName><password>' . self::xml($password) . '</password>');
            foreach ($headers as $header) {
                if (preg_match('/^Set-Cookie:\s*(vmware_soap_session=[^;\r\n]{1,4096})(?:;|$)/i', (string)$header, $m)) $this->cookie = $m[1];
            }
            if ($this->cookie === null) throw new RuntimeException('folder_session_cookie_missing');
            // Walk only the datacenter Folder domain, not host/network inventories.
            $this->walk(array($root), array('Folder', 'Datacenter'), true);
            $datacenters = array(); $roots = array('vm' => array(), 'datastore' => array()); $allRoots = array();
            foreach ($this->records as $record) {
                if ($record['type'] !== 'Datacenter') continue;
                $datacenters[] = array('external_id' => $record['id'], 'name' => $record['name']);
                foreach (array('vm' => 'vmFolder', 'datastore' => 'datastoreFolder') as $domain => $property) {
                    $ref = isset($record['refs'][$property][0]) ? $record['refs'][$property][0] : null;
                    if ($ref === null || $ref['type'] !== 'Folder') throw new RuntimeException('folder_datacenter_root_missing');
                    $key = self::key($ref);
                    if (isset($allRoots[$key])) throw new RuntimeException('folder_root_shared_by_domains_or_datacenters');
                    $allRoots[$key] = true; $roots[$domain][$key] = $record['id'];
                }
            }
            if (!$datacenters) throw new RuntimeException('folder_no_visible_datacenters');
            $result = array('ok' => true, 'datacenters' => $datacenters, 'domains' => array());
            foreach ($roots as $domain => $domainRoots) {
                $references = array();
                foreach ($domainRoots as $key => $dc) $references[] = self::from_key($key);
                $allowed = $domain === 'vm' ? array('Folder', 'VirtualApp', 'VirtualMachine') : array('Folder', 'StoragePod', 'Datastore');
                $domainKeys = $this->walk($references, $allowed, false);
                $result['domains'][$domain] = self::project(array_intersect_key($this->records, $domainKeys), $domainRoots, $domain);
            }
            $result['missing_object_count'] = count($this->missing);
            $result['unsupported_edge_count'] = $this->unsupported;
            $result['partial'] = !empty($this->missing) || $this->unsupported > 0;
            foreach ($result['domains'] as $domain) if ($domain['unresolved_object_count'] > 0 || $domain['unresolved_container_count'] > 0) $result['partial'] = true;
        } finally {
            if ($this->cookie !== null && $manager !== null) {
                try { $this->request('Logout', '<_this type="SessionManager">' . self::xml($manager['id']) . '</_this>', true); } catch (Throwable $ignored) {}
            }
            $this->cookie = null;
        }
        $result['request_count'] = $this->calls;
        $result['duration_ms'] = (int)round((microtime(true) - $this->started) * 1000);
        $result['property_method'] = 'RetrieveProperties';
        $result['verify_tls'] = $this->verifyTls;
        $result['persistent_inventory_written'] = false;
        $result['phpipam_objects_modified'] = false;
        $result['vcenter_objects_modified'] = false;
        return $result;
    }

    private function walk(array $queue, array $allowed, $dcPhase)
    {
        $depth = 0; $domainKeys = array();
        while ($queue) {
            if (++$depth > 32) throw new RuntimeException('folder_depth_limit');
            $next = array(); $pending = array();
            foreach ($queue as $ref) {
                $key = self::key($ref); $domainKeys[$key] = true;
                if (isset($this->seen[$key])) continue;
                $this->seen[$key] = true; $pending[$key] = $ref;
                if (count($this->seen) > $this->maxObjects) throw new RuntimeException('folder_object_limit');
            }
            foreach (array_chunk($pending, self::BATCH, true) as $batch) {
                $rows = $this->read_batch($batch);
                foreach ($batch as $key => $ref) if (!isset($rows[$key])) $this->missing[$key] = true;
                foreach ($rows as $key => $row) {
                    $this->records[$key] = $row;
                    if ($dcPhase && $row['type'] === 'Datacenter') continue;
                    $edges = array();
                    foreach (array('childEntity', 'vm', 'resourcePool') as $property) {
                        foreach (isset($row['refs'][$property]) ? $row['refs'][$property] : array() as $ref) $edges[] = $ref;
                    }
                    foreach ($edges as $ref) {
                        if (!in_array($ref['type'], $allowed, true)) { $this->unsupported++; continue; }
                        $next[self::key($ref)] = $ref;
                    }
                }
            }
            $queue = array_values($next);
        }
        return $domainKeys;
    }

    private function read_batch(array $batch)
    {
        $types = array(); $objects = '';
        foreach ($batch as $ref) {
            $type = $ref['type'];
            if (!isset(self::PATHS[$type])) throw new RuntimeException('folder_type_not_allowed');
            $types[$type] = true;
            $objects .= '<objectSet><obj type="' . $type . '">' . self::xml($ref['id']) . '</obj><skip>false</skip></objectSet>';
        }
        $props = '';
        foreach ($types as $type => $unused) {
            $props .= '<propSet><type>' . $type . '</type><all>false</all>';
            foreach (self::PATHS[$type] as $path) $props .= '<pathSet>' . $path . '</pathSet>';
            $props .= '</propSet>';
        }
        list($doc) = $this->request('RetrieveProperties', '<_this type="PropertyCollector">' . self::xml($this->collector['id']) . '</_this><specSet>' . $props . $objects . '</specSet>');
        $xp = self::xpath($doc); $out = array();
        foreach ($xp->query('/s:Envelope/s:Body/v:RetrievePropertiesResponse/v:returnval') as $node) {
            $ref = self::ref($xp->query('v:obj', $node)->item(0)); $key = self::key($ref);
            if (!isset($batch[$key]) || isset($out[$key])) throw new RuntimeException('folder_unexpected_or_duplicate_object');
            $row = array('id' => $ref['id'], 'type' => $ref['type'], 'name' => null, 'refs' => array(), 'template' => false);
            foreach ($xp->query('v:propSet', $node) as $property) {
                $nameNode = $xp->query('v:name', $property)->item(0); $val = $xp->query('v:val', $property)->item(0);
                if ($nameNode === null || $val === null) continue;
                $name = $nameNode->textContent;
                if ($name === 'name') {
                    $text = $val->textContent;
                    if ($text === '' || strlen($text) > 2048) throw new RuntimeException('folder_invalid_name');
                    $row['name'] = $text;
                } elseif ($name === 'config.template') {
                    $row['template'] = in_array(trim($val->textContent), array('true', '1'), true);
                } elseif (in_array($name, self::PATHS[$ref['type']], true)) {
                    $row['refs'][$name] = array();
                    if ($val->hasAttribute('type')) $row['refs'][$name][] = self::ref($val);
                    else foreach ($xp->query('v:ManagedObjectReference', $val) as $child) $row['refs'][$name][] = self::ref($child);
                }
            }
            // MissingSet/permissions do not become invented empty objects.
            if ($row['name'] === null) { $this->missing[$key] = true; continue; }
            $out[$key] = $row;
        }
        return $out;
    }

    /** Pure, unit-testable graph projection. Unknown or racing edges stay unresolved. */
    public static function project(array $records, array $roots, $domain)
    {
        if (!in_array($domain, array('vm', 'datastore'), true)) throw new InvalidArgumentException('folder_invalid_domain');
        $leafType = $domain === 'vm' ? 'VirtualMachine' : 'Datastore';
        $containerTypes = $domain === 'vm' ? array('Folder', 'VirtualApp') : array('Folder', 'StoragePod');
        $memo = array(); $visiting = array();
        $resolve = function ($key) use (&$resolve, &$memo, &$visiting, $records, $roots, $containerTypes) {
            if (array_key_exists($key, $memo)) return $memo[$key];
            if (!isset($records[$key]) || isset($visiting[$key]) || count($visiting) >= 32) return null;
            if (isset($roots[$key])) return $memo[$key] = array('dc' => $roots[$key], 'parent' => null, 'depth' => 0);
            $row = $records[$key]; $visiting[$key] = true;
            $property = $row['type'] === 'VirtualApp' ? 'parentFolder' : 'parent';
            if (!empty($row['refs']['parentVApp'])) $property = 'parentVApp';
            $refs = isset($row['refs'][$property]) ? $row['refs'][$property] : array();
            $parent = count($refs) === 1 ? self::key($refs[0]) : null;
            $resolved = null;
            if ($parent !== null && isset($records[$parent]) && in_array($records[$parent]['type'], $containerTypes, true)) {
                $corroborated = false;
                foreach (array('childEntity', 'vm', 'resourcePool') as $edge) {
                    foreach (isset($records[$parent]['refs'][$edge]) ? $records[$parent]['refs'][$edge] : array() as $child) {
                        if (self::key($child) === $key) $corroborated = true;
                    }
                }
                $up = $corroborated ? $resolve($parent) : null;
                if ($up !== null) $resolved = array('dc' => $up['dc'], 'parent' => $parent, 'depth' => $up['depth'] + 1);
            }
            unset($visiting[$key]);
            return $memo[$key] = $resolved;
        };
        $nodes = array(); $objects = array(); $unresolved = array(); $unresolvedContainers = array();
        foreach ($records as $key => $row) {
            if ($row['type'] !== $leafType && !in_array($row['type'], $containerTypes, true)) continue;
            $where = $resolve($key);
            if ($where === null) {
                if ($row['type'] === $leafType) $unresolved[] = array('external_id' => $row['id'], 'name' => $row['name'], 'reason' => 'parent_not_proven_in_this_domain');
                else $unresolvedContainers[] = array('key' => $key, 'name' => $row['name'], 'reason' => 'parent_not_proven_in_this_domain');
                continue;
            }
            $entry = array('external_id' => $row['id'], 'object_type' => $row['type'], 'name' => $row['name'], 'datacenter_external_id' => $where['dc'], 'parent_key' => $where['parent'], 'depth' => $where['depth']);
            if ($row['type'] === $leafType) {
                $entry['is_template'] = !empty($row['template']); $objects[] = $entry;
            } else {
                $entry['key'] = $key; $entry['implicit_root'] = isset($roots[$key]);
                $entry['kind'] = $row['type'] === 'StoragePod' ? 'datastore_cluster' : ($row['type'] === 'VirtualApp' ? 'vapp' : 'folder');
                $entry['direct_object_count'] = 0; $entry['subtree_object_count'] = 0; $nodes[$key] = $entry;
            }
        }
        foreach ($objects as $entry) {
            $parent = $entry['parent_key'];
            if (isset($nodes[$parent])) $nodes[$parent]['direct_object_count']++;
            for ($i = 0; $i < 32 && $parent !== null && isset($nodes[$parent]); $i++) {
                $nodes[$parent]['subtree_object_count']++; $parent = $nodes[$parent]['parent_key'];
            }
        }
        $sort = function ($a, $b) { return strcmp($a['datacenter_external_id'], $b['datacenter_external_id']) ?: ($a['depth'] <=> $b['depth']) ?: strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['external_id'], $b['external_id']); };
        usort($objects, $sort); uasort($nodes, $sort);
        return array('domain' => $domain, 'nodes' => array_values($nodes), 'objects' => $objects, 'unresolved_objects' => $unresolved, 'unresolved_containers' => $unresolvedContainers, 'unresolved_container_count' => count($unresolvedContainers), 'resolved_object_count' => count($objects), 'unresolved_object_count' => count($unresolved), 'root_count' => count($roots), 'relation_evidence' => 'typed_parent_and_container_membership_under_datacenter_domain_root');
    }

    private function request($operation, $body, $cleanup = false)
    {
        if (!in_array($operation, array('RetrieveServiceContent', 'Login', 'RetrieveProperties', 'Logout'), true)) throw new RuntimeException('folder_operation_denied');
        if (!$cleanup && ($this->calls >= $this->maxCalls - 1 || microtime(true) - $this->started > 60)) throw new RuntimeException('folder_request_budget');
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($this->cookie !== null) $headers[] = 'Cookie: ' . $this->cookie;
        $envelope = '<?xml version="1.0" encoding="UTF-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><' . $operation . ' xmlns="urn:vim25">' . $body . '</' . $operation . '></s:Body></s:Envelope>';
        $this->calls++;
        $timeout = $cleanup ? 5 : max(1, min(20, (int)ceil(60 - (microtime(true) - $this->started))));
        try { $response = call_user_func($this->transport, 'POST', $this->sdk, $headers, $envelope, $this->verifyTls, min(8, $timeout), $timeout); }
        catch (Throwable $e) { throw new RuntimeException('folder_transport_failure'); }
        if (!is_array($response)) throw new RuntimeException('folder_invalid_transport_response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        if ($status < 200 || $status >= 300) throw new RuntimeException('folder_http_' . $status);
        $text = isset($response['body']) ? (string)$response['body'] : '';
        if (strlen($text) > self::MAX_BYTES || stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) throw new RuntimeException('folder_unsafe_xml');
        $doc = new DOMDocument(); $old = libxml_use_internal_errors(true);
        try { $ok = $doc->loadXML($text, LIBXML_NONET | LIBXML_NOBLANKS); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
        if (!$ok) throw new RuntimeException('folder_invalid_xml');
        $xp = self::xpath($doc);
        if ($xp->query('/s:Envelope/s:Body/s:Fault')->length) throw new RuntimeException('folder_soap_fault');
        if ($xp->query('/s:Envelope/s:Body/v:' . $operation . 'Response')->length !== 1) throw new RuntimeException('folder_response_operation_mismatch');
        return array($doc, isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array());
    }

    private function service_ref(DOMDocument $doc, $name, $type)
    {
        $ref = self::ref(self::xpath($doc)->query('/s:Envelope/s:Body/v:RetrieveServiceContentResponse/v:returnval/v:' . $name)->item(0));
        if ($ref['type'] !== $type) throw new RuntimeException('folder_invalid_service_reference');
        return $ref;
    }
    private static function xpath(DOMDocument $doc) { $xp = new DOMXPath($doc); $xp->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/'); $xp->registerNamespace('v', 'urn:vim25'); return $xp; }
    private static function ref($node)
    {
        if (!($node instanceof DOMElement)) throw new RuntimeException('folder_reference_missing');
        $ref = array('type' => $node->getAttribute('type'), 'id' => trim($node->textContent));
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/D', $ref['type']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $ref['id'])) throw new RuntimeException('folder_reference_invalid');
        return $ref;
    }
    private static function key(array $ref) { return $ref['type'] . ':' . $ref['id']; }
    private static function from_key($key) { $parts = explode(':', $key, 2); return array('type' => $parts[0], 'id' => $parts[1]); }
    private static function xml($text) { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
}
