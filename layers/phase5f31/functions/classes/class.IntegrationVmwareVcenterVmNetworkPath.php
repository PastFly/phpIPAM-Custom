<?php

/**
 * phpIPAM Custom — Phase 5F.3.1 bounded read-only VM -> physical network path reader.
 *
 * The collector combines two already accepted data families without persisting
 * anything:
 *   - direct/no-proxy modern VM detail for one explicit VM and its vNIC backing;
 *   - legacy-compatible vSphere Web Services reads for VirtualMachine.runtime.host,
 *     HostSystem.config.network/configManager.networkSystem and QueryNetworkHint.
 *
 * It deliberately does NOT guess a single runtime uplink. Standard vSwitch
 * nicOrder and DVS host-proxy pNIC attachment are exposed as policy/attachment
 * evidence. A "current_uplink" is only populated if a later read-only runtime
 * source proves it directly; this first slice therefore leaves it null.
 */
class IntegrationVmwareVcenterVmNetworkPath
{
    private $soapTransport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;
    private $maxNics;
    private $maxPnics;
    private $maxSwitches;
    private $maxPortgroups;
    private $maxHints;
    private $restRequestCount = 0;
    private $soapRequestCount = 0;

    public function __construct($soapTransport, $connectTimeout = 8, $requestTimeout = 25, $maxResponseBytes = 4194304, $maxNics = 64, $maxPnics = 128, $maxSwitches = 128, $maxPortgroups = 512, $maxHints = 128)
    {
        if (!is_callable($soapTransport)) throw new InvalidArgumentException('VM network path SOAP transport must be callable');
        $this->soapTransport = $soapTransport;
        $this->connectTimeout = max(1, min(30, (int)$connectTimeout));
        $this->requestTimeout = max($this->connectTimeout, min(60, (int)$requestTimeout));
        $this->maxResponseBytes = max(65536, min(8388608, (int)$maxResponseBytes));
        $this->maxNics = max(1, min(256, (int)$maxNics));
        $this->maxPnics = max(1, min(512, (int)$maxPnics));
        $this->maxSwitches = max(1, min(512, (int)$maxSwitches));
        $this->maxPortgroups = max(1, min(2048, (int)$maxPortgroups));
        $this->maxHints = max(1, min(512, (int)$maxHints));
    }

    public function read_path($endpoint, $username, $password, $vmId, $verifyTls = true)
    {
        if (!class_exists('IntegrationVmwareVcenter')) throw new RuntimeException('Base VMware connector class is required');
        if (!class_exists('DOMDocument')) throw new RuntimeException('PHP DOM extension is required for VM network path');

        $EndpointPolicy = new IntegrationVmwareVcenter();
        $endpoint = $EndpointPolicy->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $vmId = trim((string)$vmId);
        $verifyTls = (bool)$verifyTls;

        if ($username === '' || $password === '') throw new InvalidArgumentException('vCenter username and password are required');
        if (!preg_match('/^vm-[0-9]+$/', $vmId)) throw new InvalidArgumentException('Invalid VirtualMachine managed object id');

        $this->restRequestCount = 0;
        $this->soapRequestCount = 0;
        $started = microtime(true);

        $vm = $this->read_vm_core_direct($endpoint, $username, $password, $vmId, $verifyTls);
        $host = $this->read_host_network_soap($endpoint, $username, $password, $vmId, $verifyTls);
        $paths = $this->map_nics($vm['nics'], $host['network_info'], $host['hints']);

        $resolved = 0;
        $standard = 0;
        $distributed = 0;
        $opaque = 0;
        $uplinks = 0;
        foreach ($paths as $path) {
            if ($path['relation_status'] === 'resolved' || $path['relation_status'] === 'partial') $resolved++;
            if ($path['path_type'] === 'standard_vswitch') $standard++;
            elseif ($path['path_type'] === 'distributed_vswitch') $distributed++;
            elseif ($path['path_type'] === 'opaque_network') $opaque++;
            $uplinks += count($path['uplinks']);
        }

        return array(
            'ok' => true,
            'provider' => 'vmware_vcenter',
            'vm_external_id' => $vmId,
            'vm_name' => $vm['name'],
            'host_external_id' => $host['host_external_id'],
            'host_name' => $host['host_name'],
            'nics' => $paths,
            'nic_count' => count($paths),
            'resolved_nic_count' => $resolved,
            'standard_nic_count' => $standard,
            'distributed_nic_count' => $distributed,
            'opaque_nic_count' => $opaque,
            'uplink_row_count' => $uplinks,
            'current_uplink_proven_count' => 0,
            'property_method' => 'RetrieveProperties',
            'hint_method' => 'QueryNetworkHint',
            'rest_transport' => 'direct_no_proxy',
            'soap_transport' => 'direct_no_proxy',
            'verify_tls' => $verifyTls,
            'rest_request_count' => $this->restRequestCount,
            'soap_request_count' => $this->soapRequestCount,
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'persistent_inventory_written' => false,
            'phpipam_objects_modified' => false,
            'vcenter_objects_modified' => false
        );
    }

