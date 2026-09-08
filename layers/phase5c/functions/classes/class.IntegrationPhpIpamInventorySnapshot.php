<?php

/**
 * phpIPAM Custom — read-only phpIPAM projection for VMware reconciliation.
 *
 * This adapter reads only the fields required by the Phase 5C advisory
 * reconciler. It never performs INSERT/UPDATE/DELETE and never changes phpIPAM
 * objects. Address rows are normalized to canonical text IPs before use.
 */
class IntegrationPhpIpamInventorySnapshot
{
    private $Database;
    private $ipConverter;
    private $maxDevices;
    private $maxAddresses;

    public function __construct($Database, $ipConverter = null, $maxDevices = 50000, $maxAddresses = 250000)
    {
        if (!is_object($Database) || !method_exists($Database, 'getObjectsQuery')) {
            throw new InvalidArgumentException('phpIPAM inventory snapshot requires a read-capable database adapter');
        }

        if ($ipConverter === null) {
            if (!class_exists('Addresses')) {
                throw new InvalidArgumentException('phpIPAM Addresses class is required for IP conversion');
            }
            $Addresses = new Addresses($Database);
            $ipConverter = array($Addresses, 'transform_to_dotted');
        }
        if (!is_callable($ipConverter)) {
            throw new InvalidArgumentException('phpIPAM IP converter must be callable');
        }

        $this->Database = $Database;
        $this->ipConverter = $ipConverter;
        $this->maxDevices = max(1, min(200000, (int)$maxDevices));
        $this->maxAddresses = max(1, min(1000000, (int)$maxAddresses));
    }

    public function read_projection()
    {
        $deviceRows = $this->Database->getObjectsQuery(
            'no_html_escape',
            "SELECT `id`,`hostname` FROM `devices` WHERE `hostname` IS NOT NULL AND TRIM(`hostname`) <> '' ORDER BY `id`;"
        );
        $addressRows = $this->Database->getObjectsQuery(
            'no_html_escape',
            "SELECT `id`,`ip_addr`,`switch`,`mac` FROM `ipaddresses` WHERE `ip_addr` IS NOT NULL AND `ip_addr` <> '' ORDER BY `id`;"
        );

        if (!is_array($deviceRows)) {
            $deviceRows = array();
        }
        if (!is_array($addressRows)) {
            $addressRows = array();
        }

        if (count($deviceRows) > $this->maxDevices) {
            throw new RuntimeException('phpIPAM Device projection exceeded the reconciliation safety limit');
        }
        if (count($addressRows) > $this->maxAddresses) {
            throw new RuntimeException('phpIPAM address projection exceeded the reconciliation safety limit');
        }

        $devices = array();
        foreach ($deviceRows as $row) {
            $row = $this->row_to_array($row);
            $id = $this->positive_id(isset($row['id']) ? $row['id'] : null);
            $hostname = isset($row['hostname']) ? trim((string)$row['hostname']) : '';
            if ($id === null || $hostname === '' || strlen($hostname) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $hostname)) {
                continue;
            }
            $devices[] = array(
                'device_id' => (string)$id,
                'name' => $hostname
            );
        }

        $addresses = array();
        $skippedInvalidIp = 0;
        $ignoredInvalidMac = 0;
        foreach ($addressRows as $row) {
            $row = $this->row_to_array($row);
            $id = $this->positive_id(isset($row['id']) ? $row['id'] : null);
            if ($id === null) {
                continue;
            }

            try {
                $ip = call_user_func($this->ipConverter, isset($row['ip_addr']) ? $row['ip_addr'] : null);
            } catch (Exception $ignored) {
                $skippedInvalidIp++;
                continue;
            }
            $ip = is_scalar($ip) ? trim((string)$ip) : '';
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $skippedInvalidIp++;
                continue;
            }
            $packed = @inet_pton($ip);
            $canonical = $packed !== false ? @inet_ntop($packed) : false;
            if (!is_string($canonical) || $canonical === '') {
                $skippedInvalidIp++;
                continue;
            }

            $deviceId = $this->positive_id(isset($row['switch']) ? $row['switch'] : null);
            $mac = null;
            if (isset($row['mac']) && $row['mac'] !== null && trim((string)$row['mac']) !== '') {
                $mac = $this->normalize_mac($row['mac']);
                if ($mac === null) {
                    $ignoredInvalidMac++;
                }
            }

            $addresses[] = array(
                'address_id' => (string)$id,
                'ip_address' => strtolower($canonical),
                'device_id' => $deviceId !== null ? (string)$deviceId : null,
                'mac_address' => $mac
            );
        }

        return array(
            'devices' => $devices,
            'addresses' => $addresses,
            'device_count' => count($devices),
            'address_count' => count($addresses),
            'skipped_invalid_ip_count' => $skippedInvalidIp,
            'ignored_invalid_mac_count' => $ignoredInvalidMac,
            'read_only' => true
        );
    }

    private function row_to_array($row)
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row)) {
            return get_object_vars($row);
        }
        return array();
    }

    private function positive_id($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
            $id = (int)$value;
            return $id > 0 ? $id : null;
        }
        if (is_float($value) && floor($value) === $value && $value > 0 && $value <= PHP_INT_MAX) {
            return (int)$value;
        }
        return null;
    }

    private function normalize_mac($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        $compact = strtolower(trim((string)$value));
        $compact = str_replace(array(':', '-', '.'), '', $compact);
        if (!preg_match('/^[0-9a-f]{12}$/', $compact)) {
            return null;
        }
        return implode(':', str_split($compact, 2));
    }
}
