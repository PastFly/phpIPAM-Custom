<?php

/**
 * Phase 5D VM disk ↔ datastore correlation regression.
 * Pure in-memory test: no live vCenter, DB or phpIPAM mutation.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php');

$passed = 0;
$failed = 0;

function dsr_check($name, $condition)
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

function dsr_same($name, $expected, $actual)
{
    dsr_check($name, $expected === $actual);
}

function dsr_expect_exception($name, $needle, $callback)
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
            print "  expected: $needle\n";
            print "  actual: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

$source = file_get_contents($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterDatastoreResolver.php');
dsr_check('source-resolve-method', strpos($source, 'resolve_vm_detail') !== false);
dsr_check('source-correlation-not-authority', strpos($source, 'correlation_only_not_authoritative_vmware_relation') !== false);
dsr_check('source-datacenter-scoped-evidence', strpos($source, 'vmdk_backing_name_exact_and_datacenter_scoped') !== false);
dsr_check('source-no-network-client', strpos($source, 'curl_') === false && strpos($source, 'CURLOPT_') === false);
dsr_check('source-no-db-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
dsr_check('source-no-phpipam-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);

$resolver = new IntegrationVmwareVcenterDatastoreResolver();

$vmDetail = array(
    'ok' => true,
    'vm' => array(
        'external_id' => 'vm-501',
        'name' => 'app-01',
        'disks' => array(
            array(
                'external_id' => '2000',
                'observed_datastore_name' => 'SAN-Shared',
                'datastore_external_id' => null,
                'datastore_relation_evidence' => 'vmdk_backing_path_observation_only'
            ),
            array(
                'external_id' => '2001',
                'observed_datastore_name' => 'Archive',
                'datastore_external_id' => null,
                'datastore_relation_evidence' => 'vmdk_backing_path_observation_only'
            ),
            array(
                'external_id' => '2002',
                'observed_datastore_name' => null,
                'datastore_external_id' => null,
                'datastore_relation_evidence' => 'vmdk_backing_path_observation_only'
            )
        )
    ),
    'persistent_inventory_written' => false,
    'phpipam_objects_modified' => false,
    'vcenter_objects_modified' => false
);

$datastores = array(
    array('external_id' => 'datastore-1', 'name' => 'SAN-Shared', 'datacenter_external_id' => 'datacenter-1'),
    array('external_id' => 'datastore-2', 'name' => 'SAN-Shared', 'datacenter_external_id' => 'datacenter-2'),
    array('external_id' => 'datastore-3', 'name' => 'Archive', 'datacenter_external_id' => 'datacenter-1'),
    array('external_id' => 'datastore-4', 'name' => 'UniqueGlobal', 'datacenter_external_id' => null)
);

$resolved = $resolver->resolve_vm_detail($vmDetail, $datastores, 'datacenter-1');
dsr_same('scoped-first-disk-resolved-id', 'datastore-1', $resolved['vm']['disks'][0]['datastore_external_id']);
dsr_same('scoped-first-disk-name', 'SAN-Shared', $resolved['vm']['disks'][0]['datastore_name']);
dsr_same('scoped-first-disk-status', 'correlated', $resolved['vm']['disks'][0]['datastore_relation_status']);
dsr_same('scoped-first-disk-evidence', 'vmdk_backing_name_exact_and_datacenter_scoped', $resolved['vm']['disks'][0]['datastore_relation_evidence']);
dsr_same('scoped-first-disk-candidates', 1, $resolved['vm']['disks'][0]['datastore_candidate_count']);
dsr_same('scoped-second-disk-resolved', 'datastore-3', $resolved['vm']['disks'][1]['datastore_external_id']);
dsr_same('missing-observation-status', 'no_observation', $resolved['vm']['disks'][2]['datastore_relation_status']);
dsr_same('resolved-disk-count', 2, $resolved['datastore_resolution']['resolved_disk_count']);
dsr_same('no-observation-count', 1, $resolved['datastore_resolution']['no_observation_disk_count']);
dsr_same('resolved-datastore-count', 2, $resolved['datastore_resolution']['resolved_datastore_count']);
dsr_same('vm-datacenter-provenance', 'datacenter-1', $resolved['datastore_resolution']['vm_datacenter_external_id']);
dsr_same('authority-explicitly-correlation-only', 'correlation_only_not_authoritative_vmware_relation', $resolved['datastore_resolution']['authority']);
dsr_check('result-no-persistence-flags', $resolved['datastore_resolution']['persistent_inventory_written'] === false && $resolved['datastore_resolution']['phpipam_objects_modified'] === false && $resolved['datastore_resolution']['vcenter_objects_modified'] === false);

$ambiguousDetail = array('vm' => array('disks' => array(array('external_id' => '1', 'observed_datastore_name' => 'SAN-Shared'))));
$ambiguous = $resolver->resolve_vm_detail($ambiguousDetail, $datastores, null);
dsr_same('global-duplicate-is-ambiguous', 'ambiguous', $ambiguous['vm']['disks'][0]['datastore_relation_status']);
dsr_same('global-duplicate-candidate-count', 2, $ambiguous['vm']['disks'][0]['datastore_candidate_count']);
dsr_same('global-duplicate-no-stable-id', null, $ambiguous['vm']['disks'][0]['datastore_external_id']);
dsr_same('global-duplicate-evidence', 'vmdk_backing_name_exact_but_ambiguous', $ambiguous['vm']['disks'][0]['datastore_relation_evidence']);

$uniqueDetail = array('vm' => array('disks' => array(array('external_id' => '1', 'observed_datastore_name' => 'uniqueglobal'))));
$unique = $resolver->resolve_vm_detail($uniqueDetail, $datastores, null);
dsr_same('global-unique-case-insensitive-resolve', 'datastore-4', $unique['vm']['disks'][0]['datastore_external_id']);
dsr_same('global-unique-evidence', 'vmdk_backing_name_exact_and_globally_unique', $unique['vm']['disks'][0]['datastore_relation_evidence']);

$missingDetail = array('vm' => array('disks' => array(array('external_id' => '1', 'observed_datastore_name' => 'DoesNotExist'))));
$missing = $resolver->resolve_vm_detail($missingDetail, $datastores, 'datacenter-1');
dsr_same('unknown-name-unresolved', 'unresolved', $missing['vm']['disks'][0]['datastore_relation_status']);
dsr_same('unknown-name-no-stable-id', null, $missing['vm']['disks'][0]['datastore_external_id']);
dsr_same('unknown-name-evidence', 'vmdk_backing_name_not_found_in_inventory', $missing['vm']['disks'][0]['datastore_relation_evidence']);

$wrongDc = $resolver->resolve_vm_detail($ambiguousDetail, $datastores, 'datacenter-99');
dsr_same('wrong-dc-does-not-fallback-global', 'unresolved', $wrongDc['vm']['disks'][0]['datastore_relation_status']);
dsr_same('wrong-dc-candidate-count-zero', 0, $wrongDc['vm']['disks'][0]['datastore_candidate_count']);

dsr_expect_exception('missing-vm-object-rejected', 'missing the VM object', function () use ($resolver) {
    $resolver->resolve_vm_detail(array(), array(), null);
});

dsr_expect_exception('duplicate-datastore-id-rejected', 'duplicate external id', function () use ($resolver, $vmDetail) {
    $resolver->resolve_vm_detail($vmDetail, array(
        array('external_id' => 'ds-1', 'name' => 'A', 'datacenter_external_id' => null),
        array('external_id' => 'ds-1', 'name' => 'B', 'datacenter_external_id' => null)
    ), null);
});

dsr_expect_exception('invalid-datacenter-id-rejected', 'VM Datacenter identifier is invalid', function () use ($resolver, $vmDetail, $datastores) {
    $resolver->resolve_vm_detail($vmDetail, $datastores, "bad\nvalue");
});

print "\nPhase 5D datastore resolver regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