    private function read_vm_core_direct($endpoint, $username, $password, $vmId, $verifyTls)
    {
        $session = null;
        try {
            $login = $this->rest_request('POST', $endpoint . '/api/session', array('Accept: application/json'), $username, $password, $verifyTls);
            $status = (int)$login['status'];
            if ($status !== 200 && $status !== 201) {
                if ($status === 401) throw new RuntimeException('vCenter authentication failed (HTTP 401)');
                if ($status === 403) throw new RuntimeException('vCenter authentication was forbidden (HTTP 403)');
                throw new RuntimeException('vCenter authentication failed (HTTP ' . $status . ')');
            }
            $session = $this->extract_rest_session($login['body']);
            $headers = array('Accept: application/json', 'vmware-api-session-id: ' . $session);
            $response = $this->rest_request('GET', $endpoint . '/api/vcenter/vm/' . rawurlencode($vmId), $headers, null, null, $verifyTls);
            $status = (int)$response['status'];
            if ($status === 404) throw new RuntimeException('vCenter VM object was not found (HTTP 404)');
            if ($status === 403) throw new RuntimeException('vCenter account lacks permission to read VM detail (HTTP 403)');
            if ($status !== 200) throw new RuntimeException('vCenter VM detail request failed (HTTP ' . $status . ')');

            $row = json_decode((string)$response['body'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($row)) throw new RuntimeException('vCenter VM detail response is not valid JSON object');
            $name = $this->clean_text(isset($row['name']) ? $row['name'] : null, 'VM name', 2048);
            $nics = $this->normalize_vm_nics(isset($row['nics']) ? $row['nics'] : array());
            return array('name' => $name, 'nics' => $nics);
        } finally {
            if (is_string($session) && $session !== '') {
                try {
                    $this->rest_request('DELETE', $endpoint . '/api/session', array('Accept: application/json', 'vmware-api-session-id: ' . $session), null, null, $verifyTls, true);
                } catch (Exception $ignored) {}
            }
        }
    }

    private function normalize_vm_nics($container)
    {
        if ($container === null || $container === array()) return array();
        if (!is_array($container)) throw new RuntimeException('vCenter VM NIC collection has an invalid shape');
        if (count($container) > $this->maxNics) throw new RuntimeException('vCenter VM NIC collection exceeded the safety limit');

        $result = array();
        foreach ($container as $nicId => $nic) {
            if (!is_array($nic)) throw new RuntimeException('vCenter VM NIC entry has an invalid shape');
            $nicId = $this->clean_identifier($nicId, 'Virtual NIC id');
            $backing = isset($nic['backing']) && is_array($nic['backing']) ? $nic['backing'] : array();
            $result[] = array(
                'external_id' => $nicId,
                'label' => $this->optional_text(isset($nic['label']) ? $nic['label'] : null, 'Virtual NIC label', 2048),
                'adapter_type' => $this->optional_token(isset($nic['type']) ? $nic['type'] : null, 'Virtual NIC adapter type'),
                'mac_address' => $this->optional_mac(isset($nic['mac_address']) ? $nic['mac_address'] : null),
                'state' => $this->optional_token(isset($nic['state']) ? $nic['state'] : null, 'Virtual NIC state'),
                'backing_type' => $this->optional_token(isset($backing['type']) ? $backing['type'] : null, 'Virtual NIC backing type'),
                'network_external_id' => $this->optional_identifier(isset($backing['network']) ? $backing['network'] : null, 'Network id'),
                'network_name' => $this->optional_text(isset($backing['network_name']) ? $backing['network_name'] : null, 'Network name', 2048),
                'distributed_switch_uuid' => $this->optional_text(isset($backing['distributed_switch_uuid']) ? $backing['distributed_switch_uuid'] : null, 'Distributed switch UUID', 1024),
                'distributed_port' => $this->optional_text(isset($backing['distributed_port']) ? $backing['distributed_port'] : null, 'Distributed port', 1024),
                'opaque_network_type' => $this->optional_text(isset($backing['opaque_network_type']) ? $backing['opaque_network_type'] : null, 'Opaque network type', 1024),
                'opaque_network_id' => $this->optional_text(isset($backing['opaque_network_id']) ? $backing['opaque_network_id'] : null, 'Opaque network id', 2048)
            );
        }
        return $result;
    }

    private function read_host_network_soap($endpoint, $username, $password, $vmId, $verifyTls)
    {
        $sdk = $endpoint . '/sdk';
        $cookie = null;
        $service = null;
        try {
            $serviceResponse = $this->soap_request($sdk, $this->envelope('<RetrieveServiceContent xmlns="urn:vim25"><_this type="ServiceInstance">ServiceInstance</_this></RetrieveServiceContent>'), null, $verifyTls);
            $serviceDoc = $this->parse_soap($serviceResponse['body'], 'RetrieveServiceContent');
            $service = array(
                'session_manager' => $this->required_text($serviceDoc, 'sessionManager'),
                'property_collector' => $this->required_text($serviceDoc, 'propertyCollector')
            );

            $login = '<Login xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this>'
                . '<userName>' . $this->xml($username) . '</userName><password>' . $this->xml($password) . '</password></Login>';
            $loginResponse = $this->soap_request($sdk, $this->envelope($login), null, $verifyTls);
            $this->parse_soap($loginResponse['body'], 'Login');
            $cookie = $this->extract_soap_cookie($loginResponse['headers']);
            if ($cookie === null) throw new RuntimeException('vCenter SOAP login did not return a session cookie');

            $vmRetrieve = '<RetrieveProperties xmlns="urn:vim25"><_this type="PropertyCollector">' . $this->xml($service['property_collector']) . '</_this>'
                . '<specSet><propSet><type>VirtualMachine</type><pathSet>runtime.host</pathSet></propSet>'
                . '<objectSet><obj type="VirtualMachine">' . $this->xml($vmId) . '</obj><skip>false</skip></objectSet></specSet></RetrieveProperties>';
            $vmResponse = $this->soap_request($sdk, $this->envelope($vmRetrieve), $cookie, $verifyTls);
            $vmDoc = $this->parse_soap($vmResponse['body'], 'RetrieveProperties(VirtualMachine.runtime.host)');
            $hostId = $this->find_property_text($vmDoc, 'runtime.host');
            if ($hostId === null || !preg_match('/^host-[0-9]+$/', $hostId)) throw new RuntimeException('VirtualMachine runtime.host was not returned');

            // One bounded HostSystem read: name, network system MOR and transient
            // HostNetworkInfo. Legacy RetrieveProperties is intentional.
            $hostRetrieve = '<RetrieveProperties xmlns="urn:vim25"><_this type="PropertyCollector">' . $this->xml($service['property_collector']) . '</_this>'
                . '<specSet><propSet><type>HostSystem</type><pathSet>name</pathSet><pathSet>configManager.networkSystem</pathSet><pathSet>config.network</pathSet></propSet>'
                . '<objectSet><obj type="HostSystem">' . $this->xml($hostId) . '</obj><skip>false</skip></objectSet></specSet></RetrieveProperties>';
            $hostResponse = $this->soap_request($sdk, $this->envelope($hostRetrieve), $cookie, $verifyTls);
            $hostDoc = $this->parse_soap($hostResponse['body'], 'RetrieveProperties(HostSystem network)');
            $hostName = $this->find_property_text($hostDoc, 'name');
            $networkSystemId = $this->find_property_text($hostDoc, 'configManager.networkSystem');
            $networkInfoNode = $this->find_property_value($hostDoc, 'config.network');
            if ($hostName === null) $hostName = $hostId;
            if ($networkSystemId === null || !preg_match('/^[A-Za-z0-9_.:-]+$/', $networkSystemId)) throw new RuntimeException('HostNetworkSystem managed object reference was not returned');
            if ($networkInfoNode === null) throw new RuntimeException('HostSystem config.network was not returned');

            $networkInfo = $this->parse_host_network_info($networkInfoNode);

            $query = '<QueryNetworkHint xmlns="urn:vim25"><_this type="HostNetworkSystem">' . $this->xml($networkSystemId) . '</_this></QueryNetworkHint>';
            $hintResponse = $this->soap_request($sdk, $this->envelope($query), $cookie, $verifyTls);
            $hintDoc = $this->parse_soap($hintResponse['body'], 'QueryNetworkHint');
            $hints = $this->parse_hints($hintDoc);

            return array(
                'host_external_id' => $hostId,
                'host_name' => $this->clean_text($hostName, 'Host name', 2048),
                'network_system_external_id' => $networkSystemId,
                'network_info' => $networkInfo,
                'hints' => $hints
            );
        } finally {
            if ($cookie !== null && is_array($service) && !empty($service['session_manager'])) {
                $logout = '<Logout xmlns="urn:vim25"><_this type="SessionManager">' . $this->xml($service['session_manager']) . '</_this></Logout>';
                try { $this->soap_request($sdk, $this->envelope($logout), $cookie, $verifyTls, true); } catch (Exception $ignored) {}
            }
        }
    }

    private function parse_host_network_info($networkInfo)
    {
        $pnicByKey = array();
        $pnicDevices = array();
        $pnics = $this->children($networkInfo, 'pnic');
        if (count($pnics) > $this->maxPnics) throw new RuntimeException('Host physical NIC count exceeded the safety limit');
        foreach ($pnics as $pnic) {
            $device = $this->optional_text($this->text($pnic, 'device'), 'Physical NIC device', 128);
            $key = $this->optional_text($this->text($pnic, 'key'), 'Physical NIC key', 1024);
            if ($device === null || !preg_match('/^vmnic[0-9]+$/i', $device)) continue;
            $device = strtolower($device);
            $pnicDevices[$device] = true;
            $pnicByKey[$device] = $device;
            if ($key !== null) $pnicByKey[$key] = $device;
        }

        $vswitches = array();
        $vswitchNodes = $this->children($networkInfo, 'vswitch');
        if (count($vswitchNodes) > $this->maxSwitches) throw new RuntimeException('Host vSwitch count exceeded the safety limit');
        foreach ($vswitchNodes as $node) {
            $name = $this->optional_text($this->text($node, 'name'), 'vSwitch name', 256);
            if ($name === null) continue;
            $pnicRefs = array();
            foreach ($this->children($node, 'pnic') as $pnicNode) {
                $device = $this->resolve_pnic_ref(trim((string)$pnicNode->textContent), $pnicByKey);
                if ($device !== null) $pnicRefs[$device] = true;
            }
            $spec = $this->child($node, 'spec');
            $policy = $spec === null ? null : $this->child($spec, 'policy');
            $vswitches[$name] = array(
                'name' => $name,
                'key' => $this->optional_text($this->text($node, 'key'), 'vSwitch key', 1024),
                'pnics' => array_keys($pnicRefs),
                'teaming' => $this->parse_nic_teaming($policy)
            );
        }

        $portgroups = array();
        $portgroupNodes = $this->children($networkInfo, 'portgroup');
        if (count($portgroupNodes) > $this->maxPortgroups) throw new RuntimeException('Host portgroup count exceeded the safety limit');
        foreach ($portgroupNodes as $node) {
            $spec = $this->child($node, 'spec');
            if ($spec === null) continue;
            $name = $this->optional_text($this->text($spec, 'name'), 'Portgroup name', 2048);
            $vswitchName = $this->optional_text($this->text($spec, 'vswitchName'), 'Portgroup vSwitch name', 256);
            if ($name === null) continue;
            $policy = $this->child($spec, 'policy');
            if (!isset($portgroups[$name])) $portgroups[$name] = array();
            $portgroups[$name][] = array(
                'name' => $name,
                'key' => $this->optional_text($this->text($node, 'key'), 'Portgroup key', 1024),
                'vswitch_name' => $vswitchName,
                'teaming' => $this->parse_nic_teaming($policy)
            );
        }

        $proxySwitches = array();
        $proxyNodes = $this->children($networkInfo, 'proxySwitch');
        if (count($proxyNodes) > $this->maxSwitches) throw new RuntimeException('Host proxy switch count exceeded the safety limit');
        foreach ($proxyNodes as $node) {
            $uuidRaw = $this->optional_text($this->text($node, 'dvsUuid'), 'DVS UUID', 1024);
            $uuid = $this->normalize_dvs_uuid($uuidRaw);
            if ($uuid === null) continue;
            $uplinkNames = array();
            foreach ($this->children($node, 'uplinkPort') as $uplink) {
                $key = $this->optional_text($this->text($uplink, 'key'), 'DVS uplink port key', 1024);
                $value = $this->optional_text($this->text($uplink, 'value'), 'DVS uplink name', 1024);
                if ($key !== null && $value !== null) $uplinkNames[$key] = $value;
            }

            $pnicSpecs = array();
            $spec = $this->child($node, 'spec');
            $backing = $spec === null ? null : $this->child($spec, 'backing');
            if ($backing !== null) {
                foreach ($this->children($backing, 'pnicSpec') as $pnicSpec) {
                    $device = $this->optional_text($this->text($pnicSpec, 'pnicDevice'), 'DVS pNIC device', 128);
                    if ($device === null || !preg_match('/^vmnic[0-9]+$/i', $device)) continue;
                    $device = strtolower($device);
                    $uplinkKey = $this->optional_text($this->text($pnicSpec, 'uplinkPortKey'), 'DVS uplink port key', 1024);
                    $pnicSpecs[] = array(
                        'adapter' => $device,
                        'uplink_port_key' => $uplinkKey,
                        'uplink_name' => ($uplinkKey !== null && isset($uplinkNames[$uplinkKey])) ? $uplinkNames[$uplinkKey] : null,
                        'uplink_portgroup_key' => $this->optional_text($this->text($pnicSpec, 'uplinkPortgroupKey'), 'DVS uplink portgroup key', 1024)
                    );
                }
            }

            $fallbackPnics = array();
            foreach ($this->children($node, 'pnic') as $pnicNode) {
                $device = $this->resolve_pnic_ref(trim((string)$pnicNode->textContent), $pnicByKey);
                if ($device !== null) $fallbackPnics[$device] = true;
            }

            $proxySwitches[$uuid] = array(
                'uuid' => $uuid,
                'uuid_raw' => $uuidRaw,
                'name' => $this->optional_text($this->text($node, 'dvsName'), 'DVS name', 2048),
                'key' => $this->optional_text($this->text($node, 'key'), 'Host proxy switch key', 1024),
                'pnic_specs' => $pnicSpecs,
                'pnics' => array_keys($fallbackPnics)
            );
        }

        return array(
            'pnic_devices' => array_keys($pnicDevices),
            'vswitches' => $vswitches,
            'portgroups' => $portgroups,
            'proxy_switches' => $proxySwitches,
            'pnic_count' => count($pnicDevices),
            'vswitch_count' => count($vswitches),
            'portgroup_count' => count($portgroupNodes),
            'proxy_switch_count' => count($proxySwitches)
        );
    }

    private function parse_nic_teaming($policyNode)
    {
        if ($policyNode === null) return null;
        $teaming = $this->child($policyNode, 'nicTeaming');
        if ($teaming === null) return null;
        $order = $this->child($teaming, 'nicOrder');
        $active = array();
        $standby = array();
        if ($order !== null) {
            foreach ($this->children($order, 'activeNic') as $node) {
                $value = strtolower(trim((string)$node->textContent));
                if (preg_match('/^vmnic[0-9]+$/', $value)) $active[$value] = true;
            }
            foreach ($this->children($order, 'standbyNic') as $node) {
                $value = strtolower(trim((string)$node->textContent));
                if (preg_match('/^vmnic[0-9]+$/', $value)) $standby[$value] = true;
            }
        }
        return array(
            'policy' => $this->optional_text($this->text($teaming, 'policy'), 'NIC teaming policy', 256),
            'order_present' => $order !== null,
            'active' => array_keys($active),
            'standby' => array_keys($standby)
        );
    }

    private function parse_hints(DOMDocument $doc)
    {
        $xpath = new DOMXPath($doc);
        $result = array();
        $nodes = $xpath->query('//*[local-name()="QueryNetworkHintResponse"]/*[local-name()="returnval"]');
        foreach ($nodes as $node) {
            if (count($result) >= $this->maxHints) throw new RuntimeException('vCenter network hint count exceeded the safety limit');
            $device = $this->optional_text($this->text($node, 'device'), 'Network hint device', 128);
            if ($device === null || !preg_match('/^vmnic[0-9]+$/i', $device)) continue;
            $device = strtolower($device);
            $protocol = null;
            $deviceId = null;
            $portId = null;
            $cdp = $this->child($node, 'connectedSwitchPort');
            if ($cdp !== null) {
                $protocol = 'CDP';
                $deviceId = $this->optional_text($this->text($cdp, 'devId'), 'CDP Device ID', 1024);
                $portId = $this->optional_text($this->text($cdp, 'portId'), 'CDP Port ID', 1024);
            } else {
                $lldp = $this->child($node, 'lldpInfo');
                if ($lldp !== null) {
                    $protocol = 'LLDP';
                    $deviceId = $this->optional_text($this->text($lldp, 'chassisId'), 'LLDP Chassis ID', 1024);
                    $portId = $this->optional_text($this->text($lldp, 'portId'), 'LLDP Port ID', 1024);
                }
            }
            $result[$device] = array('adapter' => $device, 'protocol' => $protocol, 'device_id' => $deviceId, 'port_id' => $portId);
        }
        return $result;
    }

    private function map_nics(array $nics, array $networkInfo, array $hints)
    {
        $result = array();
        foreach ($nics as $nic) {
            $base = array(
                'nic_external_id' => $nic['external_id'],
                'label' => $nic['label'],
                'adapter_type' => $nic['adapter_type'],
                'mac_address' => $nic['mac_address'],
                'state' => $nic['state'],
                'backing_type' => $nic['backing_type'],
                'network_external_id' => $nic['network_external_id'],
                'network_name' => $nic['network_name'],
                'distributed_switch_uuid' => $nic['distributed_switch_uuid'],
                'distributed_port' => $nic['distributed_port'],
                'path_type' => 'unresolved',
                'switch_name' => null,
                'teaming_policy' => null,
                'relation_status' => 'unresolved',
                'relation_evidence' => 'no_supported_host_network_relation',
                'current_uplink' => null,
                'current_uplink_proven' => false,
                'current_uplink_evidence' => 'not_proven_by_available_read_only_properties',
                'uplinks' => array()
            );

            if ($nic['opaque_network_id'] !== null || (is_string($nic['backing_type']) && strpos($nic['backing_type'], 'opaque') !== false)) {
                $base['path_type'] = 'opaque_network';
                $base['relation_status'] = 'unsupported';
                $base['relation_evidence'] = 'opaque_network_requires_separate_transport_zone_mapping';
                $result[] = $base;
                continue;
            }

            $dvsUuid = $this->normalize_dvs_uuid($nic['distributed_switch_uuid']);
            if ($dvsUuid !== null) {
                $base['path_type'] = 'distributed_vswitch';
                if (!isset($networkInfo['proxy_switches'][$dvsUuid])) {
                    $base['relation_status'] = 'unresolved';
                    $base['relation_evidence'] = 'dvs_uuid_not_returned_in_host_proxy_switch_inventory';
                    $result[] = $base;
                    continue;
                }
                $proxy = $networkInfo['proxy_switches'][$dvsUuid];
                $base['switch_name'] = $proxy['name'];
                $seen = array();
                foreach ($proxy['pnic_specs'] as $spec) {
                    $adapter = $spec['adapter'];
                    if (isset($seen[$adapter])) continue;
                    $seen[$adapter] = true;
                    $base['uplinks'][] = $this->uplink_row($adapter, 'dvs_attached', $spec['uplink_name'], $hints);
                }
                foreach ($proxy['pnics'] as $adapter) {
                    if (isset($seen[$adapter])) continue;
                    $seen[$adapter] = true;
                    $base['uplinks'][] = $this->uplink_row($adapter, 'dvs_attached', null, $hints);
                }
                $base['relation_status'] = count($base['uplinks']) > 0 ? 'resolved' : 'partial';
                $base['relation_evidence'] = count($base['uplinks']) > 0 ? 'vm_dvs_uuid_to_host_proxy_switch_pnic_binding' : 'vm_dvs_uuid_to_host_proxy_switch_without_pnic_binding';
                $result[] = $base;
                continue;
            }

            if ($nic['network_name'] !== null) {
                $base['path_type'] = 'standard_vswitch';
                $matches = isset($networkInfo['portgroups'][$nic['network_name']]) ? $networkInfo['portgroups'][$nic['network_name']] : array();
                if (count($matches) !== 1) {
                    $base['relation_status'] = 'unresolved';
                    $base['relation_evidence'] = count($matches) === 0 ? 'standard_portgroup_name_not_found_on_runtime_host' : 'standard_portgroup_name_ambiguous_on_runtime_host';
                    $result[] = $base;
                    continue;
                }
                $pg = $matches[0];
                $switchName = $pg['vswitch_name'];
                if ($switchName === null || !isset($networkInfo['vswitches'][$switchName])) {
                    $base['relation_status'] = 'partial';
                    $base['relation_evidence'] = 'standard_portgroup_found_but_vswitch_not_returned';
                    $result[] = $base;
                    continue;
                }
                $vswitch = $networkInfo['vswitches'][$switchName];
                $base['switch_name'] = $switchName;
                $teaming = $pg['teaming'];
                if ($teaming === null || empty($teaming['order_present'])) $teaming = $vswitch['teaming'];
                $base['teaming_policy'] = is_array($teaming) ? $teaming['policy'] : null;
                foreach ($vswitch['pnics'] as $adapter) {
                    $role = 'associated';
                    if (is_array($teaming) && !empty($teaming['order_present'])) {
                        if (in_array($adapter, $teaming['active'], true)) $role = 'active';
                        elseif (in_array($adapter, $teaming['standby'], true)) $role = 'standby';
                        else $role = 'unused';
                    }
                    $base['uplinks'][] = $this->uplink_row($adapter, $role, null, $hints);
                }
                $base['relation_status'] = count($base['uplinks']) > 0 ? 'resolved' : 'partial';
                $base['relation_evidence'] = count($base['uplinks']) > 0 ? 'vm_network_name_to_unique_host_portgroup_and_vswitch_pnic' : 'standard_vswitch_has_no_returned_pnic_binding';
                $result[] = $base;
                continue;
            }

            $result[] = $base;
        }
        return $result;
    }

    private function uplink_row($adapter, $role, $uplinkName, array $hints)
    {
        $hint = isset($hints[$adapter]) ? $hints[$adapter] : array();
        return array(
            'adapter' => $adapter,
            'role' => $role,
            'uplink_name' => $uplinkName,
            'discovery' => isset($hint['protocol']) ? $hint['protocol'] : null,
            'device_id' => isset($hint['device_id']) ? $hint['device_id'] : null,
            'port_id' => isset($hint['port_id']) ? $hint['port_id'] : null
        );
    }

    private function resolve_pnic_ref($value, array $pnicByKey)
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (isset($pnicByKey[$value])) return $pnicByKey[$value];
        if (preg_match('/(vmnic[0-9]+)$/i', $value, $match)) return strtolower($match[1]);
        return null;
    }

