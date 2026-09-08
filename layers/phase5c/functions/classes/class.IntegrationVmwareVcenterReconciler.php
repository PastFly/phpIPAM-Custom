<?php

/**
 * phpIPAM Custom — advisory VMware/phpIPAM reconciliation engine.
 *
 * Pure in-memory comparison only. It accepts already normalized observations
 * and returns advisory match states. It performs no database queries or writes.
 */
class IntegrationVmwareVcenterReconciler
{
    private $maxItems;

    public function __construct($maxItems = 20000)
    {
        $this->maxItems = max(1, min(100000, (int)$maxItems));
    }

    public function reconcile(array $vmInventory, array $ipObservations, array $phpipamDevices, array $phpipamAddresses)
    {
        $this->guard_count('VM inventory', $vmInventory);
        $this->guard_count('VM IP observations', $ipObservations);
        $this->guard_count('phpIPAM Devices', $phpipamDevices);
        $this->guard_count('phpIPAM addresses', $phpipamAddresses);

        $vms = $this->normalize_vms($vmInventory);
        $devices = $this->normalize_devices($phpipamDevices);
        $addresses = $this->normalize_addresses($phpipamAddresses);
        $observations = $this->normalize_ip_observations($ipObservations);

        $devicesByName = array();
        foreach ($devices as $device) {
            $key = $this->name_key($device['name']);
            if (!isset($devicesByName[$key])) {
                $devicesByName[$key] = array();
            }
            $devicesByName[$key][] = $device;
        }

        $deviceMatches = array();
        $vmToDevice = array();
        $touchedDeviceIds = array();
        foreach ($vms as $vm) {
            $key = $this->name_key($vm['name']);
            $candidates = isset($devicesByName[$key]) ? $devicesByName[$key] : array();
            if (count($candidates) === 1) {
                $device = $candidates[0];
                $vmToDevice[$vm['external_id']] = $device;
                $touchedDeviceIds[$device['device_id']] = true;
                $deviceMatches[] = array(
                    'vm_external_id' => $vm['external_id'],
                    'vm_name' => $vm['name'],
                    'status' => 'matched',
                    'phpipam_device_id' => $device['device_id'],
                    'candidate_device_ids' => array($device['device_id'])
                );
            } elseif (count($candidates) > 1) {
                $candidateIds = array();
                foreach ($candidates as $device) {
                    $candidateIds[] = $device['device_id'];
                    $touchedDeviceIds[$device['device_id']] = true;
                }
                $deviceMatches[] = array(
                    'vm_external_id' => $vm['external_id'],
                    'vm_name' => $vm['name'],
                    'status' => 'ambiguous',
                    'phpipam_device_id' => null,
                    'candidate_device_ids' => $candidateIds
                );
            } else {
                $deviceMatches[] = array(
                    'vm_external_id' => $vm['external_id'],
                    'vm_name' => $vm['name'],
                    'status' => 'vmware_only',
                    'phpipam_device_id' => null,
                    'candidate_device_ids' => array()
                );
            }
        }

        $addressesByIp = array();
        foreach ($addresses as $address) {
            if (!isset($addressesByIp[$address['ip_address']])) {
                $addressesByIp[$address['ip_address']] = array();
            }
            $addressesByIp[$address['ip_address']][] = $address;
        }

        $observedIps = array();
        $ipMatches = array();
        foreach ($observations as $observation) {
            $observedIps[$observation['ip_address']] = true;
            $candidates = isset($addressesByIp[$observation['ip_address']]) ? $addressesByIp[$observation['ip_address']] : array();

            if (count($candidates) === 0) {
                $ipMatches[] = $this->ip_match_row($observation, 'vmware_only', null, null);
                continue;
            }
            if (count($candidates) > 1) {
                $candidateIds = array();
                foreach ($candidates as $candidate) {
                    $candidateIds[] = $candidate['address_id'];
                }
                $row = $this->ip_match_row($observation, 'ambiguous', null, null);
                $row['candidate_address_ids'] = $candidateIds;
                $ipMatches[] = $row;
                continue;
            }

            $address = $candidates[0];
            $status = 'matched';
            $matchedDevice = isset($vmToDevice[$observation['vm_external_id']]) ? $vmToDevice[$observation['vm_external_id']] : null;

            if (
                $observation['mac_address'] !== null &&
                $address['mac_address'] !== null &&
                $observation['mac_address'] !== $address['mac_address']
            ) {
                $status = 'ip_mac_mismatch';
            } elseif (
                is_array($matchedDevice) &&
                $address['device_id'] !== null &&
                $address['device_id'] !== $matchedDevice['device_id']
            ) {
                $status = 'metadata_mismatch';
            }

            $ipMatches[] = $this->ip_match_row($observation, $status, $address['address_id'], $address['device_id']);
        }

        $phpipamOnlyDevices = array();
        foreach ($devices as $device) {
            if (!isset($touchedDeviceIds[$device['device_id']])) {
                $phpipamOnlyDevices[] = $device;
            }
        }

        $phpipamOnlyAddresses = array();
        foreach ($addresses as $address) {
            if (!isset($observedIps[$address['ip_address']])) {
                $phpipamOnlyAddresses[] = $address;
            }
        }

        return array(
            'device_matches' => $deviceMatches,
            'ip_matches' => $ipMatches,
            'phpipam_only_devices' => $phpipamOnlyDevices,
            'phpipam_only_addresses' => $phpipamOnlyAddresses,
            'summary' => array(
                'vm_count' => count($vms),
                'ip_observation_count' => count($observations),
                'phpipam_device_count' => count($devices),
                'phpipam_address_count' => count($addresses),
                'device_status_counts' => $this->count_statuses($deviceMatches),
                'ip_status_counts' => $this->count_statuses($ipMatches)
            )
        );
    }

