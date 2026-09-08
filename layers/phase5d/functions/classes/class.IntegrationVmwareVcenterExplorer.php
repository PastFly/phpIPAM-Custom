<?php

/**
 * phpIPAM Custom — Phase 5D bounded VMware explorer projection.
 *
 * Pure in-memory model over already-normalized Phase 5D topology/datastore
 * results. It performs no network or database access and never mutates vCenter
 * or phpIPAM objects. Search is display filtering only and is never used as an
 * identity/placement inference signal.
 */
class IntegrationVmwareVcenterExplorer
{
    private $maxPageSize;

    public function __construct($maxPageSize = 100)
    {
        $this->maxPageSize = max(10, min(250, (int)$maxPageSize));
    }

    public function build(array $topology, array $datastoreInventory)
    {
        $datacenters = $this->index_collection($this->collection($topology, 'datacenters'), 'Datacenter');
        $clusters = $this->index_collection($this->collection($topology, 'clusters'), 'Cluster');
        $hosts = $this->index_collection($this->collection($topology, 'hosts'), 'Host');
        $vms = $this->index_collection($this->collection($topology, 'vms'), 'VM');
        $datastores = $this->index_collection($this->collection($datastoreInventory, 'datastores'), 'Datastore');

        $dcStats = array();
        foreach ($datacenters as $id => $row) {
            $dcStats[$id] = array(
                'external_id' => $id,
                'name' => $row['name'],
                'cluster_count' => 0,
                'host_count' => 0,
                'vm_count' => 0,
                'datastore_count' => 0
            );
        }

        foreach ($clusters as $id => $row) {
            $parent = $this->optional_identifier_field($row, 'datacenter_external_id', 'Cluster Datacenter');
            if ($parent !== null) {
                $this->require_known($datacenters, $parent, 'Cluster Datacenter');
                $dcStats[$parent]['cluster_count']++;
            }
        }
        foreach ($hosts as $id => $row) {
            $dc = $this->optional_identifier_field($row, 'datacenter_external_id', 'Host Datacenter');
            $cluster = $this->optional_identifier_field($row, 'cluster_external_id', 'Host Cluster');
            if ($dc !== null) {
                $this->require_known($datacenters, $dc, 'Host Datacenter');
                $dcStats[$dc]['host_count']++;
            }
            if ($cluster !== null) {
                $this->require_known($clusters, $cluster, 'Host Cluster');
            }
        }
        foreach ($vms as $id => $row) {
            $dc = $this->optional_identifier_field($row, 'datacenter_external_id', 'VM Datacenter');
            $cluster = $this->optional_identifier_field($row, 'cluster_external_id', 'VM Cluster');
            $host = $this->optional_identifier_field($row, 'host_external_id', 'VM Host');
            if ($dc !== null) {
                $this->require_known($datacenters, $dc, 'VM Datacenter');
                $dcStats[$dc]['vm_count']++;
            }
            if ($cluster !== null) {
                $this->require_known($clusters, $cluster, 'VM Cluster');
            }
            if ($host !== null) {
                $this->require_known($hosts, $host, 'VM Host');
            }
        }
        foreach ($datastores as $id => $row) {
            $dc = $this->optional_identifier_field($row, 'datacenter_external_id', 'Datastore Datacenter');
            if ($dc !== null) {
                $this->require_known($datacenters, $dc, 'Datastore Datacenter');
                $dcStats[$dc]['datastore_count']++;
            }
        }

        $overview = array_values($dcStats);
        usort($overview, array($this, 'compare_name'));

        return array(
            'datacenters' => $datacenters,
            'clusters' => $clusters,
            'hosts' => $hosts,
            'vms' => $vms,
            'datastores' => $datastores,
            'overview' => $overview,
            'counts' => array(
                'datacenters' => count($datacenters),
                'clusters' => count($clusters),
                'hosts' => count($hosts),
                'vms' => count($vms),
                'datastores' => count($datastores)
            ),
            'persistent_inventory_written' => false,
            'phpipam_objects_modified' => false,
            'vcenter_objects_modified' => false
        );
    }

    public function list_vms(array $explorer, array $filters = array())
    {
        $this->require_built_explorer($explorer);
        $scope = isset($filters['scope']) ? trim((string)$filters['scope']) : '';
        $scopeId = isset($filters['scope_id']) ? trim((string)$filters['scope_id']) : '';
        $search = isset($filters['search']) ? trim((string)$filters['search']) : '';
        $power = isset($filters['power_state']) ? trim((string)$filters['power_state']) : '';
        $page = isset($filters['page']) ? (int)$filters['page'] : 1;
        $pageSize = isset($filters['page_size']) ? (int)$filters['page_size'] : 50;

        if ($page < 1) {
            throw new InvalidArgumentException('Explorer page must be >= 1');
        }
        if ($pageSize < 1 || $pageSize > $this->maxPageSize) {
            throw new InvalidArgumentException('Explorer page size exceeds the configured bound');
        }
        if ($scope !== '' && !in_array($scope, array('datacenter', 'cluster', 'host'), true)) {
            throw new InvalidArgumentException('Unsupported VM explorer scope');
        }
        if ($scope !== '') {
            $scopeId = $this->validate_identifier($scopeId, 'Explorer scope');
            $mapName = $scope === 'datacenter' ? 'datacenters' : ($scope === 'cluster' ? 'clusters' : 'hosts');
            $this->require_known($explorer[$mapName], $scopeId, 'Explorer scope');
        }
        if (strlen($search) > 256 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $search)) {
            throw new InvalidArgumentException('Explorer search is invalid');
        }
        if ($power !== '' && strlen($power) > 128) {
            throw new InvalidArgumentException('Explorer power state is invalid');
        }

