<?php

/**
 * Phase 5D.5 bounded Explorer projection regression.
 */

$root = dirname(__DIR__);
require_once($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterExplorer.php');

$passed = 0;
$failed = 0;

function explorer_check($name, $condition)
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

function explorer_same($name, $expected, $actual)
{
    explorer_check($name, $expected === $actual);
}

function explorer_expect_exception($name, $needle, $callback)
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

$source = file_get_contents($root . '/layers/phase5d/functions/classes/class.IntegrationVmwareVcenterExplorer.php');
explorer_check('source-no-db-write', strpos($source, 'INSERT INTO') === false && strpos($source, 'UPDATE ') === false && strpos($source, 'DELETE FROM') === false);
explorer_check('source-no-network', strpos($source, 'curl_') === false && strpos($source, 'http://') === false && strpos($source, 'https://') === false);
explorer_check('source-no-object-write', strpos($source, 'object_modify') === false && strpos($source, 'modify_object') === false);
explorer_check('source-search-not-inference', strpos($source, 'Search is display filtering only') !== false);

$topology = array(
    'datacenters' => array(
        array('external_id' => 'dc-2', 'name' => 'NORD'),
        array('external_id' => 'dc-1', 'name' => 'IRK')
    ),
    'clusters' => array(
        array('external_id' => 'cluster-1', 'name' => 'NORD-Cluster', 'datacenter_external_id' => 'dc-2')
    ),
    'hosts' => array(
        array('external_id' => 'host-1', 'name' => 'esx-a', 'datacenter_external_id' => 'dc-2', 'cluster_external_id' => 'cluster-1'),
        array('external_id' => 'host-2', 'name' => 'esx-standalone', 'datacenter_external_id' => 'dc-1', 'cluster_external_id' => null)
    ),
    'vms' => array(
        array('external_id' => 'vm-2', 'name' => 'beta', 'power_state' => 'powered_off', 'cpu_count' => 2, 'memory_size_mib' => 2048, 'datacenter_external_id' => 'dc-1', 'cluster_external_id' => null, 'host_external_id' => 'host-2'),
        array('external_id' => 'vm-1', 'name' => 'Alpha', 'power_state' => 'powered_on', 'cpu_count' => 4, 'memory_size_mib' => 8192, 'datacenter_external_id' => 'dc-2', 'cluster_external_id' => 'cluster-1', 'host_external_id' => 'host-1'),
        array('external_id' => 'vm-3', 'name' => 'alpha-tools', 'power_state' => 'powered_on', 'cpu_count' => 1, 'memory_size_mib' => 1024, 'datacenter_external_id' => 'dc-2', 'cluster_external_id' => 'cluster-1', 'host_external_id' => 'host-1')
    )
);

$datastores = array(
    'datastores' => array(
        array('external_id' => 'ds-2', 'name' => 'SAN-B', 'datacenter_external_id' => 'dc-2'),
        array('external_id' => 'ds-1', 'name' => 'SAN-A', 'datacenter_external_id' => 'dc-1')
    )
);

$Explorer = new IntegrationVmwareVcenterExplorer(100);
$built = $Explorer->build($topology, $datastores);

explorer_same('count-dc', 2, $built['counts']['datacenters']);
explorer_same('count-cluster', 1, $built['counts']['clusters']);
explorer_same('count-host', 2, $built['counts']['hosts']);
explorer_same('count-vm', 3, $built['counts']['vms']);
explorer_same('count-datastore', 2, $built['counts']['datastores']);
explorer_check('build-no-persistence', $built['persistent_inventory_written'] === false && $built['phpipam_objects_modified'] === false && $built['vcenter_objects_modified'] === false);
explorer_same('overview-sorted-first', 'IRK', $built['overview'][0]['name']);
explorer_same('overview-irk-host-count', 1, $built['overview'][0]['host_count']);
explorer_same('overview-irk-vm-count', 1, $built['overview'][0]['vm_count']);
explorer_same('overview-irk-ds-count', 1, $built['overview'][0]['datastore_count']);
explorer_same('overview-nord-cluster-count', 1, $built['overview'][1]['cluster_count']);
explorer_same('overview-nord-vm-count', 2, $built['overview'][1]['vm_count']);

$page = $Explorer->list_vms($built, array('page' => 1, 'page_size' => 2));
explorer_same('pagination-total', 3, $page['total']);
explorer_same('pagination-page-count', 2, $page['page_count']);
explorer_same('pagination-first-name', 'Alpha', $page['items'][0]['name']);
explorer_same('pagination-second-name', 'alpha-tools', $page['items'][1]['name']);
explorer_check('pagination-no-persistence', $page['persistent_inventory_written'] === false && $page['phpipam_objects_modified'] === false && $page['vcenter_objects_modified'] === false);

$nord = $Explorer->list_vms($built, array('scope' => 'datacenter', 'scope_id' => 'dc-2', 'page' => 1, 'page_size' => 50));
explorer_same('dc-scope-count', 2, $nord['total']);
$cluster = $Explorer->list_vms($built, array('scope' => 'cluster', 'scope_id' => 'cluster-1', 'page' => 1, 'page_size' => 50));
explorer_same('cluster-scope-count', 2, $cluster['total']);
$host = $Explorer->list_vms($built, array('scope' => 'host', 'scope_id' => 'host-2', 'page' => 1, 'page_size' => 50));
explorer_same('host-scope-count', 1, $host['total']);
explorer_same('host-scope-vm', 'vm-2', $host['items'][0]['external_id']);

$search = $Explorer->list_vms($built, array('search' => 'ALPHA', 'page' => 1, 'page_size' => 50));
explorer_same('search-case-insensitive', 2, $search['total']);
$idSearch = $Explorer->list_vms($built, array('search' => 'VM-2', 'page' => 1, 'page_size' => 50));
explorer_same('search-by-id', 1, $idSearch['total']);
$powered = $Explorer->list_vms($built, array('power_state' => 'POWERED_ON', 'page' => 1, 'page_size' => 50));
explorer_same('power-filter', 2, $powered['total']);

$context = $Explorer->vm_context($built, 'vm-1');
explorer_same('context-dc-name', 'NORD', $context['datacenter_name']);
explorer_same('context-cluster-name', 'NORD-Cluster', $context['cluster_name']);
explorer_same('context-host-name', 'esx-a', $context['host_name']);

explorer_expect_exception('unknown-parent-fails-closed', 'unknown external id', function () use ($Explorer, $datastores) {
    $bad = array(
        'datacenters' => array(array('external_id' => 'dc-1', 'name' => 'DC')),
        'clusters' => array(),
        'hosts' => array(),
        'vms' => array(array('external_id' => 'vm-1', 'name' => 'vm', 'datacenter_external_id' => 'dc-missing', 'cluster_external_id' => null, 'host_external_id' => null))
    );
    $Explorer->build($bad, $datastores);
});

explorer_expect_exception('duplicate-id-fails-closed', 'duplicate external id', function () use ($Explorer, $datastores) {
    $bad = array(
        'datacenters' => array(array('external_id' => 'dc-1', 'name' => 'A'), array('external_id' => 'dc-1', 'name' => 'B')),
        'clusters' => array(), 'hosts' => array(), 'vms' => array()
    );
    $Explorer->build($bad, array('datastores' => array()));
});

explorer_expect_exception('page-size-bound', 'page size', function () use ($Explorer, $built) {
    $Explorer->list_vms($built, array('page_size' => 101));
});
explorer_expect_exception('unknown-scope-id', 'unknown external id', function () use ($Explorer, $built) {
    $Explorer->list_vms($built, array('scope' => 'host', 'scope_id' => 'host-missing'));
});
explorer_expect_exception('bad-scope', 'Unsupported VM explorer scope', function () use ($Explorer, $built) {
    $Explorer->list_vms($built, array('scope' => 'datastore', 'scope_id' => 'ds-1'));
});

print "\nPhase 5D.5 Explorer projection regression summary: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
