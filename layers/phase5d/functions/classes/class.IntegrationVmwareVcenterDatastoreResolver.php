<?php

/**
 * phpIPAM Custom — Phase 5D VM disk ↔ datastore correlation.
 *
 * The vSphere Automation VM disk detail exposes a VMDK backing path but does
 * not expose a datastore stable ID. This resolver never promotes a datastore
 * name to an authoritative identity blindly. It correlates an observed
 * bracketed datastore name only when the Phase 5D datastore inventory makes
 * that correlation unambiguous, preferably scoped by the VM's authoritative
 * Datacenter ID.
 *
 * This is a pure in-memory operation. No vCenter/phpIPAM requests or writes.
 */
class IntegrationVmwareVcenterDatastoreResolver
{
    public function resolve_vm_detail(array $vmDetailResult, array $datastores, $vmDatacenterExternalId = null)
    {
        if (!isset($vmDetailResult['vm']) || !is_array($vmDetailResult['vm'])) {
            throw new InvalidArgumentException('VM detail result is missing the VM object');
        }
        if (!isset($vmDetailResult['vm']['disks']) || !is_array($vmDetailResult['vm']['disks'])) {
            throw new InvalidArgumentException('VM detail result is missing the disk collection');
        }

        $vmDatacenterExternalId = $this->optional_identifier($vmDatacenterExternalId, 'VM Datacenter');
        $index = $this->index_datastores($datastores);

        $resolved = 0;
        $unresolved = 0;
        $ambiguous = 0;
        $noObservation = 0;
        $resolvedIds = array();
        $disks = array();

        foreach ($vmDetailResult['vm']['disks'] as $disk) {
            if (!is_array($disk)) {
                throw new RuntimeException('VM disk detail has an invalid shape');
            }

            $observedName = $this->optional_name(isset($disk['observed_datastore_name']) ? $disk['observed_datastore_name'] : null);
            $disk['datastore_external_id'] = null;
            $disk['datastore_name'] = null;
            $disk['datastore_relation_status'] = 'unresolved';
            $disk['datastore_relation_evidence'] = isset($disk['datastore_relation_evidence'])
                ? (string)$disk['datastore_relation_evidence']
                : null;
            $disk['datastore_candidate_count'] = 0;

            if ($observedName === null) {
                $disk['datastore_relation_status'] = 'no_observation';
                $noObservation++;
                $disks[] = $disk;
                continue;
            }

            $candidates = $this->find_candidates($index, $observedName, $vmDatacenterExternalId);
            $disk['datastore_candidate_count'] = count($candidates);

            if (count($candidates) === 1) {
                $candidate = $candidates[0];
                $disk['datastore_external_id'] = $candidate['external_id'];
                $disk['datastore_name'] = $candidate['name'];
                $disk['datastore_relation_status'] = 'correlated';
                $disk['datastore_relation_evidence'] = $vmDatacenterExternalId !== null
                    ? 'vmdk_backing_name_exact_and_datacenter_scoped'
                    : 'vmdk_backing_name_exact_and_globally_unique';
                $resolved++;
                $resolvedIds[$candidate['external_id']] = true;
            } elseif (count($candidates) > 1) {
                $disk['datastore_relation_status'] = 'ambiguous';
                $disk['datastore_relation_evidence'] = 'vmdk_backing_name_exact_but_ambiguous';
                $ambiguous++;
            } else {
                $disk['datastore_relation_status'] = 'unresolved';
                $disk['datastore_relation_evidence'] = 'vmdk_backing_name_not_found_in_inventory';
                $unresolved++;
            }

            $disks[] = $disk;
        }

        $vmDetailResult['vm']['disks'] = $disks;
        $vmDetailResult['vm']['resolved_datastore_external_ids'] = array_keys($resolvedIds);
        $vmDetailResult['datastore_resolution'] = array(
            'vm_datacenter_external_id' => $vmDatacenterExternalId,
            'resolved_disk_count' => $resolved,
            'unresolved_disk_count' => $unresolved,
            'ambiguous_disk_count' => $ambiguous,
            'no_observation_disk_count' => $noObservation,
            'resolved_datastore_count' => count($resolvedIds),
            'authority' => 'correlation_only_not_authoritative_vmware_relation',
            'persistent_inventory_written' => false,
            'phpipam_objects_modified' => false,
            'vcenter_objects_modified' => false
        );

        return $vmDetailResult;
    }

    private function index_datastores(array $datastores)
    {
        $ids = array();
        $byNormalizedName = array();

        foreach ($datastores as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Datastore inventory row has an invalid shape');
            }

            $id = $this->required_identifier(isset($row['external_id']) ? $row['external_id'] : null, 'Datastore');
            $name = $this->required_name(isset($row['name']) ? $row['name'] : null, 'Datastore');
            $datacenterId = $this->optional_identifier(isset($row['datacenter_external_id']) ? $row['datacenter_external_id'] : null, 'Datastore Datacenter');

            if (isset($ids[$id])) {
                throw new RuntimeException('Datastore inventory contains a duplicate external id');
            }
            $ids[$id] = true;

            $normalized = $this->normalize_name_key($name);
            if (!isset($byNormalizedName[$normalized])) {
                $byNormalizedName[$normalized] = array();
            }
            $byNormalizedName[$normalized][] = array(
                'external_id' => $id,
                'name' => $name,
                'datacenter_external_id' => $datacenterId
            );
        }

        return $byNormalizedName;
    }

    private function find_candidates(array $index, $observedName, $vmDatacenterExternalId)
    {
        $key = $this->normalize_name_key($observedName);
        if (!isset($index[$key])) {
            return array();
        }

        $candidates = $index[$key];
        if ($vmDatacenterExternalId === null) {
            return $candidates;
        }

        $scoped = array();
        foreach ($candidates as $candidate) {
            if ($candidate['datacenter_external_id'] === $vmDatacenterExternalId) {
                $scoped[] = $candidate;
            }
        }
        return $scoped;
    }

    private function normalize_name_key($value)
    {
        $value = trim((string)$value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private function required_identifier($value, $label)
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

    private function optional_identifier($value, $label)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->required_identifier($value, $label);
    }

    private function required_name($value, $label)
    {
        if (!is_scalar($value)) {
            throw new RuntimeException($label . ' name is invalid');
        }
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException($label . ' name is invalid');
        }
        return $value;
    }

    private function optional_name($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->required_name($value, 'Observed datastore');
    }
}