    private function normalize_dvs_uuid($value)
    {
        if ($value === null || $value === '') return null;
        if (!is_scalar($value)) return null;
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', (string)$value));
        return strlen($hex) === 32 ? $hex : null;
    }

    private function rest_request($method, $url, array $headers, $username, $password, $verifyTls, $cleanup = false)
    {
        if (!$cleanup) {
            $this->restRequestCount++;
            if ($this->restRequestCount > 8) throw new RuntimeException('VM network path REST request safety limit exceeded');
        }
        $method = strtoupper(trim((string)$method));
        if (!in_array($method, array('GET', 'POST', 'DELETE'), true)) throw new InvalidArgumentException('Unsupported VM network path REST method');
        if (!preg_match('#^https://#i', (string)$url)) throw new InvalidArgumentException('VM network path REST transport requires HTTPS');
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for VM network path REST transport');

        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Unable to initialize VM network path REST transport');
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
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-VMNetworkPath/1.0',
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
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        curl_close($ch);
        if ($tooLarge) throw new RuntimeException('VM network path REST response exceeded the safety limit');
        if ($ok === false) throw new RuntimeException('VM network path REST network error: ' . ($error !== '' ? $error : 'request failed'));
        return array('status' => $status, 'body' => $body);
    }

    private function soap_request($url, $body, $cookie, $verifyTls, $cleanup = false)
    {
        if (!$cleanup) {
            $this->soapRequestCount++;
            if ($this->soapRequestCount > 8) throw new RuntimeException('VM network path SOAP request safety limit exceeded');
        }
        $headers = array('Content-Type: text/xml; charset=utf-8', 'Accept: text/xml');
        if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
        $response = call_user_func($this->soapTransport, 'POST', $url, $headers, (string)$body, (bool)$verifyTls, $this->connectTimeout, $this->requestTimeout);
        if (!is_array($response)) throw new RuntimeException('VM network path SOAP transport returned an invalid response');
        $status = isset($response['status']) ? (int)$response['status'] : 0;
        $responseBody = isset($response['body']) ? (string)$response['body'] : '';
        $responseHeaders = isset($response['headers']) && is_array($response['headers']) ? $response['headers'] : array();
        if (strlen($responseBody) > $this->maxResponseBytes) throw new RuntimeException('VM network path SOAP response exceeded the safety limit');
        if ($status < 200 || $status >= 300) throw new RuntimeException('VM network path SOAP request failed (HTTP ' . $status . ')');
        return array('status' => $status, 'body' => $responseBody, 'headers' => $responseHeaders);
    }

