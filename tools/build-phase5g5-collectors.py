#!/usr/bin/env python3
"""Generate versioned collectors from exact retained sources; never patch a live file."""
import hashlib
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
INPUTS = {
    'layers/phase5f/functions/classes/class.IntegrationVmwareVcenterPerformance.php': '426c8c32632037d2b2ceaac23ef11eb79a06edd2',
    'layers/phase5c/functions/classes/class.IntegrationVmwareVcenterGuestNetwork.php': 'f4db8a61a937424ce93ec9e5cd00a58ca77bdddd',
}


def replace_once(source, old, new):
    if source.count(old) != 1:
        raise RuntimeError('collector_source_anchor_mismatch')
    return source.replace(old, new, 1)


def generate():
    data = []
    for path, expected in INPUTS.items():
        raw = (ROOT / path).read_bytes()
        if hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest() != expected:
            raise RuntimeError('collector_source_pin_mismatch')
        data.append(raw.decode('utf-8'))
    cpu, guest = data
    cpu = replace_once(cpu, 'class IntegrationVmwareVcenterPerformance\n', 'class IntegrationVmwareInventoryPerformance\n')
    cpu = replace_once(cpu, '        $verifyTls = (bool)$verifyTls;', "        if ($verifyTls !== true) throw new RuntimeException('mirror_strict_tls_required');")
    cpu = replace_once(cpu,
        "            if (!$provider['current_supported'] || $provider['refresh_rate'] === null || $provider['refresh_rate'] < 1 || $provider['refresh_rate'] > 300) {\n                throw new RuntimeException('Selected vCenter entity does not expose bounded real-time performance statistics');\n            }",
        """            if ($provider['current_supported'] === false) {
                return $this->unavailable($entityType, $entityId, $provider, 'provider_not_realtime', $started);
            }
            if ($provider['refresh_rate'] === null || $provider['refresh_rate'] < 1 || $provider['refresh_rate'] > 300) {
                throw new RuntimeException('Invalid realtime provider refresh rate');
            }""")
    cpu = replace_once(cpu,
        "            foreach (array('cpu.usage.average', 'mem.usage.average') as $required) {\n                if (!isset($counterMap[$required])) throw new RuntimeException('Required vCenter performance counter not available: ' . $required);\n                if ($counterMap[$required]['unit'] !== 'percent') throw new RuntimeException('Unexpected unit for performance counter: ' . $required);\n            }",
        """            foreach (array('cpu.usage.average', 'mem.usage.average') as $required) {
                if (isset($counterMap[$required]) && $counterMap[$required]['unit'] !== 'percent') {
                    throw new RuntimeException('Unexpected unit for performance counter: ' . $required);
                }
            }""")
    cpu = replace_once(cpu,
        """            $selected = array();
            foreach (array('cpu.usage.average', 'mem.usage.average') as $name) {
                $id = (int)$counterMap[$name]['id'];
                if (!isset($available[$id]) || !$available[$id]) throw new RuntimeException('Aggregate performance metric is unavailable for selected entity: ' . $name);
                $selected[$name] = $counterMap[$name];
            }
""",
        """            $selected = array(); $counterStatus = array();
            foreach (array('cpu.usage.average', 'mem.usage.average') as $name) {
                if (!isset($counterMap[$name])) {
                    $counterStatus[$name] = 'counter_unavailable';
                } elseif (empty($available[(int)$counterMap[$name]['id']])) {
                    $counterStatus[$name] = 'aggregate_unavailable';
                } else {
                    $selected[$name] = $counterMap[$name]; $counterStatus[$name] = 'available';
                }
            }
            if ($selected === array()) {
                return $this->unavailable($entityType, $entityId, $provider, 'aggregate_metrics_unavailable', $started, $counterStatus);
            }
""")
    cpu = replace_once(cpu, "                'counters' => $selected,", "                'counters' => $selected,\n                'counter_status' => $counterStatus,\n                'supported' => true,\n                'reason' => null,")
    cpu = replace_once(cpu, '    private function parse_provider_summary(DOMDocument $doc)', """    private function unavailable($kind, $id, array $provider, $reason, $started, array $counters = array())
    {
        return array('entity_type' => $kind, 'entity_external_id' => $id, 'provider' => $provider,
            'supported' => false, 'reason' => $reason, 'counter_status' => $counters, 'counters' => array(),
            'samples' => array(), 'sample_count' => 0, 'property_method' => 'RetrieveProperties',
            'verify_tls' => true, 'duration_ms' => (int)round((microtime(true) - $started) * 1000));
    }

    private function parse_provider_summary(DOMDocument $doc)""")
    cpu = replace_once(cpu, "        return array(\n            'current_supported' => $this->bool_value($this->child_text($returnval, 'currentSupported')) === true,", """        $current = $this->child_text($returnval, 'currentSupported');
        if (!in_array($current, array('true', 'false', '1', '0'), true)) {
            throw new RuntimeException('Invalid performance provider capability');
        }
        return array(
            'current_supported' => in_array($current, array('true', '1'), true),""")
    cpu = replace_once(cpu, "        $available = array();\n        foreach ($xpath->query('//*[local-name()=\"QueryAvailablePerfMetricResponse\"]/*[local-name()=\"returnval\"]') as $metric) {", """        $responses = $xpath->query('//*[local-name()="QueryAvailablePerfMetricResponse"]');
        if ($responses->length !== 1) throw new RuntimeException('Performance availability response missing');
        $available = array();
        foreach ($xpath->query('./*[local-name()="returnval"]', $responses->item(0)) as $metric) {""")
    cpu = replace_once(cpu, '            if ($id === null) continue;\n            $instance = $this->child_text($metric, \'instance\');', "            if ($id === null || $id < 1) throw new RuntimeException('Invalid available performance counter');\n            $instance = $this->child_text($metric, 'instance');")
    cpu = replace_once(cpu, "        if (!isset($valuesByName['cpu.usage.average']) || !isset($valuesByName['mem.usage.average'])) return array();", "        if ($valuesByName === array()) return array();")
    cpu = replace_once(cpu, "'cpu_percent' => $valuesByName['cpu.usage.average'][$i],", "'cpu_percent' => $valuesByName['cpu.usage.average'][$i] ?? null,")
    cpu = replace_once(cpu, "'memory_percent' => $valuesByName['mem.usage.average'][$i]", "'memory_percent' => $valuesByName['mem.usage.average'][$i] ?? null")
    cpu = replace_once(cpu, "$values[] = preg_match('/^-?[0-9]+$/', $raw) ? ((int)$raw / 100.0) : null;", "$values[] = preg_match('/^[0-9]+$/', $raw) ? ((int)$raw / 100.0) : null;")
    # A valid empty availability list is different from a SOAP fault or a malformed response.
    cpu = replace_once(cpu, '        return $doc;\n    }', """        $responseName = explode('(', $operation, 2)[0] . 'Response';
        $nodes = $xpath->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*[local-name()="' . $responseName . '" and namespace-uri()="urn:vim25"]');
        if ($nodes->length !== 1) throw new RuntimeException('Performance response operation mismatch');
        return $doc;
    }""")
    cpu = replace_once(cpu, '        $map = array();\n', """        if ($xpath->query('//*[local-name()="propSet"][*[local-name()="name" and text()="perfCounter"]]/*[local-name()="val"]')->length !== 1) {
            throw new RuntimeException('Performance counter property missing');
        }
        $map = array();
""")
    guest = replace_once(guest, 'class IntegrationVmwareVcenterGuestNetwork\n', 'class IntegrationVmwareInventoryGuestNetwork\n')
    guest = replace_once(guest, '        $verifyTls = (bool)$verifyTls;', "        if ($verifyTls !== true) throw new RuntimeException('mirror_strict_tls_required');")
    guest = replace_once(guest,
        """        $nic = isset($row['nic']) ? trim((string)$row['nic']) : '';
        if ($nic === '' || strlen($nic) > 1024 || preg_match('/[\\x00-\\x1F\\x7F]/', $nic)) {
            throw new RuntimeException('vCenter guest NIC has an invalid external id');
        }""",
        """        // nic is an OPTIONAL hardware association, not the guest interface identity.
        // Preserve guest-only interfaces and IP observations without fabricating a vNIC ID.
        $nic = $row['nic'] ?? null;
        if ($nic !== null && (!is_string($nic) || trim($nic) === '' || strlen($nic) > 1024 ||
            preg_match('/[\\x00-\\x1F\\x7F]/', $nic))) {
            throw new RuntimeException('vCenter guest NIC has an invalid external id');
        }
        if ($nic !== null) $nic = trim($nic);""")
    guest = replace_once(guest, "            'nic_external_id' => $nic,", "            'nic_external_id' => $nic,\n            'hardware_association' => $nic === null ? 'unassociated' : 'reported',")
    prefix = 'collectors/'
    return {prefix + 'class.IntegrationVmwareInventoryPerformance.php': cpu.encode(),
            prefix + 'class.IntegrationVmwareInventoryGuestNetwork.php': guest.encode()}


if __name__ == '__main__':
    if len(sys.argv) != 2: raise SystemExit('Usage: build-phase5g5-collectors.py OUTPUT_DIRECTORY')
    out = Path(sys.argv[1])
    for name, raw in generate().items():
        p = out / name; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(raw)
    print('PHASE5G5_PINNED_COLLECTORS_GENERATED=2')
