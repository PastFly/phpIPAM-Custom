<?php

/**
 * Phase 5C.4 advisory VMware/phpIPAM reconciliation regression.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterReconciler.php');

$passed = 0;
$failed = 0;

function rec_check_true($name, $condition)
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

function rec_check_same($name, $expected, $actual)
{
    rec_check_true($name, $expected === $actual);
}

function rec_expect_exception_contains($name, $needle, $callback)
{
    global $passed, $failed;
    try {
        $callback();
        print "FAIL $name\n";
        $failed++;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), $needle) !== false) {
            print "PASS $name\n";
            $passed++;
        } else {
            print "FAIL $name\n";
            print "  expected exception containing: $needle\n";
            print "  actual: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

$classSource = file_get_contents($root . '/layers/phase5c/functions/classes/class.IntegrationVmwareVcenterReconciler.php');
rec_check_true('source-no-sql-writes', strpos($classSource, 'INSERT INTO') === false && strpos($classSource, 'UPDATE ') === false && strpos($classSource, 'DELETE FROM') === false);
rec_check_true('source-no-phpipam-object-write', strpos($classSource, 'object_modify') === false && strpos($classSource, 'modify_object') === false);
rec_check_true('source-advisory-statuses', strpos($classSource, "'vmware_only'") !== false && strpos($classSource, "'ambiguous'") !== false && strpos($classSource, "'metadata_mismatch'") !== false && strpos($classSource, "'ip_mac_mismatch'") !== false);

$vms = array(
    array('external_id' => 'vm-101', 'name' => 'APP-01'),
    array('external_id' => 'vm-102', 'name' => 'db-01'),
    array('external_id' => 'vm-103', 'name' => 'duplicate-name'),
    array('external_id' => 'vm-104', 'name' => 'vmware-only')
);

$devices = array(
    array('device_id' => 10, 'name' => 'app-01'),
    array('device_id' => 20, 'name' => 'DB-01'),
    array('device_id' => 30, 'name' => 'duplicate-name'),
    array('device_id' => 31, 'name' => 'DUPLICATE-NAME'),
    array('device_id' => 40, 'name' => 'phpipam-only')
);

$ipObservations = array(
    array('vm_external_id' => 'vm-101', 'nic_external_id' => '4000', 'ip_address' => '10.0.0.10', 'mac_address' => '00:50:56:aa:aa:10'),
    array('vm_external_id' => 'vm-102', 'nic_external_id' => '4000', 'ip_address' => '10.0.0.20', 'mac_address' => '00:50:56:aa:aa:20'),
    array('vm_external_id' => 'vm-102', 'nic_external_id' => '4001', 'ip_address' => '10.0.0.21', 'mac_address' => '00:50:56:aa:aa:21'),
    array('vm_external_id' => 'vm-104', 'nic_external_id' => '4000', 'ip_address' => '10.0.0.99', 'mac_address' => '00:50:56:aa:aa:99'),
    array('vm_external_id' => 'vm-101', 'nic_external_id' => '4002', 'ip_address' => '2001:0db8:0:0:0:0:0:10', 'mac_address' => null)
);

$addresses = array(
    array('address_id' => 100, 'ip_address' => '10.0.0.10', 'device_id' => 10, 'mac_address' => '00-50-56-AA-AA-10'),
    array('address_id' => 200, 'ip_address' => '10.0.0.20', 'device_id' => 999, 'mac_address' => '00:50:56:aa:aa:20'),
    array('address_id' => 210, 'ip_address' => '10.0.0.21', 'device_id' => 20, 'mac_address' => '00:50:56:ff:ff:ff'),
    array('address_id' => 300, 'ip_address' => '10.0.0.30', 'device_id' => 30, 'mac_address' => null),
    array('address_id' => 301, 'ip_address' => '10.0.0.30', 'device_id' => 31, 'mac_address' => null),
    array('address_id' => 400, 'ip_address' => '10.0.0.40', 'device_id' => 40, 'mac_address' => null),
    array('address_id' => 500, 'ip_address' => '2001:db8::10', 'device_id' => 10, 'mac_address' => null)
);

$reconciler = new IntegrationVmwareVcenterReconciler();
$result = $reconciler->reconcile($vms, $ipObservations, $devices, $addresses);

rec_check_same('summary-vm-count', 4, $result['summary']['vm_count']);
rec_check_same('summary-ip-observation-count', 5, $result['summary']['ip_observation_count']);
rec_check_same('summary-device-count', 5, $result['summary']['phpipam_device_count']);
rec_check_same('summary-address-count', 7, $result['summary']['phpipam_address_count']);
rec_check_same('vm-case-insensitive-exact-match', 'matched', $result['device_matches'][0]['status']);
rec_check_same('vm-first-device-id', '10', $result['device_matches'][0]['phpipam_device_id']);
rec_check_same('vm-second-device-id', '20', $result['device_matches'][1]['phpipam_device_id']);
rec_check_same('duplicate-device-name-ambiguous', 'ambiguous', $result['device_matches'][2]['status']);
rec_check_same('duplicate-device-candidate-count', 2, count($result['device_matches'][2]['candidate_device_ids']));
rec_check_same('missing-device-vmware-only', 'vmware_only', $result['device_matches'][3]['status']);
rec_check_same('only-one-phpipam-only-device', 1, count($result['phpipam_only_devices']));
rec_check_same('phpipam-only-device-id', '40', $result['phpipam_only_devices'][0]['device_id']);

rec_check_same('ip-exact-match', 'matched', $result['ip_matches'][0]['status']);
rec_check_same('ip-exact-address-id', '100', $result['ip_matches'][0]['phpipam_address_id']);
rec_check_same('ip-device-metadata-mismatch', 'metadata_mismatch', $result['ip_matches'][1]['status']);
rec_check_same('ip-mac-mismatch', 'ip_mac_mismatch', $result['ip_matches'][2]['status']);
rec_check_same('ip-vmware-only', 'vmware_only', $result['ip_matches'][3]['status']);
rec_check_same('ipv6-canonical-match', 'matched', $result['ip_matches'][4]['status']);
rec_check_same('ipv6-address-id', '500', $result['ip_matches'][4]['phpipam_address_id']);
rec_check_same('phpipam-only-address-count', 3, count($result['phpipam_only_addresses']));

$ambiguousIpResult = $reconciler->reconcile(
    array(array('external_id' => 'vm-1', 'name' => 'x')),
    array(array('vm_external_id' => 'vm-1', 'ip_address' => '10.0.0.30', 'mac_address' => null)),
    array(),
    array(
        array('address_id' => 1, 'ip_address' => '10.0.0.30'),
        array('address_id' => 2, 'ip_address' => '10.0.0.30')
    )
);
rec_check_same('duplicate-ip-ambiguous', 'ambiguous', $ambiguousIpResult['ip_matches'][0]['status']);
rec_check_same('duplicate-ip-candidate-count', 2, count($ambiguousIpResult['ip_matches'][0]['candidate_address_ids']));

rec_expect_exception_contains('duplicate-vm-id-rejected', 'Duplicate VM external id', function () use ($reconciler) {
    $reconciler->reconcile(
        array(array('external_id' => 'vm-1', 'name' => 'a'), array('external_id' => 'vm-1', 'name' => 'b')),
        array(),
        array(),
        array()
    );
});

rec_expect_exception_contains('duplicate-device-id-rejected', 'Duplicate phpIPAM Device id', function () use ($reconciler) {
    $reconciler->reconcile(
        array(),
        array(),
        array(array('device_id' => 1, 'name' => 'a'), array('device_id' => 1, 'name' => 'b')),
        array()
    );
});

rec_expect_exception_contains('invalid-observed-ip-rejected', 'VM observed IP address', function () use ($reconciler) {
    $reconciler->reconcile(
        array(),
        array(array('vm_external_id' => 'vm-1', 'ip_address' => '999.1.1.1')),
        array(),
        array()
    );
});

rec_expect_exception_contains('invalid-phpipam-mac-rejected', 'phpIPAM MAC address', function () use ($reconciler) {
    $reconciler->reconcile(
        array(),
        array(),
        array(),
        array(array('address_id' => 1, 'ip_address' => '10.0.0.1', 'mac_address' => 'bad'))
    );
});

$bounded = new IntegrationVmwareVcenterReconciler(1);
rec_expect_exception_contains('reconciliation-count-limit-enforced', 'VM inventory exceeded', function () use ($bounded) {
    $bounded->reconcile(
        array(array('external_id' => 'vm-1', 'name' => 'a'), array('external_id' => 'vm-2', 'name' => 'b')),
        array(),
        array(),
        array()
    );
});

rec_check_same('device-status-matched-count', 2, $result['summary']['device_status_counts']['matched']);
rec_check_same('device-status-ambiguous-count', 1, $result['summary']['device_status_counts']['ambiguous']);
rec_check_same('device-status-vmware-only-count', 1, $result['summary']['device_status_counts']['vmware_only']);
rec_check_same('ip-status-matched-count', 2, $result['summary']['ip_status_counts']['matched']);
rec_check_same('ip-status-metadata-mismatch-count', 1, $result['summary']['ip_status_counts']['metadata_mismatch']);
rec_check_same('ip-status-mac-mismatch-count', 1, $result['summary']['ip_status_counts']['ip_mac_mismatch']);
rec_check_same('ip-status-vmware-only-count', 1, $result['summary']['ip_status_counts']['vmware_only']);

print "\nPhase 5C.4 reconciliation regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