        $rows = array();
        foreach ($explorer['vms'] as $row) {
            if ($scope !== '' && !$this->vm_matches_scope($row, $scope, $scopeId)) {
                continue;
            }
            if ($power !== '') {
                $rowPower = isset($row['power_state']) && $row['power_state'] !== null ? (string)$row['power_state'] : '';
                if (strcasecmp($rowPower, $power) !== 0) {
                    continue;
                }
            }
            if ($search !== '' && !$this->row_matches_search($row, $search)) {
                continue;
            }
            $rows[] = $this->vm_list_row($row, $explorer);
        }

        usort($rows, array($this, 'compare_name'));
        $total = count($rows);
        $offset = ($page - 1) * $pageSize;
        $items = $offset < $total ? array_slice($rows, $offset, $pageSize) : array();

        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $total === 0 ? 0 : (int)ceil($total / $pageSize),
            'persistent_inventory_written' => false,
            'phpipam_objects_modified' => false,
            'vcenter_objects_modified' => false
        );
    }

    public function vm_context(array $explorer, $vmId)
    {
        $this->require_built_explorer($explorer);
        $vmId = $this->validate_identifier($vmId, 'VM');
        $this->require_known($explorer['vms'], $vmId, 'VM');
        return $this->vm_list_row($explorer['vms'][$vmId], $explorer);
    }

    private function vm_list_row(array $row, array $explorer)
    {
        $dcId = $this->optional_identifier_field($row, 'datacenter_external_id', 'VM Datacenter');
        $clusterId = $this->optional_identifier_field($row, 'cluster_external_id', 'VM Cluster');
        $hostId = $this->optional_identifier_field($row, 'host_external_id', 'VM Host');

        return array(
            'external_id' => $row['external_id'],
            'name' => $row['name'],
            'power_state' => isset($row['power_state']) ? $row['power_state'] : null,
            'cpu_count' => isset($row['cpu_count']) ? $row['cpu_count'] : null,
            'memory_size_mib' => isset($row['memory_size_mib']) ? $row['memory_size_mib'] : null,
            'datacenter_external_id' => $dcId,
            'datacenter_name' => $dcId !== null ? $explorer['datacenters'][$dcId]['name'] : null,
            'cluster_external_id' => $clusterId,
            'cluster_name' => $clusterId !== null ? $explorer['clusters'][$clusterId]['name'] : null,
            'host_external_id' => $hostId,
            'host_name' => $hostId !== null ? $explorer['hosts'][$hostId]['name'] : null
        );
    }

    private function vm_matches_scope(array $row, $scope, $scopeId)
    {
        $field = $scope === 'datacenter' ? 'datacenter_external_id' : ($scope === 'cluster' ? 'cluster_external_id' : 'host_external_id');
        return isset($row[$field]) && $row[$field] !== null && (string)$row[$field] === $scopeId;
    }

    private function row_matches_search(array $row, $search)
    {
        $needle = $this->lower($search);
        $name = isset($row['name']) ? $this->lower($row['name']) : '';
        $id = isset($row['external_id']) ? $this->lower($row['external_id']) : '';
        return strpos($name, $needle) !== false || strpos($id, $needle) !== false;
    }

    private function collection(array $result, $field)
    {
        if (!isset($result[$field]) || !is_array($result[$field])) {
            throw new InvalidArgumentException('Explorer source is missing collection ' . $field);
        }
        return $result[$field];
    }

    private function index_collection(array $rows, $label)
    {
        $map = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException($label . ' explorer row has an invalid shape');
            }
            $id = $this->validate_identifier(isset($row['external_id']) ? $row['external_id'] : null, $label);
            $name = isset($row['name']) ? trim((string)$row['name']) : '';
            if ($name === '' || strlen($name) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $name)) {
                throw new RuntimeException($label . ' explorer row has an invalid name');
            }
            if (isset($map[$id])) {
                throw new RuntimeException($label . ' explorer collection contains a duplicate external id');
            }
            $row['external_id'] = $id;
            $row['name'] = $name;
            $map[$id] = $row;
        }
        return $map;
    }

    private function optional_identifier_field(array $row, $field, $label)
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        return $this->validate_identifier($row[$field], $label);
    }

    private function validate_identifier($value, $label)
    {
        if (!is_scalar($value)) {
            throw new RuntimeException($label . ' identifier is invalid');
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException($label . ' identifier is invalid');
        }
        return $value;
    }

    private function require_known(array $map, $id, $label)
    {
        if (!array_key_exists($id, $map)) {
            throw new RuntimeException($label . ' references an unknown external id');
        }
    }

    private function require_built_explorer(array $explorer)
    {
        foreach (array('datacenters', 'clusters', 'hosts', 'vms', 'datastores') as $field) {
            if (!isset($explorer[$field]) || !is_array($explorer[$field])) {
                throw new InvalidArgumentException('Explorer projection is incomplete');
            }
        }
    }

    private function lower($value)
    {
        $value = (string)$value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function compare_name($a, $b)
    {
        $left = isset($a['name']) ? $this->lower($a['name']) : '';
        $right = isset($b['name']) ? $this->lower($b['name']) : '';
        if ($left === $right) {
            return strcmp((string)$a['external_id'], (string)$b['external_id']);
        }
        return strcmp($left, $right);
    }
}