    private function extract_rest_session($body)
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

    private function extract_soap_cookie(array $headers)
    {
        foreach ($headers as $header) {
            if (stripos((string)$header, 'Set-Cookie:') !== 0) continue;
            $value = trim(substr((string)$header, strlen('Set-Cookie:')));
            $pair = explode(';', $value, 2);
            $cookie = trim($pair[0]);
            if ($cookie !== '') return $cookie;
        }
        return null;
    }

    private function parse_soap($xml, $operation)
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML((string)$xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) throw new RuntimeException('vCenter SOAP ' . $operation . ' response is not valid XML');
        $xpath = new DOMXPath($doc);
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);
        if ($fault !== null) {
            $faultString = $xpath->query('.//*[local-name()="faultstring"]', $fault)->item(0);
            $message = $faultString !== null ? trim($faultString->textContent) : 'SOAP fault';
            throw new RuntimeException('vCenter SOAP ' . $operation . ' failed: ' . substr($message, 0, 300));
        }
        return $doc;
    }

    private function required_text(DOMDocument $doc, $localName)
    {
        $xpath = new DOMXPath($doc);
        $node = $xpath->query('//*[local-name()="' . $localName . '"]')->item(0);
        if ($node === null || trim((string)$node->textContent) === '') throw new RuntimeException('vCenter ServiceContent missing ' . $localName);
        return trim((string)$node->textContent);
    }

    private function find_property_value(DOMDocument $doc, $propertyName)
    {
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//*[local-name()="propSet"]') as $propSet) {
            $nameNode = $xpath->query('./*[local-name()="name"]', $propSet)->item(0);
            if ($nameNode !== null && trim((string)$nameNode->textContent) === $propertyName) {
                return $xpath->query('./*[local-name()="val"]', $propSet)->item(0);
            }
        }
        return null;
    }

    private function find_property_text(DOMDocument $doc, $propertyName)
    {
        $node = $this->find_property_value($doc, $propertyName);
        if ($node === null) return null;
        $value = trim((string)$node->textContent);
        return $value === '' ? null : $value;
    }

    private function child($node, $localName)
    {
        if (!($node instanceof DOMNode)) return null;
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement && $child->localName === $localName) return $child;
        return null;
    }

    private function children($node, $localName)
    {
        $out = array();
        if (!($node instanceof DOMNode)) return $out;
        foreach ($node->childNodes as $child) if ($child instanceof DOMElement && $child->localName === $localName) $out[] = $child;
        return $out;
    }

    private function text($node, $localName)
    {
        $child = $this->child($node, $localName);
        if ($child === null) return null;
        $value = trim((string)$child->textContent);
        return $value === '' ? null : $value;
    }

    private function clean_identifier($value, $label)
    {
        if (!is_scalar($value)) throw new RuntimeException($label . ' is invalid');
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new RuntimeException($label . ' is invalid');
        return $value;
    }

    private function optional_identifier($value, $label)
    {
        if ($value === null || $value === '') return null;
        return $this->clean_identifier($value, $label);
    }

    private function clean_text($value, $label, $maxLength)
    {
        $value = $this->optional_text($value, $label, $maxLength);
        if ($value === null) throw new RuntimeException($label . ' is required');
        return $value;
    }

    private function optional_text($value, $label, $maxLength)
    {
        if ($value === null || $value === '') return null;
        if (!is_scalar($value)) throw new RuntimeException($label . ' has an invalid value');
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > (int)$maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new RuntimeException($label . ' has an invalid value');
        return $value;
    }

    private function optional_token($value, $label)
    {
        $value = $this->optional_text($value, $label, 256);
        if ($value === null) return null;
        $value = strtolower($value);
        if (!preg_match('/^[a-z0-9_.:-]+$/', $value)) throw new RuntimeException($label . ' has an invalid value');
        return $value;
    }

    private function optional_mac($value)
    {
        $value = $this->optional_text($value, 'Virtual NIC MAC address', 64);
        if ($value === null) return null;
        $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $value));
        if (strlen($hex) !== 12 || !ctype_xdigit($hex)) throw new RuntimeException('Virtual NIC MAC address has an invalid value');
        return implode(':', str_split($hex, 2));
    }

    private function envelope($body)
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
    }

    private function xml($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