    private function normalize_vms(array $rows)
    {
        $result = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('VM inventory row has an invalid shape');
            }
            $externalId = $this->require_scalar_id(isset($row['external_id']) ? $row['external_id'] : null, 'VM external id');
            if (isset($seen[$externalId])) {
                throw new InvalidArgumentException('Duplicate VM external id in reconciliation input');
            }
            $seen[$externalId] = true;
            $result[] = array(
                'external_id' => $externalId,
                'name' => $this->require_name(isset($row['name']) ? $row['name'] : null, 'VM name')
            );
        }
        return $result;
    }

    private function normalize_devices(array $rows)
    {
        $result = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('phpIPAM Device row has an invalid shape');
            }
            $id = $this->require_scalar_id(isset($row['device_id']) ? $row['device_id'] : null, 'phpIPAM Device id');
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Duplicate phpIPAM Device id in reconciliation input');
            }
            $seen[$id] = true;
            $result[] = array(
                'device_id' => $id,
                'name' => $this->require_name(isset($row['name']) ? $row['name'] : null, 'phpIPAM Device name')
            );
        }
        return $result;
    }

    private function normalize_addresses(array $rows)
    {
        $result = array();
        $seen = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('phpIPAM address row has an invalid shape');
            }
            $id = $this->require_scalar_id(isset($row['address_id']) ? $row['address_id'] : null, 'phpIPAM address id');
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Duplicate phpIPAM address id in reconciliation input');
            }
            $seen[$id] = true;
            $result[] = array(
                'address_id' => $id,
                'ip_address' => $this->normalize_ip(isset($row['ip_address']) ? $row['ip_address'] : null, 'phpIPAM IP address'),
                'device_id' => $this->optional_scalar_id(isset($row['device_id']) ? $row['device_id'] : null, 'phpIPAM address device id'),
                'mac_address' => $this->optional_mac(isset($row['mac_address']) ? $row['mac_address'] : null, 'phpIPAM MAC address')
            );
        }
        return $result;
    }

    private function normalize_ip_observations(array $rows)
    {
        $result = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('VM IP observation row has an invalid shape');
            }
            $result[] = array(
                'vm_external_id' => $this->require_scalar_id(isset($row['vm_external_id']) ? $row['vm_external_id'] : null, 'VM observation external id'),
                'nic_external_id' => $this->optional_scalar_id(isset($row['nic_external_id']) ? $row['nic_external_id'] : null, 'VM NIC external id'),
                'ip_address' => $this->normalize_ip(isset($row['ip_address']) ? $row['ip_address'] : null, 'VM observed IP address'),
                'mac_address' => $this->optional_mac(isset($row['mac_address']) ? $row['mac_address'] : null, 'VM observed MAC address')
            );
        }
        return $result;
    }

    private function ip_match_row(array $observation, $status, $addressId, $addressDeviceId)
    {
        return array(
            'vm_external_id' => $observation['vm_external_id'],
            'nic_external_id' => $observation['nic_external_id'],
            'ip_address' => $observation['ip_address'],
            'mac_address' => $observation['mac_address'],
            'status' => $status,
            'phpipam_address_id' => $addressId,
            'phpipam_device_id' => $addressDeviceId,
            'candidate_address_ids' => $addressId !== null ? array($addressId) : array()
        );
    }

    private function count_statuses(array $rows)
    {
        $counts = array();
        foreach ($rows as $row) {
            $status = isset($row['status']) ? (string)$row['status'] : 'unknown';
            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
        }
        ksort($counts);
        return $counts;
    }

    private function guard_count($label, array $rows)
    {
        if (count($rows) > $this->maxItems) {
            throw new InvalidArgumentException($label . ' exceeded the reconciliation safety limit');
        }
    }

    private function name_key($name)
    {
        return strtolower(trim((string)$name));
    }

    private function require_name($value, $label)
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        $name = trim((string)$value);
        if ($name === '' || strlen($name) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $name)) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        return $name;
    }

    private function require_scalar_id($value, $label)
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        $id = trim((string)$value);
        if ($id === '' || strlen($id) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        return $id;
    }

    private function optional_scalar_id($value, $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->require_scalar_id($value, $label);
    }

    private function normalize_ip($value, $label)
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        $ip = trim((string)$value);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        $packed = @inet_pton($ip);
        $canonical = $packed !== false ? @inet_ntop($packed) : false;
        if (!is_string($canonical) || $canonical === '') {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        return strtolower($canonical);
    }

    private function optional_mac($value, $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        $compact = strtolower(trim((string)$value));
        $compact = str_replace(array(':', '-', '.'), '', $compact);
        if (!preg_match('/^[0-9a-f]{12}$/', $compact)) {
            throw new InvalidArgumentException($label . ' has an invalid value');
        }
        return implode(':', str_split($compact, 2));
    }
}
