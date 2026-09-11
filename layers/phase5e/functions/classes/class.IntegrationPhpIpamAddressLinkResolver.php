<?php

/**
 * phpIPAM Custom — exact read-only guest IP -> native phpIPAM address link resolver.
 *
 * The resolver performs SELECT-only lookup against ipaddresses/subnets and
 * returns a native create_link() target only when exactly one phpIPAM address
 * row matches the canonical IP. Ambiguous or missing matches are deliberately
 * not linked.
 */
class IntegrationPhpIpamAddressLinkResolver
{
    private $Database;
    private $Addresses;

    public function __construct($Database)
    {
        if (!is_object($Database) || !method_exists($Database, 'getObjectsQuery')) {
            throw new InvalidArgumentException('phpIPAM address link resolver requires a read-capable database adapter');
        }
        if (!class_exists('Addresses')) {
            throw new InvalidArgumentException('phpIPAM Addresses class is required for IP conversion');
        }
        $this->Database = $Database;
        $this->Addresses = new Addresses($Database);
    }

    public function resolve($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $decimal = (string)$this->Addresses->transform_to_decimal($ip);
        if ($decimal === '' || preg_match('/^[0-9]+$/', $decimal) !== 1) {
            return null;
        }

        $rows = $this->Database->getObjectsQuery(
            'no_html_escape',
            "SELECT i.`id` AS `address_id`, i.`subnetId` AS `subnet_id`, s.`sectionId` AS `section_id` " .
            "FROM `ipaddresses` i INNER JOIN `subnets` s ON s.`id` = i.`subnetId` " .
            "WHERE i.`ip_addr` = '" . $decimal . "' ORDER BY i.`id` LIMIT 2;"
        );

        if (!is_array($rows) || count($rows) !== 1) {
            return null;
        }

        $row = is_object($rows[0]) ? get_object_vars($rows[0]) : $rows[0];
        if (!is_array($row)) {
            return null;
        }

        $addressId = $this->positive_id(isset($row['address_id']) ? $row['address_id'] : null);
        $subnetId = $this->positive_id(isset($row['subnet_id']) ? $row['subnet_id'] : null);
        $sectionId = $this->positive_id(isset($row['section_id']) ? $row['section_id'] : null);
        if ($addressId === null || $subnetId === null || $sectionId === null) {
            return null;
        }

        return array(
            'address_id' => $addressId,
            'subnet_id' => $subnetId,
            'section_id' => $sectionId,
            'url' => create_link('subnets', $sectionId, $subnetId, 'address-details', $addressId),
            'match' => 'exact_unique_ip',
            'read_only' => true
        );
    }

    private function positive_id($value)
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (is_string($value) && preg_match('/^[0-9]+$/', $value)) {
            $id = (int)$value;
            return $id > 0 ? $id : null;
        }
        if (is_float($value) && floor($value) === $value && $value > 0 && $value <= PHP_INT_MAX) {
            return (int)$value;
        }
        return null;
    }
}
