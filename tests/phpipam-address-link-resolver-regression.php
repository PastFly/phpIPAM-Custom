<?php

$passed = 0;
$failed = 0;

function t_assert($condition, $label)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL: {$label}\n");
}

function create_link(...$parts)
{
    return '/index.php?' . implode('/', array_map('strval', $parts));
}

class Addresses
{
    public function __construct($Database) {}
    public function transform_to_decimal($ip)
    {
        if ($ip === '10.170.4.246') return '179962102';
        if ($ip === '10.170.4.153') return '179962009';
        if ($ip === '2001:db8::1') return '42540766411282592856903984951653826561';
        return '';
    }
}

class FakeDatabase
{
    public $rows = array();
    public $queries = array();

    public function getObjectsQuery($mode, $query)
    {
        $this->queries[] = $query;
        return $this->rows;
    }
}

require_once __DIR__ . '/../layers/phase5e/functions/classes/class.IntegrationPhpIpamAddressLinkResolver.php';

$db = new FakeDatabase();
$resolver = new IntegrationPhpIpamAddressLinkResolver($db);

t_assert($resolver->resolve('not-an-ip') === null, 'invalid IP is not queried or linked');
t_assert(count($db->queries) === 0, 'invalid IP performs no DB query');

$db->rows = array(array('address_id' => '77', 'subnet_id' => '12', 'section_id' => '3'));
$match = $resolver->resolve('10.170.4.246');
t_assert(is_array($match), 'one exact IP match resolves');
t_assert(isset($match['match']) && $match['match'] === 'exact_unique_ip', 'exact match provenance');
t_assert(isset($match['read_only']) && $match['read_only'] === true, 'read-only provenance');
t_assert(isset($match['url']) && $match['url'] === '/index.php?subnets/3/12/address-details/77', 'native phpIPAM address route');
t_assert(strpos($db->queries[0], "WHERE i.`ip_addr` = '179962102'") !== false, 'query matches normalized decimal IP');
t_assert(stripos($db->queries[0], 'SELECT ') === 0, 'query is SELECT-only');
t_assert(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/i', $db->queries[0]), 'query contains no write verb');

$db->rows = array();
t_assert($resolver->resolve('10.170.4.153') === null, 'missing IP remains unlinked');

$db->rows = array(
    array('address_id' => '77', 'subnet_id' => '12', 'section_id' => '3'),
    array('address_id' => '78', 'subnet_id' => '12', 'section_id' => '3')
);
t_assert($resolver->resolve('10.170.4.153') === null, 'ambiguous duplicate IP remains unlinked');

$db->rows = array(array('address_id' => '88', 'subnet_id' => '21', 'section_id' => '5'));
$ipv6 = $resolver->resolve('2001:db8::1');
t_assert(is_array($ipv6) && $ipv6['url'] === '/index.php?subnets/5/21/address-details/88', 'IPv6 uses same exact native route');

printf("phpIPAM address-link resolver regression: %d passed, %d failed\n", $passed, $failed);
if ($failed !== 0) exit(1);
echo "PHPIPAM_ADDRESS_LINK_RESOLVER_REGRESSION_OK\n";
