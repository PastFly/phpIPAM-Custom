<?php

require_once(dirname(__DIR__) . '/layers/phase5c/functions/classes/class.IntegrationPhpIpamInventorySnapshot.php');

$passed = 0;
$failed = 0;

function snapshot_check($name, $condition)
{
    global $passed, $failed;
    if ($condition) {
        print "PASS $name\n";
        $passed++;
    } else {
        print "FAIL $name\n";
        $failed++;
    }
}

class SnapshotFakeDatabase
{
    public $queries = array();
    private $devices;
    private $addresses;

    public function __construct(array $devices, array $addresses)
    {
        $this->devices = $devices;
        $this->addresses = $addresses;
    }

    public function getObjectsQuery($escape, $query, $params = array())
    {
        $this->queries[] = array($escape, $query, $params);
        if (strpos($query, 'FROM `devices`') !== false) {
            return $this->devices;
        }
        if (strpos($query, 'FROM `ipaddresses`') !== false) {
            return $this->addresses;
        }
        throw new RuntimeException('Unexpected snapshot query');
    }
}

$converter = function ($value) {
    $map = array(
        '168430090' => '10.10.10.10',
        '168430091' => '10.10.10.11',
        'ipv6' => '2001:0db8:0000:0000:0000:0000:0000:0001'
    );
    if (!isset($map[(string)$value])) {
        return 'not-an-ip';
    }
    return $map[(string)$value];
};

$db = new SnapshotFakeDatabase(
    array(
        (object)array('id' => 10, 'hostname' => 'vm-a.local'),
        array('id' => '11', 'hostname' => ' VM-B.local '),
        array('id' => 0, 'hostname' => 'ignored.local'),
        array('id' => 12, 'hostname' => '')
    ),
    array(
        (object)array('id' => 20, 'ip_addr' => '168430090', 'switch' => 10, 'mac' => 'AA-BB-CC-DD-EE-FF'),
        array('id' => '21', 'ip_addr' => 'ipv6', 'switch' => '11', 'mac' => 'aabb.ccdd.eeff'),
        array('id' => 22, 'ip_addr' => 'bad', 'switch' => null, 'mac' => null),
        array('id' => 23, 'ip_addr' => '168430091', 'switch' => '0', 'mac' => 'invalid-mac')
    )
);

$snapshot = new IntegrationPhpIpamInventorySnapshot($db, $converter, 100, 100);
$result = $snapshot->read_projection();

snapshot_check('read-only-flag', $result['read_only'] === true);
snapshot_check('device-count', $result['device_count'] === 2);
snapshot_check('device-id-normalized', $result['devices'][0]['device_id'] === '10' && $result['devices'][1]['device_id'] === '11');
snapshot_check('device-name-trimmed', $result['devices'][1]['name'] === 'VM-B.local');
snapshot_check('address-count', $result['address_count'] === 3);
snapshot_check('ipv4-canonical', $result['addresses'][0]['ip_address'] === '10.10.10.10');
snapshot_check('ipv6-canonical', $result['addresses'][1]['ip_address'] === '2001:db8::1');
snapshot_check('switch-is-device-reference', $result['addresses'][0]['device_id'] === '10' && $result['addresses'][1]['device_id'] === '11');
snapshot_check('invalid-switch-becomes-null', $result['addresses'][2]['device_id'] === null);
snapshot_check('mac-canonical-colon', $result['addresses'][0]['mac_address'] === 'aa:bb:cc:dd:ee:ff');
snapshot_check('mac-canonical-dot', $result['addresses'][1]['mac_address'] === 'aa:bb:cc:dd:ee:ff');
snapshot_check('invalid-mac-ignored', $result['addresses'][2]['mac_address'] === null && $result['ignored_invalid_mac_count'] === 1);
snapshot_check('invalid-ip-skipped', $result['skipped_invalid_ip_count'] === 1);
snapshot_check('exact-two-read-queries', count($db->queries) === 2);
snapshot_check('queries-select-only', stripos($db->queries[0][1], 'SELECT ') === 0 && stripos($db->queries[1][1], 'SELECT ') === 0);

$limitFailed = false;
try {
    $tooSmall = new IntegrationPhpIpamInventorySnapshot($db, $converter, 1, 100);
    $tooSmall->read_projection();
} catch (RuntimeException $e) {
    $limitFailed = strpos($e->getMessage(), 'Device projection exceeded') !== false;
}
snapshot_check('device-limit-fails-closed', $limitFailed);

$source = file_get_contents(dirname(__DIR__) . '/layers/phase5c/functions/classes/class.IntegrationPhpIpamInventorySnapshot.php');
snapshot_check('source-has-no-insert', stripos($source, 'insertObject(') === false && stripos($source, 'INSERT INTO') === false);
snapshot_check('source-has-no-update', stripos($source, 'updateObject(') === false && stripos($source, 'UPDATE `') === false);
snapshot_check('source-has-no-delete', stripos($source, 'deleteRow(') === false && stripos($source, 'DELETE FROM') === false);
snapshot_check('reads-device-hostname-only', strpos($source, 'SELECT `id`,`hostname` FROM `devices`') !== false);
snapshot_check('reads-address-reconciliation-fields', strpos($source, 'SELECT `id`,`ip_addr`,`switch`,`mac` FROM `ipaddresses`') !== false);

print "\nphpIPAM inventory snapshot regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
